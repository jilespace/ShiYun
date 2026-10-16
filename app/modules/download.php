<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 单文件下载（带 Range 支持与限速） */
function download_file(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        http_response_code(404);
        exit;
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        http_response_code(403);
        exit;
    }

    activity_log($userId, 'file.download', (int) $node['id'], (string) $node['name']);
    traffic_add($userId, 'down', (int) $node['size'], 1);

    $limit = (int) user_setting($userId, 'download_speed_limit', 0);
    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];

    // 私密空间不直链：预签名 URL 在有效期内等于一张通行证
    if (!node_in_private($userId, $node)) {
        blob_deliver($userId, (int) $node['blob_id'], $mime, $name, $limit);
    }
    blob_stream((int) $node['blob_id'], $mime, $name, false, $limit);
}

/** 打包下载：多选，或整个文件夹 */
function download_zip(): void
{
    $userId = require_auth_api();

    $ids = array_filter(array_map('intval', explode(',', (string) input('ids', ''))));
    $folderId = input_int('folder');

    if ($ids === [] && $folderId > 0) {
        $ids = [$folderId];
    }
    if ($ids === []) {
        http_response_code(400);
        exit('未选择任何文件');
    }

    $nodes = nodes_by_ids($userId, $ids);
    if ($nodes === []) {
        http_response_code(404);
        exit;
    }

    $name = count($nodes) === 1
        ? safe_zip_name((string) $nodes[0]['name']) . '.zip'
        : '拾云打包-' . date('Ymd-His') . '.zip';

    activity_log($userId, 'file.download', null, '打包下载 ' . count($nodes) . ' 项');

    zip_stream_nodes($userId, $nodes, $name);
}
