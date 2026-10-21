# 对象存储（COS / S3）管理页实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增独立的「对象存储」页面，用一组 COS/S3 凭据管理桶与对象：列桶、建删桶、浏览/搜索/翻页、上传（单次 + 分片）、下载、复制/移动、批量删除，以及版本控制、静态网站、CORS、生命周期、标签、ACL、策略只读。

**Architecture:** 复用现有 `s3_request` 的 SigV4 协议层并补齐签名头、STS 预签名、ListObjectsV2、对象级操作、子资源与 Multipart；新增 `object_accounts` 表存凭据；新增 `app/lib/object_store.php`（账号/绑定/键名）、`app/lib/object_settings.php`（桶设置 XML）、`app/modules/objects.php`（路由）、`app/views/objects.php` + `assets/js/objects.js`（页面）。浏览器直传/直下走预签名 URL，字节不经过本机；页面渲染时重新下发 CSP，把当前账号的 endpoint 加入 `connect-src`。

**Tech Stack:** PHP 8.1+（无框架、无 Composer、无构建步骤）、MySQL 8、原生 JS/CSS、curl、DOMDocument。不新增任何依赖。

**Spec:** `docs/superpowers/specs/2026-10-21-cos-object-storage-design.md`

## Global Constraints

- 所有新增 PHP 文件顶部：`declare(strict_types=1);`，并带 `if (!defined('BASE_PATH')) { http_response_code(404); exit; }`。
- 路由约定：`模块.动作` → `app/modules/模块.php` 的 `模块_动作()`；模块文件由 router 自动 `require_once`。
- 写接口：`require_method('POST')` + `require_csrf()` + `require_auth_api()`；读接口：`require_auth_api()`。
- 密钥永不进入 JSON 响应；统一掩码 `••••••`（常量 `OBJECT_MASK`）。
- 所有用户输入写进 XML 前必须 `object_xml_escape()`（内部 `htmlspecialchars(..., ENT_QUOTES | ENT_XML1, 'UTF-8')`）。
- 预签名 TTL 钳制到 `[60, 3600]` 秒；下载默认 300 秒。
- 已绑定存储源的桶对所有写操作返回 `409 in_use`，服务端守卫，不能只靠前端。
- 不修改 `nodes` / `blobs` / `blob_locations` / 下载链路。
- 前端只用 Lucide 图标（`lucide()` / `SY.icon`），不使用 emoji。
- 测试必须用 `runuser -u www -- php tests/...` 运行；不要用 root。
- 不新增依赖、不改系统配置、不下载二进制。

## Review Focus

1. 对象键含空格、中文、`+`、`&`、引号、`<` 时，列表、复制、删除、下载都必须正确编码/转义，不出现签名不匹配或 XML 注入。
2. 已绑定桶的写操作必须全部被服务端 409 拦下，包括上传、复制、删除、建文件夹、清空、解冻。
3. 大文件：>64 MiB 走分片；分片数不得超过 10000；取消/失败必须 abort，不能留下悬挂分片。
4. 桶未配置 CORS 或 CSP 未放行：上传必须在发请求前给出可操作提示，不能静默失败。
5. 空桶、空文件、0 字节文件夹标记：列表、统计、删除、清空都不能算错或报错。

---

### Task 1: 数据表、账号领域层与测试骨架

**Files:**
- Modify: `app/schema.php`
- Modify: `app/bootstrap.php`
- Create: `app/lib/object_store.php`
- Create: `app/lib/object_settings.php`（先建空壳，Task 7 填充）
- Create: `tests/t23_objects.php`

**Interfaces:**
- Consumes: `q_all()`, `q_one()`, `q_insert()`, `q_update()`, `q_exec()`, `now()`, `s3_bucket_name_valid()`。
- Produces:
  - `object_account_list(int $userId): list<array>`
  - `object_account_get(int $userId, int $id): ?array`
  - `object_account_config(array $row): array`
  - `object_account_save(int $userId, array $in, int $id = 0): int`
  - `object_account_delete(int $userId, int $id): bool`
  - `object_account_origins(int $userId): list<string>`
  - `object_url_origin(string $url): ?string`
  - `object_bucket_config(array $account, string $bucket): array`
  - `object_bound_buckets(int $userId): array`
  - `object_bound_by(int $userId, string $bucket): ?string`
  - `object_key_valid(string $key): bool`
  - `object_key_join(string $prefix, string $name): string`
  - `object_cos_bucket_name_valid(string $bucket): bool`
  - `object_storage_class_or_fail(string $class): string`
  - `object_site_origin(): string`
  - `object_request_origin(): string`
  - `object_archive_classes(): list<string>`

- [ ] **Step 1: 写失败测试**

Create `tests/t23_objects.php`:

```php
<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   对象存储管理页的回归测试。
   本文件按任务增量扩展：账号 → 协议 → 设置 → 上传/下载/复制/删除。
   ========================================================================= */

$email = 't23-objects@example.test';
$otherEmail = 't23-other@example.test';
foreach ([$email, $otherEmail] as $e) {
    $u = find_user_by_email($e);
    if ($u !== null) {
        $oid = (int) $u['id'];
        q_exec('DELETE FROM object_accounts WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM users WHERE id = ?', [$oid]);
    }
}
$uid = create_user($email, 'fixt-pass-123', '对象存储');
$otherId = create_user($otherEmail, 'fixt-pass-123', '旁观者');

echo "\n· 账号\n";
$id = object_account_save($uid, [
    'name' => '腾讯云主账号',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETEXAMPLE',
]);
check($id > 0, '新建账号返回 id');

$list = object_account_list($uid);
check(count($list) === 1, '账号列表返回 1 条');
check($list[0]['secret_key'] === '••••••', '账号列表掩码 Secret Key');
check($list[0]['session_token'] === '', '没有临时令牌时不回显掩码');
check(object_account_get($otherId, $id) === null, '别的用户读不到该账号');

object_account_save($uid, [
    'name' => '改名',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => '••••••',
], $id);
$row = object_account_get($uid, $id);
check($row !== null && $row['name'] === '改名', '更新账号名称');
check($row !== null && $row['secret_key'] === 'SECRETEXAMPLE', '掩码原样回传时不覆盖已存密钥');

$origins = object_account_origins($uid);
check(in_array('https://cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含精确 endpoint');
check(in_array('https://*.cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含桶名子域通配');

$bad = false;
try {
    object_account_save($uid, [
        'name' => '非法', 'provider' => 'cos', 'endpoint' => 'file:///etc/passwd',
        'region' => 'ap-guangzhou', 'access_key' => 'a', 'secret_key' => 'b',
    ]);
} catch (InvalidArgumentException $e) {
    $bad = true;
}
check($bad, '非 http/https endpoint 被拒');

echo "\n· 键名与桶名\n";
check(object_key_valid('photos/2026/a b+c.jpg'), '普通对象键合法');
check(!object_key_valid(''), '空键非法');
check(!object_key_valid("a\0b"), '含 NUL 的键非法');
check(!object_key_valid(str_repeat('a', 1025)), '超过 1024 字节的键非法');
check(object_key_join('photos/', '/2026/a.jpg') === 'photos/2026/a.jpg', '键拼接去掉多余斜杠');
check(object_cos_bucket_name_valid('photos-1250000000'), 'COS 桶名带 APPID 合法');
check(!object_cos_bucket_name_valid('photos'), 'COS 桶名缺 APPID 非法');
check(!object_cos_bucket_name_valid('Photos-1250000000'), 'COS 桶名大写非法');
check(object_storage_class_or_fail('standard_ia') === 'STANDARD_IA', '存储类型归一化为大写');
$badClass = false;
try { object_storage_class_or_fail('GLACIER'); } catch (InvalidArgumentException $e) { $badClass = true; }
check($badClass, '未知存储类型被拒');

echo "\n· 绑定守卫\n";
q_insert('storage_sources', [
    'user_id' => $uid, 'name' => '照片桶', 'driver' => 's3',
    'config' => json_encode(['bucket' => 'photos-1250000000']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok', 'created_at' => now(),
]);
check(object_bound_by($uid, 'photos-1250000000') === '照片桶', '已绑定桶能查到存储源名称');
check(object_bound_by($uid, 'other-1250000000') === null, '未绑定桶返回 null');
check(object_bound_by($otherId, 'photos-1250000000') === null, '别的用户看不到该绑定');

object_account_delete($uid, $id);
check(object_account_get($uid, $id) === null, '删除账号');

q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM user_settings WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM users WHERE id IN (?, ?)', [$uid, $otherId]);

finish();
```

- [ ] **Step 2: 运行测试确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL with `Call to undefined function object_account_save()`。

- [ ] **Step 3: 加表**

In `app/schema.php` change `const SCHEMA_VERSION = 17;` to `18`, and before `return $sql;` append:

```php
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS object_accounts (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            name          VARCHAR(64)     NOT NULL,
            provider      ENUM('cos','s3') NOT NULL DEFAULT 'cos',
            endpoint      VARCHAR(255)    NOT NULL,
            region        VARCHAR(64)     NOT NULL,
            access_key    VARCHAR(255)    NOT NULL,
            secret_key    VARCHAR(255)    NOT NULL,
            session_token VARCHAR(2048)   NULL,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_objacct_user (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;
```

在 `app/bootstrap.php` 的 `require BASE_PATH . '/app/lib/storage_drivers.php';` 之后加：

```php
require BASE_PATH . '/app/lib/object_store.php';
require BASE_PATH . '/app/lib/object_settings.php';
```

Create `app/lib/object_settings.php` as a guard-only placeholder (Task 7 fills it):

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}
```

- [ ] **Step 4: 写领域层**

Create `app/lib/object_store.php`:

```php
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
        'provider'      => (string) ($row['provider'] ?? 's3'),
        'endpoint'      => (string) ($row['endpoint'] ?? ''),
        'region'        => (string) ($row['region'] ?? ''),
        'access_key'    => (string) ($row['access_key'] ?? ''),
        'secret_key'    => (string) ($row['secret_key'] ?? ''),
        'session_token' => (string) ($row['session_token'] ?? ''),
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
```

- [ ] **Step 5: 运行测试确认通过**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

- [ ] **Step 6: 提交**

```bash
git add app/schema.php app/bootstrap.php app/lib/object_store.php \
        app/lib/object_settings.php tests/t23_objects.php
git commit -m "feat: 对象存储账号表与领域层"
```

---

### Task 2: CSP 可追加 connect-src

**Files:**
- Modify: `app/helpers.php`
- Modify: `app/bootstrap.php`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces: `csp_content(array $extraConnect = []): string`

- [ ] **Step 1: 加失败测试**

Append to `tests/t23_objects.php` before cleanup/finish (put it after the account section):

```php
echo "\n· CSP\n";
$csp = csp_content(['https://cos.ap-guangzhou.myqcloud.com']);
check(str_contains($csp, "connect-src 'self' https://cos.ap-guangzhou.myqcloud.com;"), 'CSP 追加对象存储来源');
check(str_contains($csp, "script-src 'self' 'nonce-"), 'CSP 仍带脚本 nonce');
check(str_contains(csp_content(), "connect-src 'self';"), '无额外来源时 connect-src 仍只有 self');
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL `Call to undefined function csp_content()`。

- [ ] **Step 3: 实现 CSP 拼接**

In `app/helpers.php` after `csp_nonce()` add:

```php
/**
 * 生成 CSP。页面默认只允许连接本站；对象存储页渲染前会把当前账号的
 * endpoint 作为 $extraConnect 传进来，因为浏览器直传 COS 走的是 fetch/XHR。
 *
 * @param list<string> $extraConnect
 */
function csp_content(array $extraConnect = []): string
{
    $connect = ["'self'"];
    foreach ($extraConnect as $origin) {
        $origin = trim((string) $origin);
        if ($origin !== '') {
            $connect[] = $origin;
        }
    }
    $connect = array_values(array_unique($connect));

    return "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' blob:; "
        . "frame-src 'self'; "
        . "font-src 'self' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline'; "
        . "connect-src " . implode(' ', $connect) . "; "
        . "script-src 'self' 'nonce-" . csp_nonce() . "'";
}
```

In `app/bootstrap.php` replace the whole `header("Content-Security-Policy: " . ... );` expression with:

```php
    header('Content-Security-Policy: ' . csp_content());
```

Keep the explanatory comment above it.

- [ ] **Step 4: 运行测试确认通过**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

- [ ] **Step 5: 提交**

```bash
git add app/helpers.php app/bootstrap.php tests/t23_objects.php
git commit -m "feat: CSP 支持按页面追加 connect-src 来源"
```

---

### Task 3: 协议层——签名头、STS 预签名、ListObjectsV2

**Files:**
- Modify: `app/lib/storage_drivers.php`
- Modify: `tests/stub_s3.php`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Consumes: 现有 `s3_request()`、`s3_presign()`、`s3_bucket_config()`。
- Produces:
  - `s3_request()` 新增 `$opts['headers']`
  - `s3_presign()` 支持 `$config['session_token']` 与 `$extraHeaders`（参与签名）
  - `s3_presign_url(array $config, string $method, string $key, int $ttl, array $query = [], array $extraHeaders = []): string`
  - `s3_parse_object_page(string $xml, string $prefix = ''): array`
  - `s3_list_objects_page(array $config, string $bucket, array $opts = []): array`
  - `s3_encode_key(string $key): string`
  - `s3_copy_source(string $bucket, string $key): string`
  - `s3_response_headers(string $raw): array`

- [ ] **Step 1: 扩展存根**

In `tests/stub_s3.php`:

1. In the log entry add all request headers:

```php
    'headers' => function_exists('getallheaders') ? getallheaders() : [],
    // 保留 auth 字段供 t16 断言；另存原始 Authorization 供新测试检查签名头
    'authHeader' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
```

2. At the very top of the `list-type=2` branch, add a COS-shaped fixture:

```php
    if ($bucket === 'photos-1250000000') {
        $prefix = '';
        $delimiter = '';
        $cursor = '';
        foreach (explode('&', $query) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $v = rawurldecode($v);
            if ($k === 'prefix') { $prefix = $v; }
            if ($k === 'delimiter') { $delimiter = $v; }
            if ($k === 'continuation-token') { $cursor = $v; }
        }
        echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>'
            . '<Name>photos-1250000000</Name>'
            . '<Prefix>' . htmlspecialchars($prefix, ENT_XML1) . '</Prefix>'
            . '<KeyCount>3</KeyCount><MaxKeys>100</MaxKeys>';
        if ($cursor === '' && $delimiter === '/') {
            echo '<IsTruncated>true</IsTruncated>'
                . '<NextContinuationToken>page-2</NextContinuationToken>'
                . '<CommonPrefixes><Prefix>' . htmlspecialchars($prefix . '2026/', ENT_XML1) . '</Prefix></CommonPrefixes>'
                . '<Contents><Key>' . htmlspecialchars($prefix . 'a b+c.jpg', ENT_XML1) . '</Key>'
                . '<Size>1024</Size><ETag>"etag-a"</ETag>'
                . '<LastModified>2026-01-02T03:04:05Z</LastModified><StorageClass>STANDARD</StorageClass></Contents>'
                . '<Contents><Key>' . htmlspecialchars($prefix . 'notes.txt', ENT_XML1) . '</Key>'
                . '<Size>15</Size><ETag>"etag-n"</ETag>'
                . '<LastModified>2026-01-03T03:04:05Z</LastModified><StorageClass>STANDARD_IA</StorageClass></Contents>';
        } else {
            echo '<IsTruncated>false</IsTruncated>';
        }
        echo '</ListBucketResult>';
        exit;
    }
```

