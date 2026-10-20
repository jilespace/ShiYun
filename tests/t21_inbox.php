<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';
ob_start();

/* =========================================================================
   收取箱：分享的另一半 —— 只收不发。

   验四件事：
   1. 落地页不泄漏目录内容（这是 upload 权限一直以来的毛病）；
   2. 投递能把文件放进主人的文件夹，并记下投递者；
   3. 数量上限真的会拦住超额投递，收满后链接变成「已收满」；
   4. 下载 / 浏览 / 原始流三条读路径对收取箱一律关闭。
   ========================================================================= */

const PORT = 15244;

$email = 't21-inbox@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM share_visits WHERE share_id IN (SELECT id FROM shares WHERE user_id = ?)', [$oid]);
    q_exec('DELETE FROM shares WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}
$uid = create_user($email, 'fixt-pass-123', '收取箱测试');
$root = user_root_id($uid);

/** 建一个文件夹并返回节点 */
$mkFolder = static function (string $name) use ($uid, $root): array {
    $parent = node_require($uid, $root);
    $id = (int) q_insert('nodes', [
        'user_id' => $uid, 'parent_id' => $root, 'name' => $name, 'name_key' => mb_strtolower($name),
        'kind' => 'folder', 'size' => 0, 'path' => (string) $parent['path'] . '0/', 'depth' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    q_update('nodes', ['path' => (string) $parent['path'] . $id . '/'], 'id = ?', [$id]);
    node_refresh_child_count($uid, $root);
    return node_require($uid, $id);
};

$inboxFolder = $mkFolder('收取箱');
$openFolder = $mkFolder('协作');

// 箱子里预先放一个文件：收取箱落地页绝不能把它列出来
$t = tempnam(sys_get_temp_dir(), 'pre');
file_put_contents($t, 'secret-existing');
$preBlob = blob_ingest_file($t, null, 'text/plain');
@unlink($t);
node_create_file($uid, (int) $inboxFolder['id'], 'secret-existing.txt', $preBlob, 'text/plain', 'txt');

/* ------------------------------------------------------------- 会话 */
$ownerSid = 't21own' . bin2hex(random_bytes(8));
session_write_close();
ini_set('session.use_strict_mode', '0');
session_id($ownerSid);
session_start();
$_SESSION['user_id'] = $uid;
$_SESSION['csrf_token'] = 'csrf' . bin2hex(random_bytes(6));
$ownerCsrf = $_SESSION['csrf_token'];
session_write_close();

$guestSid = 't21gst' . bin2hex(random_bytes(8));
session_id($guestSid);
session_start();
$_SESSION['csrf_token'] = 'gcsrf' . bin2hex(random_bytes(6));
$guestCsrf = $_SESSION['csrf_token'];
session_write_close();

$base = 'http://127.0.0.1:' . (getenv('TEST_HTTP_PORT') ?: (string) PORT);

/** 返回 [body, status, decoded-json|null] */
$req = static function (string $route, string $sid, ?array $json = null, ?string $csrf = null) use ($base): array {
    $headers = ['Cookie: shiyun_sid=' . $sid, 'Accept: application/json'];
    $body = '';
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'X-CSRF-Token: ' . (string) $csrf;
        $body = json_encode($json);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $json === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $out = (string) @file_get_contents($base . '/index.php?r=' . $route, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    $decoded = null;
    if (str_starts_with(trim($out), '{')) {
        $decoded = json_decode($out, true);
    }
    return [$out, $status, $decoded];
};

/** multipart 投递 */
$upload = static function (string $sid, string $token, string $name, string $content, array $extra = []) use ($base): array {
    $b = '----t21' . bin2hex(random_bytes(8));
    $body = '';
    foreach (['t' => $token] + $extra as $k => $v) {
        $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
    }
    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n{$content}\r\n--{$b}--\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Cookie: shiyun_sid={$sid}\r\nAccept: application/json\r\n"
            . "Content-Type: multipart/form-data; boundary={$b}",
        'content' => $body,
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $out = (string) @file_get_contents($base . '/index.php?r=shares.upload', false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, json_decode($out, true)];
};

/* ------------------------------------------------------- 创建收取箱 */
[$html, $status, $res] = $req('shares.create', $ownerSid, [
    'id' => (int) $inboxFolder['id'],
    'permission' => 'inbox',
    'days' => 7,
    'note' => '请把项目资料传到这里，谢谢',
    'maxFiles' => 2,
    'password' => '',
], $ownerCsrf);

check($status === 200 && ($res['ok'] ?? false) === true, '创建收取箱成功（HTTP ' . $status . '）');
check(($res['data']['share']['isInbox'] ?? false) === true, '返回 isInbox');
check(($res['data']['share']['maxFiles'] ?? 0) === 2, '返回上限 2');
check(($res['data']['share']['inboxNote'] ?? '') === '请把项目资料传到这里，谢谢', '返回收取说明');

$token = (string) ($res['data']['share']['token'] ?? '');
check($token !== '', '拿到 token');

/* ------------------------------------------------- 落地页不泄漏内容 */
[$page, $ps] = $req('shares.open&t=' . $token, $guestSid);
check($ps === 200, '落地页 200（实际 ' . $ps . '）');
check(str_contains($page, '请把项目资料传到这里'), '落地页显示主人的说明');
check(!str_contains($page, 'secret-existing.txt'), '落地页不列出箱子里的既有文件');
check(!str_contains($page, '打包下载'), '收取箱没有下载按钮');
check(str_contains($page, 'data-share-drop'), '落地页有投递区');
check(str_contains($page, '还能提交'), '显示剩余可提交数量');

/* ----------------------------------------------------- 投递两次 */
[$s1, $r1] = $upload($guestSid, $token, '报告一.pdf', '%PDF-1.4 one', ['sender' => '张三', 'message' => '第一份']);
check($s1 === 200 && ($r1['ok'] ?? false) === true, '第一次投递成功（HTTP ' . $s1 . '）');
check(($r1['data']['uploads'] ?? 0) === 1, '投递后计数为 1');
check(($r1['data']['full'] ?? true) === false, '还没满');

[$s2, $r2] = $upload($guestSid, $token, '报告二.pdf', '%PDF-1.4 two', ['sender' => '李四']);
check($s2 === 200 && ($r2['ok'] ?? false) === true, '第二次投递成功');
check(($r2['data']['full'] ?? false) === true, '第二次后已满');

[$s3, $r3] = $upload($guestSid, $token, '报告三.pdf', '%PDF-1.4 three');
check($s3 === 409 && ($r3['code'] ?? '') === 'full', '第三次被上限拦住（HTTP ' . $s3 . '）');

/* 文件真的进了主人的文件夹 */
$inFolder = q_all(
    'SELECT id, name, size, note FROM nodes WHERE user_id = ? AND parent_id = ? AND kind = ? AND deleted_at IS NULL ORDER BY id',
    [$uid, (int) $inboxFolder['id'], 'file']
);
check(count($inFolder) === 3, '箱子里共 3 个文件（原有 1 + 新收 2，实际 ' . count($inFolder) . '）');
check(str_contains((string) $inFolder[1]['note'], '来自：张三'), '第一份记下了投递者');
check(str_contains((string) $inFolder[1]['note'], '第一份'), '第一份记下了留言');
check(($inFolder[2]['note'] ?? '') === '来自：李四', '第二份只有署名');

/* --------------------------------------------- 收满后链接状态 */
[$page2, $ps2] = $req('shares.open&t=' . $token, $guestSid);
check($ps2 === 410, '收满后落地页 410（实际 ' . $ps2 . '）');
check(str_contains($page2, '已经收满'), '收满后提示「已经收满」而不是「分享失效」');

/* ------------------------------------------------- 读路径全关 */
[$dl, $dls] = $req('shares.download&t=' . $token . '&id=' . (int) $inFolder[1]['id'], $guestSid);
check($dls === 403, '下载被拒（HTTP ' . $dls . '）');
[$raw, $raws] = $req('shares.raw&t=' . $token, $guestSid);
check($raws === 403, '原始流被拒（HTTP ' . $raws . '）');
[$br, $brs, $brj] = $req('shares.browse&t=' . $token, $guestSid);
check($brs === 403 && ($brj['ok'] ?? true) === false, '浏览接口被拒（HTTP ' . $brs . '）');

/* ------------------------------------------------- 主人看收到 */
[$recv, $recvs, $recvj] = $req('shares.received&id=' . (int) ($res['data']['share']['id'] ?? 0), $ownerSid);
check($recvs === 200 && ($recvj['ok'] ?? false) === true, '主人能拉取收到列表');
check(($recvj['data']['count'] ?? 0) === 2, '收到 2 条（实际 ' . ($recvj['data']['count'] ?? 0) . '）');
check(($recvj['data']['items'][1]['sender'] ?? '') === '张三', '列表里带投递者');
check(($recvj['data']['items'][1]['message'] ?? '') === '第一份', '列表里带留言');

/* ----------------------------------------------- 分片投递（大文件） */

$chunkFolder = $mkFolder('分片箱');
[, , $chunkShare] = $req('shares.create', $ownerSid, [
    'id' => (int) $chunkFolder['id'], 'permission' => 'inbox', 'days' => 7,
    'note' => '', 'maxFiles' => 5, 'password' => '',
], $ownerCsrf);
$chunkToken = (string) ($chunkShare['data']['share']['token'] ?? '');
$chunkShareId = (int) ($chunkShare['data']['share']['id'] ?? 0);
check($chunkToken !== '' && $chunkShareId > 0, '分片箱创建成功');

// 手工造一个 3 片会话：init 用的是 5 MB 分片，测试里不必真的传 10 MB
$uploadId = bin2hex(random_bytes(16));
q_insert('upload_sessions', [
    'user_id' => $uid, 'upload_id' => $uploadId, 'filename' => 'chunked.bin', 'relative_path' => null,
    'size' => 12, 'chunk_size' => 4, 'total_chunks' => 3, 'received' => json_encode([]),
    'received_bytes' => 0, 'parent_id' => (int) $chunkFolder['id'], 'source_id' => null,
    'share_id' => $chunkShareId, 'hash' => null, 'status' => 'pending',
    'created_at' => now(), 'updated_at' => now(),
]);
chunk_dir($uploadId);

$putChunk = static function (string $sid, string $token, string $uid, int $index, string $body) use ($base): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Cookie: shiyun_sid={$sid}\r\nAccept: application/json\r\n"
            . "Content-Type: application/octet-stream\r\nX-Upload-Id: {$uid}\r\nX-Chunk-Index: {$index}",
        'content' => $body,
        'timeout' => 20,
        'ignore_errors' => true,
    ]]);
    $out = (string) @file_get_contents(
        $base . '/index.php?r=shares.upload_chunk&t=' . rawurlencode($token),
        false,
        $ctx
    );
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return [$status, json_decode($out, true)];
};

