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
    <script>
    window.__SHIYUN__ = {
        csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
        route: <?= json_encode((string) ($_GET['r'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        user: <?= $layoutUser === null ? 'null' : json_encode([
            'id'          => (int) $layoutUser['id'],
            'name'        => (string) $layoutUser['display_name'],
            'email'       => (string) $layoutUser['email'],
            'quota'       => (int) $layoutUser['quota_bytes'],
            'used'        => (int) $layoutUser['used_bytes'],
            'privateOpen' => Auth::privateUnlocked(),
        ], JSON_UNESCAPED_UNICODE) ?>,
        chunkSize: <?= (int) config('chunk_size') ?>,
        maxUpload: <?= (int) config('max_upload') ?>,
        base: <?= json_encode(url(''), JSON_UNESCAPED_UNICODE) ?>
    };
    </script>
    <script src="<?= e(asset_url('/assets/js/app.js')) ?>" defer></script>
    <?php foreach ($scripts as $script): ?>
    <script src="<?= e(asset_url((string) $script)) ?>" defer></script>
    <?php endforeach; ?>
</body>
</html>
