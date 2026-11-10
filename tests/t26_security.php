<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   测试目录公网暴露回归。
   tests/ 位于站点根目录：PHP 被 Web 直接请求会真的执行建/删测试数据，
   静态的评审文档也可能带出本机审计内容。这里固定住两层防线。
   ========================================================================= */

$lib = (string) file_get_contents(__DIR__ . '/lib.php');
check(str_contains($lib, "PHP_SAPI !== 'cli'"), 'PHP 测试入口带 CLI 守卫');

$stub = (string) file_get_contents(__DIR__ . '/stub_s3.php');
check(str_contains($stub, "'cli-server'"), 'S3 存根只允许 CLI / 内置服务器');

$htaccess = (string) file_get_contents(BASE_PATH . '/.htaccess');
check(str_contains($htaccess, 'tests'), 'Apache 规则拦截 tests/');
check(str_contains($htaccess, 'docs/review'), 'Apache 规则拦截 docs/review/');

$base = 'http://127.0.0.1:' . (getenv('TEST_HTTP_PORT') ?: '15244');
$status = static function (string $url): int {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    @file_get_contents($url, false, $ctx);
    $line = (string) ($http_response_header[0] ?? '');
    return preg_match('#\s(\d{3})\s#', $line, $m) === 1 ? (int) $m[1] : 0;
};

check($status($base . '/tests/t1_schema.php') === 404, 'Web 直接访问测试脚本返回 404');
check($status($base . '/tests/stub_s3.php') === 404, 'Web 直接访问 S3 存根返回 404');
check($status($base . '/tests/js/fixture.php') === 404, 'Web 直接访问 JS 夹具返回 404');

$robots = @file_get_contents($base . '/robots.txt');
check($robots !== false && str_contains($robots, 'Disallow: /'), 'robots.txt 禁止搜索引擎抓取');

$headers = @get_headers($base . '/index.php?r=home.index', false, stream_context_create(['http' => [
    'method' => 'HEAD', 'ignore_errors' => true, 'timeout' => 10,
]]));
$flat = strtolower(implode("\n", is_array($headers) ? $headers : []));
foreach ([
    'content-security-policy' => 'CSP',
    'x-frame-options'         => 'X-Frame-Options',
    'referrer-policy'         => 'Referrer-Policy',
    'cross-origin-opener-policy' => 'COOP',
    'x-permitted-cross-domain-policies' => '跨域策略',
    'x-robots-tag'            => 'X-Robots-Tag',
] as $header => $label) {
    check(str_contains($flat, $header), "响应头包含 {$label}");
}

finish();
