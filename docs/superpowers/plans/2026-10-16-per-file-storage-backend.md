# 每文件可选存储后端（本地 / COS）实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让用户上传时可为每个文件选择存储后端（本地 / 腾讯云 COS），选 COS 的文件下载时 302 到预签名直链、字节不经过本机，同时分享、版本、API 等既有功能全部保持可用。

**Architecture:** 本地永远保留一份副本，COS 只作副本 + 下载带宽出口。新增 `blob_locations` 表以 **blob 级**粒度记录「某份内容在某存储源上的位置与状态」。推送与远程删除都走既有的 `transfer_tasks` 队列（由宝塔计划任务驱动的 cron 推进），避免阻塞上传请求。下载路径新增一层 `blob_deliver()`，命中可用直链则 302，否则回退本地流式。

**Tech Stack:** PHP 8.5（无框架）+ MySQL 8 + 原生 JS/CSS。零新增依赖；签名与 HTTP 全部用 PHP 内置的 `hash_hmac` / `curl`。

**Spec:** `docs/superpowers/specs/2026-10-16-per-file-storage-backend-design.md`

## Global Constraints

- **零新增依赖**：不装任何扩展、库、外部程序。签名用 `hash_hmac`，HTTP 用 `curl`，解压/编码类能力若缺失就如实报不可用。
- **进程执行被禁用**：不得使用 `exec/shell_exec/proc_open/popen/system/passthru`（见 `app/lib/shell.php`）。
- **本地永远保留副本**：任何情况下不得删除本地 blob 只留 COS 副本。
- **只对 `driver='s3'` 的源做直链**：FTP / 本地源一律回退本地流式。
- **读取 location 必须按 `storage_sources.user_id` 过滤**：`blobs` 表无 `user_id` 且跨用户去重，不过滤会导致流量串用。
- **所有改动必须通过 `php -l`**：PHP 8.5 下 `declare(strict_types=1)`，函数参数类型严格。
- **无 git**：本目录不是 git 仓库，计划中的检查点是运行验证命令，不是提交。
- **测试方式**：零依赖 PHP CLI 脚本，放 `tests/`，用 `php tests/<name>.php` 运行，失败退出码非零。
- **设计约束**：界面禁用 emoji；图标只用 `lucide()`（服务端）与 `icon()`（前端）已有映射；文案用简体中文。

## Review Focus

以下五类问题 spec 有提及但最容易被漏测，实现时必须在对应任务里落成验证：

1. **内容寻址 key 被复用导致误删新对象** —— 删除后立刻重传同一份内容，`purge` 任务不得删掉新对象。测试见 Task 7。
2. **跨用户去重导致流量串用** —— B 用户下载时不得被 302 到 A 用户的源。测试见 Task 8。
3. **预签名 URL 中途过期导致断流** —— 大文件续传期间 URL 失效。由 `presign_ttl` 默认 7200 秒覆盖，Task 3 验证参数确实写进了签名。
4. **COS 不可达时每次下载都等 HEAD 超时** —— 靠 `stale` 标记让惩罚只吃一次。测试见 Task 8。
5. **私密空间文件被 302 泄露** —— 私密空间的文件必须始终走本地。测试见 Task 9。

---

### Task 1: 数据库迁移（新表 + 删旧列）

**Files:**
- Modify: `app/schema.php`（新增 DDL；`SCHEMA_VERSION` 1 → 2）
- Test: `tests/t1_schema.php`

**注意**：`nodes.source_id` 的删除**不在本任务**，推迟到 Task 11（见下方裁定 R2）。

**Interfaces:**
- Consumes: 无
- Produces: 表 `blob_locations(id, blob_id, source_id, object_key, size, status, error, created_at, updated_at)`，`status ∈ {'pending','ok','failed','stale'}`；`nodes.source_id` 被删除。

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t1_schema.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 触发迁移
db();

check(
    (bool) q_val("SHOW TABLES LIKE 'blob_locations'"),
    'blob_locations 表应已创建'
);

$cols = array_column(q_all("SHOW COLUMNS FROM blob_locations"), 'Field');
foreach (['blob_id', 'source_id', 'object_key', 'size', 'status', 'error', 'updated_at'] as $c) {
    check(in_array($c, $cols, true), "blob_locations 应有列 {$c}");
}

$statusType = '';
foreach (q_all('SHOW COLUMNS FROM blob_locations') as $row) {
    if ($row['Field'] === 'status') {
        $statusType = (string) $row['Type'];
    }
}
check(str_contains($statusType, 'stale'), 'status 枚举应含 stale');

check(
    (bool) q_val("SHOW COLUMNS FROM nodes LIKE 'source_id'"),
    'nodes.source_id 本任务后仍应保留（删除推迟到 Task 11，见计划裁定 R2）'
);

finish();
```

- [ ] **Step 2: 建 `tests/lib.php`（零依赖断言助手）**

```php
<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/bootstrap.php';

$GLOBALS['__failures'] = 0;

function check(bool $cond, string $message): void
{
    if ($cond) {
        echo "  ok   {$message}\n";
        return;
    }
    $GLOBALS['__failures']++;
    echo "  FAIL {$message}\n";
}

function finish(): never
{
    $n = (int) $GLOBALS['__failures'];
    echo $n === 0 ? "\n全部通过\n" : "\n{$n} 项失败\n";
    exit($n === 0 ? 0 : 1);
}
```

注意 `q_val()` 的签名是 `q_val(string $sql, array $params = [])`，只返回单值、没有第三个参数——查列定义要用 `q_all()` 再在 PHP 里挑。

- [ ] **Step 3: 运行验证，确认失败**

Run: `php tests/t1_schema.php`
Expected: FAIL — `blob_locations 表应已创建`

- [ ] **Step 4: 实现**

在 `app/schema.php` 里把 `SCHEMA_VERSION` 改为 `2`，并在 `schema_statements()` 的 DDL 数组中加入：

```php
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS blob_locations (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blob_id    BIGINT UNSIGNED NOT NULL,
            source_id  BIGINT UNSIGNED NOT NULL,
            object_key VARCHAR(512)    NOT NULL,
            size       BIGINT UNSIGNED NOT NULL,
            status     ENUM('pending','ok','failed','stale') NOT NULL DEFAULT 'pending',
            error      VARCHAR(500)    NULL,
            created_at DATETIME        NOT NULL,
            updated_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_blob_source (blob_id, source_id),
            KEY idx_blobloc_status (status),
            KEY idx_blobloc_key (source_id, object_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;
```

`idx_blobloc_key (source_id, object_key)` 是 Task 7 的误删护栏要用的索引。

**不要**在 `app/db.php` 里加删列代码——删 `nodes.source_id` 推迟到 Task 11（裁定 R2）。

- [ ] **Step 5: 运行验证，确认通过**

Run: `php tests/t1_schema.php`
Expected: 全部通过（退出码 0）

- [ ] **Step 6: 回归检查**

Run: `php -l app/schema.php && php -l app/db.php`
Expected: `No syntax errors detected`（两条）

Run: `curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:15244/index.php?r=auth.login"`
Expected: `200`（迁移不会打挂站点）

---

### Task 2: 位置记录助手

**Files:**
- Create: `app/lib/locations.php`
- Modify: `app/bootstrap.php`（在 `require .../lib/storage_drivers.php` 之后加一行 require）
- Test: `tests/t2_locations.php`

**Interfaces:**
- Consumes: `blob_locations` 表（Task 1）；`q_one/q_all/q_exec/q_insert/q_update`（既有）
- Produces:
  - `blob_location_record(int $blobId, int $sourceId, string $objectKey, int $size): int` — upsert，返回 location id
  - `blob_location_mark_ok(int $blobId, int $sourceId, string $objectKey): void`
  - `blob_location_mark_failed(int $blobId, int $sourceId, string $error): void`
  - `blob_location_mark_stale(int $locationId, string $error): void`
  - `blob_location_find(int $blobId, int $sourceId): ?array`
  - `blob_location_direct(int $userId, int $blobId): ?array` — 返回带 `config` 的可用直链 location，按 `storage_sources.priority DESC` 取第一条
  - `blob_locations_of_blob(int $blobId): array`
  - `blob_location_forget(int $locationId): void`

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t2_locations.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't2-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'region' => 'ap-guangzhou', 'bucket' => 'test-1250000000',
        'access_key' => 'AK', 'secret_key' => 'SK']),
    'is_default' => 0, 'priority' => 5, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

// 造一个假 blob
$blobId = q_insert('blobs', ['hash' => str_repeat('a', 64), 'size' => 100,
    'mime' => 'text/plain', 'ref_count' => 1, 'created_at' => now()]);

$id = blob_location_record($blobId, $sourceId, 'aa/' . str_repeat('a', 64), 100);
check($id > 0, 'record 应返回 location id');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$id]) === 'pending',
    '新记录状态应为 pending');

// 幂等：同 (blob, source) 再记一次不应新增行
$id2 = blob_location_record($blobId, $sourceId, 'aa/' . str_repeat('a', 64), 100);
check($id2 === $id, '重复 record 应复用同一行（幂等）');
check((int) q_val('SELECT COUNT(*) FROM blob_locations WHERE blob_id = ? AND source_id = ?',
    [$blobId, $sourceId]) === 1, '同 (blob,source) 只应有一行');

blob_location_mark_ok($blobId, $sourceId, 'aa/' . str_repeat('a', 64));
$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', 'mark_ok 后状态应为 ok');

$direct = blob_location_direct($userId, $blobId);
check($direct !== null && isset($direct['config']), 'direct 应返回带 config 的记录');
check((int) $direct['source_id'] === $sourceId, 'direct 应返回刚建的源');

// 另一个用户的 direct 必须为 null（跨用户隔离）
$otherId = q_insert('users', [
    'email' => 't2other@example.test', 'username' => 't2other',
    'display_name' => 't2other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
]);
check(blob_location_direct($otherId, $blobId) === null, '其它用户不得看到该 location');

