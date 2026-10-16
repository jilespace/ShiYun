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
    $largest = q_all(
        'SELECT * FROM nodes
         WHERE user_id = ? AND kind = ? AND deleted_at IS NULL
         ORDER BY size DESC LIMIT ' . max(1, min(50, $limit)),
        [$userId, 'file']
    );

    // 同一份内容被多个节点引用 → 冗余占位（去重后只剩一份物理文件，但仍占节点空间）
    $dupes = q_all(
        'SELECT b.hash, b.size, COUNT(n.id) AS copies, SUM(n.size) AS wasted,
                MIN(n.name) AS sample
         FROM nodes n JOIN blobs b ON b.id = n.blob_id
         WHERE n.user_id = ? AND n.kind = ? AND n.deleted_at IS NULL
         GROUP BY b.hash HAVING copies > 1
         ORDER BY wasted DESC LIMIT 5',
        [$userId, 'file']
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

    $stale = q_all(
        'SELECT id, name, size, updated_at FROM nodes
         WHERE user_id = ? AND kind = ? AND deleted_at IS NULL AND updated_at < ?
         ORDER BY size DESC LIMIT 5',
        [$userId, 'file', date('Y-m-d H:i:s', time() - 365 * 86400)]
    );

    return [
        'largest'    => array_map('node_public', $largest),
        'dupes'      => $dupes,
        'trashBytes' => $trashBytes,
        'trashCount' => $trashCount,
        'stale'      => $stale,
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
