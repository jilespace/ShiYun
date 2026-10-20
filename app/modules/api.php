<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

require_once BASE_PATH . '/app/modules/tasks.php';

/* ============================================================ REST 接口 */

/**
 * 统一入口：index.php?r=api.handle&path=xxx
 * 认证用 Authorization: Bearer <token>
 */
function api_handle(): void
{
    $userId = api_require_user();
    $path = trim((string) ($_GET['path'] ?? $_POST['path'] ?? ''), '/');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    switch ($path) {
        case '':
        case 'ping':
            json_ok(['pong' => true, 'version' => (string) config('version')]);

        case 'me':
            $user = find_user_by_id($userId);
            json_ok([
                'id'       => $userId,
                'email'    => (string) ($user['email'] ?? ''),
                'username' => (string) ($user['username'] ?? ''),
                'name'     => (string) ($user['display_name'] ?? ''),
                'quota'    => (int) ($user['quota_bytes'] ?? 0),
                'used'     => user_recompute_usage($userId),
            ]);

        case 'list':
            api_route_list($userId);
        case 'stat':
            api_route_stat($userId);
        case 'mkdir':
            api_route_mkdir($userId);
        case 'rename':
            api_route_rename($userId);
        case 'move':
            api_route_move($userId);
        case 'copy':
            api_route_copy($userId);
        case 'delete':
            api_route_delete($userId);
        case 'search':
            api_route_search($userId);
        case 'upload':
            api_route_upload($userId);
        case 'download':
            api_route_download($userId);
        case 'share':
            api_route_share($userId);
        case 'trash':
            api_route_trash($userId);
        default:
            json_fail('未知接口：' . $path, 404, 'not_found');
    }
}

function api_body(): array
{
    $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function api_needs(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $method) {
        json_fail('该接口需要 ' . $method . ' 方法。', 405, 'method_not_allowed');
    }
}

function api_route_list(int $userId): void
{
    $folderId = (int) ($_GET['folder'] ?? 0) ?: user_root_id($userId);
    $node = node_require($userId, $folderId);

    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    json_ok([
        'folder'   => node_public($node),
        'crumbs'   => node_breadcrumb($userId, $folderId),
        'children' => array_map('node_public', node_children($userId, $folderId, [
            'sort' => (string) ($_GET['sort'] ?? 'name'),
            'dir'  => (string) ($_GET['dir'] ?? 'asc'),
            'showHidden' => false,
        ])),
    ]);
}

function api_route_stat(int $userId): void
{
    $node = node_require($userId, (int) ($_GET['id'] ?? 0));
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }
    $info = node_public(node_attach_meta($userId, [$node])[0]);
    $info['stats'] = $node['kind'] === 'folder'
        ? node_subtree_stats($userId, $node)
        : ['files' => 1, 'bytes' => (int) $node['size'], 'folders' => 0];

    json_ok(['node' => $info]);
}

function api_route_mkdir(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $parent = (int) ($body['parent'] ?? 0) ?: user_root_id($userId);
    $name = (string) ($body['name'] ?? '');

    $node = node_create_folder($userId, $parent, $name);
    activity_log($userId, 'file.create', (int) $node['id'], $name);

    json_ok(['node' => node_public($node)]);
}

function api_route_rename(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $node = node_require($userId, (int) ($body['id'] ?? 0));
    $updated = node_rename($userId, $node, (string) ($body['name'] ?? ''));

    activity_log($userId, 'file.rename', (int) $updated['id'], (string) $updated['name']);
    json_ok(['node' => node_public($updated)]);
}

function api_route_move(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $target = (int) ($body['target'] ?? 0);
    $ids = array_map('intval', (array) ($body['ids'] ?? []));
    if ($target <= 0 || $ids === []) {
        json_fail('需要目标目录 ids 与 target。');
    }

    $moved = [];
    foreach (nodes_by_ids($userId, $ids) as $node) {
        $moved[] = node_public(node_move($userId, $node, $target));
    }

    json_ok(['moved' => $moved, 'count' => count($moved)]);
}