- [ ] **Step 2: 加失败测试**

Append to `tests/t23_objects.php` a stub-backed section. Copy the stub startup from `tests/t16_bucket.php` (lines 18–77) into t23, then:

```php
/* ------------------------------------------------------------ 协议层 */

$base = 'http://localhost:' . $port;
$cfg = [
    'endpoint' => $base, 'region' => 'ap-guangzhou', 'bucket' => 'photos-1250000000',
    'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'SECRETEXAMPLE', 'prefix' => '',
];

echo "\n· 签名头\n";
s3_request($cfg, 'PUT', 'target.txt', [
    'headers' => ['x-amz-copy-source' => '/photos-1250000000/a b+c.jpg'],
    'payload_hash' => hash('sha256', ''),
]);
$req = $requests(0);
$headers = array_change_key_case((array) ($req['headers'] ?? []), CASE_LOWER);
check(isset($headers['x-amz-copy-source']), '额外头出现在请求里');
check(str_contains((string) ($req['authHeader'] ?? ''), 'x-amz-copy-source'), '额外头参与签名');

echo "\n· STS 预签名\n";
$url = s3_presign($cfg + ['session_token' => 'TOKEN+WITH/SLASH'], 'GET', 'a.txt', 300);
check(str_contains($url, 'X-Amz-Security-Token=TOKEN%2BWITH%2FSLASH'), '临时令牌进入预签名查询串');

$signed = s3_presign($cfg, 'PUT', 'a.txt', 300, [], [
    'content-type' => 'text/plain',
    'x-amz-storage-class' => 'STANDARD_IA',
]);
check(str_contains($signed, 'X-Amz-SignedHeaders=content-type%3Bhost%3Bx-amz-storage-class'),
    '预签名把额外头纳入签名');

echo "\n· ListObjectsV2\n";
$page = s3_list_objects_page($cfg, 'photos-1250000000', ['prefix' => 'photos/', 'delimiter' => '/']);
check(count($page['folders']) === 1 && $page['folders'][0]['name'] === '2026', '解析 CommonPrefixes');
check(count($page['objects']) === 2, '解析两个对象');
check($page['objects'][0]['name'] === 'a b+c.jpg', '对象名去掉前缀并保留空格与加号');
check($page['objects'][0]['etag'] === '"etag-a"', '解析 ETag');
check($page['objects'][1]['storage_class'] === 'STANDARD_IA', '解析存储类型');
check($page['truncated'] === true && $page['next_token'] === 'page-2', '解析分页游标');

$req = $requests(0);
check(str_contains((string) ($req['uri'] ?? ''), 'prefix=photos%2F'), '请求带 prefix');
check(str_contains((string) ($req['uri'] ?? ''), 'delimiter=%2F'), '请求带 delimiter');

$page2 = s3_list_objects_page($cfg, 'photos-1250000000', ['prefix' => 'photos/', 'continuation_token' => 'page-2']);
check($page2['truncated'] === false && $page2['objects'] === [], '第二页返回空且未截断');
```

Place this before the cleanup at the end of the file. The stub process is terminated by its shutdown handler.

- [ ] **Step 3: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL `Call to undefined function s3_list_objects_page()`。

- [ ] **Step 4: 实现协议扩展**

In `app/lib/storage_drivers.php`:

1. In `s3_request()`, replace the `$headers = [...]` initialization block with:

```php
    $headers = [];
    foreach ((array) ($opts['headers'] ?? []) as $hk => $hv) {
        $hk = strtolower(trim((string) $hk));
        if ($hk === '' || in_array($hk, [
            'host', 'authorization', 'content-length', 'expect',
            'x-amz-date', 'x-amz-content-sha256', 'x-amz-security-token',
        ], true)) {
            continue;
        }
        $headers[$hk] = trim((string) $hv);
    }
    $headers['host']                 = $hostHeader;
    $headers['x-amz-content-sha256'] = $payloadHash;
    $headers['x-amz-date']           = $amzDate;
    if (!empty($c['session_token'])) {
        $headers['x-amz-security-token'] = (string) $c['session_token'];
    }
    if (isset($opts['content_type'])) {
        $headers['content-type'] = (string) $opts['content_type'];
    }
    if (isset($opts['content_md5'])) {
        $headers['content-md5'] = (string) $opts['content_md5'];
    }
```

2. In `s3_presign()`, extend the signature and the signed-header computation:

```php
function s3_presign(array $c, string $method, string $key, int $ttl, array $query = [], array $extraHeaders = []): string
```

Before `$params = $query + [...]` build the signed header map:

```php
    // 参与签名的请求头：host 必选；额外头用于 content-type / x-amz-storage-class
    $signedMap = ['host' => $hostHeader];
    foreach ($extraHeaders as $hk => $hv) {
        $hk = strtolower(trim((string) $hk));
        if ($hk === '' || $hk === 'host') {
            continue;
        }
        $signedMap[$hk] = trim((string) $hv);
    }
    ksort($signedMap);
    $signedHeaders = implode(';', array_keys($signedMap));
    $canonicalHeaders = '';
    foreach ($signedMap as $hk => $hv) {
        $canonicalHeaders .= $hk . ':' . $hv . "\n";
    }
```

Replace `'X-Amz-SignedHeaders' => 'host'` with `'X-Amz-SignedHeaders' => $signedHeaders`, and after `$params = $query + [...]` add:

```php
    if (!empty($c['session_token'])) {
        $params['X-Amz-Security-Token'] = (string) $c['session_token'];
    }
```

Replace the canonical request's header lines with:

```php
    $canonicalRequest = implode("\n", [
        $method,
        parse_url($url, PHP_URL_PATH) ?: '/',
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        'UNSIGNED-PAYLOAD',
    ]);
```

3. After `s3_presign()` add:

```php
function s3_presign_url(
    array $config,
    string $method,
    string $key,
    int $ttl,
    array $query = [],
    array $extraHeaders = []
): string {
    return s3_presign($config, $method, $key, max(60, min(3600, $ttl)), $query, $extraHeaders);
}

/** 对象键 → URL 路径段；保留 / 作为分隔符 */
function s3_encode_key(string $key): string
{
    return str_replace('%2F', '/', rawurlencode($key));
}

/** CopyObject 的 x-amz-copy-source 值 */
function s3_copy_source(string $bucket, string $key): string
{
    return '/' . $bucket . '/' . s3_encode_key($key);
}
```

4. After `s3_list_objects()` add (or replace the parser region with these new functions; keep the old ones for `s3_purge_objects`):

```php
/** 解析 ListObjectsV2（含 CommonPrefixes / 分页 / ETag / 存储类型） */
function s3_parse_object_page(string $xml, string $prefix = ''): array
{
    $out = ['folders' => [], 'objects' => [], 'truncated' => false, 'next_token' => '', 'key_count' => 0];
    if ($xml === '' || !class_exists('DOMDocument')) {
        return $out;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || $doc->documentElement === null) {
        return $out;
    }

    foreach ($doc->documentElement->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if ($node->nodeName === 'IsTruncated') {
            $out['truncated'] = strtolower(trim($node->textContent)) === 'true';
        } elseif ($node->nodeName === 'NextContinuationToken') {
            $out['next_token'] = trim($node->textContent);
        } elseif ($node->nodeName === 'KeyCount') {
            $out['key_count'] = (int) $node->textContent;
        } elseif ($node->nodeName === 'CommonPrefixes') {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'Prefix') {
                    $full = $child->textContent;
                    $name = ($prefix !== '' && str_starts_with($full, $prefix))
                        ? substr($full, strlen($prefix)) : $full;
                    $out['folders'][] = ['prefix' => $full, 'name' => rtrim($name, '/')];
                }
            }
        } elseif ($node->nodeName === 'Contents') {
            $row = ['key' => '', 'name' => '', 'size' => 0, 'etag' => '', 'modified' => '', 'storage_class' => ''];
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                switch ($child->nodeName) {
                    case 'Key': $row['key'] = $child->textContent; break;
                    case 'Size': $row['size'] = (int) $child->textContent; break;
                    case 'ETag': $row['etag'] = trim($child->textContent); break;
                    case 'LastModified': $row['modified'] = trim($child->textContent); break;
                    case 'StorageClass': $row['storage_class'] = trim($child->textContent); break;
                }
            }
            if ($row['key'] !== '') {
                $row['name'] = ($prefix !== '' && str_starts_with($row['key'], $prefix))
                    ? substr($row['key'], strlen($prefix)) : $row['key'];
                $out['objects'][] = $row;
            }
        }
    }
    return $out;
}

/** ListObjectsV2 分页读取 */
function s3_list_objects_page(array $config, string $bucket, array $opts = []): array
{
    $prefix = (string) ($opts['prefix'] ?? '');
    $delimiter = (string) ($opts['delimiter'] ?? '/');
    $maxKeys = max(1, min(1000, (int) ($opts['max_keys'] ?? 100)));

    $query = ['list-type' => '2', 'max-keys' => (string) $maxKeys];
    if ($prefix !== '') {
        $query['prefix'] = $prefix;
    }
    if ($delimiter !== '') {
        $query['delimiter'] = $delimiter;
    }
    if (!empty($opts['continuation_token'])) {
        $query['continuation-token'] = (string) $opts['continuation_token'];
    }
    if (!empty($opts['start_after'])) {
        $query['start-after'] = (string) $opts['start_after'];
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => $query,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取桶内对象失败'));
    }
    return s3_parse_object_page((string) $res['body'], $prefix);
}
```

- [ ] **Step 5: 运行测试确认通过**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。再跑 `runuser -u www -- php tests/t16_bucket.php`，确认旧存根行为未被破坏。

- [ ] **Step 6: 提交**

```bash
git add app/lib/storage_drivers.php tests/stub_s3.php tests/t23_objects.php
git commit -m "feat: S3 协议支持签名头、STS 预签名与 ListObjectsV2"
```

---

### Task 4: 对象级协议——HEAD、Copy、Restore、子资源

**Files:**
- Modify: `app/lib/storage_drivers.php`
- Modify: `tests/stub_s3.php`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces:
  - `s3_response_headers(string $raw): array`
  - `s3_head_object(array $config, string $bucket, string $key): ?array`
  - `s3_copy_object(array $config, string $bucket, string $source, string $target, ?string $storageClass = null): array`
  - `s3_restore_object(array $config, string $bucket, string $key, int $days, string $tier = 'Standard'): array`
  - `s3_get_subresource(array $config, string $bucket, string $sub, array $query = []): array`
  - `s3_put_subresource(array $config, string $bucket, string $sub, string $xml, array $headers = []): array`
  - `s3_delete_subresource(array $config, string $bucket, string $sub): array`

- [ ] **Step 1: 扩展存根**

In `tests/stub_s3.php` change the `HEAD` branch so object HEAD is distinguishable from bucket probe:

```php
if ($method === 'HEAD') {
    if ($path !== '/') {
        if ($path === '/missing.txt') {
            http_response_code(404);
            exit;
        }
        header('Content-Length: 1024');
        header('Content-Type: text/plain');
        header('ETag: "etag-head"');
        header('x-amz-storage-class: STANDARD_IA');
        http_response_code(200);
        exit;
    }
    if ($bucket === 'missing-bucket') {
        http_response_code(404);
    } elseif ($bucket === 'denied-bucket') {
        http_response_code(403);
    } else {
        http_response_code(200);
    }
    exit;
}
```

Add copy/restore branches **before** the generic bucket `PUT` branch:

```php
if ($method === 'PUT' && isset($_SERVER['HTTP_X_AMZ_COPY_SOURCE'])) {
    echo '<?xml version="1.0" encoding="UTF-8"?><CopyObjectResult>'
        . '<ETag>"etag-copy"</ETag><LastModified>2026-01-04T00:00:00Z</LastModified></CopyObjectResult>';
    exit;
}

if ($method === 'POST' && str_contains($query, 'restore')) {
    http_response_code(202);
    exit;
}
```

- [ ] **Step 2: 加失败测试**

Append to `tests/t23_objects.php` before cleanup:

```php
echo "\n· 对象级协议\n";
$head = s3_head_object($cfg, 'photos-1250000000', 'a.txt');
check($head !== null && $head['size'] === 1024, 'HEAD 解析大小');
check($head !== null && $head['storage_class'] === 'STANDARD_IA', 'HEAD 解析存储类型');
check(s3_head_object($cfg, 'photos-1250000000', 'missing.txt') === null, 'HEAD 404 返回 null');

$copy = s3_copy_object($cfg, 'photos-1250000000', 'a b+c.jpg', 'copied/中文.txt');
check($copy['ok'] === true, '复制成功');
$req = $requests(0);
$headers = array_change_key_case((array) ($req['headers'] ?? []), CASE_LOWER);
check(($headers['x-amz-copy-source'] ?? '') === '/photos-1250000000/a%20b%2Bc.jpg', '复制源键按路径段编码');

$restore = s3_restore_object($cfg + ['provider' => 'cos'], 'photos-1250000000', 'cold.bin', 2);
check($restore['ok'] === true, '解冻返回成功');
$req = $requests(0);
check(str_contains((string) ($req['body'] ?? ''), '<Days>2</Days>'), '解冻请求体带天数');
check(str_contains((string) ($req['body'] ?? ''), '<CASJobParameters><Tier>Standard</Tier></CASJobParameters>'),
    'COS 解冻把 Tier 放进 CASJobParameters');
$restoreS3 = s3_restore_object($cfg + ['provider' => 's3'], 'photos-1250000000', 'cold.bin', 2);
$req = $requests(0);
check($restoreS3['ok'] === true
    && str_contains((string) ($req['body'] ?? ''), '<GlacierJobParameters><Tier>Standard</Tier></GlacierJobParameters>'),
    '通用 S3 解冻把 Tier 放进 GlacierJobParameters');

$version = s3_get_subresource($cfg, 'photos-1250000000', 'versioning');
check(in_array($version['status'], [200, 404], true), '子资源 GET 返回状态码');
$put = s3_put_subresource($cfg, 'photos-1250000000', 'versioning', '<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>');
check($put['ok'] === true, '子资源 PUT 成功');

$err = s3_error_message(['status' => 400, 'body' => '<Error><Code>EntityTooLarge</Code></Error>', 'headers' => ''], '上传失败');
check(str_contains($err, '单次上传上限'), '翻译 EntityTooLarge');
$err2 = s3_error_message(['status' => 404, 'body' => '<Error><Code>NoSuchCORSConfiguration</Code></Error>', 'headers' => ''], '读取失败');
check(str_contains($err2, '未配置 CORS'), '翻译 NoSuchCORSConfiguration');
```

Note: the stub currently returns 400 for unknown GET queries. Add these subresource branches **before** the existing bucket-level HEAD/PUT/DELETE branches (otherwise `PUT ?versioning` is mistaken for bucket creation and `DELETE ?website` for bucket deletion):

