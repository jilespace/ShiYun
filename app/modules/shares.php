<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* ============================================================ 站内：管理分享 */

function shares_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $rows = q_all(
        'SELECT s.*, n.name AS node_name, n.kind AS node_kind, n.size AS node_size,
                n.ext AS node_ext, n.mime AS node_mime, n.child_count
         FROM shares s JOIN nodes n ON n.id = s.node_id
         WHERE s.user_id = ?
         ORDER BY s.id DESC LIMIT 300',
        [$userId]
    );

    $items = [];
    foreach ($rows as $row) {
        $share = share_public($row);
        $share['node'] = node_public($row + [
            'id' => (int) $row['node_id'],
            'name' => (string) $row['node_name'],
            'kind' => (string) $row['node_kind'],
            'size' => (int) $row['node_size'],
            'ext' => $row['node_ext'],
            'mime' => $row['node_mime'],
            'parent_id' => null,
            'is_favorite' => 0,
            'is_hidden' => 0,
            'is_locked' => 0,
            'is_private' => 0,
            'color' => null,
            'note' => null,
            'child_count' => (int) $row['child_count'],
            'updated_at' => (string) $row['created_at'],
            'created_at' => (string) $row['created_at'],
            'deleted_at' => null,
        ]);
        $items[] = $share;
    }

    render('shares', [
        'title'     => '分享 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'shares',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'shares',
        'items'     => $items,
    ]);
}

function shares_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    if ($node['deleted_at'] !== null) {
        json_fail('该条目已在回收站中。');
    }

    $permission = (string) input('permission', 'download');
    if (!in_array($permission, SHARE_PERMISSIONS, true)) {
        json_fail('权限不合法。');
    }

    $days = input_int('days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + min(3650, $days) * 86400) : null;

    $password = trim((string) input('password', ''));
    if ($password !== '' && mb_strlen($password) > 32) {
        json_fail('提取密码最多 32 位。');
    }

    $maxDownloads = input_int('maxDownloads', 0);

    $id = q_insert('shares', [
        'user_id'        => $userId,
        'node_id'        => (int) $node['id'],
        'token'          => share_token(),
        'password_hash'  => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
        'permission'     => $permission,
        'expires_at'     => $expires,
        'max_downloads'  => $maxDownloads > 0 ? $maxDownloads : null,
        'download_count' => 0,
        'view_count'     => 0,
        'status'         => 'active',
        'created_at'     => now(),
    ]);

    $share = q_one('SELECT * FROM shares WHERE id = ?', [$id]);
    activity_log($userId, 'share.create', (int) $node['id'], (string) $node['name']);

    json_ok([
        'share'    => share_public($share ?? [], $node),
        'password' => $password !== '' ? $password : null,
    ]);
}

function shares_revoke(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请选择要取消的分享。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $count = q_exec(
        "UPDATE shares SET status = 'revoked' WHERE user_id = ? AND id IN ({$in}) AND status = 'active'",
        [$userId, ...$ids]
    );

    activity_log($userId, 'share.revoke', null, $count . ' 条');
    json_ok(['count' => $count]);
}

/** 分享二维码（SVG） */
function shares_qr(): void
{
    $userId = require_auth_api();
    $share = q_one('SELECT * FROM shares WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($share === null) {
        http_response_code(404);
        exit;
    }

    $link = absolute_url((string) ($share['token']));
    $svg = qr_svg($link, 6, 2);
    if ($svg === null) {
        http_response_code(500);
        exit('二维码生成失败');
    }

    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo $svg;
    exit;
}

/** 把站内相对链接补成绝对地址，二维码里必须能直接扫 */
function absolute_url(string $token): string
{
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $https . '://' . $host . '/' . url('shares.open', ['t' => $token]);
}

function shares_visits(): void
{
    $userId = require_auth_api();
    $share = q_one('SELECT * FROM shares WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($share === null) {
        json_fail('分享不存在。', 404);
    }

    $visits = array_map(
        static fn (array $v): array => [
            'ip'     => (string) $v['ip'],
            'device' => detect_device((string) $v['ua']),
            'action' => match ($v['action']) {
                'download' => '下载',
                'upload'   => '上传',
                default    => '浏览',
            },
            'when'   => time_ago((string) $v['created_at']),
        ],
        share_visits_of((int) $share['id'], 80)
    );

    json_ok(['visits' => $visits, 'share' => share_public($share)]);
}

