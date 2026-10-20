<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function stats_show(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    snapshot_today($userId);

    $days = (int) ($_GET['days'] ?? 30);
    if (!in_array($days, [7, 30, 90, 180], true)) {
        $days = 30;
    }

    $storage = storage_series($userId, $days);
    $traffic = traffic_series($userId, $days);
    $dist = type_distribution($userId);

    $totals = [
        'files'   => (int) q_val('SELECT COUNT(*) FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL', [$userId, 'file']),
        'folders' => (int) q_val('SELECT COUNT(*) FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL', [$userId, 'folder']),
        'trash'   => (int) q_val('SELECT COUNT(*) FROM nodes WHERE user_id = ? AND deleted_at IS NOT NULL', [$userId]),
        'shared'  => (int) q_val("SELECT COUNT(*) FROM shares WHERE user_id = ? AND status = 'active'", [$userId]),
    ];

    $sumUp = array_sum(array_map(static fn (array $r): int => $r['up'], $traffic));
    $sumDown = array_sum(array_map(static fn (array $r): int => $r['down'], $traffic));

    render('stats', [
        'title'     => '统计 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'stats',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'stats',
        'days'      => $days,
        'storage'   => $storage,
        'traffic'   => $traffic,
        'dist'      => $dist,
        'totals'    => $totals,
        'sumUp'     => $sumUp,
        'sumDown'   => $sumDown,
        'suggestions' => stats_suggestions($userId),
    ]);
}

/**
 * 清理建议：找出真正占地方的大文件与重复内容。
 */
function stats_suggestions(int $userId, int $limit = 8): array
{
    [$visibleWhere, $visibleParams] = stats_visible_where($userId);
    $largest = q_all(
        'SELECT * FROM nodes n WHERE ' . $visibleWhere
        . ' ORDER BY n.size DESC LIMIT ' . max(1, min(50, $limit)),
        $visibleParams
    );

    // 同一份内容被多个节点引用 → 冗余占位（去重后只剩一份物理文件，但仍占节点空间）
    [$dupeWhere, $dupeParams] = stats_visible_where($userId);
    $dupes = q_all(
        "SELECT b.hash, b.size, COUNT(n.id) AS copies, SUM(n.size) - b.size AS wasted,
                MIN(n.name) AS sample
         FROM nodes n JOIN blobs b ON b.id = n.blob_id
         WHERE {$dupeWhere}
         GROUP BY b.id, b.hash, b.size HAVING copies > 1
         ORDER BY wasted DESC LIMIT 5",
        $dupeParams
    );

    // 回收站占用
    $trashBytes = (int) q_val(
        'SELECT COALESCE(SUM(size), 0) FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NOT NULL',
        [$userId, 'file']
    );
    $trashCount = (int) q_val(
        'SELECT COUNT(*) FROM nodes WHERE user_id = ? AND deleted_at IS NOT NULL',
        [$userId]
    );

    $cold = stats_cold_summary($userId);

    return [
        'largest'    => array_map('node_public', $largest),
        'dupes'      => $dupes,
        'trashBytes' => $trashBytes,
        'trashCount' => $trashCount,
        'cold'       => $cold,
    ];
}

/** 图表数据（前端重新拉取用） */
function stats_data(): void
{
    $userId = require_auth_api();
    $days = (int) input('days', 30);
    if (!in_array($days, [7, 30, 90, 180], true)) {
        $days = 30;
    }

    json_ok([
        'storage' => storage_series($userId, $days),
        'traffic' => traffic_series($userId, $days),
        'dist'    => type_distribution($userId),
    ]);
}

/**
 * 可见文件的公共过滤条件：排除已删除、回收站子树里的条目，以及锁定私密空间时的私密文件。
 * 回收站只把被删的顶层节点打上 deleted_at，其子节点仍为 NULL，
 * 所以必须用路径前缀排除整棵回收站子树——否则会对已在回收站里的文件再次执行 node_trash，把路径写坏。
 *
 * @return array{0:string,1:list<mixed>}
 */
function stats_visible_where(int $userId, string $alias = 'n'): array
{
    $where = ["{$alias}.user_id = ?", "{$alias}.kind = ?", "{$alias}.deleted_at IS NULL"];
    $params = [$userId, 'file'];

    if (!Auth::privateUnlocked()) {
        $where[] = "{$alias}.is_private = 0";
    }

    $trashPrefix = node_trash_prefix($userId);
    if ($trashPrefix !== '') {
        $where[] = "{$alias}.path NOT LIKE ?";
        $params[] = $trashPrefix . '%';
    }

    return [implode(' AND ', $where), $params];
}

/** 重复内容清单（带每组的全部副本，前端据此勾选清理）。 */
function stats_duplicates(): void
{
    $userId = require_auth_api();
    $limit = min(100, max(1, (int) input('limit', 50)));

    $groups = stats_duplicate_groups($userId, $limit);
    $totalWasted = array_sum(array_column($groups, 'wasted'));

    json_ok([
        'groups'          => $groups,
        'totalWasted'     => $totalWasted,
        'totalWastedText' => format_bytes((float) $totalWasted),
    ]);
}

