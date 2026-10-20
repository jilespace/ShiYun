# 文件直链（Direct Link）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 给单个文件生成一条免登录、可直接嵌入网页或交给别的服务长期取用的字节流 URL，可随时撤销。

**Architecture:** 新建 `direct_links` 表存令牌（长期有效 + 可撤销 + 可选绑来源 IP），新增公开路由 `direct.serve` 交付字节。准入判定全部抽进 `lib/directs.php` 的 `direct_resolve()`，路由里不留 `if`。顺带把内联类型白名单收进 `blob_stream()` 一个咽喉点，修掉 `shares.raw` 的既有内联 XSS。

**Tech Stack:** PHP 8.5（无框架、无 Composer、无构建步骤）+ MySQL 8.0 + 原生 JS/CSS。

**Spec:** `docs/superpowers/specs/2026-10-18-direct-link-design.md`

## Global Constraints

- PHP **≥ 8.1**（用到 `never` 返回类型与 `match`）；测试环境为 PHP 8.5.9。
- 无 Composer、无 npm、无构建步骤。改完文件刷新即生效。
- 所有 SQL 走预处理语句（`q_one` / `q_all` / `q_val` / `q_insert` / `q_update` / `q_exec`）。
- 所有输出 HTML 转义（`e()`）；文件名永远不当作 HTML。
- 路由是查询串形式：`index.php?r=模块.动作` → `app/modules/模块.php` 的 `模块_动作()`。
- 未登录可访问的路由必须登记进 `app/router.php` 的 `PUBLIC_ROUTES`。
- **测试必须以 Web 服务运行身份执行**：`runuser -u www -- php tests/t20_direct.php`。用 root 跑会在 `runtime/storage` 留下 root 属主目录，之后 www 写不进去。
- 表结构改动要把 `app/schema.php` 的 `SCHEMA_VERSION` 加一，下次请求自动补建。
- 设计系统：控制台页面用 3px 圆角（`--radius-sm`），认证页用 10–12px。只用 `app/icons.php` 里已有的 Lucide 图标，不加新图标文件。
- 文案用中文，句末不加句号（与现有界面一致）。不用 emoji。

## Review Focus

按「最可能咬到使用者」排序。每条都在下方对应任务里有测试钉住。

1. **绑了来源 IP 的直链在反向代理 / 内网穿透后必然 403。** `client_ip()`（`app/helpers.php:72`）只读 `$_SERVER['REMOTE_ADDR']`，不看 `X-Forwarded-For`。本机跑着樱花内网穿透，穿透后 `REMOTE_ADDR` 是隧道地址而非访客真实 IP。使用者会以为「填了 IP 更安全」，实际是「填了就永远打不开」。**本计划不修 `client_ip()`**——信任 XFF 而不校验来源本身就是漏洞，属于独立改动。改为在界面文案上明说这条限制，并在 Task 11 实测确认行为。→ Task 7、Task 9、Task 11。
2. **`/0` 与 `/32` 两个 CIDR 边界。** 掩码用 `str_repeat` + `str_pad` 拼，`/0` 写错会变成「谁都不匹配」（比「谁都匹配」更隐蔽，因为它看起来像被拒绝了）。→ Task 5。
3. **`&dl=1` 与内联白名单的交叉。** `.jpg` 带 `dl=1` 必须 attachment；`.html` 不带 `dl=1` 也必须 attachment。两个条件任何一个写反都会让「点开就下」或「防 XSS」失效。→ Task 1、Task 7。
4. **HEAD 与 Range 的处理顺序。** 若 HEAD 短路写在 header 块之前，HEAD 会漏掉 `Content-Length`；若写在 `$partial` 计算之后但 header 之前，逻辑仍对但 Range+HEAD 组合可能给出 `Content-Range`。播放器普遍先 HEAD 再 Range。→ Task 2、Task 11。
5. **token 大小写。** `random_token()` 产出小写十六进制，校验正则 `^[a-f0-9]{32}$` 拒绝大写。使用者手动把 URL 复制进别处再粘贴时若被工具改成大写，会得到 404 而不是可用的链接。→ Task 5。

---

## 文件结构

| 文件 | 动作 | 职责 |
|---|---|---|
| `app/helpers.php` | 改 | 新增 `mime_inline_ok()`：内联类型白名单，无依赖纯函数 |
| `app/lib/storage.php` | 改 | `blob_stream()` 加内联守卫与 HEAD 短路；`blob_direct_url()` / `blob_deliver()` 加 `$disposition` |
| `app/schema.php` | 改 | `SCHEMA_VERSION` 10 → 11；新增 `direct_links` 表 |
| `app/lib/directs.php` | 新 | 直链领域逻辑：令牌、IP 匹配、准入判定、对外形状 |
| `app/modules/direct.php` | 新 | 路由：`direct_serve()` / `direct_create()` / `direct_revoke()` |
| `app/bootstrap.php` | 改 | 装载 `lib/directs.php` |
| `app/router.php` | 改 | `PUBLIC_ROUTES` 加 `direct.serve` |
| `app/lib/activity.php` | 改 | `activity_label()` 补 `direct.create` / `direct.revoke` |
| `app/lib/nodes.php` | 改 | 批量取 `direct_count`；`node_public()` 出 `directCount` |
| `app/modules/shares.php` | 改 | `shares_list()` 同时查直链，传 `tab` |
| `app/views/shares.php` | 改 | 分段控件 + 直链面板 |
| `assets/js/console.js` | 改 | 直链标签的复制 / 作废交互 |
| `assets/js/files.js` | 改 | 右键「直链」菜单 + 生成面板 + 卡片角标 |
| `tests/t20_direct.php` | 新 | 单元测试（CLI 直调，不走 HTTP） |

---

## Task 1: 内联类型白名单 `mime_inline_ok()`

**Files:**
- Modify: `app/helpers.php`（在 `is_textual()` 之后插入，约 `app/helpers.php:260` 后）
- Test: `tests/t20_direct.php`（新建）

**Interfaces:**
- Consumes: 无
- Produces: `mime_inline_ok(string $mime): bool` — 供 `blob_stream()`（Task 2）与 `direct_serve()`（Task 7）使用

- [ ] **Step 1: 写失败的测试**

新建 `tests/t20_direct.php`：

```php
<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

/* 直链（direct_links）：免登录、可撤销、可选绑来源 IP 的单文件字节流入口。 */

// ---------------------------------------------------------------- 内联白名单

check(mime_inline_ok('image/jpeg'), 'jpeg 应可内联');
check(mime_inline_ok('image/png'), 'png 应可内联');
check(mime_inline_ok('image/gif'), 'gif 应可内联');
check(mime_inline_ok('image/webp'), 'webp 应可内联');
check(mime_inline_ok('image/avif'), 'avif 应可内联');
check(mime_inline_ok('image/bmp'), 'bmp 应可内联');
check(mime_inline_ok('video/mp4'), 'mp4 应可内联');
check(mime_inline_ok('audio/mpeg'), 'mp3 应可内联');
check(mime_inline_ok('application/pdf'), 'pdf 应可内联');
check(mime_inline_ok('text/plain'), '纯文本应可内联');

// 带参数（真实响应里常见 image/jpeg; charset=...）
check(mime_inline_ok('text/plain; charset=utf-8'), '带参数的 MIME 应能识别');
check(mime_inline_ok('IMAGE/PNG'), '大小写不敏感');

// 这些能执行脚本，绝不能内联
check(!mime_inline_ok('text/html'), 'html 不得内联');
check(!mime_inline_ok('image/svg+xml'), 'svg 不得内联（可含 script）');
check(!mime_inline_ok('application/xhtml+xml'), 'xhtml 不得内联');
check(!mime_inline_ok('application/javascript'), 'js 不得内联');
check(!mime_inline_ok('application/xml'), 'xml 不得内联');
check(!mime_inline_ok('text/xml'), 'text/xml 不得内联');

// 未知 / 空
check(!mime_inline_ok('application/octet-stream'), '未知类型不得内联');
check(!mime_inline_ok(''), '空 MIME 不得内联');

finish();
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `未捕获异常：Error: Call to undefined function mime_inline_ok()`

- [ ] **Step 3: 写最小实现**

在 `app/helpers.php` 的 `is_textual()` 函数之后插入：

```php
/**
 * 该 MIME 是否可以作为内联内容发给浏览器。
 *
 * 白名单而非黑名单——黑名单永远漏。能进来的必须是浏览器会当作「数据」
 * 渲染的类型；text/html、image/svg+xml 这类可以执行脚本的一律排除，
 * 否则在站点同源下就是存储型 XSS。
 */
