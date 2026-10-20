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

finish();