function api_route_copy(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $target = (int) ($body['target'] ?? 0);
    $ids = array_map('intval', (array) ($body['ids'] ?? []));
    if ($target <= 0 || $ids === []) {
        json_fail('需要目标目录 ids 与 target。');
    }

    $copied = [];
    foreach (nodes_by_ids($userId, $ids) as $node) {
        $copied[] = node_public(node_copy($userId, $node, $target));
    }

    json_ok(['copied' => $copied, 'count' => count($copied)]);
}

function api_route_delete(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $ids = array_map('intval', (array) ($body['ids'] ?? []));
    $hard = !empty($body['permanent']);

    $count = 0;
    $skipped = 0;
    foreach (nodes_by_ids($userId, $ids) as $node) {
        if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
            json_fail('私密空间已锁定。', 403, 'private_locked');
        }
        if ($hard) {
            if ($node['deleted_at'] === null) {   // 与 Web 一致：彻底删除前必须先入回收站
                $skipped++;
                continue;
            }
            node_purge($userId, $node);
        } else {
            node_trash($userId, $node);
        }
        $count++;
    }

    user_recompute_usage($userId);
    json_ok(['count' => $count, 'permanent' => $hard, 'skipped' => $skipped]);
}

function api_route_trash(int $userId): void
{
    json_ok([
        'items' => array_map('node_public', node_trash_list($userId)),
    ]);
}

function api_route_search(int $userId): void
{
    $result = search_nodes($userId, [
        'q'     => (string) ($_GET['q'] ?? ''),
        'scope' => (string) ($_GET['scope'] ?? 'name'),
        'type'  => (string) ($_GET['type'] ?? 'all'),
        'limit' => (int) ($_GET['limit'] ?? 50),
        'sort'  => (string) ($_GET['sort'] ?? 'time'),
        'dir'   => (string) ($_GET['dir'] ?? 'desc'),
    ]);

    json_ok(['items' => $result['items'], 'total' => $result['total']]);
}

function api_route_upload(int $userId): void
{
    api_needs('POST');
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        json_fail('没有收到文件，或上传出错。');
    }

    $parent = (int) ($_POST['parent'] ?? 0) ?: user_root_id($userId);
    $name = safe_filename((string) $file['name']);
    if ($name === null) {
        json_fail('文件名不合法。');
    }

    // 可选目标存储源：0/缺省 = 仅本地。非零时必须是本人可用的 COS（s3）源。
    $sourceId = (int) input('source', 0);
    if ($sourceId > 0) {
        $ok = q_one('SELECT id FROM storage_sources WHERE id = ? AND user_id = ? AND driver = ?',
            [$sourceId, $userId, 's3']);
        if ($ok === null) {
            json_fail('存储源不存在或不可用。');
        }
    }

    $size = (int) $file['size'];
    try {
        assert_quota($userId, $size);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 413, 'quota');
    }

    $mime = mime_of($name, (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 12));
    $blob = blob_ingest_file((string) $file['tmp_name'], null, $mime);

    // 同名文件覆盖上传 → 留版本
    $existing = q_one(
        'SELECT * FROM nodes WHERE user_id = ? AND parent_id = ? AND name_key = ? AND kind = ? AND deleted_at IS NULL LIMIT 1',
        [$userId, $parent, mb_strtolower($name), 'file']
    );

    if ($existing !== null) {
        $node = node_replace_content($userId, $existing, $blob, $mime);
        versions_prune($userId, (int) $node['id'], 10);
    } else {
        $node = node_create_file($userId, $parent, $name, $blob, $mime);
    }

    if (str_starts_with($mime, 'image/')) {
        make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
    }
    search_index_node($userId, $node);
    rules_apply_to_node($userId, (int) $node['id']);

    // 选了 COS：入队推送，由计划任务驱动的队列执行，不阻塞本次上传
    if ($sourceId > 0) {
        tasks_enqueue_push($userId, (int) $blob['blob_id'], $sourceId, (string) $node['name']);
    }

    activity_log($userId, 'file.upload', (int) $node['id'], $name);
    traffic_add($userId, 'up', $size, 1);

    json_ok(['node' => node_public($node), 'dedup' => (bool) $blob['dedup']]);
}

