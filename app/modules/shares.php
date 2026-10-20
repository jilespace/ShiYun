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

    $directRows = q_all(
        'SELECT d.*, n.name AS node_name, n.kind AS node_kind, n.size AS node_size,
                n.ext AS node_ext, n.mime AS node_mime, n.deleted_at AS node_deleted_at
         FROM direct_links d JOIN nodes n ON n.id = d.node_id
         WHERE d.user_id = ?
         ORDER BY d.id DESC LIMIT 300',
        [$userId]
    );

    $directs = [];
    foreach ($directRows as $row) {
        // 文件在回收站时直链实际返回 410，状态列必须据此改口，不能仍显示「生效中」。
        // 不把这类行过滤掉：用户仍要能看到并作废它。
        $link = direct_public($row, $row['node_deleted_at'] !== null);
        $link['node'] = node_public([
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
            'child_count' => 0,
            'updated_at' => (string) $row['created_at'],
            'created_at' => (string) $row['created_at'],
            'deleted_at' => null,
        ]);
        $directs[] = $link;
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
        'directs'   => $directs,
        'tab'       => ($_GET['tab'] ?? '') === 'direct' ? 'direct' : 'shares',
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

    // 写权限（可上传 / 收取箱）只对文件夹有意义
    if (in_array($permission, SHARE_UPLOAD_PERMISSIONS, true) && $node['kind'] !== 'folder') {
        json_fail('只有文件夹能开启上传或收取箱。');
    }

    $days = input_int('days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + min(3650, $days) * 86400) : null;

    $password = trim((string) input('password', ''));
    if ($password !== '' && mb_strlen($password) > 32) {
        json_fail('提取密码最多 32 位。');
    }

    // 仅预览与收取箱都没有下载入口，次数限制没有意义；顺手夹一下上限，避免脏数据
    $maxDownloads = in_array($permission, ['preview', 'inbox'], true)
        ? 0
        : min(100000, max(0, input_int('maxDownloads', 0)));

    $inboxNote = null;
    $maxFiles = null;
    if ($permission === 'inbox') {
        $note = trim((string) input('note', ''));
        $inboxNote = $note !== '' ? mb_substr($note, 0, 500) : null;

        // 0 或留空 = 不限数量；给个上限防止意外开成无限量
        $maxFiles = min(5000, max(0, input_int('maxFiles', 0)));
        $maxFiles = $maxFiles > 0 ? $maxFiles : null;
    }

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
        'inbox_note'     => $inboxNote,
        'max_files'      => $maxFiles,
        'upload_count'   => 0,
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

    $link = absolute_url('shares.open', ['t' => (string) $share['token']]);
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
                'upload'   => (string) $share['permission'] === 'inbox' ? '收到' : '上传',
                default    => '浏览',
            },
            'when'   => time_ago((string) $v['created_at']),
        ],
        share_visits_of((int) $share['id'], 80)
    );

    json_ok(['visits' => $visits, 'share' => share_public($share)]);
}

/* ============================================================ 站外：访问分享 */

/**
 * 解析分享链接。
 *
 * $allowFullInbox 只给投递接口用：收取箱收满时 share_is_live() 会返回 false，
 * 但我们需要让上传请求拿到明确的 409「已收满」，而不是被当成失效链接直接 404。
 */