/**
 * 把选中的重复副本移入回收站。
 * 每组至少保留一份，且整批先校验再执行——避免删到一半才发现某组不能删。
 */
function stats_duplicate_delete(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) input('ids', [])),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === []) {
        json_fail('请选择要清理的重复副本。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    [$where, $whereParams] = stats_visible_where($userId);

    $nodes = q_all(
        "SELECT * FROM nodes n WHERE n.id IN ({$in}) AND {$where}",
        [...$ids, ...$whereParams]
    );

    if (count($nodes) !== count($ids)) {
        json_fail('部分条目已不存在或不可见，请刷新后重试。');
    }

    // 每组必须至少留下一份
    $byBlob = [];
    foreach ($nodes as $node) {
        $byBlob[(int) $node['blob_id']][] = $node;
    }

    foreach ($byBlob as $blobId => $selected) {
        // 用同一套可见性口径统计总数，回收站子树与未解锁的私密文件不计入，
        // 否则它们会把「最多能删几份」算多，导致整组被删光。
        $total = (int) q_val(
            "SELECT COUNT(*) FROM nodes n WHERE n.blob_id = ? AND {$where}",
            [$blobId, ...$whereParams]
        );
        if (count($selected) >= $total) {
            json_fail('「' . $selected[0]['name'] . '」只剩这些副本，至少要保留一份。');
        }
    }

    foreach ($nodes as $node) {
        if ((int) $node['is_locked'] === 1) {
            json_fail('「' . $node['name'] . '」已锁定，请先解锁后再清理。');
        }
    }

    $trashed = 0;
    $bytes = 0;
    foreach ($nodes as $node) {
        node_trash($userId, $node);
        activity_log($userId, 'dupe.clean', (int) $node['id'], (string) $node['name']);
        $trashed++;
        $bytes += (int) $node['size'];
    }

    json_ok([
        'trashed'   => $trashed,
        'bytes'     => $bytes,
        'bytesText' => format_bytes((float) $bytes),
    ]);
}

/**
 * 取出重复分组详情。
 *
 * @return list<array{hash:string,size:int,copies:int,wasted:int,sample:string,
 *                    sizeText:string,wastedText:string,nodes:list<array>}>
 */
function stats_duplicate_groups(int $userId, int $limit): array
{
    [$where, $params] = stats_visible_where($userId);

    $blobs = q_all(
        "SELECT b.id, b.hash, b.size, COUNT(n.id) AS copies,
                SUM(n.size) - b.size AS wasted, MIN(n.name) AS sample
         FROM nodes n JOIN blobs b ON b.id = n.blob_id
         WHERE {$where}
         GROUP BY b.id, b.hash, b.size HAVING copies > 1
         ORDER BY wasted DESC LIMIT {$limit}",
        $params
    );

    $result = [];
    foreach ($blobs as $blob) {
        $nodes = q_all(
            "SELECT n.id, n.name, n.size, n.created_at, n.is_private
             FROM nodes n WHERE n.blob_id = ? AND {$where}
             ORDER BY n.created_at ASC, n.id ASC",
            [(int) $blob['id'], ...$params]
        );

        $result[] = [
            'hash'       => (string) $blob['hash'],
            'size'       => (int) $blob['size'],
            'copies'     => (int) $blob['copies'],
            'wasted'     => (int) $blob['wasted'],
            'sample'     => (string) $blob['sample'],
            'sizeText'   => format_bytes((float) $blob['size']),
            'wastedText' => format_bytes((float) $blob['wasted']),
            'nodes'      => array_map(static fn (array $n): array => [
                'id'        => (int) $n['id'],
                'name'      => (string) $n['name'],
                'size'      => (int) $n['size'],
                'sizeText'  => format_bytes((float) $n['size']),
                'created'   => (string) $n['created_at'],
                'isPrivate' => (int) $n['is_private'] === 1,
            ], $nodes),
        ];
    }

    return $result;
}

/* ------------------------------------------------------------ 冷数据 */

/** 冷数据判定：超过 180 天没访问，且单文件不小于 5 MB */
const COLD_IDLE_DAYS = 180;
const COLD_MIN_BYTES = 5242880;

/** 冷数据公共条件（已排除回收站、私密锁定、已备份到云端的） */
function stats_cold_where(int $userId): array
{
    [$where, $params] = stats_visible_where($userId);
    $cutoff = date('Y-m-d H:i:s', time() - COLD_IDLE_DAYS * 86400);

    // 从未访问过的文件用 updated_at 兜底：否则刚上传的大文件会立刻被当成「冷数据」。
    // 只认「云端」的可用副本：本地存储源上的 location 不算备份，
    // 否则用户添加过本地源并推送后，冷数据清单会永久为空。
    $where .= ' AND n.size >= ?'
        . ' AND (n.last_access_at < ? OR (n.last_access_at IS NULL AND n.updated_at < ?))'
        . ' AND NOT EXISTS (SELECT 1 FROM blob_locations l'
        . ' JOIN storage_sources s ON s.id = l.source_id'
        . ' WHERE l.blob_id = n.blob_id AND l.status = \'ok\' AND s.driver <> \'local\')';
    $params[] = COLD_MIN_BYTES;
    $params[] = $cutoff;
    $params[] = $cutoff;

    return [$where, $params];
}

function stats_cold_summary(int $userId): array
{
    [$where, $params] = stats_cold_where($userId);
    $row = q_one(
        'SELECT COUNT(*) AS c, COALESCE(SUM(n.size), 0) AS s FROM nodes n WHERE ' . $where,
        $params
    );

    return [
        'count' => (int) ($row['c'] ?? 0),
        'bytes' => (int) ($row['s'] ?? 0),
        'days'  => COLD_IDLE_DAYS,
    ];
}

/** 可接收备份的存储源（本地源没有意义，排除） */
function stats_backup_sources(int $userId): array
{
    return q_all(
        "SELECT id, name, driver FROM storage_sources
         WHERE user_id = ? AND driver <> 'local'
         ORDER BY is_default DESC, priority ASC, id ASC",
        [$userId]
    );
}

function stats_cold(): void
{
    $userId = require_auth_api();
    $limit = min(200, max(1, input_int('limit', 100)));

    [$where, $params] = stats_cold_where($userId);
    $rows = q_all(
        'SELECT n.id, n.name, n.size, n.updated_at, n.last_access_at
         FROM nodes n WHERE ' . $where . ' ORDER BY n.size DESC LIMIT ' . $limit,
        $params
    );

    $items = array_map(static fn (array $r): array => [
        'id'        => (int) $r['id'],
        'name'      => (string) $r['name'],
        'size'      => (int) $r['size'],
        'sizeText'  => format_bytes((float) $r['size']),
        'updated'   => (string) $r['updated_at'],
        'lastSeen'  => $r['last_access_at'] !== null ? time_ago((string) $r['last_access_at']) : '从未访问',
    ], $rows);

    $summary = stats_cold_summary($userId);

    json_ok([
        'items'       => $items,
        'count'       => $summary['count'],
        'bytes'       => $summary['bytes'],
        'bytesText'   => format_bytes((float) $summary['bytes']),
        'days'        => COLD_IDLE_DAYS,
        'sources'     => stats_backup_sources($userId),
    ]);
}

/** 把选中的冷数据排队备份到指定存储源（只加副本，不删本地） */
function stats_cold_backup(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_values(array_unique(array_filter(
        array_map('intval', (array) input('ids', [])),
        static fn (int $id): bool => $id > 0
    )));
    if ($ids === []) {
        json_fail('请选择要备份的文件。');
    }

    $sourceId = input_int('sourceId');
    $source = q_one('SELECT * FROM storage_sources WHERE id = ? AND user_id = ?', [$sourceId, $userId]);
    if ($source === null) {
        json_fail('存储源不存在。', 404);
    }
    if ((string) $source['driver'] === 'local') {
        json_fail('本地存储源不能作为备份目标。');
    }

    [$where, $params] = stats_visible_where($userId);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $nodes = q_all(
        "SELECT n.id, n.name, n.blob_id FROM nodes n
         WHERE n.id IN ({$in}) AND {$where} AND n.blob_id IS NOT NULL",
        [...$ids, ...$params]
    );
    if ($nodes === []) {
        json_fail('选中的文件不存在或不可见。');
    }

    require_once BASE_PATH . '/app/modules/tasks.php';

    // 已经在队列里等着的同一内容不再排一次，避免连点两下产生两份任务
    $pending = [];
    foreach (q_all(
        "SELECT payload FROM transfer_tasks
         WHERE user_id = ? AND kind = 'push' AND status = 'queued' AND payload IS NOT NULL",
        [$userId]
    ) as $row) {
        $p = json_decode((string) $row['payload'], true) ?: [];
        if ((int) ($p['source_id'] ?? 0) === $sourceId) {
            $pending[(int) ($p['blob_id'] ?? 0)] = true;
        }
    }

    $seen = [];
    $queued = 0;
    foreach ($nodes as $node) {
        $blobId = (int) $node['blob_id'];
        if (isset($seen[$blobId]) || isset($pending[$blobId])) {
            continue;   // 同一份内容只排一次，去重后本来就是同一个对象
        }
        $seen[$blobId] = true;
        tasks_enqueue_push($userId, $blobId, $sourceId, (string) $node['name'] . '（冷数据备份）');
        $queued++;
    }

    activity_log($userId, 'cold.backup', null, $queued . ' 项 → ' . (string) $source['name']);

    json_ok(['queued' => $queued, 'source' => (string) $source['name']]);
}