blob_location_mark_stale($id, '测试');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$id]) === 'stale',
    'mark_stale 后状态应为 stale');

blob_location_forget($id);
check(blob_location_find($blobId, $sourceId) === null, 'forget 后应查不到');

// 清理
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM users WHERE id = ?', [$otherId]);

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t2_locations.php`
Expected: FAIL — `Call to undefined function blob_location_record()`

- [ ] **Step 3: 实现**

创建 `app/lib/locations.php`：

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   内容副本位置。
   blobs 表只记「内容是什么」，这里记「这份内容在哪个存储源上也有一份」。
   粒度是 blob 级：去重下同一份内容可能被多个节点引用，推一次即可全部受益。
   ========================================================================= */

/** 登记一条位置记录（幂等），返回 location id */
function blob_location_record(int $blobId, int $sourceId, string $objectKey, int $size): int
{
    $existing = blob_location_find($blobId, $sourceId);
    if ($existing !== null) {
        q_update('blob_locations', [
            'object_key' => $objectKey,
            'size'       => $size,
            'status'     => 'pending',
            'error'      => null,
            'updated_at' => now(),
        ], 'id = ?', [(int) $existing['id']]);
        return (int) $existing['id'];
    }

    return q_insert('blob_locations', [
        'blob_id'    => $blobId,
        'source_id'  => $sourceId,
        'object_key' => $objectKey,
        'size'       => $size,
        'status'     => 'pending',
        'error'      => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function blob_location_mark_ok(int $blobId, int $sourceId, string $objectKey): void
{
    q_exec(
        'UPDATE blob_locations SET status = ?, object_key = ?, error = NULL, updated_at = ?
         WHERE blob_id = ? AND source_id = ?',
        ['ok', $objectKey, now(), $blobId, $sourceId]
    );
}

function blob_location_mark_failed(int $blobId, int $sourceId, string $error): void
{
    q_exec(
        'UPDATE blob_locations SET status = ?, error = ?, updated_at = ?
         WHERE blob_id = ? AND source_id = ?',
        ['failed', mb_substr($error, 0, 480), now(), $blobId, $sourceId]
    );
}

function blob_location_mark_stale(int $locationId, string $error): void
{
    q_update('blob_locations', [
        'status'     => 'stale',
        'error'      => mb_substr($error, 0, 480),
        'updated_at' => now(),
    ], 'id = ?', [$locationId]);
}

function blob_location_find(int $blobId, int $sourceId): ?array
{
    return q_one(
        'SELECT * FROM blob_locations WHERE blob_id = ? AND source_id = ? LIMIT 1',
        [$blobId, $sourceId]
    );
}

function blob_locations_of_blob(int $blobId): array
{
    return q_all('SELECT * FROM blob_locations WHERE blob_id = ?', [$blobId]);
}

function blob_location_forget(int $locationId): void
{
    q_exec('DELETE FROM blob_locations WHERE id = ?', [$locationId]);
}

/**
 * 找出该用户可用的直链。
 * 必须按 source 归属过滤：blobs 跨用户去重，不过滤会把别人的流量算到自己头上。
 */
function blob_location_direct(int $userId, int $blobId): ?array
{
    return q_one(
        "SELECT bl.*, s.config, s.driver, s.priority
         FROM blob_locations bl
         JOIN storage_sources s ON s.id = bl.source_id
         WHERE bl.blob_id = ? AND bl.status = 'ok' AND s.driver = 's3' AND s.user_id = ?
         ORDER BY s.priority DESC, bl.id ASC
         LIMIT 1",
        [$blobId, $userId]
    );
}
```

在 `app/bootstrap.php` 的 `require BASE_PATH . '/app/lib/storage_drivers.php';` 之后插入：

```php
require BASE_PATH . '/app/lib/locations.php';
```

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t2_locations.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php -l app/lib/locations.php && php -l app/bootstrap.php`
Expected: 两条 `No syntax errors detected`

---

### Task 3: `s3_presign()` 查询串签名

**Files:**
- Modify: `app/lib/storage_drivers.php`（在 `s3_request()` 之后新增函数）
- Modify: `app/config.php`（新增 `presign_ttl`）
- Test: `tests/t3_presign.php`

**Interfaces:**
- Consumes: 无
- Produces: `s3_presign(array $c, string $method, string $key, int $ttl, array $query = []): string` —— 返回完整可 GET 的 URL

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t3_presign.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cfg = [
    'endpoint'   => 'https://cos.ap-guangzhou.myqcloud.com',
    'region'     => 'ap-guangzhou',
    'bucket'     => 'examplebucket-1250000000',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETEXAMPLE',
    'prefix'     => '',
];

$url = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 7200);

check(str_starts_with($url, 'https://cos.ap-guangzhou.myqcloud.com/examplebucket-1250000000/ab/'),
    'URL 应为 path-style 且含桶名');
check(str_contains($url, 'X-Amz-Algorithm=AWS4-HMAC-SHA256'), '应含 X-Amz-Algorithm');
check(str_contains($url, 'X-Amz-Expires=7200'), '应含 X-Amz-Expires=7200');
check(str_contains($url, 'X-Amz-SignedHeaders=host'), '应含 X-Amz-SignedHeaders=host');
check(str_contains($url, 'ap-guangzhou%2Fs3%2Faws4_request'), '凭据范围应含 region 与 s3');
check(preg_match('/X-Amz-Signature=[0-9a-f]{64}$/', $url) === 1, '应以 64 位十六进制签名结尾');

// 同样输入两次必须得到同样结果（签名可复现）
$again = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 7200);
check(substr($url, 0, strrpos($url, 'X-Amz-Signature=')) ===
      substr($again, 0, strrpos($again, 'X-Amz-Signature=')), '相同输入应产生相同待签串');

// 额外查询参数必须进入签名（response-content-disposition 用于保住文件名）
$withName = s3_presign($cfg, 'GET', 'ab/x', 60, ['response-content-disposition' => 'attachment; filename="a.txt"']);
check(str_contains($withName, 'response-content-disposition='), '应含自定义查询参数');
check(substr($url, -64) !== substr($withName, -64), '查询参数不同则签名必须不同');

// 有效期不同则签名不同
$shortTtl = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 60);
check(substr($url, -64) !== substr($shortTtl, -64), '有效期不同则签名必须不同');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t3_presign.php`
Expected: FAIL — `Call to undefined function s3_presign()`

- [ ] **Step 3: 实现**

在 `app/lib/storage_drivers.php` 的 `s3_request()` 之后加入：

```php
/* =========================================================================
   寻址风格：COS 拒绝 path-style（实测返回 PathStyleDomainForbidden，要求
   「must be addressed using COS virtual-styled domain」）。因此 s3_request() 与
   s3_presign() 都必须改用**虚拟主机风格**：桶名进 host，key 进路径。

   兼容两种填法：若 endpoint 的 host 已经以 `{bucket}.` 开头（用户直接粘了桶域名，
   例如 https://mybucket-1250000000.cos.ap-guangzhou.myqcloud.com），就不再重复拼桶名。
   ========================================================================= */

/**
 * 由配置推导虚拟主机风格的 base URL 与 host 头。
 * 两套签名（请求头 / 查询串）都必须走这里，否则会出现「签名与发送的 host 不一致」。
 *
 * @return array{base:string,host:string}
 */
function s3_addressed(array $c): array
{
    $endpoint = rtrim((string) $c['endpoint'], '/');
    $bucket = (string) $c['bucket'];

    $scheme = (string) (parse_url($endpoint, PHP_URL_SCHEME) ?: 'https');
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);

    // 桶名进 host；已经带过就不再拼
    if (!str_starts_with($host, $bucket . '.')) {
        $host = $bucket . '.' . $host;
    }

    $authority = $host . ($port !== null ? ':' . $port : '');

    return ['base' => $scheme . '://' . $authority, 'host' => $authority];
}

/* ------------------------------------------------------ S3 预签名（查询串） */

/**
 * 生成预签名 URL（SigV4 查询串签名，与 s3_request 的请求头签名是两套算法）。
 * 只覆盖 path-style 的 S3 兼容存储。
 */
function s3_presign(array $c, string $method, string $key, int $ttl, array $query = []): string
{
    $endpoint = rtrim((string) $c['endpoint'], '/');
    $bucket = (string) $c['bucket'];
    $region = (string) ($c['region'] ?? 'us-east-1');
    $prefix = trim((string) ($c['prefix'] ?? ''), '/');

    $objectKey = $prefix !== '' ? $prefix . '/' . ltrim($key, '/') : ltrim($key, '/');
    $url = $endpoint . '/' . rawurlencode($bucket) . '/' . str_replace('%2F', '/', rawurlencode($objectKey));

    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);
    $hostHeader = $port !== null ? $host . ':' . $port : $host;

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $scope = $dateStamp . '/' . $region . '/s3/aws4_request';

    $params = $query + [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => (string) $c['access_key'] . '/' . $scope,
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string) $ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
    ksort($params);

    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    $canonicalQuery = implode('&', $pairs);

    $canonicalRequest = implode("\n", [
        $method,
        parse_url($url, PHP_URL_PATH) ?: '/',
        $canonicalQuery,
        'host:' . $hostHeader . "\n",
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . (string) $c['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return $url . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}
```

在 `app/config.php` 的「集成」段之后加入：