function mime_inline_ok(string $mime): bool
{
    $mime = strtolower(trim(explode(';', $mime)[0]));

    if (in_array($mime, [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/bmp', 'application/pdf', 'text/plain',
    ], true)) {
        return true;
    }

    return str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
}
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`，末行「全部通过」

- [ ] **Step 5: 提交**

```bash
git add app/helpers.php tests/t20_direct.php
git commit -m "feat: 内联类型白名单 mime_inline_ok()"
```

---

## Task 2: `blob_stream()` 内联守卫与 HEAD

**Files:**
- Modify: `app/lib/storage.php:336-350`（`blob_stream()` 的头部区段）
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: `mime_inline_ok()`（Task 1）
- Produces: 无新签名——`blob_stream()` 签名不变，行为收紧

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ------------------------------------------------- blob_stream 的守卫与 HEAD

$storage = (string) file_get_contents(BASE_PATH . '/app/lib/storage.php');
check(str_contains($storage, 'mime_inline_ok($mime)'),
    'blob_stream 应用 mime_inline_ok 守卫内联');

// 顺序断言必须先确认两侧都存在。strpos 找不到时返回 false，
// 而 PHP 里 `false < 344` 为真——只写比较的话，守卫整段缺失时这条会空洞通过。
$guardPos = strpos($storage, 'mime_inline_ok($mime)');
$dispPos  = strpos($storage, "header('Content-Disposition: '");
check($guardPos !== false && $dispPos !== false && $guardPos < $dispPos,
    '守卫必须在写 Content-Disposition 之前生效');

// 必须匹配完整的 HEAD 守卫表达式。只找 "'HEAD'" 会命中 blob_direct_url 里的
// s3_request($config, 'HEAD', ...)，那处在 Content-Disposition 之前，顺序断言会假挂。
$headGuard = "\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'";
$headPos = strpos($storage, $headGuard);
check($headPos !== false, 'blob_stream 应短路 HEAD');
check($headPos !== false && $headPos > $dispPos,
    'HEAD 短路必须写在 header 块之后（否则会漏掉 Content-Length）');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — 4 条 `FAIL`（`mime_inline_ok($mime)` 与 `'HEAD'` 尚未出现在 storage.php）

- [ ] **Step 3: 改 `blob_stream()`**

在 `app/lib/storage.php` 中，把这一段：

```php
    $length = $end - $start + 1;

    if (!headers_sent()) {
        header_remove('Content-Type');
        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="'
            . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, max-age=0, must-revalidate');
        if ($partial) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
    }

    $fh = fopen($path, 'rb');
```

替换为：

```php
    $length = $end - $start + 1;

    // 调用方传 inline=true 不代表就能内联：text/html、image/svg+xml 这类
    // 会在本域名下执行脚本。白名单不放行的一律降级为下载。
    $inline = $inline && mime_inline_ok($mime);

    if (!headers_sent()) {
        header_remove('Content-Type');
        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="'
            . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, max-age=0, must-revalidate');
        if ($partial) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
    }

    // HEAD 只要元信息：头部已经发完，不读文件
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $fh = fopen($path, 'rb');
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`

- [ ] **Step 5: 确认既有调用方没被打破**

```bash
runuser -u www -- php tests/t8_deliver.php
```

Expected: 全部通过（`blob_stream` 签名未变）

- [ ] **Step 6: 提交**

```bash
git add app/lib/storage.php tests/t20_direct.php
git commit -m "fix: blob_stream 内联白名单守卫与 HEAD 支持

shares.raw 是公开路由，把文件原始 MIME 原样作为 Content-Type 发出，
上传 .html 或含 script 的 .svg 即可在站点同源下执行脚本。"
```

---

## Task 3: `blob_direct_url()` / `blob_deliver()` 加 `$disposition`

**Files:**
- Modify: `app/lib/storage.php:241`（`blob_direct_url()` 签名）、`app/lib/storage.php:263-274`（`s3_presign` 调用）、`app/lib/storage.php:280-293`（`blob_deliver()`）
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: 无
- Produces:
  - `blob_direct_url(int $userId, int $blobId, string $mime, string $filename, string $disposition = 'attachment'): ?string`
  - `blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0, string $disposition = 'attachment'): never`

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ------------------------------------------- blob_direct_url 的 disposition

$tmpT3 = sys_get_temp_dir() . '/t15-t3.bin';
file_put_contents($tmpT3, 'inline-me');
$blobT3 = blob_ingest_file($tmpT3, null, 'text/plain');
$blobT3Id = (int) $blobT3['blob_id'];
$userT3 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');

// 无可用的对象存储副本 → 两个 disposition 都返回 null（回退本地）
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt', 'inline') === null,
    '无副本时 inline 请求也应回退');
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt') === null,
    '默认参数应保持既有行为（attachment）');

$ref = new ReflectionFunction('blob_direct_url');
check($ref->getParameters()[4]->getName() === 'disposition',
    'blob_direct_url 第 5 个参数应为 disposition');
check($ref->getParameters()[4]->getDefaultValue() === 'attachment',
    'blob_direct_url 的 disposition 默认值应为 attachment');

$refD = new ReflectionFunction('blob_deliver');
check($refD->getParameters()[5]->getName() === 'disposition',
    'blob_deliver 第 6 个参数应为 disposition');
check($refD->getParameters()[5]->getDefaultValue() === 'attachment',
    'blob_deliver 的 disposition 默认值应为 attachment');

// 签名存在 ≠ 参数被使用。把真正实现功能的那几行也钉住，否则加了参数却把
// $disposition 从 presign 值或本地回退里丢掉，上面的反射断言照样全绿。
check(strpos($storage, "\$disposition . '; filename") !== false,
    'blob_direct_url 应把 disposition 用在 presign 的 response-content-disposition 上');
check(str_contains($storage, '$disposition === \'inline\''),
    'blob_deliver 的本地回退应由 disposition 推导 inline');
check(str_contains($storage, 'mime_inline_ok($mime) ? $disposition : '),
    'blob_direct_url 应对非白名单类型强制降级为 attachment');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — 参数不存在（`ArgumentCountError` 或反射断言失败）

- [ ] **Step 3: 改签名与实现**

`app/lib/storage.php`，`blob_direct_url()` 签名改为（并在函数体开头加一道与 `blob_stream()` 同源的内联守卫——302 路径不经过 `blob_stream()`，少了这行，`text/html` + `inline` 会从这条路径把可执行内容内联发出去）：

```php
function blob_direct_url(int $userId, int $blobId, string $mime, string $filename, string $disposition = 'attachment'): ?string
{
    // 与 blob_stream() 的守卫同源：内联只对白名单类型生效。
    $disposition = mime_inline_ok($mime) ? $disposition : 'attachment';

    $loc = blob_location_direct($userId, $blobId);
```

同函数内的 `s3_presign()` 调用，把硬编码的 `'attachment;` 换成参数：

```php
    return s3_presign(
        $config,
        'GET',
        (string) $loc['object_key'],
        (int) config('presign_ttl', 7200),
        [
            'response-content-disposition' =>
                $disposition . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'response-content-type' => $mime,
        ]
    );
```

`blob_deliver()` 整体改为：

```php
function blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0, string $disposition = 'attachment'): never
{
    $url = blob_direct_url($userId, $blobId, $mime, $filename, $disposition);
    if ($url !== null) {
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Location: ' . $url, true, 302);
        }
        exit;
    }

    blob_stream($blobId, $mime, $filename, $disposition === 'inline', $speedLimitKB);
}
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php && runuser -u www -- php tests/t8_deliver.php
```

Expected: 两个文件都全部通过（既有调用方用的是默认值，行为不变）

- [ ] **Step 5: 提交**

```bash
git add app/lib/storage.php tests/t20_direct.php
git commit -m "feat: blob_direct_url / blob_deliver 支持 disposition 参数"
```

---

## Task 4: `direct_links` 表

**Files:**
- Modify: `app/schema.php:13`（`SCHEMA_VERSION`）、`app/schema.php` 末尾 `return $sql;` 之前
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: 无
- Produces: `direct_links` 表，列为 `id / user_id / node_id / token / ip_allow / expires_at / status / hit_count / last_access_at / created_at`

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ------------------------------------------------------------ direct_links 表

db();

check((bool) q_val("SHOW TABLES LIKE 'direct_links'"), 'direct_links 表应已创建');

$colsT4 = array_column(q_all('SHOW COLUMNS FROM direct_links'), 'Field');
foreach (['user_id', 'node_id', 'token', 'ip_allow', 'expires_at',
          'status', 'hit_count', 'last_access_at', 'created_at'] as $c) {
    check(in_array($c, $colsT4, true), "direct_links 应有列 {$c}");
}

$statusTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'status') {
        $statusTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($statusTypeT4, 'active') && str_contains($statusTypeT4, 'revoked'),
    'status 枚举应为 active / revoked');

$tokTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'token') {
        $tokTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($tokTypeT4, 'char(32)'), 'token 应为 CHAR(32)');

$idxT4 = array_column(q_all('SHOW INDEX FROM direct_links'), 'Key_name');
check(in_array('uk_direct_token', $idxT4, true), 'token 应有唯一索引');

