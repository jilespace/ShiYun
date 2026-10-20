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
check(str_contains($storage, 'mime_inline_ok($mime)'),
    'blob_stream 应用 mime_inline_ok 守卫内联');
check(
    strpos($storage, 'mime_inline_ok($mime)') < strpos($storage, "header('Content-Disposition: '"),
    '守卫必须在写 Content-Disposition 之前生效'
);

// 必须匹配完整的 HEAD 守卫表达式。只找 "'HEAD'" 会命中 blob_direct_url 里的
// s3_request($config, 'HEAD', ...)，那处在 Content-Disposition 之前，顺序断言会假挂。
$headGuard = "\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'";
check(str_contains($storage, $headGuard), 'blob_stream 应短路 HEAD');
check(
    strpos($storage, $headGuard) > strpos($storage, "header('Content-Disposition: '"),
    'HEAD 短路必须写在 header 块之后（否则会漏掉 Content-Length）'
);

finish();
