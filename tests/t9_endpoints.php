<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 静态检查：4 个下载端点必须调用 blob_deliver，且不得再直接调 blob_stream
$targets = [
    'app/modules/download.php' => 'download',
    'app/modules/versions.php' => 'versions',
    'app/modules/api.php'      => 'api',
];
foreach ($targets as $file => $label) {
    $src = (string) file_get_contents(BASE_PATH . '/' . $file);
    check(str_contains($src, 'blob_deliver('), "{$label} 应调用 blob_deliver()");
}

// shares.php 同时含下载与内联预览：下载走 deliver，内联预览走 stream。
// 私密空间是直接 403，不再「回落 stream」——回落等于没挡：只是不发明文直链，
// 字节照样从本机流出去了。
$shares = (string) file_get_contents(BASE_PATH . '/app/modules/shares.php');
check(substr_count($shares, 'blob_deliver(') === 1, 'shares.php 应恰好有 1 处 blob_deliver（下载）');
check(substr_count($shares, 'blob_stream(') === 1, 'shares.php 应恰好有 1 处 blob_stream（内联预览）');
check(str_contains($shares, '该内容不可下载'), 'shares.php 的下载路径应直接拒绝私密空间内容');

// 四个下载端点的直链调用必须被私密空间守卫包住
foreach (['download.php', 'versions.php', 'api.php', 'shares.php'] as $guarded) {
    $src = (string) file_get_contents(BASE_PATH . '/app/modules/' . $guarded);
    $deliverPos = strpos($src, 'blob_deliver(');
    $guardPos = strpos($src, 'node_in_private(');
    check(
        $deliverPos !== false && $guardPos !== false && $guardPos < $deliverPos,
        $guarded . ' 的 blob_deliver 应被私密空间守卫包住'
    );
}

// 内联预览与 WebDAV 不得改成 deliver
$preview = (string) file_get_contents(BASE_PATH . '/app/modules/preview.php');
check(substr_count($preview, 'blob_deliver(') === 0, 'preview.php 不得使用 blob_deliver');
$webdav = (string) file_get_contents(BASE_PATH . '/app/modules/webdav.php');
check(substr_count($webdav, 'blob_deliver(') === 0, 'webdav.php 不得使用 blob_deliver');

finish();