function shares_resolve(bool $allowFullInbox = false): array
{
    // 用 input() 而不是直接读 $_POST：分片初始化/合并是 JSON 请求，$_POST 是空的
    $token = (string) input('t', '');
    $share = share_find($token);
    if ($share === null) {
        // 用 410 而不是 404：nginx 默认会用自己的 404 页面替换响应体，
        // 那样这里精心写的「分享不存在」落地页永远露不出来。
        http_response_code(410);
        share_page([
            'title'  => '分享不存在或已失效',
            'state'  => 'gone',
            'token'  => $token,
        ]);
    }
    if (!share_is_live($share)) {
        $onlyFull = $allowFullInbox
            && share_inbox_full($share)
            && (string) $share['status'] === 'active'
            && ($share['expires_at'] === null || strtotime((string) $share['expires_at']) >= time());

        if (!$onlyFull) {
            http_response_code(410);
            // 收满的收取箱别说成「分享失效」，对方会以为链接错了
            $title = share_inbox_full($share) ? '这个收取箱已经收满了' : '分享不存在或已失效';
            share_page([
                'title'  => $title,
                'state'  => 'gone',
                'token'  => $token,
            ]);
        }
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

    // 收取箱是只写入口：绝不能把目录内容渲染出来，否则「给我传东西」等于「给你看我的文件夹」
    if ($node['kind'] === 'folder' && $state === 'ok' && ($share['permission'] ?? '') !== 'inbox') {
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
    [$share, $node] = shares_resolve(true);

    if (($share['permission'] ?? '') === 'inbox') {
        json_fail('收取箱不提供浏览。', 403, 'forbidden');
    }
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
    [$share, $node] = shares_resolve(true);

    if (($share['permission'] ?? '') === 'inbox') {
        http_response_code(403);
        exit('这是收取箱，只接受上传，不提供下载');
    }
    if (!share_unlocked($share)) {
        redirect(url('shares.open', ['t' => (string) $share['token']]));
    }
    if ($share['permission'] === 'preview') {
        http_response_code(403);
        exit('该分享仅允许预览');
    }

    q_exec('UPDATE shares SET download_count = download_count + 1 WHERE id = ?', [(int) $share['id']]);
    share_visit((int) $share['id'], 'download', (int) $node['id']);
    node_touch_access((int) $share['user_id'], (int) $node['id']);

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

    [$share, $node] = shares_resolve(true);

    if (($share['permission'] ?? '') === 'inbox') {
        http_response_code(403);
        exit;
    }
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

/**
 * 往分享目录里投递文件。
 * 可上传（upload）与收取箱（inbox）共用这条通道，区别是收取箱有数量上限、
 * 且投递者的署名会写进文件备注，方便主人知道「这是谁给的」。
 */
function shares_upload(): void
{
    [$share, $node] = share_upload_target();

    $isInbox = (string) $share['permission'] === 'inbox';
    if ($isInbox && share_inbox_full($share)) {
        json_fail('这个收取箱已经收满了。', 409, 'full');
    }
    // 收满之前的最后一个名额也要抢：并发上传时两个请求都可能看到「还没满」，
    // 所以先做一次带条件的自增，抢不到就直接拒绝，失败时再退回去。
    if ($isInbox) {
        $reserved = q_exec(
            'UPDATE shares SET upload_count = upload_count + 1
             WHERE id = ? AND status = ? AND (max_files IS NULL OR upload_count < max_files)',
            [(int) $share['id'], 'active']
        );
        if ($reserved !== 1) {
            json_fail('这个收取箱已经收满了。', 409, 'full');
        }
    }

    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('没有收到文件，或上传出错。');
    }

    $ownerId = (int) $share['user_id'];
    $name = safe_filename((string) $file['name']);
    if ($name === null) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('文件名不合法。');
    }

    $size = (int) $file['size'];
    if ($size > (int) config('max_upload')) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('文件超过单文件上限 ' . format_bytes((float) config('max_upload')) . '。', 413);
    }

    try {
        assert_quota($ownerId, $size);
    } catch (RuntimeException $e) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('分享者的存储空间不足。', 413, 'quota');
    }

    try {
        $mime = mime_of($name, (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 12));
        $blob = blob_ingest_file((string) $file['tmp_name'], null, $mime);
        $created = node_create_file($ownerId, (int) $node['id'], $name, $blob, $mime);
    } catch (Throwable $e) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('写入失败：' . $e->getMessage(), 500);
    }

    // 收取箱记下投递者，主人在文件详情里能看到来源
    if ($isInbox) {
        $sender = mb_substr(trim((string) ($_POST['sender'] ?? '')), 0, 40);
        $message = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 300);
        $note = '';
        if ($sender !== '') {
            $note = '来自：' . $sender;
        }
        if ($message !== '') {
            $note .= ($note !== '' ? "\n" : '') . $message;
        }
        if ($note !== '') {
            q_update('nodes', ['note' => $note], 'id = ?', [(int) $created['id']]);
            $created['note'] = $note;
        }
    }

    share_visit((int) $share['id'], 'upload', (int) $created['id']);
    activity_log($ownerId, 'file.upload', (int) $created['id'],
        $name . ($isInbox ? '（收取箱）' : '（来自分享）'));

    if (str_starts_with($mime, 'image/')) {
        make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
    }

    if (function_exists('search_index_node')) {
        search_index_node($ownerId, $created);
    }

    $fresh = $isInbox ? q_one('SELECT * FROM shares WHERE id = ?', [(int) $share['id']]) : null;

    json_ok([
        'node'     => node_public($created),
        'uploads'  => $fresh !== null ? (int) $fresh['upload_count'] : null,
        'maxFiles' => $fresh !== null && $fresh['max_files'] !== null ? (int) $fresh['max_files'] : null,
        'full'     => $fresh !== null ? share_inbox_full($fresh) : false,
    ]);
}