```php
    // ---------------------------------------------------------- 存储源直链
    // 预签名 URL 有效期（秒）。媒体元素开始拉流后会用同一 URL 连续发 Range 请求，
    // 中途过期会断流，所以留足 2 小时。
    'presign_ttl'  => 7200,
```

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t3_presign.php`
Expected: 全部通过

- [ ] **Step 5: 标注未验证项**

`tests/t3_presign.php` 只做结构校验。在文件顶部加注释：

```php
// 注意：本脚本只做结构校验，无法证明 COS 会接受该签名。
// 拿到真实 AK/SK 后必须补一次对真实 COS 的端到端验证（见 Task 15）。
```

---

### Task 4: 远程对象删除

**Files:**
- Modify: `app/lib/storage_drivers.php`（新增 `storage_source_delete()`）
- Test: `tests/t4_delete.php`

**Interfaces:**
- Consumes: `s3_request()`（既有）
- Produces: `storage_source_delete(array $source, string $objectKey): void` —— 失败抛 `RuntimeException`

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t4_delete.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

check(function_exists('storage_source_delete'), 'storage_source_delete 应存在');

// 未配置 aria2 之类的无关依赖不应影响；这里只验证对不可达 endpoint 会抛错
$source = [
    'id' => 999, 'driver' => 's3',
    'config' => json_encode([
        'endpoint' => 'https://127.0.0.1:1', 'region' => 'ap-guangzhou',
        'bucket' => 'b-1', 'access_key' => 'AK', 'secret_key' => 'SK',
    ]),
];

$threw = false;
try {
    storage_source_delete($source, 'aa/bb');
} catch (RuntimeException $e) {
    $threw = true;
}
check($threw, 'endpoint 不可达时应抛 RuntimeException');

// local 驱动：删不存在的文件不应抛错（幂等）
$localSource = ['id' => 998, 'driver' => 'local',
    'config' => json_encode(['root' => sys_get_temp_dir() . '/t4-none'])];
$ok = true;
try {
    storage_source_delete($localSource, 'does/not/exist');
} catch (Throwable $e) {
    $ok = false;
}
check($ok, 'local 源删除不存在的键应幂等不抛错');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t4_delete.php`
Expected: FAIL — `storage_source_delete 应存在`

- [ ] **Step 3: 实现**

在 `app/lib/storage_drivers.php` 的 `storage_source_put()` 之后加入：

```php
/**
 * 删除存储源上的一个对象。失败抛 RuntimeException。
 */
function storage_source_delete(array $source, string $objectKey): void
{
    $driver = (string) $source['driver'];
    $config = json_decode((string) $source['config'], true) ?: [];
    $prefix = trim((string) ($config['prefix'] ?? ''), '/');
    $key = $prefix !== '' ? $prefix . '/' . ltrim($objectKey, '/') : ltrim($objectKey, '/');

    switch ($driver) {
        case 'local':
            $root = rtrim((string) ($config['root'] ?? config('storage_path')), '/');
            $path = $root . '/' . $key;
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('删除本地源对象失败');
            }
            return;

        case 's3':
            $result = s3_request($config, 'DELETE', $objectKey, [
                'payload_hash' => hash('sha256', ''),
            ]);
            // 404 视为已不存在，幂等成功
            if ($result['status'] !== 404 && ($result['status'] < 200 || $result['status'] >= 300)) {
                throw new RuntimeException('S3 删除失败：HTTP ' . $result['status']);
            }
            return;

        default:
            throw new RuntimeException('该存储类型不支持远程删除：' . $driver);
    }
}
```

注意 `s3_request()` 内部会自己拼 `$prefix`（见其第 222-225 行），所以这里传 `$objectKey` 而不是 `$key`，避免前缀被拼两次。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t4_delete.php`
Expected: 全部通过

---

### Task 5: `blob_push_to_source()`

**Files:**
- Modify: `app/lib/storage.php`（新增函数）
- Test: `tests/t5_push.php`

**Interfaces:**
- Consumes: `blob_location_*`（Task 2）、`storage_source_put()`（既有）、`blob_path()`（既有）
- Produces: `blob_push_to_source(int $blobId, int $sourceId): array` —— 返回 `['ok'=>bool,'skipped'=>bool,'error'=>string]`，**永不抛异常**

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t5_push.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t5-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't5-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

// 造一份真实内容进本地仓
$tmp = sys_get_temp_dir() . '/t5-payload.bin';
file_put_contents($tmp, 'hello-push');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$r = blob_push_to_source($blobId, $sourceId);
check($r['ok'] === true && $r['skipped'] === false, '首次推送应成功且非跳过');

$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', '推送后状态应为 ok');
check(is_file($dir . '/' . substr($blob['hash'], 0, 2) . '/' . $blob['hash']),
    '目标源上应出现对象');

// 第二次：已 ok，应跳过而不是重传
$r2 = blob_push_to_source($blobId, $sourceId);
check($r2['ok'] === true && $r2['skipped'] === true, '已同步时应跳过');

// 指向不存在的源：不得抛异常，应返回失败
$r3 = blob_push_to_source($blobId, 999999);
check($r3['ok'] === false && $r3['error'] !== '', '无效源应返回失败而不是抛异常');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t5_push.php`
Expected: FAIL — `Call to undefined function blob_push_to_source()`

- [ ] **Step 3: 实现**

在 `app/lib/storage.php` 的 `blob_ingest_file()` 之后加入：

```php
/* -------------------------------------------------------------- 推送副本 */

/**
 * 把一份 blob 推送到指定存储源。幂等：已同步则跳过。
 * 永不抛异常——本地那份始终是好的，推送失败只意味着少了一份副本。
 *
 * @return array{ok:bool,skipped:bool,error:string}
 */
function blob_push_to_source(int $blobId, int $sourceId): array
{
    $source = q_one('SELECT * FROM storage_sources WHERE id = ?', [$sourceId]);
    if ($source === null) {
        return ['ok' => false, 'skipped' => false, 'error' => '存储源不存在'];
    }

    // 这里**不**判断 driver —— 本函数是「把这份内容复制到那个源」的机制，
    // 「local 源不作为推送目标」是策略，由调用方把关：
    // sources_push() 拒绝 local，上传路径只接受 driver='s3' 的源。
    // 策略放在这里会重复，还会让本函数离开网络就无法测试。

    $blob = q_one('SELECT * FROM blobs WHERE id = ?', [$blobId]);
    if ($blob === null) {
        return ['ok' => false, 'skipped' => false, 'error' => '内容不存在'];
    }

    $existing = blob_location_find($blobId, $sourceId);
    if ($existing !== null && (string) $existing['status'] === 'ok') {
        return ['ok' => true, 'skipped' => true, 'error' => ''];
    }

    $hash = (string) $blob['hash'];
    $path = blob_path($hash);
    if (!is_file($path)) {
        blob_location_mark_failed($blobId, $sourceId, '本地副本不存在');
        return ['ok' => false, 'skipped' => false, 'error' => '本地副本不存在'];
    }

    $key = substr($hash, 0, 2) . '/' . $hash;
    blob_location_record($blobId, $sourceId, $key, (int) $blob['size']);

    try {
        $storedKey = storage_source_put($source, $hash, $path);
        blob_location_mark_ok($blobId, $sourceId, $storedKey);
        return ['ok' => true, 'skipped' => false, 'error' => ''];
    } catch (Throwable $e) {
        blob_location_mark_failed($blobId, $sourceId, $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
    }
}
```

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t5_push.php`
Expected: 全部通过

---

### Task 6: `push` 任务类型

**Files:**
- Modify: `app/modules/tasks.php`（`tasks_tick()` 分派、`task_kind_label()`、`task_public()`、新增 `task_run_push()`）
- Test: `tests/t6_push_task.php`

**Interfaces:**
- Consumes: `blob_push_to_source()`（Task 5）
- Produces: `tasks_enqueue_push(int $userId, int $blobId, int $sourceId, string $title): int` —— 返回任务 id；任务 `kind='push'`，`payload` 含 `blob_id` / `source_id`

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t6_push_task.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
// 模块文件由路由按需 require，bootstrap 只加载 app/lib/。
// 测试里要调用 tasks_* 函数，必须自己把模块文件引进来。
require_once BASE_PATH . '/app/modules/tasks.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t6-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't6-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t6-payload.bin';
file_put_contents($tmp, 'push-via-task');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$taskId = tasks_enqueue_push($userId, $blobId, $sourceId, 't6.bin');
check($taskId > 0, 'enqueue 应返回任务 id');
check((string) q_val('SELECT status FROM transfer_tasks WHERE id = ?', [$taskId]) === 'queued',
    '新任务应为 queued');
check((string) q_val('SELECT kind FROM transfer_tasks WHERE id = ?', [$taskId]) === 'push',
    'kind 应为 push');

// 推进队列
tasks_tick($userId, 10);

$row = q_one('SELECT status, error FROM transfer_tasks WHERE id = ?', [$taskId]);
check($row['status'] === 'done', '推进后任务应为 done，实际：' . $row['status'] . ' ' . (string) $row['error']);
$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', '推进后 location 应为 ok');

// 清理
q_exec('DELETE FROM transfer_tasks WHERE id = ?', [$taskId]);
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));
@unlink($tmp);
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t6_push_task.php`
Expected: FAIL — `Call to undefined function tasks_enqueue_push()`

- [ ] **Step 3: 实现**

在 `app/modules/tasks.php` 的 `tasks_create()` 之后加入：

```php
/** 入队一个推送任务 */
function tasks_enqueue_push(int $userId, int $blobId, int $sourceId, string $title): int
{
    return q_insert('transfer_tasks', [
        'user_id'  => $userId,
        'kind'     => 'push',
        'title'    => mb_substr($title, 0, 255),
        'payload'  => json_encode([
            'blob_id'   => $blobId,
            'source_id' => $sourceId,
        ], JSON_UNESCAPED_UNICODE),
        'progress' => 0,
        'status'   => 'queued',
        'priority' => 0,
        'created_at' => now(),
    ]);
}
```

`transfer_tasks.kind` 的 ENUM 目前是 `('offline','bt','archive','copy','move','download')`，必须加上 `'push'` 和 `'purge'`。这是**改已有列**，迁移机制不支持——按 Task 1 的做法，在 `db_migrate()` 的幂等段里加。