```php
if ($method === 'GET' && preg_match('/\?(versioning|cors|lifecycle|website|tagging|acl|policy)=?/', $uri)) {
    if (str_contains($query, 'versioning')) {
        echo '<?xml version="1.0" encoding="UTF-8"?><VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>';
        exit;
    }
    if (str_contains($query, 'policy')) {
        http_response_code(404);
        echo '<Error><Code>NoSuchBucketPolicy</Code><Message>no policy</Message></Error>';
        exit;
    }
    http_response_code(404);
    echo '<Error><Code>NoSuchConfiguration</Code><Message>not configured</Message></Error>';
    exit;
}
if ($method === 'PUT' && preg_match('/\?(versioning|cors|lifecycle|website|tagging|acl)=?/', $uri)) {
    http_response_code(200);
    exit;
}
if ($method === 'DELETE' && preg_match('/\?(versioning|cors|lifecycle|website|tagging|acl)=?/', $uri)) {
    http_response_code(204);
    exit;
}
```

- [ ] **Step 3: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL `Call to undefined function s3_head_object()`。

- [ ] **Step 4: 实现**

Append to `app/lib/storage_drivers.php`:

```php
/* -------------------------------------------------------- 对象级操作 */

/** 把 cURL 返回的原始响应头解析成小写键的数组 */
function s3_response_headers(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $out[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return $out;
}

/** HEAD 对象；404 返回 null */
function s3_head_object(array $config, string $bucket, string $key): ?array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'HEAD', $key, [
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] === 404) {
        return null;
    }
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取对象信息失败'));
    }
    $h = s3_response_headers((string) $res['headers']);
    return [
        'size'          => (int) ($h['content-length'] ?? 0),
        'content_type'  => (string) ($h['content-type'] ?? ''),
        'etag'          => (string) ($h['etag'] ?? ''),
        'last_modified' => (string) ($h['last-modified'] ?? ''),
        'storage_class' => (string) ($h['x-amz-storage-class'] ?? 'STANDARD'),
        'restore'       => (string) ($h['x-amz-restore'] ?? ''),
    ];
}

function s3_copy_object(array $config, string $bucket, string $source, string $target, ?string $storageClass = null): array
{
    $headers = [
        'x-amz-copy-source'        => s3_copy_source($bucket, $source),
        'x-amz-metadata-directive' => 'COPY',
    ];
    if ($storageClass !== null && $storageClass !== '') {
        $headers['x-amz-storage-class'] = $storageClass;
    }
    $res = s3_request(s3_bucket_config($config, $bucket), 'PUT', $target, [
        'headers'      => $headers,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '复制对象失败')];
    }
    return ['ok' => true, 'message' => '已复制。'];
}

function s3_restore_object(array $config, string $bucket, string $key, int $days, string $tier = 'Standard'): array
{
    $days = max(1, min(30, $days));
    $tier = in_array($tier, ['Standard', 'Bulk', 'Expedited'], true) ? $tier : 'Standard';
    // Tier 不能放在顶层：COS 用 CASJobParameters，通用 S3 用 GlacierJobParameters
    $wrapper = ((string) ($config['provider'] ?? 's3')) === 'cos' ? 'CASJobParameters' : 'GlacierJobParameters';
    $xml = '<RestoreRequest><Days>' . $days . '</Days><' . $wrapper . '><Tier>'
        . $tier . '</Tier></' . $wrapper . '></RestoreRequest>';
    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['restore' => ''],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '解冻失败')];
    }
    return ['ok' => true, 'message' => '已提交解冻，归档对象通常需要数分钟到数小时。'];
}

/* ------------------------------------------------------------ 子资源 */

function s3_get_subresource(array $config, string $bucket, string $sub, array $query = []): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => [$sub => ''] + $query,
        'payload_hash' => hash('sha256', ''),
    ]);
    return [
        'status'  => (int) $res['status'],
        'body'    => (string) $res['body'],
        'headers' => (string) $res['headers'],
    ];
}

function s3_put_subresource(array $config, string $bucket, string $sub, string $xml, array $headers = []): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'PUT', '', [
        'query'        => [$sub => ''],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
        'headers'      => $headers,
    ]);
    $ok = (int) $res['status'] >= 200 && (int) $res['status'] < 300;
    return ['ok' => $ok, 'status' => (int) $res['status'], 'message' => $ok ? '已保存。' : s3_error_message($res, '保存失败')];
}

function s3_delete_subresource(array $config, string $bucket, string $sub): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'DELETE', '', [
        'query'        => [$sub => ''],
        'payload_hash' => hash('sha256', ''),
    ]);
    $status = (int) $res['status'];
    $ok = $status === 404 || ($status >= 200 && $status < 300);
    return ['ok' => $ok, 'status' => $status, 'message' => $ok ? '已删除。' : s3_error_message($res, '删除失败')];
}
```

Then extend the `$known` map in `s3_error_message()` with:

```php
        'EntityTooLarge'             => '对象超过单次上传上限，请改用分片上传。',
        'InvalidStorageClass'        => '该存储类型在此服务商不可用。',
        'CORSNotAllowed'             => '桶的 CORS 未放行本站，请先配置 CORS。',
        'NoSuchCORSConfiguration'    => '桶未配置 CORS。',
        'NoSuchLifecycleConfiguration' => '桶未配置生命周期。',
        'NoSuchBucketPolicy'         => '桶未设置策略。',
        'InvalidPart'                => '分片无效，请重新上传该分片。',
        'InvalidPartOrder'           => '分片顺序不正确，请重新完成上传。',
        'NoSuchUpload'               => '分片上传不存在或已过期，请重新开始。',
```

- [ ] **Step 5: 运行测试确认通过**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

- [ ] **Step 6: 提交**

```bash
git add app/lib/storage_drivers.php tests/stub_s3.php tests/t23_objects.php
git commit -m "feat: S3 对象 HEAD、复制、解冻与子资源读写"
```

---

### Task 5: 页面骨架、导航、账号接口与账号 UI

**Files:**
- Modify: `app/lib/nav.php`
- Modify: `app/bootstrap.php` (require new libs)
- Create: `app/modules/objects.php`
- Create: `app/views/objects.php`
- Create: `assets/js/objects.js`
- Modify: `assets/css/console.css`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Consumes: Task 1/2/3/4 的全部函数。
- Produces:
  - 路由 `objects.index`、`objects.accounts`、`objects.account_save`、`objects.account_delete`、`objects.account_test`
  - JS 全局约定：页面根 `[data-page="objects"]`，配置放在 `data-config`（JSON）

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php` before cleanup:

```php
echo "\n· 页面骨架\n";
$nav = (string) file_get_contents(BASE_PATH . '/app/lib/nav.php');
check(str_contains($nav, "'objects'") && str_contains($nav, 'objects.index'), '导航新增对象存储入口');
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_index'), '模块提供页面入口');
check(str_contains($module, 'csp_content(object_account_origins'), '页面渲染前扩展 CSP');
$view = (string) file_get_contents(BASE_PATH . '/app/views/objects.php');
check(str_contains($view, 'data-page="objects"'), '视图标记页面');
check(str_contains($view, 'data-config'), '视图注入前端配置');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, "objects.account_save"), '前端提交账号保存');
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL（文件不存在）。

- [ ] **Step 3: 注册导航**

In `app/lib/nav.php` add before the `sources` item:

```php
        ['id' => 'objects',   'label' => '对象存储', 'icon' => 'database',      'href' => url('objects.index')],
```

- [ ] **Step 4: 写模块（账号部分）**

Create `app/modules/objects.php`:

```php
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
```

- [ ] **Step 5: 写视图骨架**

Create `app/views/objects.php`:

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array $config */
/** @var array{used:int,total:int} $quota */
$initial = (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="app" data-app data-section="objects" data-page="objects"
     data-config="<?= e($initial) ?>">
    <?php view('partials/spine', ['section' => 'objects', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">对象存储</h1>
                    <p class="pagehead__sub">
                        用一组 COS / S3 凭据管理桶和对象。上传下载走预签名直连，密钥只留在服务端。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <select class="select" data-account-select aria-label="对象存储账号"></select>
                    <button class="ghost" type="button" data-account-new>
                        <?= lucide('plus', 'ghost__ico') ?><span>添加账号</span>
                    </button>
                    <button class="ghost" type="button" data-account-edit>
                        <?= lucide('pencil', 'ghost__ico') ?><span>编辑</span>
                    </button>
                    <button class="ghost" type="button" data-account-test>
                        <?= lucide('plug', 'ghost__ico') ?><span>测试连接</span>
                    </button>
                </div>
            </div>

            <div class="blank" data-no-account hidden>
                <?= lucide('database', 'blank__ico') ?>
                <p class="blank__title">还没有对象存储账号</p>
                <p class="blank__hint">添加一组腾讯云 COS 或通用 S3 密钥，就能在这里管理桶和对象。</p>
                <button class="mbtn mbtn--primary" type="button" data-account-new>添加账号</button>
            </div>

            <section data-bucket-view hidden>
                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">存储桶</h2>
                        <div class="panel__acts">
                            <button class="chipbtn" type="button" data-bucket-reload>
                                <?= lucide('refresh-cw', 'chipbtn__ico') ?>刷新
                            </button>
                            <button class="chipbtn" type="button" data-bucket-new>
                                <?= lucide('plus', 'chipbtn__ico') ?>新建桶
                            </button>
                        </div>
                    </div>
                    <div class="panel__body" data-bucket-grid>
                        <div class="blank"><?= lucide('loader', 'blank__ico') ?>
                            <p class="blank__hint">正在读取桶列表…</p></div>
                    </div>
                </div>
            </section>

            <section data-detail-view hidden>
                <div class="objdetail__head">
                    <button class="chipbtn" type="button" data-detail-back>
                        <?= lucide('arrow-left', 'chipbtn__ico') ?>全部桶
                    </button>
                    <div class="objdetail__title" data-detail-title></div>
                    <div class="objdetail__acts" data-detail-acts></div>
                </div>

                <div class="seg2" data-detail-tabs>
                    <a href="#" class="is-on" data-tab="objects">对象</a>
                    <a href="#" data-tab="settings">设置</a>
                </div>

                <div data-tab-panel="objects">
                    <div class="objbar">
                        <div class="objcrumb" data-crumb></div>
                        <div class="objbar__tools">
                            <input class="minput" type="search" data-obj-search placeholder="按前缀搜索当前目录">
                            <select class="select" data-upload-class aria-label="上传存储类型">
                                <option value="">默认存储类型</option>
                                <?php foreach ($config['classes'] as $key => $label): ?>
                                    <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="chipbtn" type="button" data-obj-upload>
                                <?= lucide('upload', 'chipbtn__ico') ?>上传
                            </button>
                            <button class="chipbtn" type="button" data-obj-mkdir>
                                <?= lucide('folder-plus', 'chipbtn__ico') ?>新建文件夹
                            </button>
                            <button class="chipbtn" type="button" data-obj-stat>
                                <?= lucide('bar-chart-3', 'chipbtn__ico') ?>统计
                            </button>
                        </div>
                    </div>

                    <div class="objsel" data-obj-selection hidden>
                        <span data-obj-selected-count></span>
                        <button class="chipbtn chipbtn--danger" type="button" data-obj-delete-selected>删除所选</button>
                    </div>

                    <div data-obj-host>
                        <div class="blank"><?= lucide('loader', 'blank__ico') ?>
                            <p class="blank__hint">正在读取对象…</p></div>
                    </div>
                </div>

                <div data-tab-panel="settings" hidden>
                    <div class="objsettings" data-settings-host></div>
                </div>
            </section>

            <input type="file" data-upload-input multiple hidden>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
```

- [ ] **Step 6: 写 JS 骨架与账号管理**

Create `assets/js/objects.js`:

```js
/* 拾云 · 对象存储管理页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const icon = SY.icon || (() => '');
    const $ = SY.$;
    const $$ = SY.$$;
    const api = SY.api;
    const toast = SY.toast;
    const escapeHtml = SY.escapeHtml;
    const humanSize = SY.humanSize;

    const root = $('[data-page="objects"]');
    if (!root) return;

    const cfg = (() => {
        try { return JSON.parse(root.dataset.config || '{}'); } catch (e) { return {}; }
    })();

    const state = {
        accounts: Array.isArray(cfg.accounts) ? cfg.accounts.slice() : [],
        account: Number(cfg.accountId) || 0,
        bucket: String(cfg.bucket || ''),
        prefix: String(cfg.prefix || ''),
        bound: cfg.bound || {},
        providers: cfg.providers || {},
        regions: cfg.regions || [],
        classes: cfg.classes || {},
        archiveClasses: cfg.archiveClasses || [],
        selected: new Set(),
        objects: [],
        cursor: '',
        history: [],
        query: '',
        sortKey: 'name',
        sortDir: 1,
        lastData: null,
    };
    if (!state.account && state.accounts.length) state.account = Number(state.accounts[0].id);
    if (!state.accounts.length) state.bucket = '';
    // URL 里可能残留已删除账号的 id；对齐到第一个可用账号，否则编辑/测试会失效
    if (state.account && !state.accounts.some((a) => Number(a.id) === Number(state.account))) {
        state.account = state.accounts.length ? Number(state.accounts[0].id) : 0;
    }

    const el = {
        select: $('[data-account-select]'),
        noAccount: $('[data-no-account]'),
        bucketView: $('[data-bucket-view]'),
        detailView: $('[data-detail-view]'),
        grid: $('[data-bucket-grid]'),
        title: $('[data-detail-title]'),
        detailActs: $('[data-detail-acts]'),
        crumb: $('[data-crumb]'),
        objHost: $('[data-obj-host]'),
        settingsHost: $('[data-settings-host]'),
        selection: $('[data-obj-selection]'),
        selectedCount: $('[data-obj-selected-count]'),
        search: $('[data-obj-search]'),
        uploadInput: $('[data-upload-input]'),
    };

    function account() {
        return state.accounts.find((a) => Number(a.id) === Number(state.account)) || null;
    }

    function updateQuery() {
        const params = new URLSearchParams(location.search);
        if (state.account) params.set('account', String(state.account));
        if (state.bucket) params.set('bucket', state.bucket);
        if (state.prefix) params.set('prefix', state.prefix);
        history.replaceState(null, '', location.pathname + '?' + params.toString());
    }

    function renderAccounts() {
        if (!el.select) return;
        el.select.innerHTML = state.accounts.map((a) =>
            '<option value="' + Number(a.id) + '"' + (Number(a.id) === Number(state.account) ? ' selected' : '') + '>'
            + escapeHtml(a.name) + ' · ' + escapeHtml((state.providers[a.provider] || a.provider)) + '</option>'
        ).join('');
        const has = state.accounts.length > 0;
        el.noAccount?.toggleAttribute('hidden', has);
        el.bucketView?.toggleAttribute('hidden', !has || !!state.bucket);
        el.detailView?.toggleAttribute('hidden', !state.bucket);
        if (state.account) updateQuery();
    }

    /* ---------------------------------------------------------- 账号表单 */

    function accountForm(a) {
        const isEdit = !!a;
        const providers = Object.entries(state.providers).map(([k, v]) =>
            '<option value="' + k + '"' + (a && a.provider === k ? ' selected' : '') + '>' + escapeHtml(v) + '</option>'
        ).join('');
        const regions = state.regions.map((r) =>
            '<option value="' + r + '"' + (a && a.region === r ? ' selected' : '') + '>' + r + '</option>'
        ).join('');
        return '<div class="mform">'
            + '<label class="mfield"><span class="mfield__label">名称</span>'
            + '<input class="minput" data-f="name" value="' + escapeHtml(a ? a.name : '') + '" maxlength="64"></label>'
            + '<label class="mfield"><span class="mfield__label">服务商</span>'
            + '<select class="select" data-f="provider">' + providers + '</select></label>'
            + '<label class="mfield"><span class="mfield__label">地域</span>'
            + '<input class="minput" data-f="region" list="obj-regions" value="' + escapeHtml(a ? a.region : '') + '">'
            + '<datalist id="obj-regions">' + regions + '</datalist></label>'
            + '<label class="mfield"><span class="mfield__label">Endpoint</span>'
            + '<input class="minput" data-f="endpoint" value="' + escapeHtml(a ? a.endpoint : '') + '" placeholder="https://cos.ap-guangzhou.myqcloud.com"></label>'
            + '<label class="mfield"><span class="mfield__label">Access Key</span>'
            + '<input class="minput" data-f="access_key" value="' + escapeHtml(a ? a.access_key : '') + '" autocomplete="off"></label>'
            + '<label class="mfield"><span class="mfield__label">Secret Key</span>'
            + '<input class="minput" type="password" data-f="secret_key" value="' + (a && a.secret_key ? '••••••' : '') + '" autocomplete="new-password" placeholder="' + (isEdit ? '留空或保持掩码表示不修改' : '') + '"></label>'
            + '<label class="mfield"><span class="mfield__label">临时令牌（STS，可选）</span>'
            + '<input class="minput" type="password" data-f="session_token" value="' + (a && a.session_token ? '••••••' : '') + '" autocomplete="new-password"></label>'
            + '<p class="mhint">建议使用只授予目标桶权限的子账号密钥。</p>'
            + '</div>';
    }

    function openAccount(a) {
        SY.openModal(a ? '编辑账号' : '添加账号', accountForm(a),
            (a ? '<button class="mbtn mbtn--danger" type="button" data-x="del">删除账号</button>' : '')
            + '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">保存</button>');
        const body = $('[data-modal-body]');
        const foot = $('[data-modal-foot]');

        // 选 COS + 地域时自动填 endpoint；用户手改过就不再覆盖
        const providerEl = body.querySelector('[data-f="provider"]');
        const regionEl = body.querySelector('[data-f="region"]');
        const endpointEl = body.querySelector('[data-f="endpoint"]');
        const syncEndpoint = () => {
            if (!endpointEl || endpointEl.dataset.touched === '1') return;
            if (providerEl && providerEl.value === 'cos' && regionEl && regionEl.value) {
                endpointEl.value = 'https://cos.' + regionEl.value + '.myqcloud.com';
            }
        };
        regionEl?.addEventListener('change', syncEndpoint);
        providerEl?.addEventListener('change', syncEndpoint);
        endpointEl?.addEventListener('input', () => { endpointEl.dataset.touched = '1'; });
        syncEndpoint();

        foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
        foot.querySelector('[data-x="del"]')?.addEventListener('click', async () => {
            const ok = await SY.confirm('删除账号', '只删除凭据，不影响已绑定的存储源。', '删除', true);
            if (!ok) return;
            try {
                await api('objects.account_delete', { id: Number(a.id) });
                SY.closeModal();
                toast('账号已删除');
                location.reload();
            } catch (err) {
                toast(err.message, 'err');
            }
        });
        foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const payload = { id: a ? Number(a.id) : 0 };
            $$('[data-f]', body).forEach((input) => { payload[input.dataset.f] = input.value; });
            btn.disabled = true;
            try {
                await api('objects.account_save', payload);
                SY.closeModal();
                toast('账号已保存');
                location.reload();
            } catch (err) {
                toast(err.message, 'err');
                btn.disabled = false;
            }
        });
    }

    $$('[data-account-new]').forEach((btn) => btn.addEventListener('click', () => openAccount(null)));
    $('[data-account-edit]')?.addEventListener('click', () => {
        const a = account();
        if (!a) { toast('先添加一个账号', 'err'); return; }
        openAccount(a);
    });
    $('[data-account-test]')?.addEventListener('click', async (ev) => {
        const a = account();
        if (!a) { toast('先添加一个账号', 'err'); return; }
        const btn = ev.currentTarget;
        btn.disabled = true;
        try {
            const r = await api('objects.account_test', { id: Number(a.id) });
            toast('连接成功，账号下有 ' + r.count + ' 个桶');
        } catch (err) {
            toast(err.message, 'err');
        } finally {
            btn.disabled = false;
        }
    });

    el.select?.addEventListener('change', () => {
        state.account = Number(el.select.value) || 0;
        state.bucket = '';
        state.prefix = '';
        renderAccounts();
        if (typeof window.__objectsLoadBuckets === 'function') window.__objectsLoadBuckets();
    });

    /* 后续任务在这里挂载：桶列表、对象列表、上传、设置 */
    window.__objects = { state, cfg, el, account, updateQuery, renderAccounts };

    renderAccounts();

    // 首屏加载：函数由后续任务定义；这里只负责在定义就绪后调用一次
    if (state.account && state.bucket && typeof window.__objectsEnterBucket === 'function') {
        window.__objectsEnterBucket();
    } else if (state.account && typeof window.__objectsLoadBuckets === 'function') {
        window.__objectsLoadBuckets();
    }
})();
```

- [ ] **Step 7: 加样式**

Append to `assets/css/console.css`:

```css
/* ── 对象存储 ── */
.objdetail__head {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 16px 0 14px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 18px;
}