/** 投递前的公共校验：权限、目录、密码、收满 */
function share_upload_target(bool $allowFull = true): array
{
    [$share, $node] = shares_resolve($allowFull);

    if (!share_unlocked($share)) {
        json_fail('需要提取密码。', 403, 'locked');
    }
    if (!in_array((string) $share['permission'], SHARE_UPLOAD_PERMISSIONS, true)) {
        json_fail('该分享不允许上传。', 403, 'forbidden');
    }
    if ($node['kind'] !== 'folder') {
        json_fail('只能往分享的文件夹里上传。', 400);
    }

    return [$share, $node];
}

/** 初始化分片会话（大文件走这条路，绕过 PHP 的 post_max_size） */
function shares_upload_init(): void
{
    require_method('POST');
    [$share, $node] = share_upload_target();

    $isInbox = (string) $share['permission'] === 'inbox';
    if ($isInbox && share_inbox_full($share)) {
        json_fail('这个收取箱已经收满了。', 409, 'full');
    }

    $name = safe_filename((string) input('name', ''));
    if ($name === null) {
        json_fail('文件名不合法。');
    }
    $size = input_int('size');
    if ($size < 0) {
        json_fail('文件大小不合法。');
    }
    if ($size > (int) config('max_upload')) {
        json_fail('文件超过单文件上限 ' . format_bytes((float) config('max_upload')) . '。', 413);
    }

    $ownerId = (int) $share['user_id'];
    try {
        assert_quota($ownerId, $size);
    } catch (RuntimeException $e) {
        json_fail('分享者的存储空间不足。', 413, 'quota');
    }

    // 名额在合并成功后才扣，所以初始化本身不消耗额度；这里单独挡一下
    // 「开一堆会话却不传完」的占用磁盘行为。
    $inflight = (int) q_val(
        "SELECT COUNT(*) FROM upload_sessions
         WHERE share_id = ? AND status IN ('pending','uploading') AND updated_at >= ?",
        [(int) $share['id'], date('Y-m-d H:i:s', time() - 3600)]
    );
    if ($inflight >= 30) {
        json_fail('同时进行的上传太多了，请稍后再试。', 429, 'too_many');
    }

    $chunkSize = (int) config('chunk_size');
    $totalChunks = $size === 0 ? 1 : (int) ceil($size / $chunkSize);
    $uploadId = bin2hex(random_bytes(16));

    q_insert('upload_sessions', [
        'user_id'        => $ownerId,
        'upload_id'      => $uploadId,
        'filename'       => $name,
        'relative_path'  => null,
        'size'           => $size,
        'chunk_size'     => $chunkSize,
        'total_chunks'   => $totalChunks,
        'received'       => json_encode([]),
        'received_bytes' => 0,
        'parent_id'      => (int) $node['id'],
        'source_id'      => null,
        'share_id'       => (int) $share['id'],
        'hash'           => null,
        'status'         => 'pending',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    chunk_dir($uploadId);

    json_ok([
        'uploadId'    => $uploadId,
        'chunkSize'   => $chunkSize,
        'totalChunks' => $totalChunks,
        'name'        => $name,
    ]);
}

/** 收一个分片 */
function shares_upload_chunk(): void
{
    require_once BASE_PATH . '/app/modules/upload.php';
    require_method('POST');
    [$share, $node] = share_upload_target();

    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) ($_SERVER['HTTP_X_UPLOAD_ID'] ?? '')) ?? '';
    $index = (int) ($_SERVER['HTTP_X_CHUNK_INDEX'] ?? -1);

    // 只认属于这条分享的会话：uploadId 泄给别人也换不到别的分享的会话
    $session = q_one(
        'SELECT * FROM upload_sessions WHERE upload_id = ? AND share_id = ? LIMIT 1',
        [$uploadId, (int) $share['id']]
    );
    if ($session === null) {
        json_fail('上传会话不存在或已过期。', 404, 'no_session');
    }

    try {
        json_ok(upload_store_chunk($session, $index));
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 409, 'chunk_failed');
    }
}