function api_route_download(int $userId): void
{
    $node = node_require($userId, (int) ($_GET['id'] ?? 0));
    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        json_fail('该条目不是可下载的文件。', 404);
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    activity_log($userId, 'file.download', (int) $node['id'], (string) $node['name']);
    node_touch_access($userId, (int) $node['id']);
    traffic_add($userId, 'down', (int) $node['size'], 1);

    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];
    $limit = (int) user_setting($userId, 'download_speed_limit', 0);

    // 私密空间不直链，理由同 download.php
    if (!node_in_private($userId, $node)) {
        blob_deliver($userId, (int) $node['blob_id'], $mime, $name, $limit);
    }
    blob_stream((int) $node['blob_id'], $mime, $name, false, $limit);
}

function api_route_share(int $userId): void
{
    api_needs('POST');
    $body = api_body();

    $node = node_require($userId, (int) ($body['id'] ?? 0));
    $permission = (string) ($body['permission'] ?? 'download');
    if (!in_array($permission, SHARE_PERMISSIONS, true)) {
        json_fail('权限不合法。');
    }

    $days = (int) ($body['days'] ?? 0);
    $password = trim((string) ($body['password'] ?? ''));

    $id = q_insert('shares', [
        'user_id'       => $userId,
        'node_id'       => (int) $node['id'],
        'token'         => share_token(),
        'password_hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
        'permission'    => $permission,
        'expires_at'    => $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null,
        'status'        => 'active',
        'created_at'    => now(),
    ]);

    json_ok(['share' => share_public(q_one('SELECT * FROM shares WHERE id = ?', [$id]) ?? [], $node)]);
}

/* ============================================================ 令牌管理 */

function api_tokens(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $tokens = q_all('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY id DESC', [$userId]);
    $base = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    render('tokens', [
        'title'     => 'API 令牌 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'tokens',
        'tokens'    => $tokens,
        'base'      => $base,
        'endpoints' => api_endpoint_help(),
    ]);
}

function api_endpoint_help(): array
{
    return [
        ['GET',  'api.handle&path=me',        '账号与配额'],
        ['GET',  'api.handle&path=list',      '列目录（folder 可选）'],
        ['GET',  'api.handle&path=stat',      '条目详情（id）'],
        ['GET',  'api.handle&path=search',    '搜索（q / scope / type）'],
        ['GET',  'api.handle&path=trash',     '回收站列表'],
        ['POST', 'api.handle&path=mkdir',     '新建文件夹（parent / name）'],
        ['POST', 'api.handle&path=rename',    '重命名（id / name）'],
        ['POST', 'api.handle&path=move',      '移动（ids / target）'],
        ['POST', 'api.handle&path=copy',      '复制（ids / target）'],
        ['POST', 'api.handle&path=delete',    '删除（ids / permanent）'],
        ['POST', 'api.handle&path=upload',    '上传（multipart: file / parent）'],
        ['GET',  'api.handle&path=download',  '下载（id）。若文件已托管到 COS，返回 302 跳转，调用方需跟随重定向'],
        ['POST', 'api.handle&path=share',     '创建分享（id / permission / days）'],
    ];
}

function api_tokens_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $name = trim((string) input('name', ''));
    if ($name === '' || mb_strlen($name) > 64) {
        json_fail('令牌名称需在 1–64 字之间。');
    }

    $ttl = input_int('days', 0);
    $result = api_token_create($userId, $name, $ttl > 0 ? $ttl : null);

    activity_log($userId, 'token.create', null, $name);

    json_ok([
        'token' => $result['token'],
        'note'  => '令牌只显示这一次，请立即保存。',
    ]);
}

function api_tokens_revoke(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        $ids = [input_int('id')];
    }
    $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
    if ($ids === []) {
        json_fail('请选择令牌。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $count = q_exec("DELETE FROM api_tokens WHERE user_id = ? AND id IN ({$in})", [$userId, ...$ids]);

    activity_log($userId, 'token.revoke', null, $count . ' 个');
    json_ok(['count' => $count]);
}
