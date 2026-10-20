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
        $row['riskNote'] = source_risk_note($row);
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
        // 桶管理要用已接入的 S3 源当凭据，没有就整块面板显示引导
        'bucketAccounts' => sources_bucket_accounts($userId),
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
    json_ok(['id' => $id, 'warning' => source_risk_note(['driver' => $driver, 'config' => $clean])]);
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
    $fresh = q_one('SELECT * FROM storage_sources WHERE id = ?', [(int) $source['id']]);
    json_ok(['updated' => true, 'warning' => $fresh !== null ? source_risk_note($fresh) : null]);
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

    json_ok($result + ['warning' => source_risk_note($source)]);
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

/* ================================================================ 存储桶管理
   复用某个已接入的 S3 存储源作为凭据来源，对账号下的桶做列 / 建 / 删。
   注意：列桶、建桶、删桶都需要账号级的 ListBuckets / PutBucket / DeleteBucket
   权限，很多子账号没有——失败文案已经在 s3_error_message() 里翻译过。
   ========================================================================= */

/** 可用于桶管理的凭据来源（只列 s3 驱动） */
function sources_bucket_accounts(int $userId): array
{
    return array_map(
        static fn (array $r): array => [
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
        ],
        q_all(
            "SELECT id, name FROM storage_sources
             WHERE user_id = ? AND driver = 's3' ORDER BY priority ASC, id ASC",
            [$userId]
        )
    );
}

/**
 * 解析桶管理要用的凭据。
 * @return array{0:array,1:array} [存储源记录, config]
 */
function sources_bucket_source(int $userId, int $sourceId): array
{
    if (!function_exists('curl_init')) {
        json_fail('服务器未启用 curl 扩展，无法访问对象存储。', 500);
    }

    $source = q_one(
        "SELECT * FROM storage_sources WHERE id = ? AND user_id = ? AND driver = 's3'",
        [$sourceId, $userId]
    );
    if ($source === null) {
        json_fail('请选择一个 S3 兼容的存储源作为凭据来源。', 404, 'no_source');
    }

    $config = json_decode((string) $source['config'], true) ?: [];
    foreach (['endpoint', 'access_key', 'secret_key'] as $key) {
        if (empty($config[$key])) {
            json_fail('该存储源缺少 ' . $key . '，补齐后才能在桶管理里使用。');
        }
    }

    return [$source, $config];
}

/** 已被存储源绑定的桶：桶名 => 存储源名称。这些桶不允许删除。 */
function sources_bound_buckets(int $userId): array
{
    $out = [];
    foreach (q_all(
        "SELECT name, config FROM storage_sources WHERE user_id = ? AND driver = 's3'",
        [$userId]
    ) as $row) {
        $cfg = json_decode((string) $row['config'], true) ?: [];
        $bucket = (string) ($cfg['bucket'] ?? '');
        if ($bucket !== '') {
            $out[$bucket] = (string) $row['name'];
        }
    }
    return $out;
}

/** 列出账号下的桶 */
function sources_buckets(): void
{
    $userId = require_auth_api();

    $accounts = sources_bucket_accounts($userId);
    if ($accounts === []) {
        json_fail('先接入一个 S3 兼容的存储源——桶管理要用它的密钥。', 404, 'no_account');
    }

    $sourceId = input_int('source', 0);
    if ($sourceId <= 0) {
        $sourceId = (int) $accounts[0]['id'];
    }
    [$source, $config] = sources_bucket_source($userId, $sourceId);

    try {
        $buckets = s3_list_buckets($config);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    $bound = sources_bound_buckets($userId);
    foreach ($buckets as $i => $bucket) {
        $buckets[$i]['bound'] = isset($bound[$bucket['name']]);
        $buckets[$i]['boundBy'] = $bound[$bucket['name']] ?? '';
    }

    json_ok([
        'accounts'   => $accounts,
        'sourceId'   => $sourceId,
        'sourceName' => (string) $source['name'],
        'endpoint'   => (string) ($config['endpoint'] ?? ''),
        'region'     => (string) ($config['region'] ?? ''),
        'buckets'    => $buckets,
    ]);
}

/** 创建桶 */
function sources_bucket_create(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法：3–63 位，只能用小写字母、数字、- 和 .，且首尾必须是字母或数字。');
    }

    [, $config] = sources_bucket_source($userId, input_int('source'));

    $result = s3_create_bucket($config, $bucket);
    if (!$result['ok']) {
        json_fail($result['message'], 502, 's3_error');
    }

    activity_log($userId, 'bucket.create', null, $bucket);
    json_ok(['bucket' => $bucket, 'message' => $result['message']]);
}

