<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string $content */
/** @var string|null $title */
/** @var string|null $bodyClass */
/** @var list<string>|null $styles */
/** @var list<string>|null $scripts */

$bodyClass = $bodyClass ?? '';
$styles = $styles ?? [];
$scripts = $scripts ?? [];
$layoutUser = Auth::check() ? Auth::user() : null;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#08090b">
    <?php /* 站点图标用内联 SVG（品牌图形 archive）：不新增文件，
             也免去浏览器每次加载都去请求并不存在的 /favicon.ico。 */ ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238a88ff' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect width='20' height='5' x='2' y='3' rx='1'/%3E%3Cpath d='M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8'/%3E%3Cpath d='M10 12h4'/%3E%3C/svg%3E">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title ?? (string) config('app_name')) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/css/app.css')) ?>">
    <?php foreach ($styles as $style): ?>
    <link rel="stylesheet" href="<?= e(asset_url((string) $style)) ?>">
    <?php endforeach; ?>
</head>
<body class="<?= e($bodyClass) ?>">
    <canvas class="field" data-field aria-hidden="true"></canvas>
    <div class="grain" aria-hidden="true"></div>
    <div class="vignette" aria-hidden="true"></div>
    <?= $content ?>
    <script nonce="<?= e(csp_nonce()) ?>">
    <?php /* 内联 JSON 一律带 JSON_HEX_TAG：默认的 \/ 转义已经挡住了 </script>，
             但那是 json_encode 的默认行为，谁加个 JSON_UNESCAPED_SLASHES 就破了。
             显式把 < > & ' " 都转成 \uXXXX，不依赖默认值。 */ ?>
    window.__SHIYUN__ = {
        csrf: <?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        route: <?= json_encode((string) ($_GET['r'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        user: <?= $layoutUser === null ? 'null' : json_encode([
            'id'          => (int) $layoutUser['id'],
            'name'        => (string) $layoutUser['display_name'],
            'email'       => (string) $layoutUser['email'],
            'quota'       => (int) $layoutUser['quota_bytes'],
            'used'        => (int) $layoutUser['used_bytes'],
            'privateOpen' => Auth::privateUnlocked(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
        chunkSize: <?= (int) config('chunk_size') ?>,
        maxUpload: <?= (int) config('max_upload') ?>,
        base: <?= json_encode(url(''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };
    </script>
    <script src="<?= e(asset_url('/assets/js/app.js')) ?>" defer></script>
    <?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset_url((string) $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