.objdetail__title {
    font-size: 1.1rem;
    font-weight: 600;
    letter-spacing: -0.02em;
    font-family: var(--font-mono);
}

.objdetail__acts {
    display: flex;
    gap: 8px;
    margin-left: auto;
    flex-wrap: wrap;
}

.objbar {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.objcrumb {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    min-width: 0;
    font-family: var(--font-mono);
    font-size: 0.82rem;
}

.objcrumb button {
    border: 0;
    background: none;
    color: var(--dim-2);
    cursor: pointer;
    padding: 2px 4px;
    font: inherit;
}

.objcrumb button:hover {
    color: var(--ink);
}

.objbar__tools {
    display: flex;
    gap: 8px;
    margin-left: auto;
    flex-wrap: wrap;
}

.objbar__tools .minput {
    width: 220px;
}

.objsel {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    margin-bottom: 12px;
    border: 1px solid var(--line);
    border-radius: 3px;
    background: var(--panel);
    font-size: 0.8rem;
    color: var(--dim);
}

.objsel button {
    margin-left: auto;
}

.objgrid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 12px;
}

.objcard {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 16px;
    border: 1px solid var(--line);
    border-radius: 3px;
    background: var(--panel);
    cursor: pointer;
    text-align: left;
    color: inherit;
    font: inherit;
    transition: border-color 0.18s ease, transform 0.18s ease;
}

.objcard:hover {
    border-color: var(--signal);
    transform: translateY(-1px);
}

.objcard__name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: var(--font-mono);
    font-size: 0.86rem;
    word-break: break-all;
}

.objcard__meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 0.74rem;
    color: var(--dim-2);
}

.objrow__folder {
    color: var(--signal);
}

.objsort {
    border: 0;
    background: none;
    color: inherit;
    font: inherit;
    padding: 0;
    cursor: pointer;
}

.objsort:hover {
    color: var(--signal);
}

.objrow__check {
    width: 16px;
    height: 16px;
    accent-color: var(--signal);
}

.objsettings {
    display: grid;
    gap: 16px;
}

.objset {
    border: 1px solid var(--line);
    border-radius: 3px;
    background: var(--panel);
}

.objset__head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--line);
}

.objset__head h3 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
}

.objset__head .panel__note {
    margin-left: auto;
}

.objset__body {
    padding: 14px 16px;
}

.objset__body .minput,
.objset__body .select,
.objset__body textarea {
    width: 100%;
}

.objset__body textarea {
    min-height: 140px;
    font-family: var(--font-mono);
    font-size: 0.76rem;
}

@media (max-width: 720px) {
    .objbar__tools,
    .objbar__tools .minput {
        width: 100%;
        margin-left: 0;
    }
}
```

- [ ] **Step 8: 运行测试并提交**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

```bash
git add app/lib/nav.php app/modules/objects.php app/views/objects.php \
        assets/js/objects.js assets/css/console.css tests/t23_objects.php
git commit -m "feat: 对象存储页面骨架与账号管理"
```

---

### Task 6: 桶接口与桶概览 UI

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces routes:
  - `objects.buckets`（GET）
  - `objects.bucket_create` / `objects.bucket_delete` / `objects.bucket_purge` / `objects.bucket_bind`（POST）
- JS：`window.__objectsLoadBuckets()` 被页面初始化调用

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 桶接口\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_buckets'), '桶列表接口存在');
check(str_contains($module, 'function objects_bucket_bind'), '绑定接口存在');
check(str_contains($module, 'function objects_assert_mutable'), '写操作有绑定守卫');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, '__objectsLoadBuckets'), '前端加载桶列表');
check(str_contains($js, 'objects.bucket_create'), '前端可建桶');
check(str_contains($js, 'objects.bucket_bind'), '前端可绑定桶');
check(str_contains($js, 'objects.bucket_delete'), '前端可删桶');
check(str_contains($js, 'objects.bucket_purge'), '前端可清空桶');
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL。

- [ ] **Step 3: 实现桶接口**

Append to `app/modules/objects.php`:

```php
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
```

- [ ] **Step 4: 实现桶概览 UI**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 桶概览 */

    async function loadBuckets() {
        if (!state.account) return;
        el.grid.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取桶列表…</p></div>';
        try {
            const data = await api('objects.buckets', { account: state.account }, { method: 'GET' });
            renderBuckets(data.buckets || []);
        } catch (err) {
            // 子账号可能没有 ListBuckets 权限；给一条直接按桶名进入的路
            el.grid.innerHTML = '<div class="blank">' + icon('alert-triangle')
                + '<p class="blank__title">读不到桶列表</p>'
                + '<p class="blank__hint">' + escapeHtml(err.message) + '</p>'
                + '<div class="objbar" style="justify-content:center;margin-top:12px">'
                + '<input class="minput" data-manual-bucket placeholder="直接输入桶名，例如 photos-1250000000">'
                + '<button class="chipbtn" type="button" data-manual-enter>直接进入</button>'
                + '</div></div>';
        }
    }
    window.__objectsLoadBuckets = loadBuckets;

    function renderBuckets(buckets) {
        if (!buckets.length) {
            el.grid.innerHTML = '<div class="blank">' + icon('database')
                + '<p class="blank__title">这个账号下还没有桶</p>'
                + '<p class="blank__hint">点右上角「新建桶」建一个，建完就能绑定为存储源。</p></div>';
            return;
        }
        el.grid.innerHTML = '<div class="objgrid">' + buckets.map((b) => {
            const name = escapeHtml(b.name);
            const bound = b.bound
                ? '<span class="pill">' + escapeHtml(b.boundBy) + '</span>'
                : '<span class="muted">未绑定</span>';
            return '<button class="objcard" type="button" data-bucket-open="' + name + '">'
                + '<span class="objcard__name">' + icon('database') + '<span>' + name + '</span></span>'
                + '<span class="objcard__meta">'
                + '<span>' + escapeHtml(b.region || '—') + '</span>'
                + '<span>' + escapeHtml(b.created ? b.created.slice(0, 10) : '—') + '</span>'
                + '</span><span class="objcard__meta">' + bound + '</span></button>';
        }).join('') + '</div>';
    }

    el.grid.addEventListener('click', (e) => {
        const manual = e.target.closest('[data-manual-enter]');
        if (manual) {
            const input = el.grid.querySelector('[data-manual-bucket]');
            const name = (input?.value || '').trim().toLowerCase();
            if (!name) { toast('请输入桶名', 'err'); return; }
            state.bucket = name;
            state.prefix = '';
            state.cursor = '';
            state.history = [];
            state.selected.clear();
            renderAccounts();
            window.__objectsEnterBucket();
            return;
        }

        const card = e.target.closest('[data-bucket-open]');
        if (card) {
            state.bucket = card.dataset.bucketOpen;
            state.prefix = '';
            state.cursor = '';
            state.history = [];
            state.selected.clear();
            renderAccounts();
            if (typeof window.__objectsEnterBucket === 'function') window.__objectsEnterBucket();
        }
    });

    $('[data-bucket-reload]')?.addEventListener('click', loadBuckets);
    $('[data-bucket-new]')?.addEventListener('click', async () => {
        const name = await SY.prompt('新建存储桶', '桶名', '', '小写字母、数字、- 与 .；COS 需带 -APPID');
        if (name === null) return;
        try {
            const r = await api('objects.bucket_create', { account: state.account, bucket: name.trim() });
            toast(r.message || '桶已创建');
            loadBuckets();
        } catch (err) {
            toast(err.message, 'err');
        }
    });
```

Also add the detail header renderer (used by later tasks):

```js
    function renderDetailHead() {
        el.title.textContent = state.bucket;
        const boundBy = state.bound[state.bucket];
        el.detailActs.innerHTML = boundBy
            ? '<span class="pill">' + escapeHtml(boundBy) + '</span>'
            : '<button class="chipbtn" type="button" data-bucket-bind>'
                + icon('link') + '绑定为存储源</button>'
                + '<button class="chipbtn" type="button" data-bucket-purge>清空桶</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-bucket-del>删除桶</button>';
    }
    window.__objectsEnterBucket = function () {
        el.bucketView.hidden = true;
        el.detailView.hidden = false;
        renderDetailHead();
        updateQuery();
        if (typeof window.__objectsLoadObjects === 'function') window.__objectsLoadObjects();
    };
    $('[data-detail-back]')?.addEventListener('click', () => {
        state.bucket = '';
        state.prefix = '';
        state.selected.clear();
        renderAccounts();
        loadBuckets();
    });

    el.detailActs.addEventListener('click', async (e) => {
        if (e.target.closest('[data-bucket-bind]')) {
            try {
                const r = await api('objects.bucket_bind', {
                    account: state.account, bucket: state.bucket, name: state.bucket,
                });
                toast('已绑定为存储源「' + r.name + '」');
                setTimeout(() => location.reload(), 600);
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        if (e.target.closest('[data-bucket-del]')) {
            const typed = await SY.prompt('删除存储桶', '原样输入桶名以确认：' + state.bucket, '', state.bucket);
            if (typed === null) return;
            try {
                const r = await api('objects.bucket_delete', {
                    account: state.account, bucket: state.bucket, confirm: typed.trim(),
                });
                toast(r.message || '桶已删除');
                state.bucket = '';
                state.prefix = '';
                renderAccounts();
                loadBuckets();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        if (e.target.closest('[data-bucket-purge]')) {
            const typed = await SY.prompt('清空存储桶', '原样输入桶名以确认：' + state.bucket, '', state.bucket);
            if (typed === null) return;
            try {
                const r = await api('objects.bucket_purge', {
                    account: state.account, bucket: state.bucket, confirm: typed.trim(),
                });
                toast(r.message + (r.remaining ? '，仍有剩余，可再点一次' : ''));
                loadObjects();
            } catch (err) {
                toast(err.message, 'err');
            }
        }
    });

```

