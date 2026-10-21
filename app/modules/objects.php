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
    json_ok([
        'id'      => $id,
        'warning' => source_risk_note([
            'driver' => 's3',
            'config' => ['endpoint' => (string) input('endpoint', '')],
        ]),
    ]);
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
    json_ok([
        'id'      => $id,
        'name'    => $name,
        'warning' => source_risk_note(['driver' => 's3', 'config' => $config]),
    ]);
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

/* ------------------------------------------------------------ 上传与 CORS */

function objects_key_or_fail(): string
{
    $key = (string) input('key', '');
    if (!object_key_valid($key)) {
        json_fail('对象键不合法。');
    }
    return $key;
}

function objects_upload_sign(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();

    try {
        $class = object_storage_class_or_fail((string) input('storage_class', ''));
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    }

    // Content-Type / 存储类型必须作为签名头一起签：S3 会对预签名 URL 里
    // 未签名的 x-amz-* 头判签名不匹配，不能只让浏览器“顺带”带上。
    $contentType = trim((string) input('content_type', '')) ?: 'application/octet-stream';
    $headers = ['content-type' => $contentType];
    if ($class !== '') {
        $headers['x-amz-storage-class'] = $class;
    }
    $url = s3_presign_url(
        object_bucket_config($account, $bucket),
        'PUT',
        $key,
        900,
        [],
        $headers
    );
    json_ok([
        'url'     => $url,
        'key'     => $key,
        'headers' => $headers,
        'expires' => 900,
    ]);
}

function objects_upload_init(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();

    $size = max(0, input_int('size', 0));
    $partSize = max(8 * 1024 * 1024, (int) ceil($size / 10000));
    $partSize = (int) (ceil($partSize / 1024 / 1024) * 1024 * 1024);

    try {
        $class = object_storage_class_or_fail((string) input('storage_class', ''));
        $contentType = trim((string) input('content_type', '')) ?: 'application/octet-stream';
        $uploadId = s3_multipart_init(
            object_account_config($account),
            $bucket,
            $key,
            $class !== '' ? $class : null,
            $contentType
        );
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    json_ok(['upload_id' => $uploadId, 'part_size' => $partSize, 'key' => $key]);
}

function objects_upload_part(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();
    $uploadId = (string) input('upload_id', '');
    if ($uploadId === '') {
        json_fail('缺少 upload_id。');
    }
    $partNumber = input_int('part_number', 0);
    if ($partNumber < 1 || $partNumber > 10000) {
        json_fail('分片编号不合法。');
    }

    $url = s3_multipart_part_url(
        object_account_config($account),
        $bucket,
        $key,
        $uploadId,
        $partNumber,
        3600
    );
    json_ok(['url' => $url]);
}

function objects_upload_complete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();
    $uploadId = (string) input('upload_id', '');
    $parts = input('parts', []);
    if ($uploadId === '' || !is_array($parts) || $parts === []) {
        json_fail('分片列表不完整。');
    }
    if (count($parts) > 10000) {
        json_fail('分片数量超过上限。', 422, 'too_large');
    }
    $prevPart = 0;
    $seenParts = [];
    foreach ($parts as $part) {
        $number = (int) ($part['part_number'] ?? 0);
        $etag = (string) ($part['etag'] ?? '');
        if ($number <= $prevPart || $number > 10000 || $etag === '' || isset($seenParts[$number])) {
            json_fail('分片列表不合法：编号需唯一且递增。', 422, 'invalid');
        }
        $prevPart = $number;
        $seenParts[$number] = true;
    }

    try {
        $result = s3_multipart_complete(object_account_config($account), $bucket, $key, $uploadId, $parts);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    }
    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.upload', null, $bucket . ' / ' . mb_substr($key, 0, 120));
    json_ok($result);
}

function objects_upload_abort(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();
    $uploadId = (string) input('upload_id', '');
    if ($uploadId === '') {
        json_fail('缺少 upload_id。');
    }
    s3_multipart_abort(object_account_config($account), $bucket, $key, $uploadId);
    json_ok(['aborted' => true]);
}

function objects_cors_check(): void
{
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    $origin = object_request_origin();

    $res = s3_get_subresource(object_account_config($account), $bucket, 'cors');
    if ((int) $res['status'] === 404) {
        json_ok(['ok' => false, 'origin' => $origin, 'issues' => ['桶未配置 CORS']]);
    }
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        json_fail(s3_error_message($res, '读取 CORS 失败'), 502, 's3_error');
    }

    $rules = object_cors_parse((string) $res['body']);
    [$ok, $issues] = object_cors_allows($rules, $origin, ['GET', 'PUT', 'HEAD']);
    json_ok(['ok' => $ok, 'origin' => $origin, 'issues' => $issues]);
}

