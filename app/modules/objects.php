<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   对象存储页面与接口。
   领域逻辑：app/lib/object_store.php；桶设置 XML：app/lib/object_settings.php；
   协议：app/lib/storage_drivers.php 的 s3_*。
   ========================================================================= */

function objects_index(): void
{
    $userId = (int) Auth::id();
    $user = Auth::user();

    // 浏览器直传 COS 走 fetch/XHR，必须让 CSP 放行当前账号的 endpoint
    header('Content-Security-Policy: ' . csp_content(object_account_origins($userId)));

    render('objects', [
        'title'     => '对象存储 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/objects.js'],
        'user'      => $user,
        'section'   => 'objects',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'objects',
        'config'    => [
            'accounts'       => object_account_list($userId),
            'providers'      => object_providers(),
            'regions'        => object_region_list(),
            'classes'        => object_storage_classes(),
            'bound'          => object_bound_buckets($userId),
            'accountId'      => input_int('account', 0),
            'bucket'         => (string) input('bucket', ''),
            'prefix'         => (string) input('prefix', ''),
            'siteOrigin'     => object_site_origin(),
            'archiveClasses' => object_archive_classes(),
        ],
    ]);
}

/** 解析当前用户的账号；不存在直接 404 */
function objects_account(): array
{
    $userId = require_auth_api();
    $account = object_account_get($userId, input_int('account'));
    if ($account === null) {
        json_fail('对象存储账号不存在。', 404, 'no_account');
    }
    return $account;
}

function objects_accounts(): void
{
    $userId = require_auth_api();
    json_ok([
        'accounts'  => object_account_list($userId),
        'providers' => object_providers(),
        'regions'   => object_region_list(),
        'classes'   => object_storage_classes(),
    ]);
}

function objects_account_save(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    try {
        $id = object_account_save($userId, [
            'name'          => input('name', ''),
            'provider'      => input('provider', 'cos'),
            'endpoint'      => input('endpoint', ''),
            'region'        => input('region', ''),
            'access_key'    => input('access_key', ''),
            'secret_key'    => input('secret_key', ''),
            'session_token' => input('session_token', ''),
        ], input_int('id'));
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    }

    activity_log($userId, 'object.account_save', null, (string) input('name', ''));
    json_ok(['id' => $id]);
}

function objects_account_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $id = input_int('id');
    if ($id <= 0) {
        json_fail('参数不完整。');
    }
    object_account_delete($userId, $id);
    activity_log($userId, 'object.account_delete', null, '#' . $id);
    json_ok(['deleted' => true]);
}