- [ ] **Step 5: 运行测试并提交**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

```bash
git add app/modules/objects.php assets/js/objects.js tests/t23_objects.php
git commit -m "feat: 对象存储桶列表、建删桶与绑定"
```

---

### Task 7: 桶设置 XML 层

**Files:**
- Modify: `app/lib/object_settings.php`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces:
  - `object_xml_escape(string $value): string`
  - `object_versioning_parse(string $xml): string`
  - `object_versioning_xml(string $status): string`
  - `object_website_parse(string $xml): array`
  - `object_website_xml(string $index, string $error): string`
  - `object_cors_parse(string $xml): list<array>`
  - `object_cors_xml(array $rules): string`
  - `object_cors_allows(array $rules, string $origin, array $methods): array`
  - `object_cors_merge_origin(array $rules, string $origin): array`
  - `object_lifecycle_validate(string $xml): void`
  - `object_lifecycle_rules_parse(string $xml): list<array>`
  - `object_lifecycle_xml(array $rules): string`
  - `object_tagging_parse(string $xml): list<array>`
  - `object_tagging_xml(array $tags): string`
  - `object_acl_parse(string $xml): array`

- [ ] **Step 1: 加失败测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 桶设置 XML\n";
check(object_versioning_parse('<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>') === 'Enabled', '解析版本控制');
check(str_contains(object_versioning_xml('Suspended'), '<Status>Suspended</Status>'), '生成版本控制');

$site = object_website_parse('<WebsiteConfiguration><IndexDocument><Suffix>index.html</Suffix></IndexDocument></WebsiteConfiguration>');
check($site['enabled'] === true && $site['index'] === 'index.html', '解析静态网站');
check(str_contains(object_website_xml('index.html', '404.html'), '<Key>404.html</Key>'), '生成静态网站');

$corsXml = object_cors_xml([[
    'id' => 'site', 'origins' => ['http://localhost:15244'], 'methods' => ['GET', 'PUT'],
    'headers' => ['*'], 'expose' => ['ETag'], 'max_age' => 600,
]]);
$rules = object_cors_parse($corsXml);
check(count($rules) === 1 && $rules[0]['origins'][0] === 'http://localhost:15244', 'CORS 往返');
check(object_cors_allows($rules, 'http://localhost:15244', ['GET', 'PUT']) === [true, []], 'CORS 放行判断');
[$ok, $issues] = object_cors_allows($rules, 'https://evil.example', ['GET']);
check($ok === false && $issues !== [], 'CORS 拒绝陌生来源');

$merged = object_cors_merge_origin($rules, 'https://shiyun.example');
[$ok2] = object_cors_allows($merged, 'https://shiyun.example', ['GET', 'PUT', 'HEAD']);
check($ok2 === true && count($merged) === 2, '一键允许本站会追加规则并保留原规则');

$life = object_lifecycle_xml([[
    'id' => 'cold', 'prefix' => 'cold/', 'expire_days' => 365,
    'transition_days' => 30, 'transition_class' => 'ARCHIVE', 'abort_days' => 7,
]]);
object_lifecycle_validate($life);
$parsed = object_lifecycle_rules_parse($life);
check($parsed[0]['expire_days'] === 365 && $parsed[0]['transition_class'] === 'ARCHIVE', '生命周期往返');

$badXml = false;
try { object_lifecycle_validate('<NotLifecycle/>'); } catch (InvalidArgumentException $e) { $badXml = true; }
check($badXml, '非法生命周期根节点被拒');

$tags = object_tagging_parse(object_tagging_xml([['key' => 'env', 'value' => 'prod']]));
check($tags[0]['key'] === 'env' && $tags[0]['value'] === 'prod', '标签往返');

$acl = object_acl_parse(
    '<AccessControlPolicy><AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group">'
    . '<URI>http://acs.amazonaws.com/groups/global/AllUsers</URI></Grantee><Permission>READ</Permission></Grant></AccessControlList></AccessControlPolicy>'
);
check($acl['canned'] === 'public-read', '从 ACL 推断 public-read');
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL（函数不存在）。

- [ ] **Step 3: 实现**

Replace the placeholder `app/lib/object_settings.php` with the implementation from the spec §5.9 / §6.5. Use this exact code:

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   桶设置 XML 的解析与生成。
   所有用户输入写进 XML 前都经过 ENT_XML1 转义；解析一律 LIBXML_NONET。
   ========================================================================= */

function object_xml_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function object_xml_doc(string $xml): ?DOMDocument
{
    if (trim($xml) === '' || !class_exists('DOMDocument')) {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

function object_xml_text(DOMElement $node, string $name): string
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return trim($child->textContent);
        }
    }
    return '';
}

function object_xml_child(DOMElement $node, string $name): ?DOMElement
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return $child;
        }
    }
    return null;
}

function object_versioning_parse(string $xml): string
{
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return '';
    }
    $status = object_xml_text($doc->documentElement, 'Status');
    return in_array($status, ['Enabled', 'Suspended'], true) ? $status : '';
}

function object_versioning_xml(string $status): string
{
    if (!in_array($status, ['Enabled', 'Suspended'], true)) {
        throw new InvalidArgumentException('版本控制状态不合法。');
    }
    return '<?xml version="1.0" encoding="UTF-8"?><VersioningConfiguration><Status>'
        . $status . '</Status></VersioningConfiguration>';
}

function object_website_parse(string $xml): array
{
    $out = ['enabled' => false, 'index' => '', 'error' => ''];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $out;
    }
    $out['enabled'] = true;
    $index = object_xml_child($doc->documentElement, 'IndexDocument');
    if ($index !== null) {
        $out['index'] = object_xml_text($index, 'Suffix');
    }
    $error = object_xml_child($doc->documentElement, 'ErrorDocument');
    if ($error !== null) {
        $out['error'] = object_xml_text($error, 'Key');
    }
    return $out;
}

function object_website_xml(string $index, string $error): string
{
    $index = trim($index);
    if ($index === '') {
        throw new InvalidArgumentException('索引文档不能为空。');
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><WebsiteConfiguration>'
        . '<IndexDocument><Suffix>' . object_xml_escape($index) . '</Suffix></IndexDocument>';
    if (trim($error) !== '') {
        $xml .= '<ErrorDocument><Key>' . object_xml_escape(trim($error)) . '</Key></ErrorDocument>';
    }
    return $xml . '</WebsiteConfiguration>';
}

function object_cors_parse(string $xml): array
{
    $rules = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $rules;
    }
    foreach ($doc->getElementsByTagName('CORSRule') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $rule = ['id' => '', 'origins' => [], 'methods' => [], 'headers' => [], 'expose' => [], 'max_age' => 0];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $value = trim($child->textContent);
            switch ($child->nodeName) {
                case 'ID': $rule['id'] = $value; break;
                case 'AllowedOrigin': $rule['origins'][] = $value; break;
                case 'AllowedMethod': $rule['methods'][] = strtoupper($value); break;
                case 'AllowedHeader': $rule['headers'][] = $value; break;
                case 'ExposeHeader': $rule['expose'][] = $value; break;
                case 'MaxAgeSeconds': $rule['max_age'] = (int) $value; break;
            }
        }
        $rules[] = $rule;
    }
    return $rules;
}

function object_cors_xml(array $rules): string
{
    if ($rules === []) {
        throw new InvalidArgumentException('至少需要一条 CORS 规则。');
    }
    $allowed = ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'];
    $xml = '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration>';
    foreach ($rules as $rule) {
        $origins = array_values(array_filter(array_map('trim', (array) ($rule['origins'] ?? []))));
        $methods = array_values(array_unique(array_map('strtoupper', (array) ($rule['methods'] ?? []))));
        if ($origins === [] || $methods === []) {
            throw new InvalidArgumentException('CORS 规则的来源与方法不能为空。');
        }
        foreach ($methods as $method) {
            if (!in_array($method, $allowed, true)) {
                throw new InvalidArgumentException('CORS 方法不合法：' . $method);
            }
        }
        $xml .= '<CORSRule>';
        $id = trim((string) ($rule['id'] ?? ''));
        if ($id !== '') {
            $xml .= '<ID>' . object_xml_escape($id) . '</ID>';
        }
        foreach ($origins as $origin) {
            $xml .= '<AllowedOrigin>' . object_xml_escape($origin) . '</AllowedOrigin>';
        }
        foreach ($methods as $method) {
            $xml .= '<AllowedMethod>' . $method . '</AllowedMethod>';
        }
        foreach ((array) ($rule['headers'] ?? []) as $header) {
            if (trim((string) $header) !== '') {
                $xml .= '<AllowedHeader>' . object_xml_escape((string) $header) . '</AllowedHeader>';
            }
        }
        foreach ((array) ($rule['expose'] ?? []) as $header) {
            if (trim((string) $header) !== '') {
                $xml .= '<ExposeHeader>' . object_xml_escape((string) $header) . '</ExposeHeader>';
            }
        }
        $maxAge = (int) ($rule['max_age'] ?? 0);
        if ($maxAge > 0) {
            $xml .= '<MaxAgeSeconds>' . $maxAge . '</MaxAgeSeconds>';
        }
        $xml .= '</CORSRule>';
    }
    return $xml . '</CORSConfiguration>';
}

/** @return array{0:bool,1:list<string>} */
function object_cors_allows(array $rules, string $origin, array $methods): array
{
    $origin = strtolower($origin);
    $methods = array_map('strtoupper', $methods);
    $needExpose = in_array('PUT', $methods, true) ? ['etag'] : [];
    $lastIssue = '未找到放行本站源 ' . $origin . ' 的规则';

    foreach ($rules as $rule) {
        $origins = array_map('strtolower', (array) ($rule['origins'] ?? []));
        $originOk = in_array('*', $origins, true) || in_array($origin, $origins, true);
        if (!$originOk) {
            foreach ($origins as $allowed) {
                if (str_contains($allowed, '*') && fnmatch($allowed, $origin)) {
                    $originOk = true;
                    break;
                }
            }
        }
        if (!$originOk) {
            continue;
        }
        $have = array_map('strtoupper', (array) ($rule['methods'] ?? []));
        $missing = array_values(array_diff($methods, $have));
        $expose = array_map('strtolower', (array) ($rule['expose'] ?? []));
        $missingExpose = array_values(array_filter(
            $needExpose,
            static fn (string $e): bool => !in_array($e, $expose, true)
        ));
        if ($missing === [] && $missingExpose === []) {
            return [true, []];
        }
        if ($missing !== []) {
            $lastIssue = '缺少方法：' . implode(', ', $missing);
        } else {
            $lastIssue = '缺少 ExposeHeader: ETag（分片上传需要读取 ETag）';
        }
    }
    return [false, [$lastIssue]];
}

function object_cors_merge_origin(array $rules, string $origin): array
{
    $origin = strtolower(trim($origin));
    foreach ($rules as $i => $rule) {
        $origins = array_map('strtolower', (array) ($rule['origins'] ?? []));
        if (in_array('*', $origins, true) || in_array($origin, $origins, true)) {
            $methods = array_map('strtoupper', (array) ($rule['methods'] ?? []));
            foreach (['GET', 'PUT', 'HEAD'] as $method) {
                if (!in_array($method, $methods, true)) {
                    $methods[] = $method;
                }
            }
            $expose = (array) ($rule['expose'] ?? []);
            if (!in_array('ETag', $expose, true)) {
                $expose[] = 'ETag';
            }
            $rules[$i]['methods'] = $methods;
            $rules[$i]['expose'] = $expose;
            return $rules;
        }
    }
    $rules[] = [
        'id' => 'shiyun-' . substr(hash('sha256', $origin), 0, 8),
        'origins' => [$origin],
        'methods' => ['GET', 'PUT', 'HEAD'],
        'headers' => ['*'],
        'expose' => ['ETag'],
        'max_age' => 3600,
    ];
    return $rules;
}

function object_lifecycle_validate(string $xml): void
{
    if (strlen($xml) > 256 * 1024) {
        throw new InvalidArgumentException('生命周期配置超过 256 KB。');
    }
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null
        || $doc->documentElement->nodeName !== 'LifecycleConfiguration') {
        throw new InvalidArgumentException('生命周期 XML 无法解析，根节点必须是 LifecycleConfiguration。');
    }
    if ($doc->getElementsByTagName('Rule')->length > 1000) {
        throw new InvalidArgumentException('生命周期规则不能超过 1000 条。');
    }
}

function object_lifecycle_rules_parse(string $xml): array
{
    $rules = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $rules;
    }
    foreach ($doc->getElementsByTagName('Rule') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $filter = object_xml_child($node, 'Filter');
        $expiration = object_xml_child($node, 'Expiration');
        $transition = object_xml_child($node, 'Transition');
        $abort = object_xml_child($node, 'AbortIncompleteMultipartUpload');
        $rules[] = [
            'id'               => object_xml_text($node, 'ID'),
            'prefix'           => $filter !== null ? object_xml_text($filter, 'Prefix') : object_xml_text($node, 'Prefix'),
            'expire_days'      => $expiration !== null ? (int) object_xml_text($expiration, 'Days') : 0,
            'transition_days'  => $transition !== null ? (int) object_xml_text($transition, 'Days') : 0,
            'transition_class' => $transition !== null ? object_xml_text($transition, 'StorageClass') : '',
            'abort_days'       => $abort !== null ? (int) object_xml_text($abort, 'DaysAfterInitiation') : 0,
        ];
    }
    return $rules;
}

function object_lifecycle_xml(array $rules): string
{
    if ($rules === []) {
        throw new InvalidArgumentException('至少需要一条规则。');
    }
    if (count($rules) > 1000) {
        throw new InvalidArgumentException('生命周期规则不能超过 1000 条。');
    }
    $xml = '<?xml version="1.0" encoding="UTF-8"?><LifecycleConfiguration>';
    $index = 0;
    foreach ($rules as $rule) {
        $index++;
        $id = trim((string) ($rule['id'] ?? '')) ?: 'rule-' . $index;
        $prefix = (string) ($rule['prefix'] ?? '');
        $expire = (int) ($rule['expire_days'] ?? 0);
        $transitionDays = (int) ($rule['transition_days'] ?? 0);
        $transitionClass = strtoupper(trim((string) ($rule['transition_class'] ?? '')));
        $abort = (int) ($rule['abort_days'] ?? 0);

        if ($expire <= 0 && $transitionDays <= 0 && $abort <= 0) {
            throw new InvalidArgumentException('规则至少要有过期、转存储或清理分片中的一项。');
        }
        if ($transitionDays > 0 && !isset(object_storage_classes()[$transitionClass])) {
            throw new InvalidArgumentException('转存储类型不合法。');
        }

        $xml .= '<Rule><ID>' . object_xml_escape($id) . '</ID>'
            . '<Filter><Prefix>' . object_xml_escape($prefix) . '</Prefix></Filter>'
            . '<Status>Enabled</Status>';
        if ($transitionDays > 0) {
            $xml .= '<Transition><Days>' . $transitionDays . '</Days><StorageClass>'
                . $transitionClass . '</StorageClass></Transition>';
        }
        if ($expire > 0) {
            $xml .= '<Expiration><Days>' . $expire . '</Days></Expiration>';
        }
        if ($abort > 0) {
            $xml .= '<AbortIncompleteMultipartUpload><DaysAfterInitiation>'
                . $abort . '</DaysAfterInitiation></AbortIncompleteMultipartUpload>';
        }
        $xml .= '</Rule>';
    }
    return $xml . '</LifecycleConfiguration>';
}