**同时必须把 `SCHEMA_VERSION` 从 2 改为 3**：`db_migrate()` 在 `current >= SCHEMA_VERSION` 时会提前 return，不升版本号这段守卫根本不会执行。

```php
    // transfer_tasks.kind 扩充 push / purge 两种类型
    $kindRow = null;
    foreach ($pdo->query('SHOW COLUMNS FROM transfer_tasks')->fetchAll() as $row) {
        if ($row['Field'] === 'kind') {
            $kindRow = $row;
        }
    }
    if ($kindRow !== null && !str_contains((string) $kindRow['Type'], 'push')) {
        $pdo->exec("ALTER TABLE transfer_tasks MODIFY COLUMN kind
            ENUM('offline','bt','archive','copy','move','download','push','purge') NOT NULL");
    }
```

同时把 `app/schema.php` 里 `transfer_tasks` 的 DDL 一并改成新的 ENUM 值，让新装环境直接是对的。

在 `tasks_tick()` 的分派处（`if ($task['kind'] === 'offline')` 那一段）加入 `push` 分支：

```php
        } elseif ($task['kind'] === 'push') {
            task_run_push($task);
            $processed++;
        } elseif ($task['kind'] === 'purge') {
            task_run_purge($task);
            $processed++;
        }
```

新增 `task_run_push()`：

```php
/** 把一份内容推送到存储源 */
function task_run_push(array $task): void
{
    $payload = json_decode((string) $task['payload'], true) ?: [];
    $blobId = (int) ($payload['blob_id'] ?? 0);
    $sourceId = (int) ($payload['source_id'] ?? 0);

    if ($blobId <= 0 || $sourceId <= 0) {
        task_fail($task, '推送任务缺少必要参数。');
        return;
    }

    q_update('transfer_tasks', ['status' => 'running', 'started_at' => now()], 'id = ?', [(int) $task['id']]);

    $result = blob_push_to_source($blobId, $sourceId);

    if ($result['ok']) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'error' => null, 'finished_at' => now(),
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    $retries = (int) $task['retries'] + 1;
    if ($retries <= 3) {
        q_update('transfer_tasks', [
            'status' => 'queued', 'retries' => $retries,
            'error' => '第 ' . $retries . ' 次重试：' . $result['error'],
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    task_fail($task, $result['error']);
}
```

在 `task_kind_label()` 的 `match` 中加两条：

```php
        'push'     => '推送副本',
        'purge'    => '清理副本',
```

在 `task_public()` 返回数组里，把 `url` 一行的取值改为兼容 push/purge（它们没有 url）：

```php
        'url'       => (string) ($payload['url'] ?? $payload['magnet'] ?? ''),
```

保持不变即可——push 任务该字段为空字符串，前端已按空值处理。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t6_push_task.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php -l app/modules/tasks.php && php -l app/db.php`
Expected: 两条 `No syntax errors detected`

---

### Task 7: `purge` 任务 + `blob_release()` 清理入队

**Files:**
- Modify: `app/modules/tasks.php`（新增 `task_run_purge()`）
- Modify: `app/lib/storage.php`（`blob_release()` 改为入队清理）
- Test: `tests/t7_purge.php`

**Interfaces:**
- Consumes: `blob_locations_of_blob()`、`blob_location_forget()`（Task 2）、`storage_source_delete()`（Task 4）
- Produces: `task_run_purge(array $task): void`；`blob_release()` 语义扩展为「本地文件 + 位置记录 + 远程对象」

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t7_purge.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
// 同 T6：tasks_* 函数在模块文件里，bootstrap 不加载，测试需自行引入。
require_once BASE_PATH . '/app/modules/tasks.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t7-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't7-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t7-payload.bin';
file_put_contents($tmp, 'purge-me');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];
$hash = (string) $blob['hash'];
blob_push_to_source($blobId, $sourceId);

$objectPath = $dir . '/' . substr($hash, 0, 2) . '/' . $hash;
check(is_file($objectPath), '前置：对象应已推送到源上');

// 引用归零 → 应入队一个 purge 任务，且本地文件消失
blob_release($blobId);

check(!is_file(blob_path($hash)), '本地 blob 应已删除');
$purge = q_one("SELECT * FROM transfer_tasks WHERE kind = 'purge' AND status = 'queued'
                ORDER BY id DESC LIMIT 1");
check($purge !== null, '应入队一个 purge 任务');

// 推进 → 远程对象应被删除
tasks_tick($userId, 10);
check(!is_file($objectPath), '推进后远程对象应被删除');

// ---- 护栏：key 复用不得误删新对象 ----
$tmp2 = sys_get_temp_dir() . '/t7-payload2.bin';
file_put_contents($tmp2, 'reuse-me');
$blob2 = blob_ingest_file($tmp2, null, 'text/plain');
$blobId2 = (int) $blob2['blob_id'];
$hash2 = (string) $blob2['hash'];
$key2 = substr($hash2, 0, 2) . '/' . $hash2;
blob_push_to_source($blobId2, $sourceId);
$objectPath2 = $dir . '/' . $key2;
check(is_file($objectPath2), '前置：第二个对象应已推送');

// 手工造一个「指向同一 key 的待执行 purge 任务」
$staleId = q_insert('transfer_tasks', [
    'user_id' => $userId, 'kind' => 'purge', 'title' => 'stale-purge',
    'payload' => json_encode(['source_id' => $sourceId, 'object_key' => $key2]),
    'progress' => 0, 'status' => 'queued', 'priority' => 0, 'created_at' => now(),
]);
tasks_tick($userId, 10);

check(is_file($objectPath2), '护栏：对象仍被 blob_locations 引用，不得被删除');
check((string) q_val('SELECT status FROM transfer_tasks WHERE id = ?', [$staleId]) === 'done',
    '跳过删除的任务应标为 done');

// 清理
q_exec('DELETE FROM transfer_tasks WHERE kind IN (?, ?)', ['push', 'purge']);
q_exec('DELETE FROM blob_locations WHERE blob_id IN (?, ?)', [$blobId, $blobId2]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id IN (?, ?)', [$blobId, $blobId2]);
@unlink(blob_path($hash));
@unlink(blob_path($hash2));
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t7_purge.php`
Expected: FAIL — 本地 blob 未删除 / 未入队 purge 任务

- [ ] **Step 3: 实现**

**先补上分派分支**：Task 6 只加了 `push` 分支（当时 `task_run_purge()` 还不存在），本任务必须把 `purge` 也接进 `tasks_tick()` 的分派链，位置在 `push` 分支之后：

```php
        } elseif ($task['kind'] === 'purge') {
            task_run_purge($task);
            $processed++;
```

**再补上标签**：`task_kind_label()` 的 `match` 里加一条（Task 6 只加了 `push`）：

```php
        'purge'    => '清理副本',
```

**最后加入运行函数**：在 `app/modules/tasks.php` 的 `task_run_push()` 之后加入：

```php
/**
 * 清理存储源上的副本。
 *
 * 关键护栏：object_key 是内容寻址的，删除后重传同一份内容会落在同一个 key 上，
 * 队列里这个待执行任务会把新对象误删。所以删除前必须复查有没有 blob 仍引用它。
 */
function task_run_purge(array $task): void
{
    $payload = json_decode((string) $task['payload'], true) ?: [];
    $sourceId = (int) ($payload['source_id'] ?? 0);
    $objectKey = (string) ($payload['object_key'] ?? '');

    if ($sourceId <= 0 || $objectKey === '') {
        task_fail($task, '清理任务缺少必要参数。');
        return;
    }

    q_update('transfer_tasks', ['status' => 'running', 'started_at' => now()], 'id = ?', [(int) $task['id']]);

    $stillUsed = (int) q_val(
        'SELECT COUNT(*) FROM blob_locations WHERE source_id = ? AND object_key = ?',
        [$sourceId, $objectKey]
    );
    if ($stillUsed > 0) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'finished_at' => now(),
            'error' => '对象已被新记录引用，跳过删除',
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    $source = q_one('SELECT * FROM storage_sources WHERE id = ?', [$sourceId]);
    if ($source === null) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'finished_at' => now(),
            'error' => '存储源已不存在',
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    try {
        storage_source_delete($source, $objectKey);
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'error' => null, 'finished_at' => now(),
        ], 'id = ?', [(int) $task['id']]);
    } catch (Throwable $e) {
        $retries = (int) $task['retries'] + 1;
        if ($retries <= 3) {
            q_update('transfer_tasks', [
                'status' => 'queued', 'retries' => $retries,
                'error' => '第 ' . $retries . ' 次重试：' . $e->getMessage(),
            ], 'id = ?', [(int) $task['id']]);
            return;
        }
        task_fail($task, $e->getMessage());
    }
}
```

把 `app/lib/storage.php` 的 `blob_release()` 整体替换为：

