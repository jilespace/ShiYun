<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   对象存储账号、桶绑定与键名规则。
   协议细节在 storage_drivers.php，桶设置 XML 在 object_settings.php。
   ========================================================================= */

const OBJECT_MASK = '••••••';

function object_providers(): array
{
    return ['cos' => '腾讯云 COS', 's3' => '通用 S3'];
}

/** COS 地域；允许手填列表之外的地域 */
function object_region_list(): array
{
    return [
        'ap-guangzhou', 'ap-shanghai', 'ap-beijing', 'ap-chengdu', 'ap-chongqing',
        'ap-nanjing', 'ap-hongkong', 'ap-singapore', 'ap-bangkok', 'ap-tokyo',
        'ap-seoul', 'ap-mumbai', 'na-siliconvalley', 'na-ashburn', 'na-toronto',
        'eu-frankfurt', 'eu-moscow', 'sa-saopaulo',
    ];
}

function object_storage_classes(): array
{
    return [
        'STANDARD'            => '标准存储',
        'STANDARD_IA'         => '低频存储',
        'INTELLIGENT_TIERING' => '智能分层',
        'ARCHIVE'             => '归档存储',
        'DEEP_ARCHIVE'        => '深度归档',
        'MAZ_STANDARD'        => '标准存储（多 AZ）',
        'MAZ_STANDARD_IA'     => '低频存储（多 AZ）',
    ];
}

function object_archive_classes(): array
{
    return ['ARCHIVE', 'DEEP_ARCHIVE'];
}

function object_account_list(int $userId): array
{
    $rows = q_all('SELECT * FROM object_accounts WHERE user_id = ? ORDER BY id ASC', [$userId]);
    return array_map(static function (array $row): array {
        return [
            'id'            => (int) $row['id'],
            'name'          => (string) $row['name'],
            'provider'      => (string) $row['provider'],
            'endpoint'      => (string) $row['endpoint'],
            'region'        => (string) $row['region'],
            'access_key'    => (string) $row['access_key'],
            'secret_key'    => (string) $row['secret_key'] !== '' ? OBJECT_MASK : '',
            'session_token' => (string) ($row['session_token'] ?? '') !== '' ? OBJECT_MASK : '',
            'created_at'    => (string) $row['created_at'],
        ];
    }, $rows);
}