// ip_allow 与 expires_at 必须可空（不限来源 / 永久有效）
$nullsT4 = [];
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    $nullsT4[$row['Field']] = (string) $row['Null'];
}
check($nullsT4['ip_allow'] === 'YES', 'ip_allow 应可空');
check($nullsT4['expires_at'] === 'YES', 'expires_at 应可空');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `direct_links 表应已创建` 及后续列断言

- [ ] **Step 3: 加表与版本号**

`app/schema.php:13` 改为（当前值是 **10**，不是 6——期间已有别的功能把版本推到 10）：

```php
const SCHEMA_VERSION = 11;
```

在 `app/schema.php` 末尾 `return $sql;` 之前插入：

```php
    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS direct_links (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id        BIGINT UNSIGNED NOT NULL,
            node_id        BIGINT UNSIGNED NOT NULL,
            token          CHAR(32)        NOT NULL,
            ip_allow       VARCHAR(64)     NULL,
            expires_at     DATETIME        NULL,
            status         ENUM('active','revoked') NOT NULL DEFAULT 'active',
            hit_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_access_at DATETIME        NULL,
            created_at     DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_direct_token (token),
            KEY idx_direct_user (user_id, created_at),
            KEY idx_direct_node (node_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php && runuser -u www -- php tests/t1_schema.php
```

Expected: 两个都全部通过

- [ ] **Step 5: 提交**

```bash
git add app/schema.php tests/t20_direct.php
git commit -m "feat: direct_links 表（SCHEMA_VERSION 11）"
```

---

## Task 5: `lib/directs.php` 基础件

**Files:**
- Create: `app/lib/directs.php`
- Modify: `app/bootstrap.php:49`（在 `lib/shares.php` 之后装载）
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: `random_token()`（`app/helpers.php:159`）
- Produces:
  - `direct_token(): string`
  - `direct_normalize_ip(string $input): ?string`
  - `direct_ip_ok(array $row, string $ip): bool`
  - `direct_is_live(array $row): bool`
  - `direct_find(string $token): ?array`
  - `direct_public(array $row): array`

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ---------------------------------------------------------- directs 基础件

// 令牌
$tk = direct_token();
check(preg_match('/^[a-f0-9]{32}$/', $tk) === 1, '令牌应为 32 位小写十六进制');
check(direct_token() !== direct_token(), '两次令牌应不同');

// IP 规范化
check(direct_normalize_ip('203.0.113.7') === '203.0.113.7', 'IPv4 单地址应原样通过');
check(direct_normalize_ip('203.0.113.0/24') === '203.0.113.0/24', 'IPv4 CIDR 应通过');
check(direct_normalize_ip('  203.0.113.7  ') === '203.0.113.7', '应去除首尾空白');
check(direct_normalize_ip('2001:db8::1') === '2001:db8::1', 'IPv6 单地址应通过');
check(direct_normalize_ip('') === null, '空串应返回 null');
check(direct_normalize_ip('203.0.113.0/33') === null, '掩码超 32 应拒绝');
check(direct_normalize_ip('999.1.1.1') === null, '非法 IPv4 应拒绝');
check(direct_normalize_ip('2001:db8::/32') === null, 'IPv6 CIDR 应拒绝');
check(direct_normalize_ip('随便写点中文') === null, '乱码应拒绝');
check(direct_normalize_ip('203.0.113.0/abc') === null, '非数字掩码应拒绝');

// IP 匹配
$rowNoIp = ['ip_allow' => null];
check(direct_ip_ok($rowNoIp, '198.51.100.9'), 'ip_allow 为空应放行任何来源');
check(direct_ip_ok(['ip_allow' => ''], '198.51.100.9'), 'ip_allow 为空串应放行');

$rowIp = ['ip_allow' => '203.0.113.7'];
check(direct_ip_ok($rowIp, '203.0.113.7'), '单 IP 命中');
check(!direct_ip_ok($rowIp, '203.0.113.8'), '单 IP 不中');
// 前缀比较陷阱：203.0.113.7 不得匹配 203.0.113.70
check(!direct_ip_ok($rowIp, '203.0.113.70'), '不得退化为字符串前缀比较');

$rowCidr = ['ip_allow' => '203.0.113.0/24'];
check(direct_ip_ok($rowCidr, '203.0.113.7'), 'CIDR 内应命中');
check(direct_ip_ok($rowCidr, '203.0.113.255'), 'CIDR 边界应命中');
check(!direct_ip_ok($rowCidr, '203.0.114.1'), 'CIDR 外不应命中');

// CIDR 边界：/0 全放行，/32 只放行一个
check(direct_ip_ok(['ip_allow' => '0.0.0.0/0'], '198.51.100.9'), '/0 应放行任何 IPv4');
check(direct_ip_ok(['ip_allow' => '203.0.113.7/32'], '203.0.113.7'), '/32 应命中自身');
check(!direct_ip_ok(['ip_allow' => '203.0.113.7/32'], '203.0.113.8'), '/32 不应命中邻居');

// 非字节对齐的掩码：/25
check(direct_ip_ok(['ip_allow' => '203.0.113.0/25'], '203.0.113.127'), '/25 上半段应命中');
check(!direct_ip_ok(['ip_allow' => '203.0.113.0/25'], '203.0.113.128'), '/25 下半段不应命中');

// IPv6
check(direct_ip_ok(['ip_allow' => '2001:db8::1'], '2001:db8::1'), 'IPv6 精确命中');
check(!direct_ip_ok(['ip_allow' => '2001:db8::1'], '2001:db8::2'), 'IPv6 不中');