/** 删除桶：只删空桶，且必须原样输入桶名 */
function sources_bucket_delete(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }

    if ((string) input('confirm', '') !== $bucket) {
        json_fail('为避免误删，请原样输入桶名后再提交。', 422, 'confirm_required');
    }

    // 硬性守卫：正被存储源使用的桶一律不许删，否则 blob_locations 会指向不存在的对象
    $bound = sources_bound_buckets($userId);
    if (isset($bound[$bucket])) {
        json_fail('该桶正被存储源「' . $bound[$bucket] . '」使用，请先解除绑定。', 409, 'in_use');
    }

    [, $config] = sources_bucket_source($userId, input_int('source'));

    // 只删空桶：有对象就明确拒绝，绝不静默清空
    try {
        $check = s3_bucket_empty($config, $bucket);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if (!$check['empty']) {
        json_fail('桶里还有对象。为避免误删，这里只允许删除空桶。', 409, 'not_empty');
    }

    $result = s3_delete_bucket($config, $bucket);
    if (!$result['ok']) {
        json_fail($result['message'], 502, 's3_error');
    }

    activity_log($userId, 'bucket.delete', null, $bucket);
    json_ok(['deleted' => true, 'message' => $result['message']]);
}

/** 把桶绑定成一个新的存储源（凭据沿用所选来源） */
function sources_bucket_bind(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }

    [, $config] = sources_bucket_source($userId, input_int('source'));

    if (isset(sources_bound_buckets($userId)[$bucket])) {
        json_fail('这个桶已经绑定过存储源了。', 409, 'exists');
    }

    $clean = [
        'endpoint'   => (string) ($config['endpoint'] ?? ''),
        'region'     => (string) ($config['region'] ?? ''),
        'bucket'     => $bucket,
        'access_key' => (string) ($config['access_key'] ?? ''),
        'secret_key' => (string) ($config['secret_key'] ?? ''),
        'prefix'     => trim((string) input('prefix', ''), '/'),
    ];
    if (!empty($config['session_token'])) {
        $clean['session_token'] = (string) $config['session_token'];
    }

    $name = trim((string) input('name', ''));
    if ($name === '' || mb_strlen($name) > 64) {
        $name = mb_substr($bucket, 0, 64);
    }

    $id = q_insert('storage_sources', [
        'user_id'    => $userId,
        'name'       => $name,
        'driver'     => 's3',
        'config'     => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'is_default' => 0,
        'priority'   => (int) q_val(
            'SELECT COALESCE(MAX(priority), 0) + 1 FROM storage_sources WHERE user_id = ?',
            [$userId]
        ),
        'status'     => 'ok',
        'created_at' => now(),
    ]);

    activity_log($userId, 'source.add', null, $name . '（' . $bucket . '）');
    json_ok([
        'id'      => $id,
        'name'    => $name,
        'warning' => source_risk_note(['driver' => 's3', 'config' => $clean]),
    ]);
}

/* ---------------------------------------------------------- 桶内对象 */

/**
 * 正被存储源使用的桶不允许改动桶内对象。
 * blob_locations 里记的副本键就落在这些桶上，删掉对象等于让副本记录指向不存在的文件。
 */
function sources_bucket_assert_unbound(int $userId, string $bucket): void
{
    $bound = sources_bound_buckets($userId);
    if (isset($bound[$bucket])) {
        json_fail(
            '该桶正被存储源「' . $bound[$bucket] . '」使用，桶内对象对应本地仓的副本记录。'
            . '请先解除绑定再操作。',
            409,
            'in_use'
        );
    }
}

/** 查看桶内对象 */
function sources_bucket_objects(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }

    [, $config] = sources_bucket_source($userId, input_int('source'));

    try {
        $listed = s3_list_objects($config, $bucket, 200);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    $total = 0;
    foreach ($listed['objects'] as $object) {
        $total += (int) $object['size'];
    }

    json_ok([
        'bucket'    => $bucket,
        'objects'   => $listed['objects'],
        'count'     => $listed['count'],
        'truncated' => $listed['truncated'],
        'totalText' => format_bytes((float) $total),
        'bound'     => isset(sources_bound_buckets($userId)[$bucket]),
    ]);
}

/** 删除桶内单个对象 */
function sources_bucket_object_delete(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    $key = (string) input('key', '');
    if (!s3_bucket_name_valid($bucket) || $key === '') {
        json_fail('参数不完整。');
    }

    sources_bucket_assert_unbound($userId, $bucket);
    [, $config] = sources_bucket_source($userId, input_int('source'));

    $result = s3_delete_object($config, $bucket, $key);
    if (!$result['ok']) {
        json_fail($result['message'], 502, 's3_error');
    }

    activity_log($userId, 'bucket.object_delete', null, $bucket . ' / ' . mb_substr($key, 0, 120));
    json_ok(['message' => $result['message']]);
}

/** 清空桶（分轮进行，每轮最多 1000 个对象） */
function sources_bucket_purge(): never
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    if ((string) input('confirm', '') !== $bucket) {
        json_fail('为避免误删，请原样输入桶名后再提交。', 422, 'confirm_required');
    }

    sources_bucket_assert_unbound($userId, $bucket);
    [, $config] = sources_bucket_source($userId, input_int('source'));

    try {
        $result = s3_purge_objects($config, $bucket);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'bucket.purge', null, $bucket . '（' . (int) $result['deleted'] . ' 个对象）');
    json_ok([
        'deleted'   => (int) $result['deleted'],
        'remaining' => (bool) $result['remaining'],
        'message'   => (string) $result['message'],
    ]);
}