/* ============================================================ 站外：访问分享 */

function shares_resolve(): array
{
    $token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
    $share = share_find($token);
    if ($share === null || !share_is_live($share)) {
        http_response_code(404);
        share_page([
            'title'  => '分享不存在或已失效',
            'state'  => 'gone',
            'token'  => $token,
        ]);
    }

    $node = node_get((int) $share['user_id'], (int) $share['node_id']);
    if ($node === null || $node['deleted_at'] !== null) {
        http_response_code(404);
        share_page([
            'title'  => '分享内容已被移除',
            'state'  => 'gone',
            'token'  => $token,
        ]);
    }

    return [$share, $node];
}

function share_render(array $share, array $node, string $state, array $extra = []): never
{
    $owner = find_user_by_id((int) $share['user_id']);

    $listing = [];
    $crumbs = [];
    $currentId = (int) $node['id'];

    if ($node['kind'] === 'folder' && $state === 'ok') {
        $folderId = (int) ($_GET['folder'] ?? $node['id']);
        // 只允许在分享目录子树内浏览
        $target = node_get((int) $share['user_id'], $folderId);
        if ($target === null || !str_starts_with((string) $target['path'], (string) $node['path'])) {
            $target = $node;
        }
        $currentId = (int) $target['id'];
        $listing = array_map('node_public', node_children((int) $share['user_id'], $currentId, [
            'sort' => 'name', 'dir' => 'asc', 'showHidden' => false,
        ]));
        $crumbs = array_slice(node_breadcrumb((int) $share['user_id'], $currentId), 0);
    }

    share_page([
        'title'    => (string) $node['name'],
        'state'    => $state,
        'token'    => (string) $share['token'],
        'share'    => share_public($share, $node),
        'node'     => node_public($node),
        'owner'    => $owner !== null ? (string) $owner['display_name'] : '某位用户',
        'listing'  => $listing,
        'crumbs'   => $crumbs,
        'current'  => $currentId,
        'rootId'   => (int) $node['id'],
        'errors'   => $extra['errors'] ?? [],
        'notice'   => $extra['notice'] ?? null,
    ]);
}

function share_page(array $data): never
{
    render('share', $data + [
        'bodyClass' => 'is-share',
        'styles'    => ['/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/share.js'],
    ]);
    exit;
}

function shares_open(): void
{
    [$share, $node] = shares_resolve();

    if (!share_unlocked($share)) {
        share_render($share, $node, 'locked');
    }

    // 浏览计数按会话去重，避免刷新刷量
    $seen = $_SESSION['share_seen'][(string) $share['token']] ?? 0;
    if ($seen < time() - 600) {
        q_exec('UPDATE shares SET view_count = view_count + 1 WHERE id = ?', [(int) $share['id']]);
        share_visit((int) $share['id'], 'view', (int) $node['id']);
        $_SESSION['share_seen'][(string) $share['token']] = time();
    }

    share_render($share, $node, 'ok');
}

function shares_unlock(): void
{
    [$share, $node] = shares_resolve();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        share_render($share, $node, 'locked');
    }

    $password = (string) ($_POST['password'] ?? '');
    if ($share['password_hash'] !== null && password_verify($password, (string) $share['password_hash'])) {
        share_mark_unlocked((string) $share['token']);
        redirect(url('shares.open', ['t' => (string) $share['token']]));
    }

    share_render($share, $node, 'locked', ['errors' => ['form' => '提取密码不正确。']]);
}