[$c1, $cj1] = $putChunk($guestSid, $chunkToken, $uploadId, 0, 'abcd');
check($c1 === 200 && ($cj1['data']['received'] ?? 0) === 1, '分片 1 被接收');

// 拿另一条分享的 token 来投这个会话：必须找不到
[, , $otherShare] = $req('shares.create', $ownerSid, [
    'id' => (int) $openFolder['id'], 'permission' => 'upload', 'days' => 7, 'password' => '',
], $ownerCsrf);
[$xStatus, ] = $putChunk($guestSid, (string) ($otherShare['data']['share']['token'] ?? ''), $uploadId, 1, 'efgh');
check($xStatus === 404, '别的分享不能用这个会话（HTTP ' . $xStatus . '）');

[, $cj2] = $putChunk($guestSid, $chunkToken, $uploadId, 1, 'efgh');
[, $cj3] = $putChunk($guestSid, $chunkToken, $uploadId, 2, 'ijkl');
check(($cj3['data']['received'] ?? 0) === 3, '三片齐了');

[, , $done] = $req('shares.upload_complete', $guestSid, [
    't' => $chunkToken, 'uploadId' => $uploadId, 'sender' => '王五', 'message' => '大文件',
]);
check(($done['ok'] ?? false) === true, '合并成功');
check(($done['data']['name'] ?? '') === 'chunked.bin', '合并后文件名正确');

