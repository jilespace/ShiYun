<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   节点模型：文件与文件夹统一在 nodes 表。
   path 为物化路径 "/根/…/自身/"，子树操作用前缀匹配一次完成。
   depth = 路径段数 - 1。
   ========================================================================= */

/** 标签调色板（标签模块与自动化规则共用） */
function tag_palette(): array
{
    return ['#3d3aff', '#8a88ff', '#4ad07a', '#ffa23a', '#ff5a5a', '#e8d44d', '#3ad0d0', '#c88aff'];
}

const NODE_SORTS = [
    'name' => 'n.name_key',
    'size' => 'n.size',
    'type' => 'n.ext',
    'time' => 'n.updated_at',
];

/* ------------------------------------------------------------------ 读取 */

function node_get(int $userId, int $id): ?array
{
    return q_one('SELECT * FROM nodes WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
}

function node_require(int $userId, int $id): array
{
    $node = node_get($userId, $id);
    if ($node === null) {
        throw new RuntimeException('文件不存在或无权访问');
    }
    return $node;
}

/**
 * 列目录。
 *
 * @param array{sort?:string,dir?:string,showHidden?:bool,folder?:?int,type?:?string,q?:?string} $opts
 */
function node_children(int $userId, int $parentId, array $opts = []): array
{
    return node_attach_meta($userId, node_children_rows($userId, $parentId, $opts));
}

/**
 * 分页列目录：返回 [rows, hasMore]。
 *
 * 单独开一个入口，而不是让 node_children 按参数返回两种形状——
 * 那样每个调用方都得先判断拿到的是数组还是结构，很容易漏。
 *
 * @return array{0:list<array>,1:bool}
 */
function node_children_page(int $userId, int $parentId, array $opts = [], int $limit = 300, int $offset = 0): array
{
    $limit = max(1, $limit);

    // 多取一条即可判断还有没有下一页，省掉一次 COUNT
    $rows = node_children_rows($userId, $parentId, $opts, $limit + 1, $offset);

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        array_pop($rows);
    }

    return [node_attach_meta($userId, $rows), $hasMore];
}

/**
 * 目录查询的公共部分：排序、过滤，可选取前多少条。
 *
 * @param array{sort?:string,dir?:string,showHidden?:bool,type?:?string,q?:?string} $opts
 * @return list<array> 未经 node_attach_meta 补全的原始行
 */
function node_children_rows(int $userId, int $parentId, array $opts = [], ?int $limit = null, int $offset = 0): array
{
    $sort = NODE_SORTS[$opts['sort'] ?? 'name'] ?? NODE_SORTS['name'];
    $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $showHidden = (bool) ($opts['showHidden'] ?? false);

    $where = ['n.user_id = ?', 'n.parent_id = ?', 'n.deleted_at IS NULL'];
    $params = [$userId, $parentId];

    if (!$showHidden) {
        $where[] = 'n.is_hidden = 0';
    }
    if (!empty($opts['type']) && $opts['type'] !== 'all') {
        $where[] = 'n.kind = ?';
        $params[] = $opts['type'] === 'folder' ? 'folder' : 'file';
    }
    if (!empty($opts['q'])) {
        $where[] = 'n.name_key LIKE ?';
        $params[] = '%' . mb_strtolower((string) $opts['q']) . '%';
    }

    // 文件夹恒在文件之前
    $sql = 'SELECT n.* FROM nodes n WHERE ' . implode(' AND ', $where)
        . " ORDER BY (n.kind = 'folder') DESC, {$sort} {$dir}, n.id ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset);
    }

    return q_all($sql, $params);
}


/** 回收站顶层条目（按删除时间倒序） */
function node_trash_list(int $userId): array
{
    $rows = q_all(
        'SELECT n.* FROM nodes n WHERE n.user_id = ? AND n.deleted_at IS NOT NULL AND n.parent_id = ?
         ORDER BY n.deleted_at DESC, n.id DESC',
        [$userId, user_trash_id($userId)]
    );
    return node_attach_meta($userId, $rows);
}

/** 收藏列表 */
function node_favorites(int $userId): array
{
    $rows = q_all(
        'SELECT n.* FROM nodes n WHERE n.user_id = ? AND n.is_favorite = 1 AND n.deleted_at IS NULL
         ORDER BY n.updated_at DESC LIMIT 500',
        [$userId]
    );
    return node_attach_meta($userId, $rows);
}