// 存活判定
check(direct_is_live(['status' => 'active', 'expires_at' => null]), '无过期应存活');
check(!direct_is_live(['status' => 'revoked', 'expires_at' => null]), '已撤销不应存活');
check(direct_is_live(['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]),
    '未过期应存活');
check(!direct_is_live(['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 60)]),
    '已过期不应存活');

// 查找
check(direct_find('') === null, '空令牌应返回 null');
check(direct_find('zzzz') === null, '格式非法应返回 null');
check(direct_find(strtoupper($tk)) === null,
    '大写令牌返回 null——random_token 只产小写，校验正则也拒绝大写');
check(direct_find(str_repeat('a', 32)) === null, '未入库的令牌应返回 null');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `Call to undefined function direct_token()`

- [ ] **Step 3: 写实现**

新建 `app/lib/directs.php`：

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   直链：免登录、可撤销、可选绑定来源 IP 的单文件字节流入口。
   准入判定全部收在 direct_resolve()，路由里不留分支。
   ========================================================================= */

function direct_token(): string
{
    return random_token(16);   // 32 位十六进制 = 128 bit
}

/**
 * 校验并规范化 ip_allow 输入。非法返回 null。
 *
 * 支持：IPv4 单地址、IPv4 CIDR、IPv6 单地址。
 * 不支持 IPv6 CIDR——写错一位就整段失效，且实际几乎用不到。
 */
function direct_normalize_ip(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (str_contains($input, '/')) {
        $parts = explode('/', $input, 2);
        $addr = trim($parts[0]);
        $bits = trim($parts[1]);

        if (str_contains($addr, ':') || preg_match('/^\d+$/', $bits) !== 1) {
            return null;
        }
        $packed = @inet_pton($addr);
        if ($packed === false || strlen($packed) !== 4) {
            return null;
        }
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return null;
        }
        return inet_ntop($packed) . '/' . $bits;
    }

    $packed = @inet_pton($input);
    if ($packed === false) {
        return null;
    }
    return inet_ntop($packed);
}

/** ip_allow 为空即不限来源；否则按单地址或 IPv4 网段匹配 */
function direct_ip_ok(array $row, string $ip): bool
{
    $allow = $row['ip_allow'] ?? null;
    if ($allow === null || $allow === '') {
        return true;
    }
    $allow = (string) $allow;

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (!str_contains($allow, '/')) {
        $want = @inet_pton($allow);
        return $want !== false && strlen($want) === strlen($packed) && hash_equals($want, $packed);
    }

    $parts = explode('/', $allow, 2);
    $net = @inet_pton($parts[0]);
    // 只支持 IPv4 网段：两侧都必须恰好 4 字节
    if ($net === false || strlen($net) !== 4 || strlen($packed) !== 4) {
        return false;
    }

    $bits = (int) $parts[1];
    if ($bits <= 0) {
        return true;
    }
    if ($bits > 32) {
        return false;
    }

    // 逐位比较：字符串前缀比较会让 203.0.113.7 匹配上 203.0.113.70
    $mask = str_repeat("\xff", intdiv($bits, 8));
    $rem = $bits % 8;
    if ($rem > 0) {
        $mask .= chr((0xFF << (8 - $rem)) & 0xFF);
    }
    $mask = str_pad($mask, 4, "\x00");

    return ($net & $mask) === ($packed & $mask);
}

/** 状态为 active 且未过期 */
function direct_is_live(array $row): bool
{
    if (($row['status'] ?? '') !== 'active') {
        return false;
    }
    $expires = $row['expires_at'] ?? null;
    return $expires === null || strtotime((string) $expires) >= time();
}

/** 按令牌查表；格式不合法或未命中返回 null */
function direct_find(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        return null;
    }
    return q_one('SELECT * FROM direct_links WHERE token = ? LIMIT 1', [$token]);
}

/** 直链信息的对外形状 */
function direct_public(array $row): array
{
    $expires = $row['expires_at'] ?? null;
    $token = (string) ($row['token'] ?? '');

    return [
        'id'          => (int) ($row['id'] ?? 0),
        'token'       => $token,
        'url'         => url('direct.serve', ['t' => $token]),
        'urlDownload' => url('direct.serve', ['t' => $token, 'dl' => '1']),
        'ipAllow'     => $row['ip_allow'] !== null ? (string) $row['ip_allow'] : '',
        'expiresAt'   => $expires !== null ? (string) $expires : null,
        'expiresText' => $expires === null
            ? '永久有效'
            : (strtotime((string) $expires) < time()
                ? '已过期'
                : '至 ' . date('Y-m-d H:i', strtotime((string) $expires))),
        'hits'        => (int) ($row['hit_count'] ?? 0),
        'lastAccess'  => ($row['last_access_at'] ?? null) !== null
            ? time_ago((string) $row['last_access_at'])
            : '从未',
        'createdAt'   => (string) ($row['created_at'] ?? ''),
        'createdText' => ($row['created_at'] ?? null) !== null ? time_ago((string) $row['created_at']) : '',
        'active'      => direct_is_live($row),
    ];
}
```

在 `app/bootstrap.php` 的 `require BASE_PATH . '/app/lib/shares.php';` 之后插入：

```php
require BASE_PATH . '/app/lib/directs.php';
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`

- [ ] **Step 5: 提交**

```bash
git add app/lib/directs.php app/bootstrap.php tests/t20_direct.php
git commit -m "feat: 直链基础件（令牌、IP 匹配、存活判定、查找）"
```

---

## Task 6: `direct_resolve()` 准入判定

**Files:**
- Modify: `app/lib/directs.php`（在 `direct_public()` 之前插入）
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: `direct_find()` / `direct_is_live()` / `direct_ip_ok()`（Task 5）、`node_get()` / `node_in_private()`（`app/lib/nodes.php`）
- Produces: `direct_resolve(string $token, string $ip): array` — 返回 `['ok' => bool, 'code' => int, 'message' => string, 'row' => ?array, 'node' => ?array]`

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// -------------------------------------------------------- direct_resolve

$uidT6 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');

// 固定邮箱的固件跑第二遍就会撞唯一键，而这个文件每个后续任务都会重跑，
// 所以先把上次留下的清掉，让测试可重复执行。
q_exec('DELETE FROM users WHERE email = ?', ['t15other@example.test']);
$otherT6 = q_insert('users', [
    'email' => 't15other@example.test', 'username' => 't15other',
    'display_name' => 't15other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024 ** 3, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
]);

$tmpT6 = sys_get_temp_dir() . '/t15-t6.txt';
file_put_contents($tmpT6, 'hello direct');
$blobT6 = blob_ingest_file($tmpT6, null, 'text/plain');
$nodeT6 = node_create_file($uidT6, user_root_id($uidT6), 't15-direct.txt', $blobT6, 'text/plain');
$nodeT6Id = (int) $nodeT6['id'];

/** 造一条直链，返回 [token, id] */
$mkLink = static function (array $over = []) use ($uidT6, $nodeT6Id): array {
    $token = direct_token();
    $id = q_insert('direct_links', $over + [
        'user_id'    => $uidT6,
        'node_id'    => $nodeT6Id,
        'token'      => $token,
        'ip_allow'   => null,
        'expires_at' => null,
        'status'     => 'active',
        'hit_count'  => 0,
        'created_at' => now(),
    ]);
    return [$token, (int) $id];
};

// 1. token 格式非法
$r = direct_resolve('nope', '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '格式非法 → 404');

// 2. 未入库
$r = direct_resolve(str_repeat('a', 32), '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '未入库 → 404');

// 3. 已撤销
[$tkRev, $idRev] = $mkLink(['status' => 'revoked']);
$r = direct_resolve($tkRev, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '已撤销 → 410');

// 4. 已过期
[$tkExp] = $mkLink(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
$r = direct_resolve($tkExp, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '已过期 → 410');

// 5. IP 不匹配
[$tkIp] = $mkLink(['ip_allow' => '198.51.100.0/24']);
$r = direct_resolve($tkIp, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 403, 'IP 不匹配 → 403');

// 6. 全部通过
[$tkOk] = $mkLink();
$r = direct_resolve($tkOk, '203.0.113.1');
check($r['ok'] === true && $r['code'] === 200, '全部通过 → ok');
check((int) ($r['row']['id'] ?? 0) > 0, '通过时 row 应已填好');
check((int) ($r['node']['id'] ?? 0) === $nodeT6Id, '通过时 node 应已填好');

// IP 匹配时同样通过
[$tkIpOk] = $mkLink(['ip_allow' => '203.0.113.0/24']);
$r = direct_resolve($tkIpOk, '203.0.113.55');
check($r['ok'] === true, 'IP 匹配 → ok');

// 7. 节点进回收站 → 410
$tmpT6b = sys_get_temp_dir() . '/t15-t6b.txt';
file_put_contents($tmpT6b, 'trash me');
$blobT6b = blob_ingest_file($tmpT6b, null, 'text/plain');
$nodeT6b = node_create_file($uidT6, user_root_id($uidT6), 't15-trash.txt', $blobT6b, 'text/plain');
[$tkTrash] = $mkLink(['node_id' => (int) $nodeT6b['id']]);
q_update('nodes', ['deleted_at' => now()], 'id = ?', [(int) $nodeT6b['id']]);
$r = direct_resolve($tkTrash, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '节点在回收站 → 410');

// 8. 恢复后原令牌复活（令牌未变）
q_update('nodes', ['deleted_at' => null], 'id = ?', [(int) $nodeT6b['id']]);
$r = direct_resolve($tkTrash, '203.0.113.1');
check($r['ok'] === true, '恢复后原令牌应复活');

// 9. 文件夹 → 404
$folderT6 = node_create_folder($uidT6, user_root_id($uidT6), 't15-folder');
[$tkFolder] = $mkLink(['node_id' => (int) $folderT6['id']]);
$r = direct_resolve($tkFolder, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '节点是文件夹 → 404');

// 10. 私密空间 → 403
$tmpT6c = sys_get_temp_dir() . '/t15-t6c.txt';
file_put_contents($tmpT6c, 'private');
$blobT6c = blob_ingest_file($tmpT6c, null, 'text/plain');
// 私密空间是按路径判定的——node_in_private() 看 path 是否落在私密根下，
// is_private 列只是这个判定的派生缓存。所以节点必须建在私密根里；
// 直接翻转 is_private 列会造出「列说是、路径说不是」的矛盾状态，
// 而 direct_resolve() 走的是路径判定，测试会假挂。
$nodeT6c = node_create_file($uidT6, user_private_id($uidT6), 't15-private.txt', $blobT6c, 'text/plain');
[$tkPriv] = $mkLink(['node_id' => (int) $nodeT6c['id']]);
$r = direct_resolve($tkPriv, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 403, '私密空间 → 403');

// 11. 跨用户隔离：别人的令牌取不到我的文件
[$tkCross] = $mkLink();
q_update('direct_links', ['user_id' => $otherT6], 'token = ?', [$tkCross]);
$r = direct_resolve($tkCross, '203.0.113.1');
check($r['ok'] === false, 'user_id 被改到别人名下后不应再放行');

// 12. 同一文件多条直链互不影响
//     上面 $mkLink() 默认都建在 $nodeT6Id 上，此刻该节点已有若干条
$linkCountT6 = (int) q_val('SELECT COUNT(*) FROM direct_links WHERE node_id = ?', [$nodeT6Id]);
check($linkCountT6 >= 3, '同一文件应能挂多条直链');
// 其中 $tkRev 已撤销，$tkOk 仍生效——撤销一条不影响另一条
check(direct_resolve($tkRev, '203.0.113.1')['ok'] === false, '被撤销的那条仍应拒绝');
check(direct_resolve($tkOk, '203.0.113.1')['ok'] === true, '未被撤销的那条不受影响');
```


- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `Call to undefined function direct_resolve()`

- [ ] **Step 3: 写实现**

在 `app/lib/directs.php` 的 `direct_public()` 之前插入：

```php
/**
 * 汇总直链的准入判定。
 *
 * ok 为真时 code 为 200，row / node 已填好；
 * 为假时 message 是要直接吐给调用方的纯文本。
 */
function direct_resolve(string $token, string $ip): array
{
    $deny = static fn (int $code, string $message): array => [
        'ok' => false, 'code' => $code, 'message' => $message,
        'row' => null, 'node' => null,
    ];

    $row = direct_find($token);
    if ($row === null) {
        return $deny(404, '链接不存在');
    }
    if (($row['status'] ?? '') !== 'active') {
        return $deny(410, '链接已被撤销');
    }
    if (!direct_is_live($row)) {
        return $deny(410, '链接已过期');
    }
    if (!direct_ip_ok($row, $ip)) {
        return $deny(403, '来源不被允许');
    }

    $ownerId = (int) $row['user_id'];
    $node = node_get($ownerId, (int) $row['node_id']);
    if ($node === null || $node['deleted_at'] !== null) {
        return $deny(410, '文件已不在');
    }
    // 建链之后文件才被移入私密空间的情况
    if (node_in_private($ownerId, $node)) {
        return $deny(403, '文件不可访问');
    }
    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        return $deny(404, '链接不存在');
    }

    return ['ok' => true, 'code' => 200, 'message' => '', 'row' => $row, 'node' => $node];
}
```

- [ ] **Step 4: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`

- [ ] **Step 5: 提交**

```bash
git add app/lib/directs.php tests/t20_direct.php
git commit -m "feat: direct_resolve() 准入判定"
```

---

## Task 7: 公开路由 `direct.serve` 与管理接口

**Files:**
- Create: `app/modules/direct.php`
- Modify: `app/router.php:16-33`（`PUBLIC_ROUTES`）、`app/lib/activity.php`（`activity_label()` 补两条）
- Test: `tests/t20_direct.php`（追加源码扫描）

**Interfaces:**
- Consumes: `direct_resolve()`（Task 6）、`direct_public()` / `direct_token()` / `direct_normalize_ip()`（Task 5）、`blob_deliver()`（Task 3）、`mime_inline_ok()`（Task 1）
- Produces: 路由 `direct.serve`（公开）、`direct.create`（POST+CSRF）、`direct.revoke`（POST+CSRF）

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ------------------------------------------------------------- 路由与装载

$router = (string) file_get_contents(BASE_PATH . '/app/router.php');
check(str_contains($router, "'direct.serve'"), 'direct.serve 应登记进 PUBLIC_ROUTES');

$boot = (string) file_get_contents(BASE_PATH . '/app/bootstrap.php');
check(str_contains($boot, "lib/directs.php"), 'bootstrap 应装载 lib/directs.php');

$mod = (string) file_get_contents(BASE_PATH . '/app/modules/direct.php');
check(str_contains($mod, 'function direct_serve'), 'direct.php 应有 direct_serve');
check(str_contains($mod, 'function direct_create'), 'direct.php 应有 direct_create');
check(str_contains($mod, 'function direct_revoke'), 'direct.php 应有 direct_revoke');
check(str_contains($mod, "require_method('POST')"), '写接口应限制 POST');
check(str_contains($mod, 'require_csrf()'), '写接口应校验 CSRF');
check(str_contains($mod, "'Allow: GET, HEAD'"), 'serve 应声明 Allow 头');
check(str_contains($mod, 'mime_inline_ok($mime)'), 'serve 应用内联白名单决定 disposition');

// 直链绝不写操作日志（视频拖动进度条会高频命中）。
// 不能整文件扫 activity_log——direct_create / direct_revoke 是正当要写的，
// 只有 direct_serve 不能写。所以切出 direct_serve 的函数体再查。
$serveStart = strpos($mod, 'function direct_serve');
$serveEnd = strpos($mod, 'function direct_create');
check($serveStart !== false && $serveEnd !== false && $serveEnd > $serveStart,
    'direct.php 应含 direct_serve 且其后的 direct_create');
$serveBody = substr($mod, (int) $serveStart, (int) $serveEnd - (int) $serveStart);
check(!str_contains($serveBody, 'activity_log'), 'direct_serve 不得写 activity_log');

// activity_label 覆盖新动作
$act = (string) file_get_contents(BASE_PATH . '/app/lib/activity.php');
check(str_contains($act, "'direct.create'"), 'activity_label 应覆盖 direct.create');
check(str_contains($act, "'direct.revoke'"), 'activity_label 应覆盖 direct.revoke');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `direct.serve 应登记进 PUBLIC_ROUTES` 等（`direct.php` 尚不存在，`file_get_contents` 会告警并返回空串）

- [ ] **Step 3: 加路由、装载、动作文案**

`app/router.php` 的 `PUBLIC_ROUTES`，在 `'shares.raw',` 之后插入：

```php
    'direct.serve',
```

`app/lib/activity.php` 的 `activity_label()` 里追加（找到现有的 `'share.create' => '创建分享',` 一带，按同样格式补）：

```php
        'direct.create' => '创建直链',
        'direct.revoke' => '作废直链',
```

- [ ] **Step 4: 写模块**

新建 `app/modules/direct.php`：

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* ============================================================ 站外：直链交付 */

function direct_serve(): void
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit('仅支持 GET / HEAD');
    }

    $r = direct_resolve((string) ($_GET['t'] ?? ''), client_ip());
    if (!$r['ok']) {
        http_response_code((int) $r['code']);
        header('Content-Type: text/plain; charset=utf-8');
        exit((string) $r['message']);
    }

    $row = (array) $r['row'];
    $node = (array) $r['node'];

    $mime = (string) ($node['mime'] ?? 'application/octet-stream');
    $name = (string) $node['name'];
    $forceDownload = (string) ($_GET['dl'] ?? '') !== '';
    $inline = !$forceDownload && mime_inline_ok($mime);

    q_exec(
        'UPDATE direct_links SET hit_count = hit_count + 1, last_access_at = ? WHERE id = ?',
        [now(), (int) $row['id']]
    );
    traffic_add((int) $row['user_id'], 'down', (int) $node['size'], 1);

    blob_deliver(
        (int) $row['user_id'],
        (int) $node['blob_id'],
        $mime,
        $name,
        0,
        $inline ? 'inline' : 'attachment'
    );
}

/* ============================================================ 站内：管理 */

function direct_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    if ($node['kind'] !== 'file') {
        json_fail('直链只支持单个文件。');
    }
    if ($node['deleted_at'] !== null) {
        json_fail('该条目已在回收站中。');
    }
    if (node_in_private($userId, $node)) {
        json_fail('私密空间的文件不提供直链。', 403, 'private');
    }

    $ipInput = trim((string) input('ipAllow', ''));
    $ipAllow = null;
    if ($ipInput !== '') {
        $ipAllow = direct_normalize_ip($ipInput);
        if ($ipAllow === null) {
            json_fail('来源格式不正确。填单个 IP（如 203.0.113.7）或 IPv4 网段（如 203.0.113.0/24）。');
        }
    }

    $days = input_int('days', 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + min(3650, $days) * 86400) : null;

    $id = q_insert('direct_links', [
        'user_id'    => $userId,
        'node_id'    => (int) $node['id'],
        'token'      => direct_token(),
        'ip_allow'   => $ipAllow,
        'expires_at' => $expires,
        'status'     => 'active',
        'created_at' => now(),
    ]);

    $row = q_one('SELECT * FROM direct_links WHERE id = ?', [$id]);
    activity_log($userId, 'direct.create', (int) $node['id'], (string) $node['name']);

    json_ok(['link' => direct_public($row ?? [])]);
}

function direct_revoke(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请选择要作废的直链。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $count = q_exec(
        "UPDATE direct_links SET status = 'revoked' WHERE user_id = ? AND id IN ({$in}) AND status = 'active'",
        [$userId, ...$ids]
    );

    activity_log($userId, 'direct.revoke', null, $count . ' 条');
    json_ok(['count' => $count]);
}
```

- [ ] **Step 5: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`

- [ ] **Step 6: 语法检查**

```bash
php -l app/modules/direct.php && php -l app/lib/directs.php && php -l app/router.php && php -l app/lib/activity.php
```

Expected: 四个都 `No syntax errors detected`

- [ ] **Step 7: 提交**

```bash
git add app/modules/direct.php app/router.php app/lib/activity.php tests/t20_direct.php
git commit -m "feat: direct.serve 公开路由与直链管理接口"
```

---

## Task 8: 节点角标 `direct_count`

**Files:**
- Modify: `app/lib/nodes.php:151-177`（`$sharedMap` 之后）、`app/lib/nodes.php:219`（`$row['share_count']` 旁）、`app/lib/nodes.php:832`（`node_public()` 的 `shareCount` 旁）
- Modify: `assets/js/files.js:169`
- Test: `tests/t20_direct.php`（追加）

**Interfaces:**
- Consumes: `direct_links` 表（Task 4）
- Produces: `node_public()` 的返回数组新增 `directCount`（int）

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ------------------------------------------------------------ 节点角标计数

$uidT8 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$tmpT8 = sys_get_temp_dir() . '/t15-t8.txt';
file_put_contents($tmpT8, 'badge');
$blobT8 = blob_ingest_file($tmpT8, null, 'text/plain');
$nodeT8 = node_create_file($uidT8, user_root_id($uidT8), 't15-badge.txt', $blobT8, 'text/plain');
$nodeT8Id = (int) $nodeT8['id'];

$pub = node_public(node_get($uidT8, $nodeT8Id));
check(array_key_exists('directCount', $pub), 'node_public 应含 directCount');
check($pub['directCount'] === 0, '无直链时 directCount 应为 0');

q_insert('direct_links', [
    'user_id' => $uidT8, 'node_id' => $nodeT8Id, 'token' => direct_token(),
    'ip_allow' => null, 'expires_at' => null, 'status' => 'active',
    'hit_count' => 0, 'created_at' => now(),
]);
q_insert('direct_links', [
    'user_id' => $uidT8, 'node_id' => $nodeT8Id, 'token' => direct_token(),
    'ip_allow' => null, 'expires_at' => null, 'status' => 'revoked',
    'hit_count' => 0, 'created_at' => now(),
]);

$children = node_children($uidT8, user_root_id($uidT8), ['sort' => 'name', 'dir' => 'asc', 'showHidden' => true]);
$badgeCount = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $nodeT8Id) {
        $badgeCount = (int) $c['direct_count'];
    }
}
check($badgeCount === 1, '只有 active 的直链计入角标（revoked 不算）');

$js = (string) file_get_contents(BASE_PATH . '/assets/js/files.js');
check(str_contains($js, 'directCount'), 'files.js 应读取 directCount');
check(str_contains($js, "icon('zap'"), 'files.js 应用 zap 图标做直链角标');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `node_public 应含 directCount`

- [ ] **Step 3: 改 `nodes.php`**

`app/lib/nodes.php` 的 `node_attach_meta()`（`app/lib/nodes.php:140`）是 `node_children` / `node_list` / `node_trash_list` / `node_favorites` 的公共出口，改一处四处都拿到。

注意这个函数**整个包在 `foreach (array_chunk($ids, 400) as $chunk)` 里**：map 的初始化在循环之前，查询在循环体内，绑参用 `[...$chunk, $userId]`。所以两处都要照这个形状加，不能照抄 `$sharedMap` 表面的样子：

1. 在 `$memberMap = [];` 之后（**循环之前**）加一行：

```php
    $directMap = [];
```

2. 在**循环体内** `$sharedMap` 那段之后插入：

```php
        foreach (q_all(
            "SELECT node_id, COUNT(*) AS c FROM direct_links
             WHERE node_id IN ({$in}) AND user_id = ? AND status = 'active' GROUP BY node_id",
            [...$chunk, $userId]
        ) as $row) {
            $directMap[(int) $row['node_id']] = (int) $row['c'];
        }
```

**两个坑，都会在列表超过 400 条时才暴露**（`node_children()` 无上限，`node_favorites()` 上限 500）：

- 绑参必须用 `$chunk` 而不是 `$ids`。`$in` 是按 chunk 生成的，用 `$ids` 会让占位符与实参个数不等，抛 `PDOException: SQLSTATE[HY093]: Invalid parameter number`。
- `$directMap = []` 必须在循环外。放循环内会每个 chunk 重置一次，前面 chunk 的计数全丢。

在 `$row['share_count'] = $sharedMap[$id] ?? 0;` 之后插入：

```php
        $row['direct_count'] = $directMap[$id] ?? 0;
```

在 `node_public()` 的 `'shareCount'  => (int) ($node['share_count'] ?? 0),` 之后插入：

```php
        'directCount' => (int) ($node['direct_count'] ?? 0),
```

- [ ] **Step 4: 改 `files.js`**

`assets/js/files.js:169`，把：

```js
        if (n.shareCount > 0) out += '<span class="badge" title="已分享">' + icon('link-2', 'badge__ico') + '</span>';
```

改为：

```js
        if (n.shareCount > 0) out += '<span class="badge" title="已分享">' + icon('link-2', 'badge__ico') + '</span>';
        if (n.directCount > 0) out += '<span class="badge" title="有直链">' + icon('zap', 'badge__ico') + '</span>';
```

- [ ] **Step 5: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php && runuser -u www -- php tests/t13_ui.php
```

Expected: 两个都全部通过

- [ ] **Step 6: 提交**

```bash
git add app/lib/nodes.php assets/js/files.js tests/t20_direct.php
git commit -m "feat: 文件卡片直链角标"
```

---

## Task 9: 分享页「直链」标签

**Files:**
- Modify: `app/modules/shares.php:12-62`（`shares_list()`）
- Modify: `app/views/shares.php`
- Modify: `assets/js/console.js`
- Test: `tests/t20_direct.php`（追加源码扫描）

**Interfaces:**
- Consumes: `direct_public()`（Task 5）、`direct_links` 表（Task 4）
- Produces: 视图变量 `directs`（`list<array>`）与 `tab`（`'shares'|'direct'`）

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// --------------------------------------------------------- 分享页直链标签

$sharesMod = (string) file_get_contents(BASE_PATH . '/app/modules/shares.php');
check(str_contains($sharesMod, 'direct_links'), 'shares_list 应查 direct_links');
check(str_contains($sharesMod, "'directs'"), 'shares_list 应传 directs');
check(str_contains($sharesMod, "'tab'"), 'shares_list 应传 tab');

$sharesView = (string) file_get_contents(BASE_PATH . '/app/views/shares.php');
check(str_contains($sharesView, 'seg2'), '视图应用 .seg2 分段控件');
check(str_contains($sharesView, 'direct.revoke'), '视图应能作废直链');
check(str_contains($sharesView, 'urlDownload'), '视图应提供下载直链（&dl=1）');
check(str_contains($sharesView, 'data-api="direct.revoke"'),
    '作废应走声明式 data-api，不自己写 JS');
// 必须说明来源 IP 在反代下不可靠
check(str_contains($sharesView, '反向代理'), '视图应说明来源 IP 在反代 / 穿透下的限制');

$consoleJs = (string) file_get_contents(BASE_PATH . '/assets/js/console.js');
check(str_contains($consoleJs, 'data-direct-copy'), 'console.js 应处理直链复制');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `shares_list 应查 direct_links`

- [ ] **Step 3: 改 `shares_list()`**

在 `app/modules/shares.php` 的 `shares_list()` 里，把现有的 `render('shares', [...])` 调用改为：

```php
    $directRows = q_all(
        'SELECT d.*, n.name AS node_name, n.kind AS node_kind, n.size AS node_size,
                n.ext AS node_ext, n.mime AS node_mime
         FROM direct_links d JOIN nodes n ON n.id = d.node_id
         WHERE d.user_id = ?
         ORDER BY d.id DESC LIMIT 300',
        [$userId]
    );

    $directs = [];
    foreach ($directRows as $row) {
        $link = direct_public($row);
        $link['node'] = node_public([
            'id' => (int) $row['node_id'],
            'name' => (string) $row['node_name'],
            'kind' => (string) $row['node_kind'],
            'size' => (int) $row['node_size'],
            'ext' => $row['node_ext'],
            'mime' => $row['node_mime'],
            'parent_id' => null,
            'is_favorite' => 0,
            'is_hidden' => 0,
            'is_locked' => 0,
            'is_private' => 0,
            'color' => null,
            'note' => null,
            'child_count' => 0,
            'updated_at' => (string) $row['created_at'],
            'created_at' => (string) $row['created_at'],
            'deleted_at' => null,
        ]);
        $directs[] = $link;
    }

    render('shares', [
        'title'     => '分享 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'shares',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'shares',
        'items'     => $items,
        'directs'   => $directs,
        'tab'       => ($_GET['tab'] ?? '') === 'direct' ? 'direct' : 'shares',
    ]);
```

- [ ] **Step 4: 改视图**

`app/views/shares.php` 文件头部的 `/** @var ... */` 块补两行：

```php
/** @var list<array> $directs */
/** @var string $tab */
```

在 `<div class="pagehead">…</div>` 之后、统计块之前插入分段控件：

```php
            <div class="seg2" style="margin-bottom:18px">
                <a href="<?= e(url('shares.list')) ?>"
                   class="<?= $tab === 'shares' ? 'is-on' : '' ?>">分享链接</a>
                <a href="<?= e(url('shares.list', ['tab' => 'direct'])) ?>"
                   class="<?= $tab === 'direct' ? 'is-on' : '' ?>">直链</a>
            </div>
```

把现有的统计块 `<div class="grid grid--4" …>` **连同其后的「分享记录」面板**一起用 `<?php if ($tab === 'shares'): ?>` … `<?php endif; ?>` 包起来，然后在 `<?php endif; ?>` 之后插入直链面板。只包统计块是不够的——「分享记录」面板会继续在直链标签下显示，标签就白做了：

```php
            <?php if ($tab === 'direct'): ?>
            <?php
            $dActive = count(array_filter($directs, static fn (array $d): bool => $d['active']));
            $dHits = array_sum(array_map(static fn (array $d): int => (int) $d['hits'], $directs));
            $dBound = count(array_filter($directs, static fn (array $d): bool => $d['ipAllow'] !== ''));
            ?>
            <div class="grid grid--4" style="margin-bottom:22px">
                <div class="tile">
                    <span class="tile__label">生效中</span>
                    <span class="tile__value"><?= $dActive ?><small>条</small></span>
                </div>
                <div class="tile">
                    <span class="tile__label">累计命中</span>
                    <span class="tile__value"><?= $dHits ?></span>
                </div>
                <div class="tile">
                    <span class="tile__label">带来源限制</span>
                    <span class="tile__value"><?= $dBound ?></span>
                </div>
                <div class="tile tile--signal">
                    <span class="tile__label">历史总数</span>
                    <span class="tile__value"><?= count($directs) ?></span>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">直链</h2>
                    <span class="panel__note">免登录、无落地页</span>
                </div>

                <?php if ($directs === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('plug', 'blank__ico') ?>
                            <p class="blank__title">还没有直链</p>
                            <p class="blank__hint">到文件列表里右键一个文件，选「直链」就能生成。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="hint" style="margin:0 0 14px">
                        填了来源限制的直链，在反向代理或内网穿透后面拿不到访客真实地址，会一律拒绝——那种环境请留空。
                    </p>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>文件</th><th>直链</th><th>来源限制</th><th>有效期</th>
                                    <th class="num">命中</th><th>最近访问</th><th>状态</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($directs as $d): ?>
                                    <tr>
                                        <td>
                                            <div class="tbl__name">
                                                <?= lucide((string) ($d['node']['icon'] ?? 'file')) ?>
                                                <span><?= e((string) ($d['node']['name'] ?? '—')) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="tbl__name tbl__link">
                                                <code class="tbl__url" title="<?= e((string) $d['url']) ?>"><?= e((string) $d['url']) ?></code>
                                                <button class="minibtn" type="button"
                                                        data-direct-copy="<?= e((string) $d['url']) ?>"
                                                        aria-label="复制直链"><?= lucide('copy') ?></button>
                                                <button class="minibtn" type="button"
                                                        data-direct-copy="<?= e((string) $d['urlDownload']) ?>"
                                                        aria-label="复制下载直链" title="下载直链（&amp;dl=1）"><?= lucide('download') ?></button>
                                            </div>
                                        </td>
                                        <td class="muted nowrap"><?= $d['ipAllow'] !== '' ? e((string) $d['ipAllow']) : '不限' ?></td>
                                        <td class="muted nowrap"><?= e((string) $d['expiresText']) ?></td>
                                        <td class="num"><?= (int) $d['hits'] ?></td>
                                        <td class="muted nowrap"><?= e((string) $d['lastAccess']) ?></td>
                                        <td>
                                            <span class="pill<?= $d['active'] ? '' : ' pill--muted' ?>">
                                                <span class="pill__dot" style="--pill:<?= $d['active'] ? '#4ad07a' : 'rgba(244,242,236,.32)' ?>"></span>
                                                <?= $d['active'] ? '生效中' : '已失效' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="tbl__acts">
                                                <?php if ($d['active']): ?>
                                                    <button class="chipbtn chipbtn--danger" type="button"
                                                            data-api="direct.revoke"
                                                            data-payload="<?= e(json_encode(['ids' => [(int) $d['id']]])) ?>"
                                                            data-confirm="作废后这条直链立即失效，且不可恢复。"
                                                            data-title="作废直链" data-label="作废" data-danger="1"
                                                            data-after="reload">作废</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
```

- [ ] **Step 5: 加前端交互**

**作废按钮不需要写 JS**——它用的是 `assets/js/console.js:118` 那个全局委托处理器（`button[data-api][data-confirm]`），属性齐了就自动生效，和分享页的「取消」按钮走同一条路。

只有「复制」需要补一小段。`pageShares()`（`assets/js/console.js:203`）本身就用 `document` 上的事件委托，在它回调的末尾加一个分支即可，不需要新增页面入口：

```js
            const dirCopy = e.target.closest('[data-direct-copy]');
            if (dirCopy) {
                try {
                    await navigator.clipboard.writeText(dirCopy.dataset.directCopy);
                    toast('直链已复制');
                } catch (err) {
                    toast('复制失败，请手动选中链接', 'err');
                }
                return;
            }
```

（`api` / `toast` / `escapeHtml` 在 `assets/js/console.js:13` 一带已从 `SY` 解构，直接用。）

- [ ] **Step 6: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: 全部 `ok`

- [ ] **Step 7: 提交**

```bash
git add app/modules/shares.php app/views/shares.php assets/js/console.js tests/t20_direct.php
git commit -m "feat: 分享页直链标签"
```

---

## Task 10: 文件列表右键「直链」面板

**Files:**
- Modify: `assets/js/files.js:860`（菜单项）、`assets/js/files.js:1117`（case 分派）
- Test: `tests/t20_direct.php`（追加源码扫描）

**Interfaces:**
- Consumes: `direct.create`（Task 7）、`direct_public()` 的 `url` / `urlDownload` 字段（Task 5）
- Produces: 无（终端交互）

- [ ] **Step 1: 写失败的测试**

在 `tests/t20_direct.php` 的 `finish();` 之前插入：

```php
// ----------------------------------------------------- 文件列表右键面板

$jsT10 = (string) file_get_contents(BASE_PATH . '/assets/js/files.js');
check(str_contains($jsT10, "['direct', 'plug', '直链']"), '右键菜单应有「直链」项');
check(str_contains($jsT10, 'async function doDirect'), 'files.js 应有 doDirect');
check(str_contains($jsT10, 'direct.create'), 'doDirect 应调 direct.create');
check(str_contains($jsT10, 'urlDownload'), 'doDirect 应提供下载直链');
check(str_contains($jsT10, 'data-dir-ip'), 'doDirect 应支持来源 IP 输入');
check(str_contains($jsT10, "case 'direct':"), 'runAction 应有 direct 分支');
// 菜单项必须挂在 node.kind === 'file' 的分支里（右键菜单恒为单节点，见 openCtx）
$fileBlock = strpos($jsT10, "if (node.kind === 'file') {");
$directItem = strpos($jsT10, "['direct', 'plug', '直链']");
check($fileBlock !== false && $directItem > $fileBlock,
    '「直链」菜单项应落在 node.kind === \'file\' 的分支内');
```

- [ ] **Step 2: 跑测试确认失败**

```bash
runuser -u www -- php tests/t20_direct.php
```

Expected: FAIL — `右键菜单应有「直链」项`

- [ ] **Step 3: 加菜单项与分派**

`openCtx(x, y, node)`（`assets/js/files.js:746`）恒为单节点——`ctx.onclick` 里写死了 `runAction(act, [node.id])`，所以不需要判断选中数量。把菜单项加进已有的 `if (node.kind === 'file') { … }` 块里，紧跟 `versions`：

```js
            if (node.kind === 'file') {
                items.push(['download', 'download', '下载']);
                items.push(['versions', 'history', '版本历史']);
                items.push(['direct', 'plug', '直链']);
            }
```

`runAction()` 的 `switch`（`assets/js/files.js:1008` 一带），在 `case 'share': doShare(ids); break;` 之后插入：

```js
            case 'direct': doDirect(ids); break;
```

- [ ] **Step 4: 写 `doDirect()`**

在 `assets/js/files.js` 里 `doShare()`（`assets/js/files.js:759`）之后新增。写法照抄 `doShare()` 的弹窗与错误处理模式：

```js
    /* 直链：免登录、无落地页的取文件地址 */
    async function doDirect(ids) {
        if (ids.length !== 1) return;
        const id = ids[0];
        const n = state.nodes.get(id);
        if (!n || n.kind !== 'file') return;

        const body = '<p class="mhint">' + escapeHtml(n.name) + '</p>'
            + '<div class="mfield"><span class="mfield__label">来源（留空 = 不限）</span>'
            + '<input class="minput" data-dir-ip placeholder="203.0.113.7 或 203.0.113.0/24"></div>'
            + '<p class="mhint">只支持单个 IP 或 IPv4 网段。走反向代理或内网穿透时请留空——'
            + '那种环境下拿不到访客真实地址，填了会一律拒绝。</p>'
            + '<div class="mfield"><span class="mfield__label">有效期</span>'
            + '<select class="minput" data-dir-days>'
            + '<option value="0" selected>永久</option>'
            + '<option value="7">7 天</option>'
            + '<option value="30">30 天</option>'
            + '<option value="365">1 年</option>'
            + '</select></div>';

        openModal('获取直链', body,
            '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">生成</button>');

        modalFoot.querySelector('[data-x="no"]').addEventListener('click', closeModal);
        modalFoot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const data = await api('direct.create', {
                    id: id,
                    ipAllow: modalBody.querySelector('[data-dir-ip]').value.trim(),
                    days: Number(modalBody.querySelector('[data-dir-days]').value),
                });
                const link = data.link;

                const modalEl = document.querySelector('[data-modal]');
                if (modalEl && modalEl.hidden) return;   // 用户已关闭弹窗，不再弹回

                openModal('直链已生成',
                    '<div class="mfield"><span class="mfield__label">直链（内联）</span>'
                    + '<input class="minput" readonly value="' + escapeHtml(link.url) + '"></div>'
                    + '<div class="mfield"><span class="mfield__label">下载直链</span>'
                    + '<input class="minput" readonly value="' + escapeHtml(link.urlDownload) + '"></div>'
                    + '<p class="mhint">' + escapeHtml(link.expiresText)
                    + (link.ipAllow ? ' · 仅限 ' + escapeHtml(link.ipAllow) : ' · 不限来源') + '</p>',
                    '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');

                $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', closeModal);

                try {
                    await navigator.clipboard.writeText(link.url + '\n' + link.urlDownload);
                    toast('两条链接已复制');
                } catch (err) {
                    toast('请手动选中链接复制', 'err');
                }
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                if (btn.isConnected) btn.disabled = false;
            }
        });
    }
```

（`api` / `toast` / `openModal` / `closeModal` / `escapeHtml` 在 `assets/js/files.js:37-42` 已从 `SY` 解构；`state.nodes` 与 `modalBody` / `modalFoot` 也都是文件内既有绑定。）

- [ ] **Step 5: 跑测试确认通过**

```bash
runuser -u www -- php tests/t20_direct.php && runuser -u www -- php tests/t13_ui.php
```

Expected: 两个都全部通过

- [ ] **Step 6: 提交**

```bash
git add assets/js/files.js tests/t20_direct.php
git commit -m "feat: 文件列表右键获取直链"
```

---

## Task 11: 端到端 HTTP 验证与回归

**Files:**
- 无代码改动（除非发现缺陷）

**Interfaces:**
- Consumes: 全部前序任务
- Produces: 一份实测结论

站点跑在 `127.0.0.1:15244`。用现成的 CDP 探针（`/tmp/probe.mjs`）或直接 `curl`。

- [ ] **Step 1: 造一条直链**

```bash
runuser -u www -- php -r '
require "/www/wwwroot/drive/app/bootstrap.php";
$uid = (int) q_val("SELECT id FROM users ORDER BY id LIMIT 1");
$node = q_one("SELECT * FROM nodes WHERE user_id = ? AND kind = ? AND blob_id IS NOT NULL AND deleted_at IS NULL LIMIT 1", [$uid, "file"]);
$tok = direct_token();
q_insert("direct_links", ["user_id"=>$uid,"node_id"=>(int)$node["id"],"token"=>$tok,"ip_allow"=>null,"expires_at"=>null,"status"=>"active","hit_count"=>0,"created_at"=>now()]);
echo $tok, "\n", $node["name"], "\n";
'
```

记下输出的 token 与文件名。

- [ ] **Step 2: 未登录访问 → 200 且字节正确**

```bash
curl -sS -D- -o /tmp/dl.bin "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>" | head -20
ls -l /tmp/dl.bin
```

Expected: `HTTP/1.1 200`，`Accept-Ranges: bytes`，`Content-Length` 与实际文件大小一致，`/tmp/dl.bin` 非空

- [ ] **Step 3: Range → 206**

```bash
curl -sS -D- -o /dev/null -H 'Range: bytes=0-9' "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>" | head -20
```

Expected: `HTTP/1.1 206`，`Content-Range: bytes 0-9/<size>`

- [ ] **Step 4: HEAD → 有 Content-Length，无 body**

```bash
curl -sS -I "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
curl -sS -o /tmp/head.bin -w '%{size_download}\n' -X HEAD "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
```

Expected: 头部含 `Content-Length` 与 `Accept-Ranges`；`size_download` 为 `0`

- [ ] **Step 5: 非 GET/HEAD → 405**

```bash
curl -sS -o /dev/null -w '%{http_code}\n' -X POST "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
```

Expected: `405`

- [ ] **Step 6: 内联白名单（关键回归）**

传一个 `.html` 与一个 `.jpg`（没有就现造），各建一条直链，检查响应头：

```bash
# .html → attachment
curl -sS -D- -o /dev/null "http://127.0.0.1:15244/index.php?r=direct.serve&t=<HTML_TOKEN>" | grep -i content-disposition
# .jpg → inline
curl -sS -D- -o /dev/null "http://127.0.0.1:15244/index.php?r=direct.serve&t=<JPG_TOKEN>" | grep -i content-disposition
# .jpg 带 dl=1 → attachment
curl -sS -D- -o /dev/null "http://127.0.0.1:15244/index.php?r=direct.serve&t=<JPG_TOKEN>&dl=1" | grep -i content-disposition
```

Expected: 依次 `attachment` / `inline` / `attachment`

- [ ] **Step 7: 确认 `<img>` 子资源不受 attachment 影响**

这是 spec §6 降级策略依赖的前提。用 CDP 探针打开一个内嵌 `<img src="…&t=<HTML_TOKEN>">` 的页面——**注意**：验证的是「浏览器会不会因 `Content-Disposition: attachment` 而拒绝把它渲染进 `<img>`」。

更直接的验证：找一个 `.svg` 文件建直链，在探针里执行：

```js
const img = new Image();
img.src = 'http://127.0.0.1:15244/index.php?r=direct.serve&t=<SVG_TOKEN>';
await img.decode().then(() => 'decoded').catch(e => 'failed: ' + e.message);
```

Expected: `decoded`。若返回 `failed`，说明降级策略会打断图片预览，必须改设计（只对公开路由降级、登录预览保持原样），并回来更新 spec。

- [ ] **Step 8: 撤销 / 回收站 / 恢复**

```bash
# 撤销
runuser -u www -- php -r 'require "/www/wwwroot/drive/app/bootstrap.php"; q_exec("UPDATE direct_links SET status = ? WHERE token = ?", ["revoked", "<TOKEN>"]);'
curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
# → 410

# 回收站
runuser -u www -- php -r 'require "/www/wwwroot/drive/app/bootstrap.php"; q_exec("UPDATE direct_links SET status = ? WHERE token = ?", ["active", "<TOKEN>"]); $n = q_one("SELECT node_id FROM direct_links WHERE token = ?", ["<TOKEN>"]); q_exec("UPDATE nodes SET deleted_at = ? WHERE id = ?", [now(), (int)$n["node_id"]]);'
curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
# → 410

# 恢复
runuser -u www -- php -r 'require "/www/wwwroot/drive/app/bootstrap.php"; $n = q_one("SELECT node_id FROM direct_links WHERE token = ?", ["<TOKEN>"]); q_exec("UPDATE nodes SET deleted_at = NULL WHERE id = ?", [(int)$n["node_id"]]);'
curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:15244/index.php?r=direct.serve&t=<TOKEN>"
# → 200
```

Expected: 依次 `410` / `410` / `200`

- [ ] **Step 9: 回归既有预览与下载**

用 CDP 探针打开图片预览、视频预览、分享页内预览各一次，确认渲染正常；再走一次普通下载，确认文件名与 MIME 不变。

```bash
runuser -u www -- php tests/t8_deliver.php && runuser -u www -- php tests/t13_ui.php
```

Expected: 全部通过

- [ ] **Step 10: 检查错误日志**

```bash
tail -50 /www/wwwlogs/127.0.0.1_15244.error.log
```

Expected: 无本次改动引入的新报错

- [ ] **Step 11: 记录来源 IP 在反代下的实际行为**

```bash
# 若站前有反向代理 / 穿透，观察 REMOTE_ADDR 是否等于访客真实 IP
curl -sS "http://127.0.0.1:15244/index.php?r=direct.serve&t=<BOUND_TOKEN>"
```

Expected: 若本机经樱花穿透访问，会得到 `403 来源不被允许`——**这是已知限制，不是缺陷**。把实测结论写进 `README.md` 的「已知限制」或直链面板文案（Task 9 已写了说明文案，此处只需确认文案与实际行为一致）。

- [ ] **Step 12: 提交（若有改动）**

```bash
git add -A
git commit -m "test: 直链端到端验证结论"
```

---

## 完成标准

- `runuser -u www -- php tests/t20_direct.php` 全部通过
- `runuser -u www -- php tests/t1_schema.php`、`t8_deliver.php`、`t13_ui.php` 全部通过（回归）
- 未登录 `curl` 能取到直链字节，支持 Range 与 HEAD
- `.html` / `.svg` 直链返回 `attachment`，`.jpg` 返回 `inline`
- 撤销 / 进回收站 → 410，恢复 → 200
- 文件列表右键单选文件可见「直链」，生成后两条链接都能复制
- 分享页有「直链」标签，能看到命中数与最近访问
- 无新的 PHP 报错