$chunkNode = q_one(
    'SELECT n.size, n.note, b.hash FROM nodes n JOIN blobs b ON b.id = n.blob_id
     WHERE n.user_id = ? AND n.parent_id = ? AND n.name = ?',
    [$uid, (int) $chunkFolder['id'], 'chunked.bin']
);
check($chunkNode !== null && (int) $chunkNode['size'] === 12, '合片大小 12 字节');
check($chunkNode !== null && file_get_contents(blob_path((string) $chunkNode['hash'])) === 'abcdefghijkl',
    '合片内容正确');
check(str_contains((string) ($chunkNode['note'] ?? ''), '来自：王五'), '分片投递也记下了署名');

// 收满后 init 必须直接拒绝
[, , $tightShare] = $req('shares.create', $ownerSid, [
    'id' => (int) $chunkFolder['id'], 'permission' => 'inbox', 'days' => 7, 'maxFiles' => 1,
], $ownerCsrf);
$tightToken = (string) ($tightShare['data']['share']['token'] ?? '');
[$u1, $uj1] = $upload($guestSid, $tightToken, 'only-one.txt', 'x');
check($u1 === 200 && ($uj1['ok'] ?? false) === true, '名额 1 的单片投递成功');
[, $initStatus, $initJson] = $req('shares.upload_init', $guestSid, [
    't' => $tightToken, 'name' => 'second.bin', 'size' => 8,
]);
check($initStatus === 409 && ($initJson['code'] ?? '') === 'full', '收满后不再接受新的分片会话');
$sessions = (int) q_val('SELECT COUNT(*) FROM upload_sessions WHERE share_id = ?', [(int) ($tightShare['data']['share']['id'] ?? 0)]);
check($sessions === 0, '被拒绝的初始化没有留下会话');