function objects_cors_allow(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);

    $origin = object_request_origin();
    $res = s3_get_subresource(object_account_config($account), $bucket, 'cors');
    $rules = [];
    if ((int) $res['status'] >= 200 && (int) $res['status'] < 300) {
        $rules = object_cors_parse((string) $res['body']);
    } elseif ((int) $res['status'] !== 404) {
        json_fail(s3_error_message($res, '读取 CORS 失败'), 502, 's3_error');
    }

    $rules = object_cors_merge_origin($rules, $origin);
    try {
        $xml = object_cors_xml($rules);
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    }
    $put = s3_put_subresource(object_account_config($account), $bucket, 'cors', $xml);
    if (!$put['ok']) {
        json_fail((string) $put['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.cors_allow', null, $bucket . ' ← ' . $origin);
    json_ok(['ok' => true, 'origin' => $origin, 'rules' => $rules]);
}

/* ------------------------------------------------------- 下载、详情与解冻 */

function objects_head(): void
{
    require_method('POST');
    require_csrf();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    $key = objects_key_or_fail();
    try {
        $meta = s3_head_object(object_account_config($account), $bucket, $key);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if ($meta === null) {
        json_fail('对象不存在。', 404, 'not_found');
    }
    json_ok(['key' => $key] + $meta);
}

function objects_download_sign(): void
{
    require_method('POST');
    require_csrf();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    $key = objects_key_or_fail();

    try {
        $meta = s3_head_object(object_account_config($account), $bucket, $key);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    if ($meta === null) {
        json_fail('对象不存在。', 404, 'not_found');
    }
    if (in_array(strtoupper((string) $meta['storage_class']), object_archive_classes(), true)
        && !str_contains(strtolower((string) $meta['restore']), 'ongoing-request="false"')) {
        json_fail('该对象在归档存储中，需先解冻才能下载。', 409, 'restore_required');
    }

    $ttl = max(60, min(3600, input_int('ttl', 300)));
    $filename = basename($key) ?: 'download.bin';
    $disposition = "attachment; filename*=UTF-8''" . rawurlencode($filename);
    $url = s3_presign_url(
        object_bucket_config($account, $bucket),
        'GET',
        $key,
        $ttl,
        ['response-content-disposition' => $disposition]
    );
    json_ok(['url' => $url, 'expires' => $ttl, 'key' => $key]);
}

function objects_restore(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);
    $key = objects_key_or_fail();

    $result = s3_restore_object(
        object_account_config($account),
        $bucket,
        $key,
        input_int('days', 1),
        (string) input('tier', 'Standard')
    );
    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }
    activity_log($userId, 'object.restore', null, $bucket . ' / ' . mb_substr($key, 0, 120));
    json_ok($result);
}

function objects_copy(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);

    $source = ltrim((string) input('source', ''), '/');
    $target = ltrim((string) input('target', ''), '/');
    $mode = (string) input('mode', 'copy');
    if (!object_key_valid($source) || !object_key_valid($target)) {
        json_fail('源或目标对象键不合法。');
    }
    if ($source === $target) {
        json_fail('源和目标是同一个对象。');
    }
    if (!in_array($mode, ['copy', 'move'], true)) {
        json_fail('不支持的复制模式。');
    }

    try {
        $meta = s3_head_object(object_account_config($account), $bucket, $source);
        if ($meta === null) {
            json_fail('源对象不存在。', 404, 'not_found');
        }
        if ((int) $meta['size'] > 5 * 1024 * 1024 * 1024) {
            json_fail('超过 5 GiB 的对象暂不支持复制（Multipart Copy 未实现）。', 422, 'too_large');
        }
        if (in_array(strtoupper((string) $meta['storage_class']), object_archive_classes(), true)
            && !str_contains(strtolower((string) $meta['restore']), 'ongoing-request="false"')) {
            json_fail('源对象在归档存储中，需先解冻才能复制。', 409, 'restore_required');
        }

        // 目标已存在时要求显式覆盖，避免手滑把现有对象冲掉
        $targetMeta = s3_head_object(object_account_config($account), $bucket, $target);
        if ($targetMeta !== null && !input_bool('overwrite')) {
            json_fail('目标对象已存在。确认覆盖后重试。', 409, 'target_exists');
        }

        $class = object_storage_class_or_fail((string) input('storage_class', ''));
        $result = s3_copy_object(
            object_account_config($account),
            $bucket,
            $source,
            $target,
            $class !== '' ? $class : null
        );
        if (!$result['ok']) {
            json_fail((string) $result['message'], 502, 's3_error');
        }
        if ($mode === 'move') {
            // 复制成功后再确认目标确实存在，然后才允许删源
            if (s3_head_object(object_account_config($account), $bucket, $target) === null) {
                json_fail('复制未生效，已保留源对象。', 502, 'copy_failed');
            }
            $deleted = s3_delete_object(object_account_config($account), $bucket, $source);
            if (!$deleted['ok']) {
                json_fail('复制成功，但删除源对象失败：' . $deleted['message'], 502, 's3_error');
            }
        }
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    activity_log($userId, 'object.copy', null, $bucket . ' / ' . mb_substr($source, 0, 80) . ' → ' . mb_substr($target, 0, 80));
    json_ok(['message' => $mode === 'move' ? '已移动。' : '已复制。', 'target' => $target]);
}

function objects_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);

    $keys = input('keys', []);
    if (!is_array($keys)) {
        json_fail('keys 必须是数组。');
    }
    $keys = array_values(array_filter(array_map('strval', $keys), static fn (string $k): bool => object_key_valid($k)));
    if ($keys === []) {
        json_fail('没有要删除的对象。');
    }
    if (count($keys) > 1000) {
        json_fail('一次最多删除 1000 个对象。', 422, 'too_large');
    }

    try {
        $result = s3_delete_objects(object_account_config($account), $bucket, $keys);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }
    // 全部失败才报错；部分成功时把 deleted/failed/errors 原样交给前端
    if (!$result['ok'] && (int) ($result['deleted'] ?? 0) === 0) {
        json_fail((string) $result['message'], 502, 's3_error');
    }

    activity_log($userId, 'object.delete', null, $bucket . '（' . count($keys) . ' 个对象）');
    json_ok($result);
}

