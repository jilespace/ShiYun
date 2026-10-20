<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* ============================================================ 站外：直链交付 */

function direct_serve(): void
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit('仅支持 GET / HEAD');
    }

    $r = direct_resolve((string) ($_GET['t'] ?? ''), client_ip());
    if (!$r['ok']) {
        http_response_code((int) $r['code']);
        header('Content-Type: text/plain; charset=utf-8');
        exit((string) $r['message']);
    }

    $row = (array) $r['row'];
    $node = (array) $r['node'];

    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];
    $forceDownload = (string) ($_GET['dl'] ?? '') !== '';
    $inline = !$forceDownload && mime_inline_ok($mime);

    q_exec(
        'UPDATE direct_links SET hit_count = hit_count + 1, last_access_at = ? WHERE id = ?',
        [now(), (int) $row['id']]
    );
    traffic_add((int) $row['user_id'], 'down', (int) $node['size'], 1);

    blob_deliver(
        (int) $row['user_id'],
        (int) $node['blob_id'],
        $mime,
        $name,
        0,
        $inline ? 'inline' : 'attachment'
    );
}

/* ============================================================ 站内：管理 */

function direct_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    if ($node['kind'] !== 'file') {
        json_fail('直链只支持单个文件。');
    }
    if ($node['deleted_at'] !== null) {
        json_fail('该条目已在回收站中。');
    }
    if (node_in_private($userId, $node)) {
        json_fail('私密空间的文件不提供直链。', 403, 'private');
    }

    $ipInput = trim((string) input('ipAllow', ''));
    $ipAllow = null;
    if ($ipInput !== '') {
        $ipAllow = direct_normalize_ip($ipInput);
        if ($ipAllow === null) {
            json_fail('来源格式不正确。填单个 IP（如 203.0.113.7）或 IPv4 网段（如 203.0.113.0/24）。');
        }
    }

    $days = input_int('days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + min(3650, $days) * 86400) : null;

    $id = q_insert('direct_links', [
        'user_id'    => $userId,
        'node_id'    => (int) $node['id'],
        'token'      => direct_token(),
        'ip_allow'   => $ipAllow,
        'expires_at' => $expires,
        'status'     => 'active',
        'created_at' => now(),
    ]);

    $row = q_one('SELECT * FROM direct_links WHERE id = ?', [$id]);
    activity_log($userId, 'direct.create', (int) $node['id'], (string) $node['name']);

    json_ok(['link' => direct_public($row ?? [])]);
}

function direct_revoke(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请选择要作废的直链。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $count = q_exec(
        "UPDATE direct_links SET status = 'revoked' WHERE user_id = ? AND id IN ({$in}) AND status = 'active'",
        [$userId, ...$ids]
    );

    activity_log($userId, 'direct.revoke', null, $count . ' 条');
    json_ok(['count' => $count]);
}