/** 给一批节点补上标签、分享状态、缩略图标记、副本状态 */
function node_attach_meta(int $userId, array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

    // 分批取元信息：IN (...) 的占位符数量等于条目数，目录一大单条语句就会超出
    // max_allowed_packet，占位符太多也更容易把连接打满。
    $tagMap = [];
    $sharedMap = [];
    $directMap = [];
    $memberMap = [];
    foreach (array_chunk($ids, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));

        foreach (q_all(
            "SELECT nt.node_id, t.id, t.name, t.color FROM node_tags nt
             JOIN tags t ON t.id = nt.tag_id
             WHERE nt.node_id IN ({$in}) AND t.user_id = ?",
            [...$chunk, $userId]
        ) as $row) {
            $tagMap[(int) $row['node_id']][] = [
                'id'    => (int) $row['id'],
                'name'  => (string) $row['name'],
                'color' => (string) $row['color'],
            ];
        }

        foreach (q_all(
            "SELECT node_id, COUNT(*) AS c FROM shares
             WHERE node_id IN ({$in}) AND user_id = ? AND status = 'active' GROUP BY node_id",
            [...$chunk, $userId]
        ) as $row) {
            $sharedMap[(int) $row['node_id']] = (int) $row['c'];
        }

        foreach (q_all(
            "SELECT node_id, COUNT(*) AS c FROM direct_links
             WHERE node_id IN ({$in}) AND user_id = ? AND status = 'active' GROUP BY node_id",
            [...$chunk, $userId]
        ) as $row) {
            $directMap[(int) $row['node_id']] = (int) $row['c'];
        }

        foreach (q_all(
            "SELECT node_id, COUNT(*) AS c FROM folder_members WHERE node_id IN ({$in}) GROUP BY node_id",
            $chunk
        ) as $row) {
            $memberMap[(int) $row['node_id']] = (int) $row['c'];
        }
    }

    // 副本状态同样一次批量取回（同一份内容可能被多个节点引用，按 blob_id 归并）
    $blobIds = [];
    foreach ($rows as $row) {
        $bid = (int) ($row['blob_id'] ?? 0);
        if ($bid > 0) {
            $blobIds[$bid] = $bid;
        }
    }

    $cosMap = [];
    // 必须按 storage_sources.user_id 过滤：blobs 跨用户去重，不过滤的话，
    // 别人的源的状态会显示在你的文件上，cosSourceId 还会泄漏别人的源 id。
    foreach (array_chunk(array_values($blobIds), 400) as $blobChunk) {
        $blobIn = implode(',', array_fill(0, count($blobChunk), '?'));

        foreach (q_all(
            "SELECT bl.blob_id, bl.source_id, bl.status
             FROM blob_locations bl
             JOIN storage_sources s ON s.id = bl.source_id
             WHERE bl.blob_id IN ({$blobIn}) AND s.user_id = ?",
            [...$blobChunk, $userId]
        ) as $row) {
            $bid = (int) $row['blob_id'];
            $status = (string) $row['status'];
            // 多副本时优先展示可用的那一份
            if (!isset($cosMap[$bid]) || $status === 'ok') {
                $cosMap[$bid] = ['sourceId' => (int) $row['source_id'], 'status' => $status];
            }
        }
    }

    foreach ($rows as &$row) {
        $id = (int) $row['id'];
        $row['tags'] = $tagMap[$id] ?? [];
        $row['share_count'] = $sharedMap[$id] ?? 0;
        $row['direct_count'] = $directMap[$id] ?? 0;
        $row['member_count'] = $memberMap[$id] ?? 0;
        $row['cos'] = $cosMap[(int) ($row['blob_id'] ?? 0)] ?? null;
        $row['kind_of'] = $row['kind'] === 'file'
            ? kind_of((string) $row['mime'], (string) $row['ext'])
            : 'folder';
        $row['has_thumb'] = $row['kind'] === 'file' && $row['kind_of'] === 'image';
    }
    unset($row);

    return $rows;
}

/**
 * 某目录直属子项的汇总（文件夹数 / 文件数 / 字节）。
 * 分页之后页脚不能再拿当前页去加总，否则数字会随翻页变化。
 *
 * @return array{folders:int,files:int,bytes:int}
 */
