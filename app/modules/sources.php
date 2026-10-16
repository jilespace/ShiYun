<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function sources_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $rows = q_all('SELECT * FROM storage_sources WHERE user_id = ? ORDER BY priority ASC, id ASC', [$userId]);

    $sources = [];
    foreach ($rows as $row) {
        $config = json_decode((string) $row['config'], true) ?: [];
        // 密钥不回传原文，只标记已设置
        $masked = [];
        foreach ($config as $k => $v) {
            $masked[$k] = in_array($k, ['secret_key', 'password', 'session_token'], true)
                ? ($v !== '' ? '••••••' : '')
                : $v;
        }
        $row['config'] = $masked;
        $row['driverLabel'] = storage_driver_info()[$row['driver']]['label'] ?? $row['driver'];
        $row['nodeCount'] = (int) q_val(
            'SELECT COUNT(*) FROM blob_locations WHERE source_id = ?',
            [(int) $row['id']]
        );
        $sources[] = $row;
    }

    render('sources', [
        'title'     => '存储源 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'sources',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'sources',
        'sources'   => $sources,
        'drivers'   => storage_driver_info(),
        'primaryUsed' => (int) $user['used_bytes'],
    ]);
}

function sources_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $name = trim((string) input('name', ''));
    $driver = (string) input('driver', 'local');

    if ($name === '' || mb_strlen($name) > 64) {
        json_fail('名称需在 1–64 字之间。');
    }
    $info = storage_driver_info();
    if (!isset($info[$driver])) {
        json_fail('不支持的驱动。');
    }
    if (!$info[$driver]['available']) {
        json_fail($info[$driver]['label'] . ' 在当前服务器上不可用。');
    }

    $config = (array) input('config', []);
    $clean = [];
    foreach ($info[$driver]['fields'] as $field) {
        $value = trim((string) ($config[$field['key']] ?? ''));
        if ($field['required'] && $value === '') {
            json_fail('缺少必填字段：' . $field['label']);
        }
        $clean[$field['key']] = $value;
    }

    $id = q_insert('storage_sources', [
        'user_id'    => $userId,
        'name'       => $name,
        'driver'     => $driver,
        'config'     => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'is_default' => 0,
        'priority'   => (int) q_val('SELECT COALESCE(MAX(priority), 0) + 1 FROM storage_sources WHERE user_id = ?', [$userId]),
        'status'     => 'ok',
        'created_at' => now(),
    ]);

    activity_log($userId, 'source.add', null, $name);
    json_ok(['id' => $id]);
}

function sources_update(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $source = q_one('SELECT * FROM storage_sources WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($source === null) {
        json_fail('存储源不存在。', 404);
    }

    $patch = [];
    if (input('name') !== null) {
        $name = trim((string) input('name'));
        if ($name === '' || mb_strlen($name) > 64) {
            json_fail('名称需在 1–64 字之间。');
        }
        $patch['name'] = $name;
    }

    if (input('config') !== null) {
        $existing = json_decode((string) $source['config'], true) ?: [];
        $incoming = (array) input('config', []);
        $info = storage_driver_info()[$source['driver']] ?? ['fields' => []];
        $merged = $existing;
        foreach ($info['fields'] as $field) {
            $key = $field['key'];
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $value = trim((string) $incoming[$key]);
            // 前端回显的是掩码，原样回传时不覆盖已存的密钥
            if ($value === '••••••') {
                continue;
            }
            $merged[$key] = $value;
        }
        $patch['config'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
    }

    if ($patch === []) {
        json_fail('没有需要更新的字段。');
    }

    q_update('storage_sources', $patch, 'id = ?', [$source['id']]);
    json_ok(['updated' => true]);
}

function sources_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $source = q_one('SELECT * FROM storage_sources WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($source === null) {
        json_fail('存储源不存在。', 404);
    }

    $inUse = (int) q_val('SELECT COUNT(*) FROM blob_locations WHERE source_id = ?', [(int) $source['id']]);
    if ($inUse > 0 && !input_bool('force')) {
        json_fail('该存储源上还有 ' . $inUse . ' 个条目，确认后仍可移除（不会删除文件内容）。', 409, 'in_use', ['count' => $inUse]);
    }

    // 只是解除绑定，节点仍指向内置内容仓，不会丢数据
    q_exec('DELETE FROM blob_locations WHERE source_id = ?', [(int) $source['id']]);
    q_exec('DELETE FROM storage_sources WHERE id = ?', [(int) $source['id']]);

    activity_log($userId, 'source.remove', null, (string) $source['name']);
    json_ok(['deleted' => true]);
}