function object_tagging_parse(string $xml): array
{
    $tags = [];
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return $tags;
    }
    foreach ($doc->getElementsByTagName('Tag') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $tags[] = ['key' => object_xml_text($node, 'Key'), 'value' => object_xml_text($node, 'Value')];
    }
    return $tags;
}

function object_tagging_xml(array $tags): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><Tagging><TagSet>';
    foreach ($tags as $tag) {
        $key = trim((string) ($tag['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $xml .= '<Tag><Key>' . object_xml_escape($key) . '</Key><Value>'
            . object_xml_escape((string) ($tag['value'] ?? '')) . '</Value></Tag>';
    }
    return $xml . '</TagSet></Tagging>';
}

function object_acl_parse(string $xml): array
{
    $grants = [];
    $canned = 'private';
    $doc = object_xml_doc($xml);
    if ($doc === null || $doc->documentElement === null) {
        return ['canned' => $canned, 'grants' => $grants];
    }
    foreach ($doc->getElementsByTagName('Grant') as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $grantee = object_xml_child($node, 'Grantee');
        $name = '';
        $type = 'CanonicalUser';
        if ($grantee !== null) {
            $type = $grantee->getAttribute('xsi:type') ?: 'CanonicalUser';
            $name = object_xml_text($grantee, 'DisplayName')
                ?: object_xml_text($grantee, 'URI')
                ?: object_xml_text($grantee, 'ID');
        }
        $permission = object_xml_text($node, 'Permission');
        $grants[] = ['type' => $type, 'name' => $name, 'permission' => $permission];
        if (str_contains($name, 'AllUsers') && in_array($permission, ['READ', 'FULL_CONTROL'], true)) {
            $canned = 'public-read';
        }
    }
    return ['canned' => $canned, 'grants' => $grants];
}
```

- [ ] **Step 4: 运行测试并提交**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

```bash
git add app/lib/object_settings.php tests/t23_objects.php
git commit -m "feat: 桶设置 XML 解析与生成"
```

---

### Task 8: 对象列表接口与浏览器 UI

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces route `objects.list`（GET）
- JS functions: `window.__objectsLoadObjects()`

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 对象列表\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_list'), '对象列表接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.list'), '前端请求对象列表');
check(str_contains($js, 'data-obj-check'), '对象行有复选框');
```

- [ ] **Step 2: 实现接口**

Append to `app/modules/objects.php`:

```php
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

    try {
        $page = s3_list_objects_page(object_account_config($account), $bucket, [
            'prefix'             => $prefix,
            'delimiter'          => $delimiter,
            'continuation_token' => (string) input('cursor', ''),
            'max_keys'           => min(1000, max(1, input_int('max_keys', 100))),
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
```

- [ ] **Step 3: 实现浏览器 UI**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 对象列表 */

    function parentPrefix(prefix) {
        const trimmed = prefix.replace(/\/$/, '');
        const pos = trimmed.lastIndexOf('/');
        return pos === -1 ? '' : trimmed.slice(0, pos + 1);
    }

    function renderCrumb() {
        const parts = state.prefix.split('/').filter(Boolean);
        let acc = '';
        const items = ['<button type="button" data-crumb-to="">' + escapeHtml(state.bucket) + '</button>'];
        parts.forEach((part) => {
            acc += part + '/';
            items.push('<span>/</span><button type="button" data-crumb-to="' + escapeHtml(acc) + '">'
                + escapeHtml(part) + '</button>');
        });
        el.crumb.innerHTML = items.join('');
    }

    async function loadObjects() {
        if (!state.account || !state.bucket) return;
        renderCrumb();
        el.objHost.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取对象…</p></div>';
        state.selected.clear();
        updateSelection();
        const params = {
            account: state.account,
            bucket: state.bucket,
            prefix: state.prefix,
            q: state.query,
            cursor: state.cursor,
            max_keys: 100,
        };
        try {
            const data = await api('objects.list', params, { method: 'GET' });
            state.objects = data.objects || [];
            state.lastData = data;
            renderObjects(data);
        } catch (err) {
            el.objHost.innerHTML = '<div class="blank">' + icon('alert-triangle')
                + '<p class="blank__title">读不到对象</p>'
                + '<p class="blank__hint">' + escapeHtml(err.message) + '</p></div>';
        }
    }
    window.__objectsLoadObjects = loadObjects;

    function iconFor(key) {
        const ext = (key.split('.').pop() || '').toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'file-image';
        if (['mp4', 'mov', 'mkv', 'webm'].includes(ext)) return 'file-video';
        if (['mp3', 'wav', 'flac', 'm4a'].includes(ext)) return 'file-audio';
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'file-archive';
        if (['js', 'ts', 'php', 'py', 'go', 'java', 'json', 'xml'].includes(ext)) return 'file-code';
        return 'file';
    }

    function renderObjects(data) {
        const rows = [];
        const objects = (data.objects || []).slice().sort((a, b) => {
            const pick = (o) => state.sortKey === 'size'
                ? Number(o.size)
                : (state.sortKey === 'modified' ? String(o.modified || '') : String(o.name || ''));
            const av = pick(a);
            const bv = pick(b);
            if (av < bv) return -state.sortDir;
            if (av > bv) return state.sortDir;
            return 0;
        });
        (data.folders || []).forEach((f) => {
            rows.push('<tr data-folder="' + escapeHtml(f.prefix) + '">'
                + '<td><input class="objrow__check" type="checkbox" disabled></td>'
                + '<td><div class="tbl__name objrow__folder">' + icon('folder')
                + '<span>' + escapeHtml(f.name) + '/</span></div></td>'
                + '<td class="muted">—</td><td class="muted">—</td><td></td></tr>');
        });
        objects.forEach((o) => {
            const key = escapeHtml(o.key);
            const isFolder = o.key.endsWith('/') && Number(o.size) === 0;
            rows.push('<tr data-key="' + key + '">'
                + '<td><input class="objrow__check" type="checkbox" data-obj-check value="' + key + '"'
                + (state.selected.has(o.key) ? ' checked' : '') + '></td>'
                + '<td><div class="tbl__name' + (isFolder ? ' objrow__folder' : '') + '">'
                + icon(isFolder ? 'folder' : iconFor(o.key))
                + '<span>' + escapeHtml(o.name) + '</span></div></td>'
                + '<td class="num">' + (isFolder ? '—' : humanSize(o.size)) + '</td>'
                + '<td class="muted nowrap">' + escapeHtml((o.modified || '').slice(0, 16).replace('T', ' ')) + '</td>'
                + '<td><div class="tbl__acts">'
                + '<button class="chipbtn" type="button" data-obj-download="' + key + '">下载</button>'
                + '<button class="chipbtn" type="button" data-obj-copy="' + key + '">复制/移动</button>'
                + '<button class="chipbtn" type="button" data-obj-info="' + key + '">详情</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-obj-del="' + key + '">删除</button>'
                + '</div></td></tr>');
        });

        if (!rows.length) {
            el.objHost.innerHTML = '<div class="blank">' + icon('archive')
                + '<p class="blank__title">这个前缀下没有对象</p>'
                + '<p class="blank__hint">拖放文件到页面，或点「上传」。</p></div>';
        } else {
            el.objHost.innerHTML = '<div class="tblwrap"><table class="tbl"><thead><tr>'
                + '<th style="width:34px"><input type="checkbox" data-obj-check-all aria-label="全选"></th>'
                + '<th><button class="objsort" type="button" data-sort="name">名称</button></th>'
                + '<th class="num"><button class="objsort" type="button" data-sort="size">大小</button></th>'
                + '<th><button class="objsort" type="button" data-sort="modified">更新时间</button></th>'
                + '<th>操作</th>'
                + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>'
                + '<div class="objbar" style="margin-top:14px">'
                + '<span class="muted">' + (data.truncated ? '还有更多，可翻页' : '已到末页') + '</span>'
                + '<div class="objbar__tools">'
                + '<button class="chipbtn" type="button" data-obj-prev' + (state.history.length ? '' : ' disabled') + '>上一页</button>'
                + '<button class="chipbtn" type="button" data-obj-next' + (data.truncated && data.next_token ? '' : ' disabled') + '>下一页</button>'
                + '</div></div>';
        }
        state.nextToken = data.next_token || '';
        state.truncated = !!data.truncated;
    }

    function updateSelection() {
        const count = state.selected.size;
        el.selection?.toggleAttribute('hidden', count === 0);
        if (el.selectedCount) el.selectedCount.textContent = '已选 ' + count + ' 项';
    }

    el.objHost.addEventListener('change', (e) => {
        const all = e.target.closest('[data-obj-check-all]');
        if (all) {
            $$('[data-obj-check]', el.objHost).forEach((box) => {
                box.checked = all.checked;
                if (all.checked) state.selected.add(box.value); else state.selected.delete(box.value);
            });
            updateSelection();
            return;
        }
        const box = e.target.closest('[data-obj-check]');
        if (box) {
            if (box.checked) state.selected.add(box.value); else state.selected.delete(box.value);
            updateSelection();
        }
    });

    el.objHost.addEventListener('click', (e) => {
        const sort = e.target.closest('[data-sort]');
        if (sort) {
            const key = sort.dataset.sort;
            state.sortDir = state.sortKey === key ? -state.sortDir : 1;
            state.sortKey = key;
            if (state.lastData) renderObjects(state.lastData);
            return;
        }

        const folder = e.target.closest('[data-folder]');
        if (folder) {
            state.prefix = folder.dataset.folder;
            state.query = '';
            state.cursor = '';
            state.history = [];
            if (el.search) el.search.value = '';
            loadObjects();
            updateQuery();
            return;
        }
        const next = e.target.closest('[data-obj-next]');
        if (next && state.nextToken) {
            state.history.push(state.cursor);
            state.cursor = state.nextToken;
            loadObjects();
            return;
        }
        const prev = e.target.closest('[data-obj-prev]');
        if (prev && state.history.length) {
            state.cursor = state.history.pop();
            loadObjects();
        }
    });

    el.crumb.addEventListener('click', (e) => {
        const to = e.target.closest('[data-crumb-to]');
        if (to) {
            state.prefix = to.dataset.crumbTo === state.bucket ? '' : to.dataset.crumbTo;
            state.query = '';
            state.cursor = '';
            state.history = [];
            if (el.search) el.search.value = '';
            loadObjects();
            updateQuery();
        }
    });

    el.search?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        state.query = el.search.value.trim();
        state.cursor = '';
        state.history = [];
        loadObjects();
    });
```

- [ ] **Step 4: 运行测试并提交**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

```bash
git add app/modules/objects.php assets/js/objects.js tests/t23_objects.php
git commit -m "feat: 对象列表浏览、面包屑、翻页与搜索"
```

---

### Task 9: 上传（单次 + 分片）与 CORS 预检

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Modify: `tests/stub_s3.php`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces routes:
  - `objects.upload_sign`（POST）
  - `objects.upload_init`（POST）
  - `objects.upload_part`（POST）
  - `objects.upload_complete`（POST）
  - `objects.upload_abort`（POST）
  - `objects.cors_check`（GET）
  - `objects.cors_allow`（POST）
- Adds `s3_multipart_*` helpers to `storage_drivers.php`.

- [ ] **Step 1: 加失败测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 预签名与分片\n";
$sign = s3_presign_url($cfg, 'PUT', 'photos/a b.jpg', 99999);
check(str_contains($sign, 'X-Amz-Expires=3600'), 'TTL 上限被钳制到 3600');
check(str_contains($sign, 'photos/a%20b.jpg'), '预签名路径正确编码');
$short = s3_presign_url($cfg, 'GET', 'a.txt', 5);
check(str_contains($short, 'X-Amz-Expires=60'), 'TTL 下限被抬到 60');

$multi = s3_multipart_part_url($cfg, 'photos-1250000000', 'big.bin', 'uid+/=', 7, 600);
check(str_contains($multi, 'partNumber=7'), '分片 URL 带 partNumber');
check(str_contains($multi, 'uploadId=uid%2B%2F%3D'), '分片 URL 编码 uploadId');

$uploadId = s3_multipart_init($cfg, 'photos-1250000000', 'big.bin', 'STANDARD_IA', 'application/octet-stream');
check($uploadId === 'stub-upload-1', '初始化分片解析 UploadId');
$done = s3_multipart_complete($cfg, 'photos-1250000000', 'big.bin', $uploadId, [
    ['part_number' => 1, 'etag' => '"etag-1"'],
]);
check($done['ok'] === true, '完成分片上传');
s3_multipart_abort($cfg, 'photos-1250000000', 'big.bin', $uploadId);
check(true, '放弃分片不抛异常');
```

同时扩展 `tests/stub_s3.php`，在桶级 `PUT` / `POST` / `DELETE` 分支之前加入：

```php
if ($method === 'POST' && str_contains($query, 'uploads')) {
    echo '<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult>'
        . '<Bucket>' . htmlspecialchars($bucket, ENT_XML1) . '</Bucket>'
        . '<Key>big.bin</Key><UploadId>stub-upload-1</UploadId>'
        . '</InitiateMultipartUploadResult>';
    exit;
}
if ($method === 'PUT' && str_contains($query, 'partNumber=') && str_contains($query, 'uploadId=')) {
    preg_match('/partNumber=(\d+)/', $query, $m);
    header('ETag: "part-' . ($m[1] ?? '1') . '"');
    http_response_code(200);
    exit;
}
if ($method === 'POST' && str_contains($query, 'uploadId=')) {
    echo '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult>'
        . '<Location>http://localhost/stub</Location><Bucket>' . htmlspecialchars($bucket, ENT_XML1) . '</Bucket>'
        . '<Key>big.bin</Key><ETag>"etag-complete"</ETag></CompleteMultipartUploadResult>';
    exit;
}
if ($method === 'DELETE' && str_contains($query, 'uploadId=')) {
    http_response_code(204);
    exit;
}
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: FAIL `Call to undefined function s3_multipart_part_url()`。

- [ ] **Step 3: 增加 Multipart 协议**

Append to `app/lib/storage_drivers.php`:

```php
/* ------------------------------------------------------------ Multipart */

function s3_multipart_init(
    array $config,
    string $bucket,
    string $key,
    ?string $storageClass = null,
    ?string $contentType = null
): string {
    $headers = [];
    if ($storageClass !== null && $storageClass !== '') {
        $headers['x-amz-storage-class'] = $storageClass;
    }
    if ($contentType !== null && $contentType !== '') {
        $headers['content-type'] = $contentType;
    }
    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['uploads' => ''],
        'headers'      => $headers,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '初始化分片上传失败'));
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML((string) $res['body'], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        throw new RuntimeException('无法解析分片上传响应。');
    }
    $nodes = $doc->getElementsByTagName('UploadId');
    if ($nodes->length === 0) {
        throw new RuntimeException('分片上传响应缺少 UploadId。');
    }
    return $nodes->item(0)->textContent;
}