```php
/** 释放一次引用；归零时删除本地文件，并把远程副本的清理入队 */
function blob_release(?int $blobId): void
{
    if ($blobId === null || $blobId <= 0) {
        return;
    }

    $result = db_tx(static function () use ($blobId): ?array {
        $row = q_one('SELECT hash, ref_count FROM blobs WHERE id = ? FOR UPDATE', [$blobId]);
        if ($row === null) {
            return null;
        }
        if ((int) $row['ref_count'] > 1) {
            q_exec('UPDATE blobs SET ref_count = ref_count - 1 WHERE id = ?', [$blobId]);
            return null;
        }

        // 引用归零：记下所有远程位置，再连同本地一起清掉
        $locations = blob_locations_of_blob($blobId);
        foreach ($locations as $loc) {
            blob_location_forget((int) $loc['id']);
        }
        q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);

        return ['hash' => (string) $row['hash'], 'locations' => $locations];
    });

    if ($result === null) {
        return;
    }

    $path = blob_path($result['hash']);
    if (is_file($path)) {
        @unlink($path);
    }

    // 远程删除走队列：清空回收站可能有几十个 COS 对象，同步做会卡住请求
    foreach ($result['locations'] as $loc) {
        $sid = (int) $loc['source_id'];
        $owner = (int) q_val('SELECT user_id FROM storage_sources WHERE id = ?', [$sid]);
        if ($owner <= 0) {
            continue;
        }
        q_insert('transfer_tasks', [
            'user_id'    => $owner,
            'kind'       => 'purge',
            'title'      => '清理 ' . mb_substr((string) $loc['object_key'], -16),
            'payload'    => json_encode([
                'source_id'  => $sid,
                'object_key' => (string) $loc['object_key'],
            ], JSON_UNESCAPED_UNICODE),
            'progress'   => 0,
            'status'     => 'queued',
            'priority'   => 0,
            'created_at' => now(),
        ]);
    }
}
```

`blob_release()` 现在依赖 `blob_locations_of_blob()` / `blob_location_forget()`。PHP 函数在运行时解析，require 顺序不影响正确性，但为可读性把 `bootstrap.php` 里的 `require BASE_PATH . '/app/lib/locations.php';` 挪到 `require .../lib/storage.php` 之前。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t7_purge.php`
Expected: 全部通过（特别是最后两条护栏断言）

- [ ] **Step 5: 回归检查**

Run: `php tests/t5_push.php && php tests/t6_push_task.php && php tests/t2_locations.php`
Expected: 三个脚本全部通过（`blob_release` 改动影响面较广）

---

### Task 8: `blob_deliver()` 下载分发

**Files:**
- Modify: `app/lib/storage.php`（新增函数）
- Test: `tests/t8_deliver.php`

**Interfaces:**
- Consumes: `blob_location_direct()`、`blob_location_mark_stale()`（Task 2）、`s3_presign()`（Task 3）、`blob_stream()`（既有）
- Produces: `blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0): never`；辅助 `blob_direct_url(int $userId, int $blobId, string $mime, string $filename): ?string`

`blob_direct_url()` 单独拆出来是为了能在 CLI 里测试（`blob_deliver()` 会 `header()` + `exit`，CLI 里测不了）。

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t8_deliver.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$otherId = q_insert('users', [
    'email' => 't8other@example.test', 'username' => 't8other',
    'display_name' => 't8other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t8.bin';
file_put_contents($tmp, 'deliver-me');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

// 无 location：不应有直链
check(blob_direct_url($userId, $blobId, 'text/plain', 'a.txt') === null,
    '没有 location 时不应返回直链');

// 造一条 ok 的 s3 location（endpoint 不可达，用于验证 HEAD 失败会回退）
$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't8-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://127.0.0.1:1',
        'region' => 'ap-guangzhou', 'bucket' => 'b-1',
        'access_key' => 'AK', 'secret_key' => 'SK']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);
$locId = blob_location_record($blobId, $sourceId, 'aa/x', 10);
blob_location_mark_ok($blobId, $sourceId, 'aa/x');

// 别的用户：不得拿到直链（跨用户隔离）
check(blob_direct_url($otherId, $blobId, 'text/plain', 'a.txt') === null,
    '其它用户不得获得该直链');

// 本用户：HEAD 不可达 → 应回退（返回 null）并把 location 标 stale
$url = blob_direct_url($userId, $blobId, 'text/plain', 'a.txt');
check($url === null, 'HEAD 失败时应回退（返回 null）');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$locId]) === 'stale',
    'HEAD 失败后 location 应标为 stale');

// 已 stale：后续调用应直接跳过，不再尝试 HEAD（用耗时粗判）
$t0 = microtime(true);
blob_direct_url($userId, $blobId, 'text/plain', 'a.txt');
check(microtime(true) - $t0 < 1.0, 'stale 后应跳过 HEAD，立即返回');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
q_exec('DELETE FROM users WHERE id = ?', [$otherId]);
@unlink(blob_path((string) $blob['hash']));

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t8_deliver.php`
Expected: FAIL — `Call to undefined function blob_direct_url()`

- [ ] **Step 3: 实现**

在 `app/lib/storage.php` 的 `blob_stream()` 之前加入：

```php
/* ------------------------------------------------------------ 下载分发 */

/**
 * 若该内容在某个 s3 源上有可用副本，返回预签名直链；否则返回 null。
 *
 * HEAD 失败即把 location 标为 stale，之后直接跳过——否则 COS 挂掉时每次下载
 * 都要白等一次超时。
 */
function blob_direct_url(int $userId, int $blobId, string $mime, string $filename): ?string
{
    $loc = blob_location_direct($userId, $blobId);
    if ($loc === null) {
        return null;
    }

    $config = json_decode((string) $loc['config'], true) ?: [];
    $config['bucket'] = (string) ($config['bucket'] ?? '');
    $config['access_key'] = (string) ($config['access_key'] ?? '');
    $config['secret_key'] = (string) ($config['secret_key'] ?? '');

    $head = s3_request($config, 'HEAD', (string) $loc['object_key'], [
        'payload_hash' => hash('sha256', ''),
        'timeout'      => 3,
    ]);
    if ($head['status'] < 200 || $head['status'] >= 400) {
        blob_location_mark_stale((int) $loc['id'], 'HEAD ' . $head['status']);
        return null;
    }

    return s3_presign(
        $config,
        'GET',
        (string) $loc['object_key'],
        (int) config('presign_ttl', 7200),
        [
            'response-content-disposition' =>
                'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'response-content-type' => $mime,
        ]
    );
}

/**
 * 下载分发：命中可用直链则 302 到 COS，否则回退本地流式。
 * 调用方需自行完成鉴权与计数。
 */
function blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0): never
{
    $url = blob_direct_url($userId, $blobId, $mime, $filename);
    if ($url !== null) {
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Location: ' . $url, true, 302);
        }
        exit;
    }

    blob_stream($blobId, $mime, $filename, false, $speedLimitKB);
}
```

`s3_request()` 目前没有 `timeout` 选项，需在它的 `curl_setopt_array` 里支持：

```php
        CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 20),
```

把原来的 `CURLOPT_TIMEOUT => 20,` 替换为上面这行。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t8_deliver.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php tests/t3_presign.php && php tests/t4_delete.php`
Expected: 两个脚本仍全部通过（`s3_request` 改动影响它们）

---

### Task 9: 四个下载端点接上 `blob_deliver()`

**Files:**
- Modify: `app/modules/download.php:29`
- Modify: `app/modules/shares.php:351`
- Modify: `app/modules/versions.php:108`
- Modify: `app/modules/api.php:277`
- Test: `tests/t9_endpoints.php`

**Interfaces:**
- Consumes: `blob_deliver()`（Task 8）
- Produces: 无新接口

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t9_endpoints.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 静态检查：4 个下载端点必须调用 blob_deliver，且不得再直接调 blob_stream
$targets = [
    'app/modules/download.php' => 'download',
    'app/modules/versions.php' => 'versions',
    'app/modules/api.php'      => 'api',
];
foreach ($targets as $file => $label) {
    $src = (string) file_get_contents(BASE_PATH . '/' . $file);
    check(str_contains($src, 'blob_deliver('), "{$label} 应调用 blob_deliver()");
}

// shares.php 同时含下载与内联预览：下载用 deliver，内联预览仍用 stream
$shares = (string) file_get_contents(BASE_PATH . '/app/modules/shares.php');
check(substr_count($shares, 'blob_deliver(') === 1, 'shares.php 应恰好有 1 处 blob_deliver（下载）');
check(substr_count($shares, 'blob_stream(') === 1, 'shares.php 应恰好有 1 处 blob_stream（内联预览）');

// 内联预览与 WebDAV 不得改成 deliver
$preview = (string) file_get_contents(BASE_PATH . '/app/modules/preview.php');
check(substr_count($preview, 'blob_deliver(') === 0, 'preview.php 不得使用 blob_deliver');
$webdav = (string) file_get_contents(BASE_PATH . '/app/modules/webdav.php');
check(substr_count($webdav, 'blob_deliver(') === 0, 'webdav.php 不得使用 blob_deliver');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t9_endpoints.php`
Expected: FAIL — `download 应调用 blob_deliver()`

- [ ] **Step 3: 实现**

`app/modules/download.php` 的 `download_file()`，把第 28-35 行替换为：

```php
    $limit = (int) user_setting($userId, 'download_speed_limit', 0);
    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];

    // 私密空间不直链：预签名 URL 在有效期内等于一张通行证
    if (!node_in_private($userId, $node)) {
        blob_deliver($userId, (int) $node['blob_id'], $mime, $name, $limit);
    }
    blob_stream((int) $node['blob_id'], $mime, $name, false, $limit);
```

`app/modules/versions.php` 的 `versions_download()`：先给查询补上判断私密空间所需的列（`node_in_private()` 读的是 `id` 与 `path`），把第 91-95 行的查询改为：

```php
    $version = q_one(
        'SELECT v.*, n.name, n.id AS node_id, n.path AS node_path
         FROM node_versions v JOIN nodes n ON n.id = v.node_id
         WHERE v.id = ? AND n.user_id = ?',
        [input_int('id'), $userId]
    );
```

再把第 108-113 行替换为：

```php
    $mime = (string) ($version['mime'] ?? 'application/octet-stream');
    $inPrivate = node_in_private($userId, [
        'id'   => (int) $version['node_id'],
        'path' => (string) $version['node_path'],
    ]);
    if (!$inPrivate) {
        blob_deliver($userId, (int) $version['blob_id'], $mime, $name, 0);
    }
    blob_stream((int) $version['blob_id'], $mime, $name, false);
```

`app/modules/api.php` 的 `api_route_download()`，把第 277-283 行的 `blob_stream(...)` 调用替换为：