/* ------------------------------------- 普通可上传分享仍然列目录 */
$t2 = tempnam(sys_get_temp_dir(), 'pre2');
file_put_contents($t2, 'collab-visible');
$cBlob = blob_ingest_file($t2, null, 'text/plain');
@unlink($t2);
node_create_file($uid, (int) $openFolder['id'], 'collab-visible.txt', $cBlob, 'text/plain', 'txt');

[, , $collab] = $req('shares.create', $ownerSid, [
    'id' => (int) $openFolder['id'], 'permission' => 'upload', 'days' => 7, 'password' => '',
], $ownerCsrf);
[$cpage, $cps, ] = $req('shares.open&t=' . (string) ($collab['data']['share']['token'] ?? ''), $guestSid);
check($cps === 200, '可上传分享落地页 200');
check(str_contains($cpage, 'collab-visible.txt'), '可上传分享照常列出目录（未误伤）');
check(str_contains($cpage, 'data-share-drop'), '可上传分享保留投递区');

/* ------------------------------------------------- 管理页展示 */
[$listPage, $listStatus] = $req('shares.list', $ownerSid);
check($listStatus === 200, '分享管理页 200（实际 ' . $listStatus . '）');
check(str_contains($listPage, '收取箱'), '管理页把权限标成「收取箱」');
check(str_contains($listPage, '查看收到'), '管理页有「查看收到」入口');
check(str_contains($listPage, '已收满'), '收满的收取箱状态显示为「已收满」');
check(!str_contains($listPage, 'Warning:') && !str_contains($listPage, 'Fatal error'), '管理页无 PHP 报错');

/* --------------------------------------------------------------- 收尾 */
q_exec('DELETE FROM share_visits WHERE share_id IN (SELECT id FROM shares WHERE user_id = ?)', [$uid]);
q_exec('DELETE FROM shares WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM upload_sessions WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);
blob_release((int) $preBlob['blob_id']);
blob_release((int) $cBlob['blob_id']);
ob_end_flush();
finish();
