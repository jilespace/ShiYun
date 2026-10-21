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