/** 分享目录内的 JSON 列表（前端翻目录用） */
function shares_browse(): void
{
    [$share, $node] = shares_resolve();

    if (!share_unlocked($share)) {
        json_fail('需要提取密码。', 403, 'locked');
    }
    if ($node['kind'] !== 'folder') {
        json_fail('该分享不是文件夹。', 400);
    }

    $folderId = input_int('folder', (int) $node['id']);
    $target = node_get((int) $share['user_id'], $folderId);
    if ($target === null || !str_starts_with((string) $target['path'], (string) $node['path'])) {
        json_fail('目录不在分享范围内。', 403);
    }

    json_ok([
        'crumbs'   => node_breadcrumb((int) $share['user_id'], $folderId),
        'rootId'   => (int) $node['id'],
        'folder'   => node_public($target),
        'children' => array_map('node_public', node_children((int) $share['user_id'], $folderId, [
            'sort' => 'name', 'dir' => 'asc', 'showHidden' => false,
        ])),
    ]);
}

/** 分享下载（单个文件或整个目录打包） */
function shares_download(): void
{
    [$share, $node] = shares_resolve();

    if (!share_unlocked($share)) {
        redirect(url('shares.open', ['t' => (string) $share['token']]));
    }
    if ($share['permission'] === 'preview') {
        http_response_code(403);
        exit('该分享仅允许预览');
    }

    q_exec('UPDATE shares SET download_count = download_count + 1 WHERE id = ?', [(int) $share['id']]);
    share_visit((int) $share['id'], 'download', (int) $node['id']);

    if ($node['kind'] === 'file') {
        if ($node['blob_id'] === null) {
            http_response_code(404);
            exit;
        }
        $mime = (string) ($node['mime'] ?? 'application/octet-stream');
        $name = (string) $node['name'];

        // 私密空间不直链：shares_create() 只验归属、不排除私密空间，
        // 且直链有效期内的通行证会绕过分享的撤销手段。
        if (!node_in_private((int) $share['user_id'], $node)) {
            blob_deliver((int) $share['user_id'], (int) $node['blob_id'], $mime, $name, 0);
        }
        blob_stream((int) $node['blob_id'], $mime, $name, false);
    }

    // 文件夹：交给通用打包逻辑，用分享者的身份读文件
    zip_stream_nodes((int) $share['user_id'], [$node], safe_zip_name((string) $node['name']) . '.zip');
}

/** 分享内容的原始流（图片/视频/PDF 内联预览） */
function shares_raw(): void
{
    require_once BASE_PATH . '/app/modules/download.php';
    require_once BASE_PATH . '/app/modules/preview.php';

    [$share, $node] = shares_resolve();

    if (!share_unlocked($share) || $node['kind'] !== 'file' || $node['blob_id'] === null) {
        http_response_code(403);
        exit;
    }

    blob_stream(
        (int) $node['blob_id'],
        (string) ($node['mime'] ?? 'application/octet-stream'),
        (string) $node['name'],
        true
    );
}

/** 上传到共享文件夹（分享权限为 upload 时） */
function shares_upload(): void
{
    [$share, $node] = shares_resolve();

    if (!share_unlocked($share)) {
        json_fail('需要提取密码。', 403, 'locked');
    }
    if ($share['permission'] !== 'upload') {
        json_fail('该分享不允许上传。', 403, 'forbidden');
    }
    if ($node['kind'] !== 'folder') {
        json_fail('只能往分享的文件夹里上传。', 400);
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_fail('没有收到文件，或上传出错。');
    }

    $ownerId = (int) $share['user_id'];
    $name = safe_filename((string) $file['name']);
    if ($name === null) {
        json_fail('文件名不合法。');
    }

    $size = (int) $file['size'];
    try {
        assert_quota($ownerId, $size);
    } catch (RuntimeException $e) {
        json_fail('分享者的存储空间不足。', 413, 'quota');
    }

    $mime = mime_of($name, (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 12));
    $blob = blob_ingest_file((string) $file['tmp_name'], null, $mime);
    $created = node_create_file($ownerId, (int) $node['id'], $name, $blob, $mime);

    share_visit((int) $share['id'], 'upload', (int) $created['id']);
    activity_log($ownerId, 'file.upload', (int) $created['id'], $name . '（来自分享）');

    if (str_starts_with($mime, 'image/')) {
        make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
    }

    if (function_exists('search_index_node')) {
        search_index_node($ownerId, $created);
    }

    json_ok(['node' => node_public($created)]);
}