```php
    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];
    $limit = (int) user_setting($userId, 'download_speed_limit', 0);

    // 私密空间不直链，理由同 download.php
    if (!node_in_private($userId, $node)) {
        blob_deliver($userId, (int) $node['blob_id'], $mime, $name, $limit);
    }
    blob_stream((int) $node['blob_id'], $mime, $name, false, $limit);
```

并在 `tests/t9_endpoints.php` 里补断言，确认**三个文件**的 `blob_deliver(` 都被 `node_in_private(` 守卫包住（只查 api.php 是不够的——另外两个文件若把守卫删掉，测试仍会通过）：

```php
foreach (['download.php', 'versions.php', 'api.php', 'shares.php'] as $guarded) {
    $src = (string) file_get_contents(BASE_PATH . '/app/modules/' . $guarded);
    $deliverPos = strpos($src, 'blob_deliver(');
    $guardPos = strpos($src, 'node_in_private(');
    check(
        $deliverPos !== false && $guardPos !== false && $guardPos < $deliverPos,
        $guarded . ' 的 blob_deliver 应被私密空间守卫包住'
    );
}
```

`app/modules/shares.php` **只改第 351 行那一处**（`shares_download()` 内），替换为：

```php
        $mime = (string) ($node['mime'] ?? 'application/octet-stream');
        $name = (string) $node['name'];

        // 私密空间不直链（裁定 R10）：shares_create() 只验归属、不排除私密空间，
        // 所以分享里可能出现私密文件；而且直链一旦签发，其有效期内的通行证会绕过
        // 分享的密码/有效期/下载次数等撤销手段。
        if (!node_in_private((int) $share['user_id'], $node)) {
            blob_deliver((int) $share['user_id'], (int) $node['blob_id'], $mime, $name, 0);
        }
        blob_stream((int) $node['blob_id'], $mime, $name, false);
```

用户 id 用分享所有者 `(int) $share['user_id']`——直链按源的归属过滤，访客未登录没有 id，必须用分享者的。第 376 行 `shares_raw()` 的内联预览**保持 `blob_stream` 不变**。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t9_endpoints.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php -l app/modules/download.php && php -l app/modules/shares.php && php -l app/modules/versions.php && php -l app/modules/api.php`
Expected: 四条 `No syntax errors detected`

Run: `curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:15244/index.php?r=files.list"`
Expected: `302`（未登录跳登录，说明路由未被打挂）

---

### Task 10: 上传接收 `source` 参数并入队推送

**Files:**
- Modify: `app/modules/upload.php`（会话创建时记录 `source_id`；合并完成后入队 push）
- Modify: `app/modules/api.php`（`api_route_upload()` 接收 `source`）
- Modify: `app/schema.php`（`upload_sessions` 加 `source_id` 列）
- Modify: `app/db.php`（幂等加列）
- Test: `tests/t10_upload.php`

**Interfaces:**
- Consumes: `tasks_enqueue_push()`（Task 6）
- Produces: 上传接口接受 `source` 参数（0 或缺省 = 本地）；`upload_sessions.source_id`

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t10_upload.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cols = array_column(q_all('SHOW COLUMNS FROM upload_sessions'), 'Field');
check(in_array('source_id', $cols, true), 'upload_sessions 应新增 source_id 列');

$src = (string) file_get_contents(BASE_PATH . '/app/modules/upload.php');
// 两处：秒传分支（内容本地已有，前端不会走到合并）+ 合并完成分支
check(substr_count($src, 'tasks_enqueue_push(') === 2,
    'upload.php 应在秒传与合并两处都入队 push 任务');

$api = (string) file_get_contents(BASE_PATH . '/app/modules/api.php');
check(str_contains($api, "input('source'") || str_contains($api, "\$body['source']"),
    'api.php 上传应接收 source 参数');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t10_upload.php`
Expected: FAIL — `upload_sessions 应新增 source_id 列`

- [ ] **Step 3: 实现**

`app/schema.php` 的 `upload_sessions` DDL 加一列（放在 `parent_id` 之后）：

```sql
            source_id      BIGINT UNSIGNED NULL,
```

`app/db.php` 幂等段加（并把 `SCHEMA_VERSION` 从 3 改为 4，理由同 Task 6——不升版本号守卫不会执行）：

```php
    // upload_sessions 记录目标存储源
    $usCols = array_column($pdo->query('SHOW COLUMNS FROM upload_sessions')->fetchAll(), 'Field');
    if (!in_array('source_id', $usCols, true)) {
        $pdo->exec('ALTER TABLE upload_sessions ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER parent_id');
    }
```

**秒传分支也必须入队推送**（裁定 R11）：`upload_init()` 里「内容已在仓库里，直接建节点」那条路会返回 `instant: true`，前端拿到后就停了、**不会走到合并端点**。规格 §5.2 明确要求「秒传命中的是本地，不代表 COS 上有，推送照样要跑」。所以在该分支 `node_create_file` 之后、`json_ok` 之前加入：

```php
            // 秒传命中只是说明本地有；用户选了 COS 的话，副本仍要推上去
            if ($sourceId > 0) {
                tasks_enqueue_push($userId, (int) $blob['id'], $sourceId, $name);
            }
```

`$sourceId` 在该分支之前就已校验过（非零即本人可用的 s3 源），可直接使用。

`app/modules/upload.php`：会话创建处（`q_insert('upload_sessions', [...])`）加入：

```php
            'source_id'    => $sourceId > 0 ? $sourceId : null,
```

其中 `$sourceId` 来自 `input_int('source')`，并在使用前校验该源属于当前用户且 `driver='s3'`：

```php
    $sourceId = input_int('source');
    if ($sourceId > 0) {
        $ok = q_one('SELECT id FROM storage_sources WHERE id = ? AND user_id = ? AND driver = ?',
            [$sourceId, $userId, 's3']);
        if ($ok === null) {
            json_fail('存储源不存在或不可用。');
        }
    }
```

合并完成、`node_create_file` / `node_replace_content` 之后（现在 `search_index_node` / `rules_apply_to_node` 那一段附近）加入：

```php
    // 选了 COS：入队推送，由计划任务驱动的队列执行，不阻塞本次上传
    $targetSource = (int) ($session['source_id'] ?? 0);
    if ($targetSource > 0) {
        tasks_enqueue_push($userId, (int) $blob['blob_id'], $targetSource, (string) $node['name']);
    }
```

`upload.php` 顶部需确保已引入 tasks 模块——`tasks_enqueue_push()` 定义在 `app/modules/tasks.php`，而模块文件是按路由按需 require 的。加一行显式引入：

```php
require_once BASE_PATH . '/app/modules/tasks.php';
```

放在文件开头 `if (!defined('BASE_PATH'))` 守卫之后。

`app/modules/api.php` 的 `api_route_upload()` 中，`$parent` 解析之后加入同样的 `source` 处理，并在 `node_create_file` 之后入队 push（同一段代码，用户 id 为 `$userId`）。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t10_upload.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php tests/t6_push_task.php && php -l app/modules/upload.php && php -l app/modules/api.php`
Expected: 测试通过 + 两条无语法错误

---

### Task 11: `sources.php` 改走 `blob_locations`

**Files:**
- Modify: `app/modules/sources.php`（列表统计、删除前检查、删除清理、`sources_push()`）
- Modify: `app/schema.php`（`SCHEMA_VERSION` 4 → 5）
- Modify: `app/db.php`（幂等删除 `nodes.source_id`）
- Test: `tests/t11_sources.php`

**裁定 R2**：`nodes.source_id` 的删除**放在本任务**，不放在 Task 1。原因：Task 1 删列后，
`sources.php` 仍在 SELECT 它，只要存在任何一个存储源，存储源页就会报错（实测 400）；
而 `sources.php` 正是本任务才停止使用该列。列应当活到它最后一个使用点被迁移为止。

**Interfaces:**
- Consumes: `blob_location_*`（Task 2）、`blob_push_to_source()`（Task 5）
- Produces: 无新接口；`sources_push()` 语义变为「把未同步的内容推到该源」

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t11_sources.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$src = (string) file_get_contents(BASE_PATH . '/app/modules/sources.php');
// 注意：早期写的 `!str_contains($src, 'n.source_id')` 是个近乎空转的断言——
// `n.source_id` 并不是 `nodes.source_id` 的子串（点号前一个是 s 不是 n），
// 所以 sources.php 里留着 `nodes.source_id` 它照样通过。两种写法都要查。
check(!str_contains($src, 'nodes.source_id'), 'sources.php 不得再引用 nodes.source_id');
check(!preg_match('/\bn\.source_id\b/', $src), 'sources.php 不得再以别名引用 source_id');
check(!str_contains($src, 'UPDATE nodes SET source_id'), 'sources.php 不得再写 nodes.source_id');
check(str_contains($src, 'blob_locations'), 'sources.php 应改用 blob_locations');
check(str_contains($src, 'blob_push_to_source('), 'sources_push 应复用 blob_push_to_source');

check(
    !q_val("SHOW COLUMNS FROM nodes LIKE 'source_id'"),
    'nodes.source_id 应已在本任务被删除（裁定 R2）'
);

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t11_sources.php`
Expected: FAIL

- [ ] **Step 3: 实现**

先做删列：`app/schema.php` 的 `SCHEMA_VERSION` 从 4 改为 5，**并从 `nodes` 的 CREATE TABLE 里
删掉 `source_id` 那一行**（否则全新安装会先建列、紧接着又被守卫删掉，且 DDL 与实际结构自相矛盾；
本任务既然负责删列，就该把声明一起清掉），然后在 `app/db.php` 的 `db_migrate()`
DDL 循环之后、写入版本号之前加入幂等删列：

```php
    // nodes.source_id 已被 blob_locations 取代（node 级标记在去重下语义错误）。
    // 放在这里而不是 Task 1：sources.php 直到本任务才停止使用该列。
    $hasOld = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'source_id'")->fetch();
    if ($hasOld !== false) {
        $pdo->exec('ALTER TABLE nodes DROP COLUMN source_id');
    }