function objects_account_test(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = object_account_get($userId, input_int('id'));
    if ($account === null) {
        json_fail('账号不存在。', 404, 'no_account');
    }
    try {
        $buckets = s3_list_buckets(object_account_config($account));
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    json_ok([
        'count'   => count($buckets),
        'buckets' => array_slice($buckets, 0, 10),
    ]);
}

function objects_assert_mutable(int $userId, string $bucket): void
{
    $bound = object_bound_by($userId, $bucket);
    if ($bound !== null) {
        json_fail(
            '该桶正被存储源「' . $bound . '」使用，桶内对象是本地仓的副本记录，这里只读。',
            409,
            'in_use'
        );
    }
}

function objects_buckets(): void
{
    $userId = require_auth_api();
    $account = objects_account();
    try {
        $buckets = s3_list_buckets(object_account_config($account));
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    $bound = object_bound_buckets($userId);
    foreach ($buckets as $i => $bucket) {
        $buckets[$i]['bound'] = isset($bound[$bucket['name']]);
        $buckets[$i]['boundBy'] = $bound[$bucket['name']] ?? '';
    }
    json_ok([
        'buckets' => $buckets,
        'account' => ['id' => (int) $account['id'], 'name' => (string) $account['name']],
    ]);
}

function objects_bucket_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法：3–63 位，只能用小写字母、数字、- 和 .，且首尾必须是字母或数字。');
    }
    if ($account['provider'] === 'cos' && !object_cos_bucket_name_valid($bucket)) {
        json_fail('COS 桶名必须带 10 位 APPID 后缀，例如 photos-1250000000。', 422, 'invalid');
    }

    try {
        $result = s3_create_bucket(object_account_config($account), $bucket);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.bucket_create', null, $bucket);
    json_ok(['bucket' => $bucket, 'message' => $result['message']]);
}

function objects_bucket_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    if ((string) input('confirm', '') !== $bucket) {
        json_fail('为避免误删，请原样输入桶名后再提交。', 422, 'confirm_required');
    }
    objects_assert_mutable($userId, $bucket);

    try {
        $check = s3_bucket_empty(object_account_config($account), $bucket);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if (!$check['empty']) {
        json_fail('桶里还有对象。为避免误删，这里只允许删除空桶。', 409, 'not_empty');
    }

    $result = s3_delete_bucket(object_account_config($account), $bucket);
    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.bucket_delete', null, $bucket);
    json_ok(['deleted' => true, 'message' => $result['message']]);
}

function objects_bucket_purge(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    if ((string) input('confirm', '') !== $bucket) {
        json_fail('为避免误删，请原样输入桶名后再提交。', 422, 'confirm_required');
    }
    objects_assert_mutable($userId, $bucket);

    try {
        $result = s3_purge_objects(object_account_config($account), $bucket);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.bucket_purge', null, $bucket . '（' . (int) $result['deleted'] . ' 个对象）');
    json_ok([
        'deleted'   => (int) $result['deleted'],
        'remaining' => (bool) $result['remaining'],
        'message'   => (string) $result['message'],
    ]);
}

function objects_bucket_bind(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();

    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    if (object_bound_by($userId, $bucket) !== null) {
        json_fail('这个桶已经绑定过存储源了。', 409, 'exists');
    }

    $name = trim((string) input('name', ''));
    if ($name === '' || mb_strlen($name) > 64) {
        $name = mb_substr($bucket, 0, 64);
    }

    $config = [
        'endpoint'   => (string) $account['endpoint'],
        'region'     => (string) $account['region'],
        'bucket'     => $bucket,
        'access_key' => (string) $account['access_key'],
        'secret_key' => (string) $account['secret_key'],
        'prefix'     => trim((string) input('prefix', ''), '/'),
    ];
    if (!empty($account['session_token'])) {
        $config['session_token'] = (string) $account['session_token'];
    }

    $id = q_insert('storage_sources', [
        'user_id'    => $userId,
        'name'       => $name,
        'driver'     => 's3',
        'config'     => json_encode($config, JSON_UNESCAPED_UNICODE),
        'is_default' => 0,
        'priority'   => (int) q_val(
            'SELECT COALESCE(MAX(priority), 0) + 1 FROM storage_sources WHERE user_id = ?',
            [$userId]
        ),
        'status'     => 'ok',
        'created_at' => now(),
    ]);

    activity_log($userId, 'object.bucket_bind', null, $name . '（' . $bucket . '）');
    json_ok(['id' => $id, 'name' => $name]);
}

function objects_list(): void
{
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }

    $query = trim((string) input('q', ''));
    $prefix = (string) input('prefix', '');
    $delimiter = $query !== '' ? '' : '/';
    if ($query !== '') {
        $prefix = object_key_join($prefix, $query);
    }

    // 目录浏览按页读取；前缀搜索是递归扫描，限制单次最多 200 条并明确截断。
    $maxKeys = min(1000, max(1, input_int('max_keys', 100)));
    if ($query !== '') {
        $maxKeys = min(200, $maxKeys);
    }

    try {
        $page = s3_list_objects_page(object_account_config($account), $bucket, [
            'prefix'             => $prefix,
            'delimiter'          => $delimiter,
            'continuation_token' => (string) input('cursor', ''),
            'max_keys'           => $maxKeys,
        ]);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    json_ok($page + [
        'bucket'    => $bucket,
        'prefix'    => $prefix,
        'delimiter' => $delimiter,
        'boundBy'   => object_bound_by((int) $account['user_id'], $bucket),
    ]);
}