function objects_mkdir(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);

    $prefix = (string) input('prefix', '');
    $name = trim((string) input('name', ''));
    if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        json_fail('文件夹名称不合法。');
    }
    $key = object_key_join($prefix, $name) . '/';
    if (!object_key_valid($key)) {
        json_fail('对象键不合法。');
    }

    $res = s3_request(object_bucket_config($account, $bucket), 'PUT', $key, [
        'body'         => '',
        'payload_hash' => hash('sha256', ''),
        'content_type' => 'application/x-directory',
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        json_fail(s3_error_message($res, '新建文件夹失败'), 502, 's3_error');
    }

    activity_log($userId, 'object.mkdir', null, $bucket . ' / ' . mb_substr($key, 0, 120));
    json_ok(['key' => $key]);
}

function objects_settings(): void
{
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    $section = (string) input('section', 'versioning');
    $config = object_account_config($account);

    $allowed = ['versioning', 'website', 'cors', 'lifecycle', 'tagging', 'acl', 'policy'];
    if (!in_array($section, $allowed, true)) {
        json_fail('不支持的设置项。');
    }

    $res = s3_get_subresource($config, $bucket, $section);
    $status = (int) $res['status'];
    $body = (string) $res['body'];

    if ($section === 'policy') {
        if ($status === 404) {
            json_ok(['section' => $section, 'exists' => false, 'json' => null, 'raw' => '']);
        }
        if ($status < 200 || $status >= 300) {
            json_fail(s3_error_message($res, '读取桶策略失败'), 502, 's3_error');
        }
        $decoded = json_decode($body, true);
        json_ok(['section' => $section, 'exists' => true, 'json' => $decoded, 'raw' => $body]);
    }

    if ($status === 404) {
        json_ok([
            'section' => $section,
            'exists'  => false,
            'raw'     => '',
            'value'   => match ($section) {
                'versioning' => '',
                'website'    => ['enabled' => false, 'index' => '', 'error' => ''],
                'cors'       => ['rules' => []],
                'lifecycle'  => ['rules' => []],
                'tagging'    => ['tags' => []],
                'acl'        => ['canned' => 'private', 'grants' => []],
            },
        ]);
    }
    if ($status < 200 || $status >= 300) {
        json_fail(s3_error_message($res, '读取设置失败'), 502, 's3_error');
    }

    $value = match ($section) {
        'versioning' => object_versioning_parse($body),
        'website'    => object_website_parse($body),
        'cors'       => ['rules' => object_cors_parse($body)],
        'lifecycle'  => ['rules' => object_lifecycle_rules_parse($body)],
        'tagging'    => ['tags' => object_tagging_parse($body)],
        'acl'        => object_acl_parse($body),
    };

    json_ok(['section' => $section, 'exists' => true, 'raw' => $body, 'value' => $value]);
}