function object_account_get(int $userId, int $id): ?array
{
    return q_one('SELECT * FROM object_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
}

function object_account_config(array $row): array
{
    return [
        'endpoint'      => (string) ($row['endpoint'] ?? ''),
        'region'        => (string) ($row['region'] ?? ''),
        'access_key'    => (string) ($row['access_key'] ?? ''),
        'secret_key'    => (string) ($row['secret_key'] ?? ''),
        'session_token' => (string) ($row['session_token'] ?? ''),
        'provider'      => (string) ($row['provider'] ?? 's3'),
    ];
}

/** 任意 URL → scheme://host[:port]；非法或非 http/https 返回 null */
function object_url_origin(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!str_contains($url, '://')) {
        $url = 'https://' . $url;
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    $origin = $scheme . '://' . strtolower((string) $parts['host']);
    if (isset($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    return $origin;
}

/**
 * CSP connect-src 需要放行的来源。
 * 虚拟主机寻址会把桶名拼在 endpoint host 前，所以除精确地址外还要给通配子域。
 *
 * @return list<string>
 */
function object_account_origins(int $userId): array
{
    $out = [];
    foreach (q_all('SELECT endpoint FROM object_accounts WHERE user_id = ?', [$userId]) as $row) {
        $origin = object_url_origin((string) $row['endpoint']);
        if ($origin === null) {
            continue;
        }
        $out[] = $origin;
        $parts = parse_url($origin);
        $host = (string) ($parts['host'] ?? '');
        // IP 没法用子域，虚拟主机寻址本身就走不通
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            continue;
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $out[] = $scheme . '://*.' . $host . $port;
    }
    return array_values(array_unique($out));
}

/** 新建或更新账号；更新时掩码值表示保持原密钥 */
function object_account_save(int $userId, array $in, int $id = 0): int
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 64) {
        throw new InvalidArgumentException('账号名称需在 1–64 字之间。');
    }

    $provider = (string) ($in['provider'] ?? 'cos');
    if (!isset(object_providers()[$provider])) {
        throw new InvalidArgumentException('不支持的服务商。');
    }

    $endpoint = trim((string) ($in['endpoint'] ?? ''));
    if (object_url_origin($endpoint) === null) {
        throw new InvalidArgumentException('Endpoint 需为 http/https 地址，例如 https://cos.ap-guangzhou.myqcloud.com。');
    }

    $region = trim((string) ($in['region'] ?? ''));
    if ($region === '' || mb_strlen($region) > 64) {
        throw new InvalidArgumentException('请填写地域。');
    }

    $accessKey = trim((string) ($in['access_key'] ?? ''));
    $secretKey = (string) ($in['secret_key'] ?? '');
    $token = trim((string) ($in['session_token'] ?? ''));

    if (strlen($endpoint) > 255 || strlen($accessKey) > 255
        || strlen($secretKey) > 255 || strlen($token) > 2048) {
        throw new InvalidArgumentException('Endpoint、密钥或临时令牌过长。');
    }

    if ($id > 0) {
        $existing = object_account_get($userId, $id);
        if ($existing === null) {
            throw new InvalidArgumentException('账号不存在。');
        }
        if ($accessKey === '') {
            $accessKey = (string) $existing['access_key'];
        }
        if ($secretKey === '' || $secretKey === OBJECT_MASK) {
            $secretKey = (string) $existing['secret_key'];
        }
        if ($token === OBJECT_MASK) {
            $token = (string) ($existing['session_token'] ?? '');
        }
        if ($accessKey === '' || $secretKey === '') {
            throw new InvalidArgumentException('请填写 Access Key 与 Secret Key。');
        }
        if (strlen($accessKey) > 255 || strlen($secretKey) > 255 || strlen($token) > 2048) {
            throw new InvalidArgumentException('密钥或临时令牌过长。');
        }
        q_update('object_accounts', [
            'name'          => $name,
            'provider'      => $provider,
            'endpoint'      => $endpoint,
            'region'        => $region,
            'access_key'    => $accessKey,
            'secret_key'    => $secretKey,
            'session_token' => $token !== '' ? $token : null,
        ], 'id = ? AND user_id = ?', [$id, $userId]);
        return $id;
    }

    if ($accessKey === '' || $secretKey === '' || $secretKey === OBJECT_MASK) {
        throw new InvalidArgumentException('请填写 Access Key 与 Secret Key。');
    }

    return q_insert('object_accounts', [
        'user_id'       => $userId,
        'name'          => $name,
        'provider'      => $provider,
        'endpoint'      => $endpoint,
        'region'        => $region,
        'access_key'    => $accessKey,
        'secret_key'    => $secretKey,
        'session_token' => $token !== '' ? $token : null,
        'created_at'    => now(),
    ]);
}

function object_account_delete(int $userId, int $id): bool
{
    return q_exec('DELETE FROM object_accounts WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
}

/** 桶级调用配置：账号凭据 + 目标桶 + 清空 prefix */
function object_bucket_config(array $account, string $bucket): array
{
    $config = object_account_config($account);
    $config['bucket'] = $bucket;
    $config['prefix'] = '';
    return $config;
}

/** 已被存储源绑定的桶：桶名 => 存储源名称 */
function object_bound_buckets(int $userId): array
{
    $out = [];
    foreach (q_all(
        "SELECT name, config FROM storage_sources WHERE user_id = ? AND driver = 's3'",
        [$userId]
    ) as $row) {
        $config = json_decode((string) $row['config'], true) ?: [];
        $bucket = (string) ($config['bucket'] ?? '');
        if ($bucket !== '') {
            $out[$bucket] = (string) $row['name'];
        }
    }
    return $out;
}

function object_bound_by(int $userId, string $bucket): ?string
{
    return object_bound_buckets($userId)[$bucket] ?? null;
}

function object_key_valid(string $key): bool
{
    $len = strlen($key);
    return $len > 0 && $len <= 1024 && !str_contains($key, "\0");
}

function object_key_join(string $prefix, string $name): string
{
    $prefix = trim($prefix, '/');
    $name = ltrim($name, '/');
    return $prefix === '' ? $name : $prefix . '/' . $name;
}

/** COS 桶名必须带 10 位 APPID 后缀 */
function object_cos_bucket_name_valid(string $bucket): bool
{
    return strlen($bucket) <= 63
        && preg_match('/^[a-z0-9][a-z0-9-]*-[0-9]{10}$/', $bucket) === 1
        && s3_bucket_name_valid($bucket);
}

function object_storage_class_or_fail(string $class): string
{
    $class = strtoupper(trim($class));
    if ($class === '') {
        return '';
    }
    if (!isset(object_storage_classes()[$class])) {
        throw new InvalidArgumentException('不支持的存储类型。');
    }
    return $class;
}

/** 当前站点源，用于 CORS 与下载 */
function object_site_origin(): string
{
    $base = trim((string) config('base_url', ''));
    if ($base !== '') {
        $origin = object_url_origin($base);
        if ($origin !== null) {
            return $origin;
        }
    }
    $https = (($_SERVER['HTTPS'] ?? '') === 'on')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** 浏览器实际来源优先取 Origin 头，避免 base_url 与实际访问域名不一致时 CORS 配对失败 */
function object_request_origin(): string
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $normalized = object_url_origin($origin);
    return $normalized ?? object_site_origin();
}