```

`app/modules/sources.php` 中：

- 第 29 行的 `nodeCount` 统计改为按 location 计：

```php
        $row['nodeCount'] = (int) q_val(
            'SELECT COUNT(*) FROM blob_locations WHERE source_id = ?',
            [(int) $row['id']]
        );
```

- 第 152 行的「使用中」检查改为：

```php
    $inUse = (int) q_val('SELECT COUNT(*) FROM blob_locations WHERE source_id = ?', [(int) $source['id']]);
```

- 删除源时（第 158 行附近）把 `UPDATE nodes SET source_id = NULL ...` 换成：

```php
    q_exec('DELETE FROM blob_locations WHERE source_id = ?', [(int) $source['id']]);
```

- `sources_push()` 整体替换为：

```php
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
```

`blob_push_to_source()` 需要 `app/lib/storage.php` 已加载——它在 bootstrap 里，无需额外 require。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t11_sources.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `curl -s -b "shiyun_sid=<测试会话>" -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:15244/index.php?r=sources.list"`
Expected: `200`

---

### Task 12: 文件状态展示

**Files:**
- Modify: `app/modules/files.php`（`files_info()` 返回 COS 状态）
- Modify: `app/lib/nodes.php`（`node_attach_meta()` 附带 location 状态，供列表徽标使用）
- Modify: `app/views/files.php`（信息对话框展示 + 重试按钮）
- Modify: `assets/js/files.js`（渲染状态与重试）
- Test: `tests/t12_status.php`

**Interfaces:**
- Consumes: `blob_locations_of_blob()`（Task 2）
- Produces: `node_public()` 输出新增键 `cosStatus`（`''|'ok'|'pending'|'failed'|'stale'`）与 `cosSourceId`（int）

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t12_status.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$tmp = sys_get_temp_dir() . '/t12.bin';
file_put_contents($tmp, 'status-check');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't12-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://x', 'region' => 'r',
        'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);
blob_location_record($blobId, $sourceId, 'aa/x', 12);

$row = q_one('SELECT * FROM blobs WHERE id = ?', [$blobId]);
$node = ['id' => 0, 'user_id' => $userId, 'blob_id' => $blobId, 'kind' => 'file',
    'name' => 't12.bin', 'name_key' => 't12.bin', 'size' => 12, 'mime' => 'text/plain',
    'ext' => 'bin', 'parent_id' => null, 'path' => '/1/', 'depth' => 1,
    'is_favorite' => 0, 'is_hidden' => 0,
    'is_locked' => 0, 'is_private' => 0, 'color' => null, 'note' => null,
    'child_count' => 0, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()];

// 必须先经过 node_attach_meta()——cos 状态由它批量取好、并进行里。
// 直接 node_public(裸节点) 拿不到，也不该拿到（那会逼 node_public 去查库，见裁定 R14）。
$pub = node_public(node_attach_meta($userId, [$node])[0]);
check(array_key_exists('cosStatus', $pub), 'node_public 应含 cosStatus');
check($pub['cosStatus'] === 'pending', 'pending 的 location 应反映为 pending');

blob_location_mark_failed($blobId, $sourceId, 'x');
$pub2 = node_public(node_attach_meta($userId, [$node])[0]);
check($pub2['cosStatus'] === 'failed', 'failed 的 location 应反映为 failed');

// 未挂 meta 的裸节点必须安全降级为空状态，而不是抛错、也不是偷偷查库
$bare = node_public($node);
check($bare['cosStatus'] === '' && $bare['cosSourceId'] === 0,
    '未附加 meta 时应安全降级为空状态');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t12_status.php`
Expected: FAIL — `node_public 应含 cosStatus`

- [ ] **Step 3: 实现**

`app/lib/nodes.php` 的 `node_attach_meta()` 里，已有的批量查询之后追加一次批量 location 查询（沿用 `IN (...)` 批处理风格，不要引入 N+1）：

```php
    // 必须按 storage_sources.user_id 过滤（全局约束，裁定 R16）。
    // blobs 跨用户去重，不过滤的话，别人的源的状态会显示在你的文件上，
    // cosSourceId 还会把别人的源 id 一并泄漏出去。
    $cosMap = [];
    foreach (q_all(
        "SELECT bl.blob_id, bl.source_id, bl.status
         FROM blob_locations bl
         JOIN storage_sources s ON s.id = bl.source_id
         WHERE bl.blob_id IN ({$blobIn}) AND s.user_id = ?",
        [...$blobIds, $userId]
    ) as $row) {
        $cosMap[(int) $row['blob_id']] = [
            'sourceId' => (int) $row['source_id'],
            'status'   => (string) $row['status'],
        ];
    }
```

其中 `$blobIds` 由 `$rows` 里非空的 `blob_id` 去重得到，`$blobIn` 是等量的占位符串；若为空数组则整段跳过。

随后在 `node_public()` 输出中带上：

```php
        'cosStatus'   => $node['cos']['status'] ?? '',
        'cosSourceId' => $node['cos']['sourceId'] ?? 0,
```

**`node_public()` 里绝对不许查库**（裁定 R14）。它只读调用方已经放进行里的 `'cos'` 键——经 `node_attach_meta()` 批处理过的行自带这个键；没有就取 `''`/`0`。原因：`node_public()` 有 27 处调用点，其中 `files.php` 的移动/复制是在**循环里逐个调**的（`$moved[] = node_public($result)`），一旦它内部按需查库，移动 50 个文件就是 50 次额外查询——正是 `node_attach_meta()` 用批量 `IN (...)` 费劲避开的 N+1。

`app/modules/files.php` 的 `files_info()` 返回值里加上同样两个键（它是文件信息对话框的数据源）。

`app/views/files.php` 的信息对话框模板中加入一行（图标只用已映射的 `lucide()`）：

```php
<div class="facts__row" data-cos-row<?= $node['cosStatus'] === '' ? ' hidden' : '' ?>>
    <dt>COS 副本</dt>
    <dd>
        <span data-cos-state><?= e(match ($node['cosStatus']) {
            'ok'      => '已同步',
            'pending' => '推送中',
            'failed'  => '推送失败',
            'stale'   => '副本失效',
            default   => '未同步',
        }) ?></span>
        <button class="minibtn" type="button" data-cos-retry hidden>重新推送</button>
    </dd>
</div>
```

`assets/js/files.js`：在渲染文件信息的函数里，按 `node.cosStatus` 切换 `[data-cos-state]` 文案与 `[data-cos-retry]` 的显隐（`failed` / `stale` 时显示按钮），并绑定：

```js
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-cos-retry]');
    if (!btn) return;
    try {
        await SY.api('sources.retryOne', { id: btn.dataset.nodeId });
        SY.toast('已加入推送队列');
    } catch (err) {
        SY.toast(err.message, 'err');
    }
});
```

配套在 `app/modules/sources.php` 新增动作 `sources_retryOne()`（`require_csrf`，校验节点属于当前用户，取 `blob_id` 与所选源，调 `tasks_enqueue_push()` 后返回成功）。把它加入 `PUBLIC_ROUTES` 之外——它是登录后接口，无需加入白名单。

**徽标必须按状态上色**（裁定 R13）。只写 `class="badge badge--cos"` 是不够的：`assets/css/` 里**没有** `badge--cos` 的规则，徽标会退回 `.badge` 的 `color: var(--dim-2)`，于是「推送失败」和「已锁定」「已隐藏」长得一模一样——而用户选 B 方案时明确要的是**「界面明确标注」**失败，一个暗灰小图标不满足这个要求。

两处（`app/views/files.php` 的 PHP 分支、`assets/js/files.js` 的 `badges()`）都要把状态一并写进 class，即 `badge--cos badge--cos-<status>`；然后在 `assets/css/files.css` 的 `.badge--star` 附近加入：

```css
/* COS 副本状态：失败要一眼看得见（用户明确要求「明确标注」） */
.badge--cos-ok {
    color: var(--signal-2);
}

.badge--cos-failed {
    color: var(--danger);
}

.badge--cos-stale {
    color: #ffcf8a;
}
```

`pending` 不额外着色，沿用 `.badge` 的暗色（进行中本就该低调）。颜色取自既有 token：`--signal-2`（与星标同族，表示「有这样一份」）、`--danger`、以及 `hint--warn` 一直在用的琥珀 `#ffcf8a`。

**顺带修一个既有缺陷**（裁定 R15）。`assets/js/files.js` 里 `modalBody` / `modalFoot` 在 `doInfo`、`doRename`、`doNote`、`doShare` 等多处被当作**自由变量**使用，但模块作用域从未声明它们（只在 `doTag` / `doVersions` 内有局部 `const`）。实测确认：页面上下文里 `typeof modalFoot === 'undefined'`，所以这些对话框一打开就在 `modalFoot.querySelector(...)` 抛 `ReferenceError`——信息对话框的页脚按钮不绑定，还会弹出「modalFoot is not defined」。

这**不是本次功能引入的**（改动前后使用次数都是 36、声明都只有 2 处），但它让本任务的成果——信息对话框里的 COS 行——实际不可用。在 `files.js` 模块作用域顶部（`const app = document.querySelector('[data-app]');` 之后）加两行即可：

```js
const modalBody = document.querySelector('[data-modal-body]');
const modalFoot = document.querySelector('[data-modal-foot]');
```