function objects_setting_save(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }
    objects_assert_mutable($userId, $bucket);

    $section = (string) input('section', '');
    $payload = input('payload', []);
    if (!is_array($payload)) {
        json_fail('payload 必须是对象。');
    }
    $config = object_account_config($account);

    try {
        switch ($section) {
            case 'versioning':
                $xml = object_versioning_xml((string) ($payload['status'] ?? 'Enabled'));
                $result = s3_put_subresource($config, $bucket, 'versioning', $xml);
                break;

            case 'website':
                if (empty($payload['enabled'])) {
                    $result = s3_delete_subresource($config, $bucket, 'website');
                } else {
                    $xml = object_website_xml((string) ($payload['index'] ?? ''), (string) ($payload['error'] ?? ''));
                    $result = s3_put_subresource($config, $bucket, 'website', $xml);
                }
                break;

            case 'cors':
                $rules = $payload['rules'] ?? [];
                if (!is_array($rules)) {
                    json_fail('CORS 规则格式不正确。', 422, 'invalid');
                }
                $xml = object_cors_xml($rules);
                $result = s3_put_subresource($config, $bucket, 'cors', $xml);
                break;

            case 'lifecycle':
                $mode = (string) ($payload['mode'] ?? 'rules');
                if ($mode === 'delete') {
                    $result = s3_delete_subresource($config, $bucket, 'lifecycle');
                    break;
                }
                if ($mode === 'xml') {
                    $xml = (string) ($payload['xml'] ?? '');
                    object_lifecycle_validate($xml);
                } else {
                    $rules = $payload['rules'] ?? [];
                    if (!is_array($rules)) {
                        json_fail('生命周期规则格式不正确。', 422, 'invalid');
                    }
                    $xml = object_lifecycle_xml($rules);
                }
                $result = s3_put_subresource($config, $bucket, 'lifecycle', $xml);
                break;

            case 'tagging':
                $xml = object_tagging_xml(is_array($payload['tags'] ?? null) ? $payload['tags'] : []);
                $result = s3_put_subresource($config, $bucket, 'tagging', $xml);
                break;

            case 'acl':
                $canned = (string) ($payload['canned'] ?? 'private');
                if (!in_array($canned, ['private', 'public-read'], true)) {
                    json_fail('不支持的 ACL。', 422, 'invalid');
                }
                $res = s3_request(object_bucket_config($account, $bucket), 'PUT', '', [
                    'query'        => ['acl' => ''],
                    'headers'      => ['x-amz-acl' => $canned],
                    'payload_hash' => hash('sha256', ''),
                ]);
                $ok = (int) $res['status'] >= 200 && (int) $res['status'] < 300;
                $result = ['ok' => $ok, 'message' => $ok ? '已保存。' : s3_error_message($res, '保存 ACL 失败')];
                break;

            case 'policy':
                json_fail('桶策略在本期只读。', 422, 'readonly');
                // no break

            default:
                json_fail('不支持的设置项。');
        }
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    if (!$result['ok']) {
        json_fail((string) $result['message'], 502, 's3_error');
    }
    activity_log($userId, 'object.setting_save', null, $bucket . ' / ' . $section);
    json_ok($result);
}

function objects_stat(): void
{
    require_method('POST');
    require_csrf();
    $account = objects_account();
    $bucket = strtolower(trim((string) input('bucket', '')));
    if (!s3_bucket_name_valid($bucket)) {
        json_fail('桶名不合法。');
    }

    $config = object_account_config($account);
    $start = microtime(true);
    $token = '';
    $count = 0;
    $bytes = 0;
    $pages = 0;
    $partial = true;

    try {
        do {
            // 在发下一页请求前先看时间预算，避免一次慢请求把进程拖过执行上限
            if ($pages > 0 && microtime(true) - $start >= 20) {
                break;
            }
            $page = s3_list_objects_page($config, $bucket, [
                'delimiter'          => '',
                'continuation_token' => $token,
                'max_keys'           => 1000,
            ]);
            foreach ($page['objects'] as $object) {
                $count++;
                $bytes += (int) $object['size'];
            }
            $token = (string) $page['next_token'];
            $pages++;
            $partial = $page['truncated'];
            if ($pages >= 20 || microtime(true) - $start >= 20) {
                break;
            }
        } while ($page['truncated'] && $token !== '');
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 502, 's3_error');
    }

    json_ok([
        'count' => $count,
        'bytes' => $bytes,
        'bytesText' => format_bytes((float) $bytes),
        'pages' => $pages,
        'partial' => $partial,
    ]);
}