function s3_multipart_part_url(array $config, string $bucket, string $key, string $uploadId, int $partNumber, int $ttl = 3600): string
{
    $partNumber = max(1, min(10000, $partNumber));
    return s3_presign_url(
        s3_bucket_config($config, $bucket),
        'PUT',
        $key,
        $ttl,
        ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId]
    );
}

function s3_multipart_complete(array $config, string $bucket, string $key, string $uploadId, array $parts): array
{
    $xml = '<CompleteMultipartUpload>';
    foreach ($parts as $part) {
        $number = (int) ($part['part_number'] ?? 0);
        $etag = (string) ($part['etag'] ?? '');
        if ($number < 1 || $number > 10000 || $etag === '') {
            throw new InvalidArgumentException('分片编号或 ETag 不合法。');
        }
        $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>'
            . object_xml_escape($etag) . '</ETag></Part>';
    }
    $xml .= '</CompleteMultipartUpload>';

    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['uploadId' => $uploadId],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
        'timeout'      => 60,
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '完成分片上传失败')];
    }
    return ['ok' => true, 'message' => '上传完成。'];
}

function s3_multipart_abort(array $config, string $bucket, string $key, string $uploadId): void
{
    s3_request(s3_bucket_config($config, $bucket), 'DELETE', $key, [
        'query'        => ['uploadId' => $uploadId],
        'payload_hash' => hash('sha256', ''),
    ]);
}
```

Note: `s3_multipart_complete` uses `object_xml_escape()` from `object_settings.php`, which is loaded in bootstrap. Fine.

- [ ] **Step 4: 实现上传与 CORS 接口**

Append to `app/modules/objects.php`:

```php
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
```

Note: `s3_error_message()` expects the response array with `status/body`; `s3_get_subresource` returns the same keys, so it works.

- [ ] **Step 5: 实现前端上传**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 上传 */

    const PART_THRESHOLD = 64 * 1024 * 1024;

    function xhrPut(url, body, headers, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url, true);
            Object.entries(headers || {}).forEach(([k, v]) => xhr.setRequestHeader(k, v));
            xhr.upload.onprogress = (e) => {
                if (onProgress && e.lengthComputable) onProgress(e.loaded, e.total);
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.getResponseHeader('ETag') || '');
                } else {
                    reject(new Error('上传失败（HTTP ' + xhr.status + '）'));
                }
            };
            xhr.onerror = () => reject(new Error('网络错误或 CORS 拒绝'));
            xhr.send(body);
        });
    }

    async function ensureCors() {
        const data = await api('objects.cors_check', { account: state.account, bucket: state.bucket }, { method: 'GET' });
        if (data.ok) return true;
        const allow = await SY.confirm(
            '桶的 CORS 未放行本站',
            (data.issues || []).join('；') + '。是否把 ' + data.origin + ' 加入该桶的 CORS？',
            '一键允许本站',
            false
        );
        if (!allow) return false;
        await api('objects.cors_allow', { account: state.account, bucket: state.bucket });
        toast('已写入 CORS 规则');
        return true;
    }

    function selectedStorageClass() {
        return $('[data-upload-class]')?.value || '';
    }

    async function uploadSingle(file, key, progress) {
        const sign = await api('objects.upload_sign', {
            account: state.account, bucket: state.bucket, key: key,
            storage_class: selectedStorageClass(),
            content_type: file.type || 'application/octet-stream',
        });
        await xhrPut(sign.url, file, sign.headers, progress);
    }

    async function uploadMultipart(file, key, progress) {
        const init = await api('objects.upload_init', {
            account: state.account, bucket: state.bucket, key: key, size: file.size,
            storage_class: selectedStorageClass(),
            content_type: file.type || 'application/octet-stream',
        });
        const partSize = Number(init.part_size) || 8 * 1024 * 1024;
        const total = Math.ceil(file.size / partSize) || 1;
        const parts = new Array(total);
        let doneBytes = 0;
        let next = 1;

        async function worker() {
            while (next <= total) {
                const partNumber = next++;
                const start = (partNumber - 1) * partSize;
                const blob = file.slice(start, Math.min(start + partSize, file.size));
                let etag = '';
                let attempt = 0;
                while (!etag && attempt < 3) {
                    attempt++;
                    try {
                        const sign = await api('objects.upload_part', {
                            account: state.account, bucket: state.bucket, key: key,
                            upload_id: init.upload_id, part_number: partNumber,
                        });
                        etag = await xhrPut(sign.url, blob, {}, (loaded) => {
                            progress(doneBytes + loaded, file.size);
                        });
                    } catch (err) {
                        if (attempt >= 3) throw err;
                    }
                }
                if (!etag) throw new Error('分片 ' + partNumber + ' 没有返回 ETag');
                parts[partNumber - 1] = { part_number: partNumber, etag: etag };
                doneBytes += blob.size;
                progress(doneBytes, file.size);
            }
        }

        try {
            await Promise.all([worker(), worker(), worker()]);
            await api('objects.upload_complete', {
                account: state.account, bucket: state.bucket, key: key,
                upload_id: init.upload_id, parts: parts.filter(Boolean),
            });
        } catch (err) {
            await api('objects.upload_abort', {
                account: state.account, bucket: state.bucket, key: key, upload_id: init.upload_id,
            }).catch(() => {});
            throw err;
        }
    }

    async function uploadFiles(files) {
        if (!files.length || !state.bucket) return;
        let corsOk = false;
        try {
            corsOk = await ensureCors();
        } catch (err) {
            toast(err.message, 'err');
            return;
        }
        if (!corsOk) return;
        for (const file of Array.from(files)) {
            const key = state.prefix + file.name;
            const note = $('[data-upload-note]');
            if (note) note.textContent = '正在上传 ' + file.name;
            try {
                if (file.size > PART_THRESHOLD) {
                    await uploadMultipart(file, key, (loaded, total) => {
                        if (note) note.textContent = '上传 ' + file.name + ' · ' + Math.round(loaded / total * 100) + '%';
                    });
                } else {
                    await uploadSingle(file, key, (loaded, total) => {
                        if (note) note.textContent = '上传 ' + file.name + ' · ' + Math.round(loaded / total * 100) + '%';
                    });
                }
                toast('已上传 ' + file.name);
            } catch (err) {
                toast(file.name + '：' + err.message, 'err');
            }
        }
        loadObjects();
    }

    $('[data-obj-upload]')?.addEventListener('click', () => el.uploadInput?.click());
    el.uploadInput?.addEventListener('change', () => {
        uploadFiles(el.uploadInput.files);
        el.uploadInput.value = '';
    });

    document.addEventListener('dragover', (e) => {
        if (!state.bucket || !el.detailView || el.detailView.hidden) return;
        e.preventDefault();
    });
    document.addEventListener('drop', (e) => {
        if (!state.bucket || !el.detailView || el.detailView.hidden) return;
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });
```

Add a small progress note to the view near the object bar:

```php
<p class="mhint" data-upload-note></p>
```

- [ ] **Step 6: 运行测试并提交**

Run: `runuser -u www -- php tests/t23_objects.php`
Expected: `全部通过`。

```bash
git add app/lib/storage_drivers.php app/modules/objects.php assets/js/objects.js \
        tests/stub_s3.php tests/t23_objects.php
git commit -m "feat: 对象上传（单次与分片）与 CORS 一键配置"
```

---

### Task 10: 下载、对象详情与解冻

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces routes `objects.head`、`objects.download_sign`、`objects.restore`（POST）

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 下载与详情\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_download_sign'), '下载预签名接口存在');
check(str_contains($module, 'function objects_restore'), '解冻接口存在');
check(str_contains($module, 'restore_required'), '归档对象会要求先解冻');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.download_sign'), '前端请求下载链接');
check(str_contains($js, 'objects.restore'), '前端可触发解冻');
```

- [ ] **Step 2: 实现接口**

Append to `app/modules/objects.php`:

```php
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
```

- [ ] **Step 3: 实现前端**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 下载与详情 */

    async function downloadKey(key) {
        try {
            const r = await api('objects.download_sign', {
                account: state.account, bucket: state.bucket, key: key,
            });
            const a = document.createElement('a');
            a.href = r.url;
            a.target = '_blank';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            a.remove();
        } catch (err) {
            if (err.code === 'restore_required') {
                const days = await SY.prompt('对象在归档存储中', '解冻天数（1–30）', '1');
                if (days === null) return;
                try {
                    const r = await api('objects.restore', {
                        account: state.account, bucket: state.bucket, key: key, days: Number(days) || 1,
                    });
                    toast(r.message);
                } catch (e2) {
                    toast(e2.message, 'err');
                }
                return;
            }
            toast(err.message, 'err');
        }
    }

    async function showInfo(key) {
        try {
            const m = await api('objects.head', {
                account: state.account, bucket: state.bucket, key: key,
            });
            SY.openModal('对象详情 · ' + key,
                '<div class="dupelist">'
                + '<div><span>大小</span><b>' + humanSize(m.size) + '</b></div>'
                + '<div><span>类型</span><b>' + escapeHtml(m.content_type || '—') + '</b></div>'
                + '<div><span>ETag</span><b>' + escapeHtml(m.etag || '—') + '</b></div>'
                + '<div><span>更新时间</span><b>' + escapeHtml(m.last_modified || '—') + '</b></div>'
                + '<div><span>存储类型</span><b>' + escapeHtml(m.storage_class || '—') + '</b></div>'
                + '</div>',
                '<button class="mbtn" type="button" data-x="close">关闭</button>');
            $('[data-modal-foot]').querySelector('[data-x="close"]').addEventListener('click', SY.closeModal);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    el.objHost.addEventListener('click', (e) => {
        const down = e.target.closest('[data-obj-download]');
        if (down) { downloadKey(down.dataset.objDownload); return; }
        const info = e.target.closest('[data-obj-info]');
        if (info) { showInfo(info.dataset.objInfo); }
    });
```

- [ ] **Step 4: 运行测试并提交**

```bash
runuser -u www -- php tests/t23_objects.php
git add app/modules/objects.php assets/js/objects.js tests/t23_objects.php
git commit -m "feat: 对象下载预签名、详情与归档解冻"
```

---