这样信息对话框可用，重命名/备注/分享等同样受影响的对话框也一并修好。做完请**实测确认**：打开文件信息对话框，控制台不再抛 `ReferenceError`、页脚按钮可点。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t12_status.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php tests/t2_locations.php && php -l app/lib/nodes.php && php -l app/modules/sources.php`
Expected: 测试通过 + 两条无语法错误

---

### Task 13: 上传后端选择 UI

**Files:**
- Modify: `app/modules/files.php`（`files_list()` 传入可选存储源列表与上次选择）
- Modify: `app/views/files.php`（「存到 ▾」下拉）
- Modify: `assets/js/upload.js`（提交时带 `source`；切换时记住）
- Modify: `app/modules/settings.php`（`settings.preferences` 保存 `upload_source_id`）
- Test: `tests/t13_ui.php`

**Interfaces:**
- Consumes: `storage_sources` 表、`user_settings`
- Produces: 视图变量 `$uploadSources`（`list<array{id:int,name:string}>`）与 `$uploadSourceId`（int）

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t13_ui.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$view = (string) file_get_contents(BASE_PATH . '/app/views/files.php');
check(str_contains($view, 'data-upload-source'), '视图应有 data-upload-source 下拉');

$js = (string) file_get_contents(BASE_PATH . '/assets/js/upload.js');
check(str_contains($js, 'source'), 'upload.js 提交时应带 source 字段');
check(str_contains($js, 'upload_source_id'), 'upload.js 应记住上次选择');

$settings = (string) file_get_contents(BASE_PATH . '/app/modules/settings.php');
check(str_contains($settings, 'upload_source_id'), '设置应可保存 upload_source_id');

$files = (string) file_get_contents(BASE_PATH . '/app/modules/files.php');
check(str_contains($files, 'uploadSources'), 'files_list 应传入可选存储源');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t13_ui.php`
Expected: FAIL

- [ ] **Step 3: 实现**

`app/modules/files.php` 的 `files_list()` 里，`render(...)` 之前加入：

```php
    $uploadSources = array_map(
        static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
        q_all(
            "SELECT id, name FROM storage_sources
             WHERE user_id = ? AND driver = 's3' ORDER BY priority DESC, id ASC",
            [$userId]
        )
    );
    $uploadSourceId = (int) user_setting($userId, 'upload_source_id', 0);
```

并把 `'uploadSources' => $uploadSources,` 与 `'uploadSourceId' => $uploadSourceId,` 加进 `render()` 的数据数组。

`app/views/files.php`：在上传按钮旁加下拉（沿用现有 `.select` 样式，图标用 `lucide('hard-drive')`）：

```php
<label class="mfield" style="min-width:150px">
    <span class="mfield__label">存到</span>
    <select class="select" data-upload-source style="width:100%">
        <option value="0"<?= $uploadSourceId === 0 ? ' selected' : '' ?>>本地磁盘</option>
        <?php foreach ($uploadSources as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= $uploadSourceId === (int) $s['id'] ? ' selected' : '' ?>>
                <?= e((string) $s['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>
```

`assets/js/upload.js`：上传时读取当前选择并附加到每个分片初始化与完成请求的载荷：

```js
function currentSource() {
    return Number(document.querySelector('[data-upload-source]')?.value || 0);
}
```

在 `start`（初始化上传会话）请求体里加 `source: currentSource()`。在选择变化时持久化：

```js
document.querySelector('[data-upload-source]')?.addEventListener('change', (e) => {
    SY.api('settings.preferences', { upload_source_id: Number(e.target.value) })
        .catch(() => { /* 静默：记住选择失败不影响上传 */ });
});
```

`app/modules/settings.php` 的 `settings_preferences()` 白名单里加入 `upload_source_id`（int，且校验该源属于当前用户或为 0）。

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t13_ui.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `node --check assets/js/upload.js && php -l app/modules/files.php && php -l app/modules/settings.php`
Expected: 三条通过

---

### Task 14: 文案与接口说明

**Files:**
- Modify: `app/views/settings.php`（限速项旁说明）
- Modify: `app/modules/api.php`（`api_endpoint_help()` 给下载加注）
- Test: `tests/t14_copy.php`

**Interfaces:**
- Consumes: 无
- Produces: 无新接口；纯文案

spec §6.4 要求「限速对 COS 直链失效」与「API 下载会 302」两件事对用户可见，否则用户会误以为限速仍在生效、或以为 API 坏了。

- [ ] **Step 1: 写失败验证脚本**

创建 `tests/t14_copy.php`：

```php
<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$settings = (string) file_get_contents(BASE_PATH . '/app/views/settings.php');
check(str_contains($settings, 'COS'), '设置页限速项旁应说明对 COS 直链无效');

$api = (string) file_get_contents(BASE_PATH . '/app/modules/api.php');
check(str_contains($api, '302'), '接口说明应写明下载会 302');

finish();
```

- [ ] **Step 2: 运行验证，确认失败**

Run: `php tests/t14_copy.php`
Expected: FAIL — `设置页限速项旁应说明对 COS 直链无效`

- [ ] **Step 3: 实现**

在 `app/views/settings.php` 里限速输入项下方的提示文字后追加一句：

```php
<p class="hint">限速仅对存在本地的文件生效；已托管到 COS 的文件下载时直连对象存储，不受此处限速影响。</p>
```

在 `app/modules/api.php` 的 `api_endpoint_help()` 数组中，把下载那一行改为：

```php
        ['GET',  'api.handle&path=download',  '下载（id）。若文件已托管到 COS，返回 302 跳转，调用方需跟随重定向'],
```

- [ ] **Step 4: 运行验证，确认通过**

Run: `php tests/t14_copy.php`
Expected: 全部通过

- [ ] **Step 5: 回归检查**

Run: `php -l app/modules/api.php && php -l app/views/settings.php`
Expected: 两条 `No syntax errors detected`

---

### Task 15: 端到端验证（需真实 COS 凭证）

**Files:**
- Test: `tests/t14_e2e.md`（记录验证步骤与结果，不写代码）

**Interfaces:**
- Consumes: 全部前序任务
- Produces: 验证结论；预签名功能从「未验证」转为「已验证」

**前置**：用户已在「存储源」页配好一个真实的 s3 源（COS），且「测试」通过。

- [ ] **Step 1: 确认源可用**

在「存储源」页对目标源点「测试」，须返回桶可访问。若失败，先修配置（endpoint 格式、bucket 是否带 APPID 后缀、region 是否与 endpoint 一致），不要继续。

- [ ] **Step 2: 上传选 COS，确认对象落地**

在文件管理器把「存到」切到该源，上传一个已知内容的小文件（例如 `测试.txt`，内容 `hello-cos`）。
然后去 COS 控制台确认对象存在，键名应为 `{hash前2位}/{hash}`（若配了路径前缀则在其下）。

Run: `mysql -ushiyun -p$MYSQL_PASSWORD shiyun -e "SELECT status, object_key, error FROM blob_locations ORDER BY id DESC LIMIT 3\G"`
Expected: 最新一行为 `status = ok`，`error` 为空

- [ ] **Step 3: 验证队列真的推进了**

Run: `curl -s "http://127.0.0.1:15244/index.php?r=tasks.cron&key=$CRON_KEY"`
Expected: `{"ok":true,...,"processed":N,...}`；任务页应能看到一条「推送副本」已完成

- [ ] **Step 4: 验证下载 302 且文件名正确**

Run: `curl -s -o /dev/null -D - -b "shiyun_sid=<测试会话>" "http://127.0.0.1:15244/index.php?r=download.get&id=<节点id>" | head -5`
Expected: 第一行为 `HTTP/1.1 302`，且 `Location:` 指向 `cos.<region>.myqcloud.com` 且含 `X-Amz-Signature=`

Run: `curl -sL -o /tmp/e2e-dl.bin -b "shiyun_sid=<测试会话>" "http://127.0.0.1:15244/index.php?r=download.get&id=<节点id>" && cmp -s /tmp/e2e-dl.bin <原始文件>`
Expected: `cmp` 无输出（字节完全一致）——这一条同时证明预签名 URL 被 COS 接受

- [ ] **Step 5: 验证 HEAD 回退**

在 COS 控制台删掉刚上传的那个对象，然后再次下载。

Run: 同 Step 4 的第二条命令
Expected: 仍能下到正确字节（回退本地），且

Run: `mysql -ushiyun -p$MYSQL_PASSWORD shiyun -e "SELECT status, error FROM blob_locations ORDER BY id DESC LIMIT 1\G"`
Expected: `status = stale`

- [ ] **Step 6: 验证去重不重复推送**

把同一份内容再上传一次（仍选该源）。上传后查 location 表应**仍只有一行**，且任务页不出现新的推送任务。

- [ ] **Step 7: 验证删除会清理远程对象**

彻底删除该文件，跑一次 cron，然后去 COS 控制台确认对象已消失。

- [ ] **Step 8: 验证护栏（key 复用不误删）**

删除某文件 → 立刻重新上传**完全相同的内容**（仍选该源）→ 在 `purge` 任务执行前跑一次 cron 使删除任务与推送任务都排空 → 确认 COS 上新对象**仍然存在**。

- [ ] **Step 9: 记录结论**

把上述每步的实际输出写入 `tests/t14_e2e.md`，并明确标注预签名是否通过真实 COS 校验。若某步失败，如实记录，不要标记为通过。

---

## 附：执行顺序与依赖

```
T1 数据层
 ├─ T2 locations 助手
 │   ├─ T5 push 逻辑 ── T6 push 任务 ── T10 上传接入 ── T13 上传 UI
 │   └─ T8 下载分发 ── T9 端点接入
 ├─ T3 presign ──── T8
 └─ T4 远程删除 ── T7 purge 任务（依赖 T2、T5）
                        └─ T11 sources 改造
                              └─ T12 状态展示
T14 文案与接口说明（独立，随时可做）
T15 端到端（依赖全部，且需真实凭证）
```

T3 与 T4 只依赖 T1，可与 T2 并行。T12、T13、T14 三者互不依赖。
