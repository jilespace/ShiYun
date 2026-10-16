<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function versions_list(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file') {
        json_fail('只有文件才有版本记录。', 400);
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    $rows = version_rows((int) $node['id']);
    $current = (int) ($node['blob_id'] ?? 0);

    json_ok([
        'node'    => node_public($node),
        'current' => [
            'versionNo' => 0,
            'sizeText'  => format_bytes((float) $node['size']),
            'when'      => time_ago((string) $node['updated_at']),
            'size'      => (int) $node['size'],
        ],
        'versions' => array_map(static fn (array $v): array => [
            'id'        => (int) $v['id'],
            'versionNo' => (int) $v['version_no'],
            'size'      => (int) $v['size'],
            'sizeText'  => format_bytes((float) $v['size']),
            'when'      => time_ago((string) $v['created_at']),
            'createdAt' => (string) $v['created_at'],
            'isCurrent' => (int) $v['blob_id'] === $current,
        ], $rows),
        'usageText' => format_bytes((float) version_usage((int) $node['id'], $current)),
    ]);
}

function versions_restore(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    $versionId = input_int('versionId');

    try {
        $updated = version_restore($userId, $node, $versionId);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 422);
    }

    activity_log($userId, 'file.restore', (int) $updated['id'], '恢复历史版本');
    user_recompute_usage($userId);

    json_ok(['node' => node_public(node_attach_meta($userId, [$updated])[0])]);
}

function versions_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请选择要删除的版本。');
    }

    $count = 0;
    foreach ($ids as $id) {
        if (version_delete($userId, $id)) {
            $count++;
        }
    }

    user_recompute_usage($userId);
    json_ok(['count' => $count]);
}

/** 下载某个历史版本 */
function versions_download(): void
{
    $userId = require_auth_api();

    $version = q_one(
        'SELECT v.*, n.name, n.id AS node_id, n.path AS node_path
         FROM node_versions v JOIN nodes n ON n.id = v.node_id
         WHERE v.id = ? AND n.user_id = ?',
        [input_int('id'), $userId]
    );
    if ($version === null) {
        http_response_code(404);
        exit;
    }

    $name = pathinfo((string) $version['name'], PATHINFO_FILENAME)
        . '-v' . (int) $version['version_no']
        . '.' . pathinfo((string) $version['name'], PATHINFO_EXTENSION);

    activity_log($userId, 'file.download', null, '历史版本 ' . $name);
    traffic_add($userId, 'down', (int) $version['size'], 1);

    $mime = (string) ($version['mime'] ?? 'application/octet-stream');
    $versionNode = [
        'id'   => (int) $version['node_id'],
        'path' => (string) $version['node_path'],
    ];
    // 私密空间未解锁时，历史版本与当前内容一样不得读取
    if (node_in_private($userId, $versionNode) && !Auth::privateUnlocked()) {
        http_response_code(403);
        exit('私密空间已锁定');
    }
    if (!node_in_private($userId, $versionNode)) {
        blob_deliver($userId, (int) $version['blob_id'], $mime, $name, 0);
    }
    blob_stream((int) $version['blob_id'], $mime, $name, false);
}
