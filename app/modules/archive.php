<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 在线解压 */
function archive_extract(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    $target = input_int('target') ?: null;
    $password = (string) input('password', '');

    try {
        $result = extract_node($userId, $node, $target, $password !== '' ? $password : null);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 422, 'extract_failed');
    }

    activity_log($userId, 'archive.extract', (int) $node['id'], (string) $node['name']);
    user_recompute_usage($userId);

    json_ok([
        'created' => $result['created'],
        'folders' => $result['folders'],
        'bytes'   => $result['bytes'],
        'bytesText' => format_bytes((float) $result['bytes']),
        'skipped' => $result['skipped'],
        'target'  => $result['target'],
    ]);
}

/** 在线压缩 */
function archive_compress(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    $target = input_int('target');
    if ($ids === []) {
        json_fail('请先选择要压缩的条目。');
    }
    if ($target <= 0) {
        $target = (int) user_root_id($userId);
    }

    $defaultName = '压缩包-' . date('Ymd-His');
    $nodes = nodes_by_ids($userId, $ids);
    if (count($nodes) === 1) {
        $base = (string) $nodes[0]['name'];
        $defaultName = pathinfo($base, PATHINFO_FILENAME) ?: $base;
    }

    $name = trim((string) input('name', '')) ?: $defaultName;

    try {
        $result = compress_nodes($userId, $ids, $name, $target);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 422, 'compress_failed');
    }

    activity_log($userId, 'archive.compress', (int) $result['node']['id'], $name);
    user_recompute_usage($userId);

    json_ok([
        'node'      => node_public(node_attach_meta($userId, [$result['node']])[0]),
        'bytes'     => $result['bytes'],
        'bytesText' => format_bytes((float) $result['bytes']),
    ]);
}

/** 探测服务器可用的解压能力 */
function archive_capabilities(): void
{
    require_auth_api();

    $tools = [];
    foreach (['zip', '7z', 'rar', 'tar', 'gz', 'xz'] as $ext) {
        $tools[$ext] = extract_tool($ext) !== null;
    }

    json_ok([
        'zipArchive'     => class_exists('ZipArchive'),
        'shellAvailable' => shell_available(),
        'tools'          => $tools,
        'maxExtract'     => format_bytes((float) config('max_extract')),
    ]);
}