### Task 11: 复制/移动、批量删除、新建文件夹

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces routes `objects.copy`、`objects.delete`、`objects.mkdir`（POST）

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 复制与删除\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_copy'), '复制接口存在');
check(str_contains($module, 'function objects_delete'), '批量删除接口存在');
check(str_contains($module, 'function objects_mkdir'), '新建文件夹接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.copy'), '前端调用复制');
check(str_contains($js, 'objects.delete'), '前端调用批量删除');
check(str_contains($js, 'data-obj-delete-selected'), '前端有删除所选');
```

- [ ] **Step 2: 实现接口**

Append to `app/modules/objects.php`:

```php
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
    if (!$result['ok']) {
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
    if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
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
```

- [ ] **Step 3: 实现前端**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 复制/删除/文件夹 */

    async function copyKey(key) {
        const isMove = await SY.confirm(
            '复制还是移动？',
            '对象：' + key + '。选「移动」会删除源对象；选「取消」则只复制。',
            '移动',
            false
        );
        const target = await SY.prompt('目标对象键', '输入完整目标键', key);
        if (target === null) return;
        try {
            const r = await api('objects.copy', {
                account: state.account, bucket: state.bucket,
                source: key, target: target.trim(), mode: isMove ? 'move' : 'copy',
            });
            toast(r.message);
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function deleteKeys(keys) {
        if (!keys.length) return;
        const ok = await SY.confirm('删除对象', '将删除 ' + keys.length + ' 个对象，此操作不可撤销。', '删除', true);
        if (!ok) return;
        try {
            const r = await api('objects.delete', {
                account: state.account, bucket: state.bucket, keys: keys,
            });
            toast(r.message);
            state.selected.clear();
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    el.objHost.addEventListener('click', (e) => {
        const copy = e.target.closest('[data-obj-copy]');
        if (copy) { copyKey(copy.dataset.objCopy); return; }
        const del = e.target.closest('[data-obj-del]');
        if (del) { deleteKeys([del.dataset.objDel]); }
    });

    $('[data-obj-delete-selected]')?.addEventListener('click', () => deleteKeys(Array.from(state.selected)));

    $('[data-obj-mkdir]')?.addEventListener('click', async () => {
        const name = await SY.prompt('新建文件夹', '文件夹名称', '');
        if (name === null) return;
        try {
            await api('objects.mkdir', {
                account: state.account, bucket: state.bucket,
                prefix: state.prefix, name: name.trim(),
            });
            toast('文件夹已创建');
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    });
```

- [ ] **Step 4: 运行测试并提交**

```bash
runuser -u www -- php tests/t23_objects.php
git add app/modules/objects.php assets/js/objects.js tests/t23_objects.php
git commit -m "feat: 对象复制/移动、批量删除与新建文件夹"
```

---

### Task 12: 设置页接口、统计与 UI

**Files:**
- Modify: `app/modules/objects.php`
- Modify: `assets/js/objects.js`
- Test: `tests/t23_objects.php`

**Interfaces:**
- Produces routes `objects.settings`、`objects.setting_save`（POST）、`objects.stat`（POST）
- JS function `window.__objectsLoadSettings()`

- [ ] **Step 1: 加静态测试**

Append to `tests/t23_objects.php`:

```php
echo "\n· 设置与统计\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_settings'), '设置读取接口存在');
check(str_contains($module, 'function objects_setting_save'), '设置保存接口存在');
check(str_contains($module, 'function objects_stat'), '统计接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.settings'), '前端读取设置');
check(str_contains($js, "data-tab=\"settings\"") || str_contains($js, 'data-tab-panel'), '前端有设置页签');
```

- [ ] **Step 2: 实现接口**

Append to `app/modules/objects.php`:

```php
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
```

- [ ] **Step 3: 实现设置 UI 与统计按钮**

Append to `assets/js/objects.js` (before the final `renderAccounts();`):

```js
    /* ---------------------------------------------------------- 设置 */

    function settingCard(title, note, body) {
        return '<section class="objset"><div class="objset__head"><h3>' + escapeHtml(title) + '</h3>'
            + '<span class="panel__note">' + escapeHtml(note) + '</span></div>'
            + '<div class="objset__body">' + body + '</div></section>';
    }

    async function loadSettings() {
        if (!state.bucket) return;
        el.settingsHost.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取设置…</p></div>';
        const sections = ['versioning', 'website', 'cors', 'lifecycle', 'tagging', 'acl', 'policy'];
        const cards = [];
        for (const section of sections) {
            try {
                const data = await api('objects.settings', {
                    account: state.account, bucket: state.bucket, section: section,
                }, { method: 'GET' });
                cards.push(renderSetting(section, data));
            } catch (err) {
                cards.push(settingCard(section, '', '<p class="mwarn">'
                    + icon('alert-triangle') + '<span>' + escapeHtml(err.message) + '</span></p>'));
            }
        }
        el.settingsHost.innerHTML = cards.join('');
    }
    window.__objectsLoadSettings = loadSettings;

    function renderSetting(section, data) {
        const v = data.value || {};
        if (section === 'versioning') {
            return settingCard('版本控制', '开启后可恢复历史版本',
                '<p class="mhint">当前：' + escapeHtml(v || '从未启用') + '</p>'
                + '<div class="objbar__tools" style="margin-top:10px">'
                + '<button class="chipbtn" type="button" data-set-versioning="Enabled">启用</button>'
                + '<button class="chipbtn" type="button" data-set-versioning="Suspended">暂停</button></div>');
        }
        if (section === 'website') {
            return settingCard('静态网站', '把桶当作静态站点',
                '<label class="mfield"><span class="mfield__label">索引文档</span>'
                + '<input class="minput" data-set="index" value="' + escapeHtml(v.index || 'index.html') + '"></label>'
                + '<label class="mfield" style="margin-top:8px"><span class="mfield__label">错误文档</span>'
                + '<input class="minput" data-set="error" value="' + escapeHtml(v.error || '') + '"></label>'
                + '<button class="chipbtn" type="button" data-set-website="1" style="margin-top:10px">保存</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-set-website-off="1"'
                + ' style="margin-top:10px;margin-left:8px">停用</button>');
        }
        if (section === 'cors') {
            const rules = (v.rules || [])
                .flatMap((r) => r.origins.map((origin) => origin + ', ' + r.methods.join(' ')))
                .join('\n');
            return settingCard('CORS', '浏览器直传需要放行本站',
                '<textarea class="minput" data-set="cors" placeholder="每行一条：来源, GET PUT HEAD">'
                + escapeHtml(rules) + '</textarea>'
                + '<p class="mhint">每行格式：<code>https://example.com, GET PUT HEAD</code></p>'
                + '<button class="chipbtn" type="button" data-set-cors="1" style="margin-top:10px">保存</button>');
        }
        if (section === 'lifecycle') {
            const raw = data.raw || '';
            return settingCard('生命周期', '按时间转存储或删除',
                '<textarea class="minput" data-set="lifecycle">' + escapeHtml(raw) + '</textarea>'
                + '<p class="mhint">直接编辑 LifecycleConfiguration XML；保存会整体替换现有配置。</p>'
                + '<button class="chipbtn" type="button" data-lifecycle-preset style="margin-top:10px">生成常用规则</button>'
                + '<button class="chipbtn" type="button" data-set-lifecycle="1" style="margin-top:10px;margin-left:8px">保存</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-lifecycle-delete'
                + ' style="margin-top:10px;margin-left:8px">删除配置</button>');
        }
        if (section === 'tagging') {
            const tags = (v.tags || []).map((t) => t.key + '=' + t.value).join('\n');
            return settingCard('标签', '键值对',
                '<textarea class="minput" data-set="tags">' + escapeHtml(tags) + '</textarea>'
                + '<p class="mhint">每行一个 <code>key=value</code>。</p>'
                + '<button class="chipbtn" type="button" data-set-tagging="1" style="margin-top:10px">保存</button>');
        }
        if (section === 'acl') {
            const grants = (v.grants || [])
                .map((g) => g.permission + ' · ' + (g.name || g.type))
                .join('\n');
            return settingCard('访问权限', '公开前请确认',
                '<select class="select" data-set="acl">'
                + '<option value="private"' + (v.canned === 'private' ? ' selected' : '') + '>私有</option>'
                + '<option value="public-read"' + (v.canned === 'public-read' ? ' selected' : '') + '>公共读</option>'
                + '</select>'
                + '<button class="chipbtn" type="button" data-set-acl="1" style="margin-top:10px">保存</button>'
                + '<pre class="mhint" style="white-space:pre-wrap;margin-top:10px">'
                + escapeHtml(grants || '无额外授权') + '</pre>');
        }
        const json = data.json ? JSON.stringify(data.json, null, 2) : '未设置策略';
        return settingCard('桶策略', '只读', '<pre class="mhint" style="white-space:pre-wrap">'
            + escapeHtml(json) + '</pre>');
    }

    function escapeXml(value) {
        return String(value).replace(/[<>&'"]/g, (c) => ({
            '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;',
        }[c]));
    }

    el.settingsHost.addEventListener('click', async (e) => {
        const preset = e.target.closest('[data-lifecycle-preset]');
        if (preset) {
            const prefix = await SY.prompt('生命周期规则', '前缀（留空表示全部对象）', '');
            if (prefix === null) return;
            const transitionDays = await SY.prompt('转存储', 'N 天后转归档（0 = 不转）', '30');
            if (transitionDays === null) return;
            const expireDays = await SY.prompt('过期删除', 'N 天后删除（0 = 不删除）', '365');
            if (expireDays === null) return;
            const abortDays = await SY.prompt('清理分片', 'N 天后清理未完成分片（0 = 不清理）', '7');
            if (abortDays === null) return;

            const t = Number(transitionDays) || 0;
            const e2 = Number(expireDays) || 0;
            const a = Number(abortDays) || 0;
            let body = '<ID>shiyun-lifecycle</ID>'
                + '<Filter><Prefix>' + escapeXml(prefix) + '</Prefix></Filter><Status>Enabled</Status>';
            if (t > 0) {
                body += '<Transition><Days>' + t + '</Days><StorageClass>ARCHIVE</StorageClass></Transition>';
            }
            if (e2 > 0) body += '<Expiration><Days>' + e2 + '</Days></Expiration>';
            if (a > 0) {
                body += '<AbortIncompleteMultipartUpload><DaysAfterInitiation>'
                    + a + '</DaysAfterInitiation></AbortIncompleteMultipartUpload>';
            }
            const xml = '<?xml version="1.0" encoding="UTF-8"?><LifecycleConfiguration><Rule>'
                + body + '</Rule></LifecycleConfiguration>';
            const area = preset.closest('.objset__body').querySelector('[data-set="lifecycle"]');
            if (area) area.value = xml;
            return;
        }

        const lifeDel = e.target.closest('[data-lifecycle-delete]');
        if (lifeDel) {
            const ok = await SY.confirm('删除生命周期配置', '这会移除桶上全部生命周期规则。', '删除', true);
            if (!ok) return;
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket,
                    section: 'lifecycle', payload: { mode: 'delete' },
                });
                toast('已删除生命周期配置');
                loadSettings();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        const versioning = e.target.closest('[data-set-versioning]');
        if (versioning) {
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket, section: 'versioning',
                    payload: { status: versioning.dataset.setVersioning },
                });
                toast('已保存');
                loadSettings();
            } catch (err) { toast(err.message, 'err'); }
            return;
        }
        const off = e.target.closest('[data-set-website-off]');
        if (off) {
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket, section: 'website',
                    payload: { enabled: false },
                });
                toast('已停用静态网站');
                loadSettings();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        const save = e.target.closest('[data-set-website], [data-set-cors], [data-set-lifecycle], [data-set-tagging], [data-set-acl]');
        if (!save) return;
        const body = save.closest('.objset__body');
        const val = (name) => body.querySelector('[data-set="' + name + '"]')?.value || '';
        let section = '';
        let payload = {};
        if (save.hasAttribute('data-set-website')) {
            section = 'website';
            payload = { enabled: true, index: val('index'), error: val('error') };
        } else if (save.hasAttribute('data-set-cors')) {
            section = 'cors';
            const rules = val('cors').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
                const [originPart, methodPart] = line.split(',');
                return {
                    origins: [originPart.trim()],
                    methods: (methodPart || 'GET PUT HEAD').trim().split(/\s+/),
                    headers: ['*'], expose: ['ETag'], max_age: 3600,
                };
            });
            payload = { rules: rules };
        } else if (save.hasAttribute('data-set-lifecycle')) {
            section = 'lifecycle';
            payload = { mode: 'xml', xml: val('lifecycle') };
        } else if (save.hasAttribute('data-set-tagging')) {
            section = 'tagging';
            const tags = val('tags').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
                const pos = line.indexOf('=');
                return pos === -1 ? { key: line, value: '' } : { key: line.slice(0, pos), value: line.slice(pos + 1) };
            });
            payload = { tags: tags };
        } else {
            section = 'acl';
            const canned = val('acl');
            if (canned === 'public-read'
                && !(await SY.confirm('设为公共读', '桶内对象将可被任何人读取，确认继续？', '设为公共读', true))) {
                return;
            }
            payload = { canned: canned };
        }
        try {
            await api('objects.setting_save', {
                account: state.account, bucket: state.bucket, section: section, payload: payload,
            });
            toast('已保存');
            loadSettings();
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* 页签切换 */
    $$('[data-tab]').forEach((tab) => tab.addEventListener('click', (e) => {
        e.preventDefault();
        const name = tab.dataset.tab;
        $$('[data-tab]').forEach((t) => t.classList.toggle('is-on', t === tab));
        $$('[data-tab-panel]').forEach((panel) => {
            panel.toggleAttribute('hidden', panel.dataset.tabPanel !== name);
        });
        if (name === 'settings') loadSettings();
    }));

    /* 统计 */
    $('[data-obj-stat]')?.addEventListener('click', async () => {
        try {
            const r = await api('objects.stat', { account: state.account, bucket: state.bucket });
            toast('扫描 ' + r.pages + ' 页：' + r.count + ' 个对象，' + r.bytesText
                + (r.partial ? '（已到上限，结果不完整）' : ''));
        } catch (err) {
            toast(err.message, 'err');
        }
    });
```

Add the upload progress note in `app/views/objects.php` after the object bar:

```php
                    <p class="mhint" data-upload-note></p>
```

- [ ] **Step 4: 运行测试并提交**

```bash
runuser -u www -- php tests/t23_objects.php
git add app/modules/objects.php assets/js/objects.js tests/t23_objects.php
git commit -m "feat: 桶设置、统计与对象存储页收尾"
```

---

### Task 13: 删除旧桶面板、README、全量回归与页面验证

**Files:**
- Modify: `app/modules/sources.php`
- Modify: `app/views/sources.php`
- Modify: `assets/js/console.js`
- Modify: `README.md`
- Test: `tests/t1…t23`

- [ ] **Step 1: 删除旧桶面板**

1. In `app/modules/sources.php` remove everything from the comment `/* ================================================================ 存储桶管理` to the end of the file (functions `sources_bucket_accounts`, `sources_bucket_source`, `sources_bound_buckets`, `sources_buckets`, `sources_bucket_create`, `sources_bucket_delete`, `sources_bucket_bind`, `sources_bucket_assert_unbound`, `sources_bucket_objects`, `sources_bucket_object_delete`, `sources_bucket_purge`).
2. In `app/modules/sources.php` remove the `'bucketAccounts' => sources_bucket_accounts($userId),` line from `sources_list()`.
3. In `app/views/sources.php` replace the whole `[data-bucket-panel]` panel (lines starting `<div class="panel" style="margin-top:22px" data-bucket-panel` through its closing `</div>`) with:

```php
            <div class="panel" style="margin-top:22px">
                <div class="panel__head"><h2 class="panel__title">对象存储</h2></div>
                <div class="panel__body">
                    <p class="mhint">桶与桶内对象的管理已经移到独立页面。</p>
                    <p style="margin-top:10px">
                        <a class="chipbtn" href="<?= e(url('objects.index')) ?>">打开对象存储</a>
                    </p>
                </div>
            </div>
```

4. In `assets/js/console.js` delete the entire `/* ── 存储桶管理 ── */` block inside `pageSources()` — from `const bucketPanel = document.querySelector('[data-bucket-panel]');` to the closing `}` of `if (bucketPanel && bucketHost) { ... }`. Leave the rest of `pageSources()` intact.

- [ ] **Step 2: 更新 README**

In `README.md` under the storage-source section add:

```markdown
**对象存储管理**
- 独立页面「对象存储」可管理腾讯云 COS / 通用 S3 的桶与对象
- 支持前缀浏览、搜索、翻页、上传（大文件自动分片）、预签名下载、复制/移动、批量删除
- 支持版本控制、静态网站、CORS（含一键允许本站）、生命周期、标签、ACL；桶策略只读
- 已绑定为存储源的桶在对象页只读，避免误删副本记录
- COS 桶名需带 APPID 后缀，例如 `photos-1250000000`；建议使用只授予目标桶权限的子账号密钥
```

- [ ] **Step 3: 全量测试**

Run each:

```bash
for f in tests/t*.php; do runuser -u www -- php "$f" || echo "FAILED: $f"; done
```

Expected: every file prints `全部通过`; no `FAILED:` lines.

- [ ] **Step 4: 页面渲染验证（无真实 COS 也能做）**

1. 造一个免密登录会话（以 www 身份）：

```bash
cd /www/wwwroot/drive
# 先确认真实存在的用户 id；下面用 2 作示例，换成你自己的 id
USER_ID=2
EPOCH=$(runuser -u www -- php -r 'require "app/bootstrap.php"; echo (int) q_val("SELECT session_epoch FROM users WHERE id = " . (int) getenv("USER_ID"));' )
runuser -u www -- sh -c "printf 'user_id|i:$USER_ID;auth_epoch|i:$EPOCH;' > runtime/sessions/sess_objtest0000000000001"
# 如果 EPOCH 为空或用户不存在，先查：runuser -u www -- php -r 'require "app/bootstrap.php"; print_r(q_all("SELECT id,email FROM users LIMIT 5"));'
```

2. 用 CDP 截图脚本打开对象存储页：

```bash
node /tmp/shot.mjs 'http://127.0.0.1:15244/index.php?r=objects.index' 'shiyun_sid=objtest0000000000001' /tmp/objects.png 1440 900 1200
```

Expected: 页面无 PHP 报错；能看到「对象存储」标题、账号区（空态时显示「还没有对象存储账号」）、控制台无 JavaScript 异常。

3. 在 CDP 里点一次「添加账号」，确认弹窗出现且输入框可用；再关闭。不要填写真实密钥。

4. 删除测试会话文件：

```bash
rm -f runtime/sessions/sess_objtest0000000000001
```

- [ ] **Step 5: 检查错误日志**

```bash
tail -50 runtime/php-error.log
```

Expected: 没有新增的 `object` 相关未捕获异常。若有，修掉再重跑。

- [ ] **Step 6: 提交**

```bash
git add app/modules/sources.php app/views/sources.php assets/js/console.js README.md
git commit -m "chore: 移除存储源页旧桶面板，补充对象存储文档"
```

---

## 真实 COS 验收（交付后由用户执行）

按规格 §10.3 的十项清单操作。注意：

- 本机没有你的 COS 密钥，无法替你做真实写入/删除。
- 上传依赖桶 CORS；页面会在上传前预检，并给出「一键允许本站」。
- 若浏览器控制台出现 CSP 报错，确认 `objects.index` 的响应头里 `connect-src` 有你配置的 endpoint（精确地址与 `*.<host>` 两种）。
- 归档对象下载前会提示解冻；解冻完成前下载仍会被服务端 409 拦截。

## 自检结果

- 规格 §1 成功标准：Task 1–12 覆盖；真实 COS 仅能由用户验收（§10.3）。
- 规格 §4 数据模型：Task 1。
- 规格 §5 页面与交互：Task 5–12。
- 规格 §6 协议层：Task 3、4、9。
- 规格 §7 路由：Task 5、6、8、9、10、11、12 全部实现。
- 规格 §8 安全：Task 1（凭据隔离/掩码）、Task 2（CSP）、Task 6（绑定守卫）、Task 9（TTL/预签名）、Task 12（ACL 提示）。
- 规格 §10 测试：Task 1–12 增量断言 + Task 13 全量回归。
- 已知限制：真实 COS 上传/下载未能在本机端到端验证；分片上传的 CORS/ETag 依赖真实桶的 CORS 配置。