/** 连通性测试 */
function sources_test(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $source = q_one('SELECT * FROM storage_sources WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($source === null) {
        json_fail('存储源不存在。', 404);
    }

    $result = storage_source_test($source);
    q_update('storage_sources', ['status' => $result['ok'] ? 'ok' : 'error'], 'id = ?', [(int) $source['id']]);

    json_ok($result);
}

/**
 * 把尚未同步到该源的内容批量推送（做冗余副本 / 迁移）。
 */
function sources_push(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $source = q_one('SELECT * FROM storage_sources WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($source === null || $source['driver'] === 'local') {
        json_fail('请选择一个外部存储源。');
    }

    $rows = q_all(
        "SELECT DISTINCT b.id, b.hash, b.size FROM nodes n
         JOIN blobs b ON b.id = n.blob_id
         LEFT JOIN blob_locations bl ON bl.blob_id = b.id AND bl.source_id = ?
         WHERE n.user_id = ? AND n.kind = ? AND n.deleted_at IS NULL
           AND (bl.source_id IS NULL OR bl.status <> 'ok')
         ORDER BY b.size ASC LIMIT 20",
        [(int) $source['id'], $userId, 'file']
    );

    $pushed = 0;
    $failed = 0;
    $bytes = 0;

    foreach ($rows as $row) {
        $result = blob_push_to_source((int) $row['id'], (int) $source['id']);
        if ($result['ok']) {
            $pushed++;
            $bytes += (int) $row['size'];
        } else {
            $failed++;
        }
    }

    q_update('storage_sources', [
        'status'     => $failed === 0 ? 'ok' : 'error',
        'used_bytes' => (int) q_val('SELECT COALESCE(used_bytes,0) + ? FROM storage_sources WHERE id = ?',
            [$bytes, (int) $source['id']]),
    ], 'id = ?', [(int) $source['id']]);

    activity_log($userId, 'source.push', null, $pushed . ' 个对象');

    json_ok([
        'pushed'    => $pushed,
        'failed'    => $failed,
        'bytesText' => format_bytes((float) $bytes),
    ]);
}

/**
 * 重新推送单个文件的副本（信息对话框里的一键重试）。
 * 只入队，实际传输交给计划任务驱动的队列。
 */
function sources_retryOne(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    require_once BASE_PATH . '/app/modules/tasks.php';

    $node = node_require($userId, input_int('id'));
    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        json_fail('只有文件可以推送副本。');
    }
    $blobId = (int) $node['blob_id'];

    $sourceId = input_int('source');
    if ($sourceId > 0) {
        $owned = q_one(
            'SELECT id FROM storage_sources WHERE id = ? AND user_id = ? AND driver <> ?',
            [$sourceId, $userId, 'local']
        );
        if ($owned === null) {
            json_fail('存储源不存在。');
        }
    } else {
        // 沿用该内容已有的、属于当前用户的副本源
        $sourceId = (int) q_val(
            'SELECT bl.source_id FROM blob_locations bl
             JOIN storage_sources s ON s.id = bl.source_id
             WHERE bl.blob_id = ? AND s.user_id = ? AND s.driver <> ?
             ORDER BY bl.id ASC LIMIT 1',
            [$blobId, $userId, 'local']
        );
        if ($sourceId <= 0) {
            $sourceId = (int) q_val(
                "SELECT id FROM storage_sources WHERE user_id = ? AND driver <> 'local'
                 ORDER BY priority ASC, id ASC LIMIT 1",
                [$userId]
            );
        }
        if ($sourceId <= 0) {
            json_fail('请先在存储源中添加一个外部存储源。');
        }
    }

    $taskId = tasks_enqueue_push($userId, $blobId, $sourceId, (string) $node['name']);
    activity_log($userId, 'source.retry', (int) $node['id'], (string) $node['name']);

    json_ok(['task' => $taskId]);
}