/** 合并分片并把文件交给主人 */
function shares_upload_complete(): void
{
    require_once BASE_PATH . '/app/modules/upload.php';
    require_method('POST');
    [$share, $node] = share_upload_target();

    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';
    $session = q_one(
        'SELECT * FROM upload_sessions WHERE upload_id = ? AND share_id = ? LIMIT 1',
        [$uploadId, (int) $share['id']]
    );
    if ($session === null) {
        json_fail('上传会话不存在或已过期。', 404, 'no_session');
    }

    $isInbox = (string) $share['permission'] === 'inbox';
    $fresh = q_one('SELECT * FROM shares WHERE id = ?', [(int) $share['id']]);

    // 已经合并过：直接回报，不重复建节点
    if ((string) $session['status'] === 'done' && $session['node_id'] !== null) {
        json_ok([
            'name'     => (string) $session['filename'],
            'uploads'  => $fresh !== null ? (int) $fresh['upload_count'] : null,
            'maxFiles' => $fresh !== null && $fresh['max_files'] !== null ? (int) $fresh['max_files'] : null,
            'full'     => $fresh !== null ? share_inbox_full($fresh) : false,
        ]);
    }

    try {
        $merged = upload_merge_chunks($session);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 409, 'incomplete');
    }

    $size = (int) filesize($merged);
    $ownerId = (int) $share['user_id'];

    // 名额在合并成功后才扣：中途放弃的分片会话不会白占收取箱的额度
    if ($isInbox) {
        $reserved = q_exec(
            'UPDATE shares SET upload_count = upload_count + 1
             WHERE id = ? AND status = ? AND (max_files IS NULL OR upload_count < max_files)',
            [(int) $share['id'], 'active']
        );
        if ($reserved !== 1) {
            @unlink($merged);
            json_fail('这个收取箱已经收满了。', 409, 'full');
        }
    }

    try {
        $mime = mime_of((string) $session['filename'], (string) @file_get_contents($merged, false, null, 0, 12));
        $blob = blob_ingest_file($merged, null, $mime);
        $created = node_create_file($ownerId, (int) $node['id'], (string) $session['filename'], $blob, $mime);
    } catch (Throwable $e) {
        if ($isInbox) {
            q_exec('UPDATE shares SET upload_count = GREATEST(0, upload_count - 1) WHERE id = ?', [(int) $share['id']]);
        }
        json_fail('写入失败：' . $e->getMessage(), 500);
    }

    if ($isInbox) {
        $sender = mb_substr(trim((string) input('sender', '')), 0, 40);
        $message = mb_substr(trim((string) input('message', '')), 0, 300);
        $note = '';
        if ($sender !== '') {
            $note = '来自：' . $sender;
        }
        if ($message !== '') {
            $note .= ($note !== '' ? "\n" : '') . $message;
        }
        if ($note !== '') {
            q_update('nodes', ['note' => $note], 'id = ?', [(int) $created['id']]);
        }
    }

    share_visit((int) $share['id'], 'upload', (int) $created['id']);
    activity_log($ownerId, 'file.upload', (int) $created['id'],
        (string) $created['name'] . ($isInbox ? '（收取箱）' : '（来自分享）'));
    traffic_add($ownerId, 'up', $size, 1);

    q_update('upload_sessions', [
        'status'     => 'done',
        'node_id'    => (int) $created['id'],
        'updated_at' => now(),
    ], 'id = ?', [(int) $session['id']]);
    chunk_remove_dir($uploadId);

    if (str_starts_with($mime, 'image/')) {
        make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
    }
    if (function_exists('search_index_node')) {
        search_index_node($ownerId, $created);
    }

    $fresh = q_one('SELECT * FROM shares WHERE id = ?', [(int) $share['id']]);

    json_ok([
        'name'     => (string) $created['name'],
        'size'     => $size,
        'uploads'  => $fresh !== null ? (int) $fresh['upload_count'] : null,
        'maxFiles' => $fresh !== null && $fresh['max_files'] !== null ? (int) $fresh['max_files'] : null,
        'full'     => $fresh !== null ? share_inbox_full($fresh) : false,
    ]);
}

/** 收取箱收到的文件（仅分享者本人可见） */
function shares_received(): void
{
    $userId = require_auth_api();
    $share = q_one('SELECT * FROM shares WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($share === null) {
        json_fail('分享不存在。', 404);
    }

    $rows = q_all(
        'SELECT v.created_at, v.ip, n.id, n.name, n.size, n.ext, n.note
         FROM share_visits v JOIN nodes n ON n.id = v.node_id
         WHERE v.share_id = ? AND v.action = ? AND n.deleted_at IS NULL
         ORDER BY v.id DESC LIMIT 200',
        [(int) $share['id'], 'upload']
    );

    $items = array_map(static function (array $r): array {
        $note = (string) ($r['note'] ?? '');
        $sender = '';
        $message = '';
        if (str_starts_with($note, '来自：')) {
            $lines = explode("\n", $note, 2);
            $sender = mb_substr($lines[0], 3, 60);
            $message = $lines[1] ?? '';
        } else {
            $message = $note;
        }

        return [
            'id'        => (int) $r['id'],
            'name'      => (string) $r['name'],
            'size'      => (int) $r['size'],
            'sizeText'  => format_bytes((float) $r['size']),
            'when'      => time_ago((string) $r['created_at']),
            'ip'        => (string) ($r['ip'] ?? ''),
            'sender'    => $sender,
            'message'   => $message,
        ];
    }, $rows);

    json_ok([
        'items' => $items,
        'count' => count($items),
        'share' => share_public($share),
    ]);
}