function node_child_stats(int $userId, int $parentId, bool $showHidden = false): array
{
    $where = ['user_id = ?', 'parent_id = ?', 'deleted_at IS NULL'];
    $params = [$userId, $parentId];

    if (!$showHidden) {
        $where[] = 'is_hidden = 0';
    }

    $row = q_one(
        'SELECT
            COALESCE(SUM(kind = \'folder\'), 0) AS folders,
            COALESCE(SUM(kind = \'file\'), 0)   AS files,
            COALESCE(SUM(IF(kind = \'file\', size, 0)), 0) AS bytes
         FROM nodes WHERE ' . implode(' AND ', $where),
        $params
    );

    return [
        'folders' => (int) ($row['folders'] ?? 0),
        'files'   => (int) ($row['files'] ?? 0),
        'bytes'   => (int) ($row['bytes'] ?? 0),
    ];
}

/** 面包屑：从根到当前节点 */
function node_breadcrumb(int $userId, int $nodeId): array
{
    $rootId = user_root_id($userId);
    if ($nodeId === $rootId || $nodeId <= 0) {
        return [['id' => $rootId, 'name' => '我的文件']];
    }

    $node = node_get($userId, $nodeId);
    if ($node === null) {
        return [['id' => $rootId, 'name' => '我的文件']];
    }

    // path 形如 /1/5/9/，逐段取回
    $ids = array_values(array_filter(explode('/', trim((string) $node['path'], '/')), static fn ($v) => $v !== ''));
    if ($ids === []) {
        return [['id' => $rootId, 'name' => '我的文件']];
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = q_all("SELECT id, name FROM nodes WHERE id IN ({$in}) AND user_id = ?", [...$ids, $userId]);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = (string) $r['name'];
    }

    $crumbs = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        $crumbs[] = [
            'id'   => $id,
            'name' => $id === $rootId ? '我的文件' : ($byId[$id] ?? '…'),
        ];
    }

    return $crumbs;
}

/** 节点是否在私密空间内 */
function node_in_private(int $userId, ?array $node): bool
{
    if ($node === null) {
        return false;
    }
    $privateId = user_private_id($userId);
    return (int) $node['id'] === $privateId
        || str_contains((string) $node['path'], '/' . $privateId . '/');
}

/* ------------------------------------------------------------------ 校验 */

function node_name_exists(int $userId, int $parentId, string $name, ?int $exceptId = null): bool
{
    $sql = 'SELECT id FROM nodes WHERE user_id = ? AND parent_id = ? AND name_key = ? AND deleted_at IS NULL';
    $params = [$userId, $parentId, mb_strtolower($name)];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    return q_val($sql . ' LIMIT 1', $params) !== null;
}

/** 目标父目录是否可作为移动/复制目的地（防止移入自身子树） */
function node_can_contain(array $moving, int $targetParentId): bool
{
    if ((int) $moving['id'] === $targetParentId) {
        return false;
    }
    if ($moving['kind'] !== 'folder') {
        return true;
    }
    $target = node_get((int) $moving['user_id'], $targetParentId);
    if ($target === null) {
        return false;
    }
    // 目标位于被移动目录的子树内 → 非法
    return !str_starts_with((string) $target['path'], (string) $moving['path']);
}

/* ------------------------------------------------------------------ 创建 */

function node_create_folder(int $userId, int $parentId, string $name): array
{
    $parent = node_require($userId, $parentId);
    $name = safe_filename($name) ?? throw new RuntimeException('文件夹名不合法');

    if (node_name_exists($userId, $parentId, $name)) {
        throw new RuntimeException('同一目录下已存在同名文件夹');
    }

    // 插入 + 回填真实路径 + 刷新计数必须原子：中途失败会留下 path 仍是占位值
    // （只有父路径、没有自己的 id）的节点，它和它的子节点此后都会定位错乱。
    $id = db_tx(static function () use ($userId, $parentId, $parent, $name): int {
        $newId = q_insert('nodes', [
            'user_id'    => $userId,
            'parent_id'  => $parentId,
            'name'       => $name,
            'name_key'   => mb_strtolower($name),
            'kind'       => 'folder',
            'size'       => 0,
            'path'       => (string) $parent['path'],   // 占位，插入后立即用真实 id 重算
            'depth'      => (int) $parent['depth'] + 1,
            'is_private' => node_in_private($userId, $parent) ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        q_update('nodes', ['path' => (string) $parent['path'] . $newId . '/'], 'id = ?', [$newId]);
        node_refresh_child_count($userId, $parentId);

        return $newId;
    });

    return node_require($userId, $id);
}

/**
 * 建文件节点并引用一份内容。
 *
 * @param array{blob_id:int,size:int,hash:string} $blob
 */
function node_create_file(int $userId, int $parentId, string $name, array $blob, ?string $mime = null, ?string $ext = null): array
{
    $parent = node_require($userId, $parentId);
    $name = safe_filename($name) ?? throw new RuntimeException('文件名不合法');

    if (node_name_exists($userId, $parentId, $name)) {
        $name = unique_name(
            $name,
            static fn (string $candidate): bool => node_name_exists($userId, $parentId, $candidate)
        );
    }

    $mime ??= mime_of($name);
    $ext ??= ext_of($name);

    // 配额占用 + 插入 + 回填真实路径 + 刷新计数放在一个事务里。
    // 配额那条带条件的 UPDATE 是「先写后判」，两个并发上传不可能同时通过
    // （assert_quota() 是先看后写，只能当预检）；包进事务则中途失败会
    // 连同配额预留一起回滚，也不会留下 path 仍是占位值的半成品节点。
    $id = db_tx(static function () use ($userId, $parentId, $parent, $name, $blob, $mime, $ext): int {
        if (!quota_claim($userId, (int) $blob['size'])) {
            throw new RuntimeException('存储空间不足，请清理文件或扩容。');
        }

        $newId = q_insert('nodes', [
            'user_id'    => $userId,
            'parent_id'  => $parentId,
            'name'       => $name,
            'name_key'   => mb_strtolower($name),
            'kind'       => 'file',
            'size'       => (int) $blob['size'],
            'mime'       => $mime,
            'ext'        => $ext,
            'blob_id'    => (int) $blob['blob_id'],
            'path'       => (string) $parent['path'] . '0/',   // 下面重算
            'depth'      => (int) $parent['depth'] + 1,
            'is_private' => node_in_private($userId, $parent) ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        q_update('nodes', ['path' => (string) $parent['path'] . $newId . '/'], 'id = ?', [$newId]);
        node_refresh_child_count($userId, $parentId);

        return $newId;
    });

    return node_require($userId, $id);
}

/** 确保目录链存在（拖拽上传整个文件夹时用） */
function node_ensure_path(int $userId, int $baseId, string $relativeDir): int
{
    $relativeDir = trim(str_replace('\\', '/', $relativeDir), '/');
    if ($relativeDir === '') {
        return $baseId;
    }

    $current = $baseId;
    foreach (explode('/', $relativeDir) as $segment) {
        $segment = safe_filename($segment);
        if ($segment === null) {
            continue;
        }
        $existing = q_one(
            'SELECT id FROM nodes WHERE user_id = ? AND parent_id = ? AND name_key = ? AND kind = ? AND deleted_at IS NULL LIMIT 1',
            [$userId, $current, mb_strtolower($segment), 'folder']
        );
        $current = $existing !== null
            ? (int) $existing['id']
            : (int) node_create_folder($userId, $current, $segment)['id'];
    }

    return $current;
}

/* ------------------------------------------------------------------ 改名 */

function node_rename(int $userId, array $node, string $newName): array
{
    if ((int) $node['is_locked'] === 1) {
        throw new RuntimeException('该条目已锁定，请先解锁。');
    }

    $newName = safe_filename($newName) ?? throw new RuntimeException('名称不合法');
    $parentId = (int) $node['parent_id'];

    if (node_name_exists($userId, $parentId, $newName, (int) $node['id'])) {
        throw new RuntimeException('同一目录下已存在同名条目');
    }

    $patch = [
        'name'       => $newName,
        'name_key'   => mb_strtolower($newName),
        'updated_at' => now(),
    ];
    if ($node['kind'] === 'file') {
        $patch['ext'] = ext_of($newName);
        $patch['mime'] = mime_of($newName);
    }

    q_update('nodes', $patch, 'id = ? AND user_id = ?', [(int) $node['id'], $userId]);

    return node_require($userId, (int) $node['id']);
}

/* ------------------------------------------------------------------ 移动 */

function node_move(int $userId, array $node, int $targetParentId): array
{
    if ((int) $node['is_locked'] === 1) {
        throw new RuntimeException('该条目已锁定，请先解锁。');
    }
    if (!node_can_contain($node, $targetParentId)) {
        throw new RuntimeException('不能移动到自身或其子目录中');
    }

    $target = node_require($userId, $targetParentId);
    if ($target['kind'] !== 'folder') {
        throw new RuntimeException('目标不是文件夹');
    }

    $name = (string) $node['name'];
    if (node_name_exists($userId, $targetParentId, $name, (int) $node['id'])) {
        $name = unique_name(
            $name,
            static fn (string $candidate): bool => node_name_exists($userId, $targetParentId, $candidate),
            $node['kind'] === 'folder'
        );
    }

    $oldPath = (string) $node['path'];
    $newPath = (string) $target['path'] . (int) $node['id'] . '/';
    $depthDelta = (int) $target['depth'] + 1 - (int) $node['depth'];
    $oldParent = (int) $node['parent_id'];

    db_tx(static function () use ($userId, $node, $targetParentId, $target, $name, $oldPath, $newPath, $depthDelta): void {
        q_update('nodes', [
            'parent_id'  => $targetParentId,
            'name'       => $name,
            'name_key'   => mb_strtolower($name),
            'is_private' => node_in_private($userId, $target) ? 1 : 0,
            'updated_at' => now(),
        ], 'id = ? AND user_id = ?', [(int) $node['id'], $userId]);

        // 整棵子树重算路径，并同步 is_private。
        // 搜索/相册/统计是按 is_private 这一列过滤的（不是按路径判定），
        // 只改被移动节点自身的话，移入私密空间的文件夹其子项仍会出现在
        // 搜索与相册里——那是隐私泄漏；反向移出则会让正常文件永久消失。
        q_exec(
            'UPDATE nodes SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ?, is_private = ?
             WHERE user_id = ? AND (id = ? OR path LIKE ?)',
            [
                $newPath, strlen($oldPath) + 1, $depthDelta,
                node_in_private($userId, $target) ? 1 : 0,
                $userId, (int) $node['id'], $oldPath . '%',
            ]
        );
    });

    node_refresh_child_count($userId, $oldParent);
    node_refresh_child_count($userId, $targetParentId);

    return node_require($userId, (int) $node['id']);
}

/* ------------------------------------------------------------------ 复制 */

/**
 * 深拷贝到目标目录（文件只增加 blob 引用，不复制物理内容）。
 */
function node_copy(int $userId, array $node, int $targetParentId): array
{
    $target = node_require($userId, $targetParentId);
    if ($target['kind'] !== 'folder') {
        throw new RuntimeException('目标不是文件夹');
    }
    if ((int) $node['id'] === $targetParentId) {
        throw new RuntimeException('不能复制到自身');
    }
    if ($node['kind'] === 'folder' && str_starts_with((string) $target['path'], (string) $node['path'])) {
        throw new RuntimeException('不能复制到自身的子目录中');
    }

    $name = unique_name(
        (string) $node['name'],
        static fn (string $candidate): bool => node_name_exists($userId, $targetParentId, $candidate),
        $node['kind'] === 'folder'
    );

    return db_tx(static function () use ($userId, $node, $targetParentId, $name): array {
        $newId = copy_node_row($userId, $node, $targetParentId, $name);

        if ($node['kind'] === 'folder') {
            copy_subtree($userId, (int) $node['id'], $newId);
        }

        return node_require($userId, $newId);
    });
}

/** 递归复制子节点 */
function copy_subtree(int $userId, int $sourceId, int $destId): void
{
    $children = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL ORDER BY id ASC',
        [$userId, $sourceId]
    );

    foreach ($children as $child) {
        $copyId = copy_node_row($userId, $child, $destId, (string) $child['name']);
        if ($child['kind'] === 'folder') {
            copy_subtree($userId, (int) $child['id'], $copyId);
        }
    }

    node_refresh_child_count($userId, $destId);
}

/** 复制单行（文件会 +1 blob 引用并计入用量） */
function copy_node_row(int $userId, array $node, int $parentId, string $name): int
{
    $parent = node_require($userId, $parentId);

    if ($node['kind'] === 'file' && $node['blob_id'] !== null) {
        q_exec('UPDATE blobs SET ref_count = ref_count + 1 WHERE id = ?', [(int) $node['blob_id']]);
        user_add_usage($userId, (int) $node['size']);
    }

    $id = q_insert('nodes', [
        'user_id'    => $userId,
        'parent_id'  => $parentId,
        'name'       => $name,
        'name_key'   => mb_strtolower($name),
        'kind'       => $node['kind'],
        'size'       => (int) $node['size'],
        'mime'       => $node['mime'],
        'ext'        => $node['ext'],
        'blob_id'    => $node['blob_id'],
        'path'       => (string) $parent['path'] . '0/',
        'depth'      => (int) $parent['depth'] + 1,
        'is_favorite' => 0,
        'is_hidden'  => (int) $node['is_hidden'],
        'color'      => $node['color'],
        'note'       => $node['note'],
        'is_private' => node_in_private($userId, $parent) ? 1 : 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    q_update('nodes', ['path' => (string) $parent['path'] . $id . '/'], 'id = ?', [$id]);

    // 复制标签
    q_exec(
        'INSERT IGNORE INTO node_tags (node_id, tag_id) SELECT ?, tag_id FROM node_tags WHERE node_id = ?',
        [$id, (int) $node['id']]
    );

    return $id;
}

/* -------------------------------------------------------------- 访问时间 */

/**
 * 回收站根节点的路径前缀。删文件夹时只标记顶层节点，子节点靠这个前缀识别；
 * 结果按用户缓存，避免一页里多次查同一条记录。
 */
function node_trash_prefix(int $userId): string
{
    static $cache = [];
    if (!array_key_exists($userId, $cache)) {
        $cache[$userId] = (string) q_val('SELECT path FROM nodes WHERE id = ?', [user_trash_id($userId)]);
    }
    return $cache[$userId];
}

/**
 * 记录一次访问。同一条目一小时内只写一次——预览页刷新很频繁，
 * 每次都 UPDATE 会让热点文件变成写放大。
 */
function node_touch_access(int $userId, int $nodeId): void
{
    // 先读后写。直接发那条带条件的 UPDATE，命中不了也会锁住被检查的行，
    // 而视频拖动进度条会并发打同一行；先做一次普通读（不加锁）就能避开。
    $last = q_val('SELECT last_access_at FROM nodes WHERE id = ? AND user_id = ?', [$nodeId, $userId]);
    if ($last !== null && strtotime((string) $last) >= time() - 3600) {
        return;
    }
    q_exec('UPDATE nodes SET last_access_at = ? WHERE id = ? AND user_id = ?', [now(), $nodeId, $userId]);
}

/* -------------------------------------------------------------- 回收站 */

function node_trash(int $userId, array $node): void
{
    if ((int) $node['is_locked'] === 1) {
        throw new RuntimeException('该条目已锁定，请先解锁。');
    }

    $trashId = user_trash_id($userId);
    $oldPath = (string) $node['path'];
    $newPath = (string) q_val('SELECT path FROM nodes WHERE id = ?', [$trashId]) . (int) $node['id'] . '/';
    $depthDelta = 1 - (int) $node['depth'];
    $oldParent = (int) $node['parent_id'];

    db_tx(static function () use ($userId, $node, $trashId, $oldPath, $newPath, $depthDelta): void {
        q_update('nodes', [
            'parent_id'    => $trashId,
            'deleted_at'   => now(),
            'deleted_from' => (int) $node['parent_id'],
            'updated_at'   => now(),
        ], 'id = ? AND user_id = ?', [(int) $node['id'], $userId]);

        q_exec(
            'UPDATE nodes SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ?
             WHERE user_id = ? AND (id = ? OR path LIKE ?)',
            [$newPath, strlen($oldPath) + 1, $depthDelta, $userId, (int) $node['id'], $oldPath . '%']
        );
    });

    node_refresh_child_count($userId, $oldParent);
}

function node_restore(int $userId, array $node): array
{
    $rootId = user_root_id($userId);
    $from = (int) ($node['deleted_from'] ?? 0);

    $parent = $from > 0 ? node_get($userId, $from) : null;
    if ($parent === null || $parent['deleted_at'] !== null) {
        $parent = node_require($userId, $rootId);
    }

    $name = (string) $node['name'];
    if (node_name_exists($userId, (int) $parent['id'], $name, (int) $node['id'])) {
        $name = unique_name(
            $name,
            static fn (string $candidate): bool => node_name_exists($userId, (int) $parent['id'], $candidate),
            $node['kind'] === 'folder'
        );
    }

    $oldPath = (string) $node['path'];
    $newPath = (string) $parent['path'] . (int) $node['id'] . '/';
    $depthDelta = (int) $parent['depth'] + 1 - (int) $node['depth'];

    db_tx(static function () use ($userId, $node, $parent, $name, $oldPath, $newPath, $depthDelta): void {
        q_update('nodes', [
            'parent_id'    => (int) $parent['id'],
            'name'         => $name,
            'name_key'     => mb_strtolower($name),
            'deleted_at'   => null,
            'deleted_from' => null,
            'is_private'   => node_in_private($userId, $parent) ? 1 : 0,
            'updated_at'   => now(),
        ], 'id = ? AND user_id = ?', [(int) $node['id'], $userId]);

        // 同 node_move：子树必须一起改 is_private，否则从回收站还原回私密空间
        // （或从私密空间还原到根）时，子项的标记与所在位置不符。
        q_exec(
            'UPDATE nodes SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ?, is_private = ?
             WHERE user_id = ? AND (id = ? OR path LIKE ?)',
            [
                $newPath, strlen($oldPath) + 1, $depthDelta,
                node_in_private($userId, $parent) ? 1 : 0,
                $userId, (int) $node['id'], $oldPath . '%',
            ]
        );
    });

    node_refresh_child_count($userId, (int) $parent['id']);

    return node_require($userId, (int) $node['id']);
}

/** 彻底删除（含整棵子树），并释放内容引用 */
function node_purge(int $userId, array $node): void
{
    db_tx(static function () use ($userId, $node): void {
        // 必须 FOR UPDATE：并发的第二次 purge（连点两次「彻底删除」，或清空回收站
        // 撞上 cron 的 trash_purge_expired）会阻塞到第一次提交，之后它的锁定读看到
        // 行已被删、拿到空集直接退出。否则两次 purge 各释放一次引用，去重共享的
        // blob 会被第二次调用当成「归零」删掉物理文件，而它仍被别的节点引用着。
        $rows = q_all(
            'SELECT id, kind, blob_id, size FROM nodes
             WHERE user_id = ? AND (id = ? OR path LIKE ?) FOR UPDATE',
            [$userId, (int) $node['id'], (string) $node['path'] . '%']
        );

        // 已被并发删除：空数组会让下面的 IN () 变成语法错误，直接返回
        if ($rows === []) {
            return;
        }

        $freedBytes = 0;
        foreach ($rows as $row) {
            if ($row['kind'] === 'file') {
                blob_release($row['blob_id'] !== null ? (int) $row['blob_id'] : null);
                $freedBytes += (int) $row['size'];
            }
        }

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));

        // 历史版本各自持有一次 blob 引用（version_snapshot 时 +1），删除节点时必须一并释放，
        // 否则 ref_count 永不归零、物理文件与远程副本（purge 任务）永不清理。
        foreach (q_all("SELECT blob_id, size FROM node_versions WHERE node_id IN ({$in})", $ids) as $v) {
            blob_release((int) $v['blob_id']);
            $freedBytes += (int) $v['size'];
        }

        q_exec("DELETE FROM node_tags WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM node_versions WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM file_index WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM folder_members WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM shares WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM direct_links WHERE node_id IN ({$in})", $ids);
        q_exec("DELETE FROM nodes WHERE id IN ({$in})", $ids);

        if ($freedBytes > 0) {
            user_add_usage($userId, -$freedBytes);
        }
    });
}

/** 回收站过期清理 */
function trash_purge_expired(int $userId, int $retentionDays, int $limit = 200): int
{
    if ($retentionDays <= 0) {
        return 0;
    }
    $cutoff = date('Y-m-d H:i:s', time() - $retentionDays * 86400);
    $rows = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND deleted_at IS NOT NULL AND deleted_at < ? AND parent_id = ?
         ORDER BY deleted_at ASC LIMIT ' . max(1, min(1000, $limit)),
        [$userId, $cutoff, user_trash_id($userId)]
    );

    $count = 0;
    foreach ($rows as $row) {
        node_purge($userId, $row);
        $count++;
    }
    return $count;
}

/* ------------------------------------------------------------------ 标记 */

function node_refresh_child_count(int $userId, int $parentId): void
{
    $count = (int) q_val(
        'SELECT COUNT(*) FROM nodes WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL',
        [$userId, $parentId]
    );
    q_update('nodes', ['child_count' => $count], 'id = ? AND user_id = ?', [$parentId, $userId]);
}

/** 批量打标记（收藏/隐藏/锁定/颜色） */
function node_set_flags(int $userId, array $ids, array $flags): int
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
    if ($ids === [] || $flags === []) {
        return 0;
    }

    $allowed = ['is_favorite', 'is_hidden', 'is_locked', 'color', 'note'];
    $flags = array_intersect_key($flags, array_flip($allowed));
    if ($flags === []) {
        return 0;
    }

    $flags['updated_at'] = now();
    $sets = implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($flags)));
    $in = implode(',', array_fill(0, count($ids), '?'));

    return q_exec(
        "UPDATE nodes SET {$sets} WHERE user_id = ? AND id IN ({$in})",
        [...array_values($flags), $userId, ...$ids]
    );
}

/** 按 id 列表取节点（保证属于该用户） */
function nodes_by_ids(int $userId, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return q_all("SELECT * FROM nodes WHERE user_id = ? AND id IN ({$in})", [$userId, ...$ids]);
}

/** 把节点整理成前端需要的形状（各模块共用） */
function node_public(array $node): array
{
    $kind = $node['kind'] === 'folder' ? 'folder' : kind_of((string) $node['mime'], (string) $node['ext']);

    return [
        'id'          => (int) $node['id'],
        'parent'      => ($node['parent_id'] ?? null) !== null ? (int) $node['parent_id'] : null,
        'name'        => (string) $node['name'],
        'kind'        => $node['kind'],
        'type'        => $kind,
        'size'        => (int) $node['size'],
        'sizeText'    => format_bytes((float) $node['size']),
        'mime'        => (string) ($node['mime'] ?? ''),
        'ext'         => (string) ($node['ext'] ?? ''),
        'icon'        => icon_for_kind($kind, (string) ($node['ext'] ?? '')),
        'favorite'    => (int) $node['is_favorite'] === 1,
        'hidden'      => (int) $node['is_hidden'] === 1,
        'locked'      => (int) $node['is_locked'] === 1,
        'private'     => (int) $node['is_private'] === 1,
        'color'       => $node['color'] !== null ? (string) $node['color'] : null,
        'note'        => (string) ($node['note'] ?? ''),
        'tags'        => $node['tags'] ?? [],
        'childCount'  => (int) $node['child_count'],
        'shareCount'  => (int) ($node['share_count'] ?? 0),
        'directCount' => (int) ($node['direct_count'] ?? 0),
        'memberCount' => (int) ($node['member_count'] ?? 0),
        'hasThumb'    => $kind === 'image',
        'cosStatus'   => $node['cos']['status'] ?? '',
        'cosSourceId' => $node['cos']['sourceId'] ?? 0,
        'updated'     => (string) $node['updated_at'],
        'updatedText' => time_ago((string) $node['updated_at']),
        'created'     => (string) $node['created_at'],
        'deleted'     => $node['deleted_at'] !== null ? time_ago((string) $node['deleted_at']) : null,
    ];
}

/** 统计某节点的子树大小与文件数 */
function node_subtree_stats(int $userId, array $node): array
{
    $row = q_one(
        'SELECT COUNT(*) AS c, COALESCE(SUM(size), 0) AS s FROM nodes
         WHERE user_id = ? AND path LIKE ? AND deleted_at IS NULL AND kind = ?',
        [$userId, (string) $node['path'] . '%', 'file']
    );
    $folders = (int) q_val(
        'SELECT COUNT(*) FROM nodes WHERE user_id = ? AND path LIKE ? AND deleted_at IS NULL AND kind = ?',
        [$userId, (string) $node['path'] . '%', 'folder']
    );

    return [
        'files'   => (int) ($row['c'] ?? 0),
        'bytes'   => (int) ($row['s'] ?? 0),
        'folders' => $folders,
    ];
}
