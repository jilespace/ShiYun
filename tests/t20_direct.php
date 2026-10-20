<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

/* 直链（direct_links）：免登录、可撤销、可选绑来源 IP 的单文件字节流入口。 */

// ---------------------------------------------------------------- 内联白名单

check(mime_inline_ok('image/jpeg'), 'jpeg 应可内联');
check(mime_inline_ok('image/png'), 'png 应可内联');
check(mime_inline_ok('image/gif'), 'gif 应可内联');
check(mime_inline_ok('image/webp'), 'webp 应可内联');
check(mime_inline_ok('image/avif'), 'avif 应可内联');
check(mime_inline_ok('image/bmp'), 'bmp 应可内联');
check(mime_inline_ok('video/mp4'), 'mp4 应可内联');
check(mime_inline_ok('audio/mpeg'), 'mp3 应可内联');
check(mime_inline_ok('application/pdf'), 'pdf 应可内联');
check(mime_inline_ok('text/plain'), '纯文本应可内联');

// 带参数（真实响应里常见 image/jpeg; charset=...）
check(mime_inline_ok('text/plain; charset=utf-8'), '带参数的 MIME 应能识别');
check(mime_inline_ok('IMAGE/PNG'), '大小写不敏感');

// 这些能执行脚本，绝不能内联
check(!mime_inline_ok('text/html'), 'html 不得内联');
check(!mime_inline_ok('image/svg+xml'), 'svg 不得内联（可含 script）');
check(!mime_inline_ok('application/xhtml+xml'), 'xhtml 不得内联');
check(!mime_inline_ok('application/javascript'), 'js 不得内联');
check(!mime_inline_ok('application/xml'), 'xml 不得内联');
check(!mime_inline_ok('text/xml'), 'text/xml 不得内联');

// 未知 / 空
check(!mime_inline_ok('application/octet-stream'), '未知类型不得内联');
check(!mime_inline_ok(''), '空 MIME 不得内联');

// ------------------------------------------------- blob_stream 的守卫与 HEAD

$storage = (string) file_get_contents(BASE_PATH . '/app/lib/storage.php');
$guardPos = strpos($storage, 'mime_inline_ok($mime)');
$dispPos  = strpos($storage, "header('Content-Disposition: '");
check(str_contains($storage, 'mime_inline_ok($mime)'),
    'blob_stream 应用 mime_inline_ok 守卫内联');
// 必须两端都存在才比较：strpos 未命中返回 false，false 参与 < 会被当成 0，
// 顺序断言会在守卫缺失时假过——正是它该报错的场景。
check($guardPos !== false && $dispPos !== false && $guardPos < $dispPos,
    '守卫必须在写 Content-Disposition 之前生效'
);

// 必须匹配完整的 HEAD 守卫表达式。只找 "'HEAD'" 会命中 blob_direct_url 里的
// s3_request($config, 'HEAD', ...)，那处在 Content-Disposition 之前，顺序断言会假挂。
$headGuard = "\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'";
$headPos = strpos($storage, $headGuard);
check($headPos !== false, 'blob_stream 应短路 HEAD');
check($headPos !== false && $headPos > $dispPos,
    'HEAD 短路必须写在 header 块之后（否则会漏掉 Content-Length）'
);

// ------------------------------------------- blob_direct_url 的 disposition

$tmpT3 = sys_get_temp_dir() . '/t15-t3.bin';
file_put_contents($tmpT3, 'inline-me');
$blobT3 = blob_ingest_file($tmpT3, null, 'text/plain');
$blobT3Id = (int) $blobT3['blob_id'];
$userT3 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');

// 无可用的对象存储副本 → 两个 disposition 都返回 null（回退本地）
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt', 'inline') === null,
    '无副本时 inline 请求也应回退');
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt') === null,
    '默认参数应保持既有行为（attachment）');

$ref = new ReflectionFunction('blob_direct_url');
check($ref->getParameters()[4]->getName() === 'disposition',
    'blob_direct_url 第 5 个参数应为 disposition');
check($ref->getParameters()[4]->getDefaultValue() === 'attachment',
    'blob_direct_url 的 disposition 默认值应为 attachment');

$refD = new ReflectionFunction('blob_deliver');
check($refD->getParameters()[5]->getName() === 'disposition',
    'blob_deliver 第 6 个参数应为 disposition');
check($refD->getParameters()[5]->getDefaultValue() === 'attachment',
    'blob_deliver 的 disposition 默认值应为 attachment');

// 签名存在 ≠ 参数被使用：把实现那两行也钉住
check(strpos($storage, "\$disposition . '; filename") !== false,
    'blob_direct_url 应把 disposition 用在 presign 的 response-content-disposition 上');
check(str_contains($storage, '$disposition === \'inline\''),
    'blob_deliver 的本地回退应由 disposition 推导 inline');

// 302 路径必须与 blob_stream() 共用同一份内联白名单：否则 text/html 会被
// 当作 inline 从本域发出，正是本功能要防的存储型 XSS。
check(str_contains($storage, 'mime_inline_ok($mime) ? $disposition : '),
    'blob_direct_url 应对非白名单类型强制降级为 attachment');

// ------------------------------------------------------------ direct_links 表

db();

check((bool) q_val("SHOW TABLES LIKE 'direct_links'"), 'direct_links 表应已创建');

$colsT4 = array_column(q_all('SHOW COLUMNS FROM direct_links'), 'Field');
foreach (['user_id', 'node_id', 'token', 'ip_allow', 'expires_at',
          'status', 'hit_count', 'last_access_at', 'created_at'] as $c) {
    check(in_array($c, $colsT4, true), "direct_links 应有列 {$c}");
}

$statusTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'status') {
        $statusTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($statusTypeT4, 'active') && str_contains($statusTypeT4, 'revoked'),
    'status 枚举应为 active / revoked');

$tokTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'token') {
        $tokTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($tokTypeT4, 'char(32)'), 'token 应为 CHAR(32)');

$idxT4 = array_column(q_all('SHOW INDEX FROM direct_links'), 'Key_name');
check(in_array('uk_direct_token', $idxT4, true), 'token 应有唯一索引');

// ip_allow 与 expires_at 必须可空（不限来源 / 永久有效）
$nullsT4 = [];
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    $nullsT4[$row['Field']] = (string) $row['Null'];
}
check($nullsT4['ip_allow'] === 'YES', 'ip_allow 应可空');
check($nullsT4['expires_at'] === 'YES', 'expires_at 应可空');

finish();
