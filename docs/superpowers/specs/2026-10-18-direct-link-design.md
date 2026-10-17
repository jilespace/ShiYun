# 文件直链（Direct Link）设计

日期：2026-10-18
状态：待实现

## 1. 目标

给单个文件生成一条**直接指向文件字节的 URL**：不经过落地页、不要求登录，可以塞进 `<img src>`、Markdown、别人的网页，也可以交给 Jellyfin / 反向代理这类服务长期取用。

成功标准：

- 文件列表里右键单个文件即可生成直链，生成后能直接复制
- 直链在未登录的浏览器里可用（新开无痕窗口、`curl` 均可）
- 支持 Range：视频能拖动进度条，下载工具能断点续传
- 支持 HEAD：媒体播放器与下载工具探测元信息时不返回 body
- 能随时手动作废；作废后立即失效
- 可选用来源 IP / CIDR 限定访问者，留空则不限
- 文件进回收站后直链立即失效，恢复后原链接自动复活
- 命中对象存储副本时 `302` 到预签名直链，字节不经过本机

## 2. 非目标

以下明确不做，避免范围蔓延：

- **不做提取密码。** 直链的核心用途是 `<img src>` / 播放器 / 别的服务直接取用，这些场景没有输入密码的机会。要密码请用分享链接。
- **不做文件夹直链。** 文件夹没有单一字节流，只能每次请求现场打包 zip——慢、吃 CPU、没法做 Range。要发整个文件夹用分享链接，它本来就支持打包下载。
- **不做无状态签名（HMAC）。** 签名方案无法撤销，只能轮换全局密钥（一换全站链接全失效），与「随时作废」的要求直接冲突。
- **不做访问明细表。** 视频播放每拖一次进度条就是一个 Range 请求，逐条落库会爆炸。只记 `hit_count` 与 `last_access_at`。
- **不做 Referer 防盗链。** 会同时打断「给别的服务用」（服务器请求不带 Referer）和「嵌到别人网页」（Referer 不是本域名）两个目标场景。
- **不做直链限速。** 不套用 `download_speed_limit`——那是限用户自己下载的设置，套到直链上会把 Jellyfin 拖死。
- **不把直链暴露给私密空间。** 见 §5 第 7 步。

## 3. 现状

项目里已有三条「链接」，都不是直链：

| 现有机制 | 实际是什么 | 为什么不算直链 |
|---|---|---|
| 分享链接 `?r=shares.open&t=…` | HTML 落地页，点按钮才下载 | 中间隔了一层页面 |
| `shares.raw` | 分享内预览用的字节流 | 只在分享内生效，界面上拿不到 URL |
| `download.file` | 登录态下载，命中对象存储时 302 到预签名 URL | 必须登录，URL 每次现签现用 |

可复用的部分：

- `blob_stream()`（`app/lib/storage.php:300`）已实现 Range、限速、边读边发
- `blob_direct_url()`（`app/lib/storage.php:241`）/ `blob_deliver()`（`app/lib/storage.php:280`）已实现「有对象存储副本就 302，否则回退本地流式」
- `app/lib/shares.php` 提供了「领域逻辑放 lib、路由放 module」的分工样板
- 分享页与文件列表的 UI 组件（`.tbl` / `.tbl__url` / `.minibtn` / `.seg2`）现成可用

### 3.1 顺带修复的既有缺陷（内联 XSS）

`blob_stream()` 的 `$inline` 参数由调用方直接传 `true`，函数本身不做任何类型检查，且把文件的原始 MIME 原样作为 `Content-Type` 发出。

`shares.raw` 是**公开路由**（`app/router.php` 的 `PUBLIC_ROUTES`），它调用 `blob_stream(..., inline: true)`。因此：上传一个 `.html`（或内含 `<script>` 的 `.svg`）、生成分享、任何人打开 `?r=shares.raw&t=…`，脚本就在本域名下以同源身份执行。

单用户自部署下危害有限（能创建分享的只有所有者本人），但直链比它更暴露——完全免登录、默认长期有效。所以本设计把内联决策**收进 `blob_stream()` 这一个咽喉点**，一次修掉 `preview.raw` / `shares.raw` / `direct.serve` 三处。

## 4. 数据模型

### 4.1 新表 `direct_links`

`app/schema.php` 中 `SCHEMA_VERSION` 6 → 7（`db_migrate()` 在下次请求时自动建表）。

```sql
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
```

字段语义：

- `token`：`random_token(16)` = `bin2hex(random_bytes(16))` = 32 位十六进制 = 128 bit 熵。**不复用 `share_token()`**——那个用 16 位无歧义字母表是为了方便口述和手输；直链永远是复制粘贴，不需要迁就可读性，可以换更长的全十六进制。
- `ip_allow`：单个 IP 或 CIDR（如 `203.0.113.7` 或 `203.0.113.0/24`）。`NULL` = 不限来源。
- `expires_at`：`NULL` = 永久。默认永久，但允许生成时选 7 天 / 30 天 / 1 年。
- `status`：`active` / `revoked`。撤销是软删除，保留行以便统计与审计。
- `hit_count`：**请求次数**，不是下载次数。Range 请求也计入。命名刻意选 `hit` 而非 `download`——视频播放一个文件能产生几十次请求，叫 `download_count` 是撒谎。它同时也是「流量异常」的信号。
- `last_access_at`：最近一次被访问的时间，同样用于发现异常。

**没有 `password_hash`、没有 `permission`。** 直链天生免密、权限只有「读」一种。这两列在 `shares` 表里存在是因为分享有落地页和三种权限（`preview` / `download` / `upload`），直链没有对应物。

### 4.2 一个文件允许多条直链

`node_id` 上没有唯一约束。同一个文件可以同时存在：

- 一条不绑 IP 的，用于嵌到公开网页
- 一条绑了服务器 IP 的，用于喂 Jellyfin

这是「新建」作为动作而非属性的自然结果，不是额外功能。界面上按文件分组展示。

### 4.3 为什么不复用 `shares` 表

备选方案是给 `shares` 加一列 `kind`（`page` / `direct`）。否决理由：两者生命周期完全不同——分享是「发出去、过期、用完就完了」，直链是「挂上去、长期存在、可能被别的服务依赖几年」。共用一张表省下的代码，会在以后每次改分享逻辑时变成「得先想清楚会不会影响直链」的负担。且 `permission`、`password_hash`、文件夹分享对直链全是空转，`shares.open` 里会多一个分叉，`shares_list` 也要区分显示。

## 5. 公开路由与请求流程

`app/router.php` 的 `PUBLIC_ROUTES` 新增 `direct.serve`。

地址沿用全站的查询串风格（不引入 nginx rewrite 依赖）：

```
index.php?r=direct.serve&t=<32位token>          内联 / 下载按 MIME 自动判断
index.php?r=direct.serve&t=<32位token>&dl=1     强制下载
```

`direct.serve` 的处理顺序：

| # | 检查 | 不通过 |
|---|---|---|
| 0 | 请求方法是 GET 或 HEAD | 405 |
| 1 | token 匹配 `^[a-f0-9]{32}$` | 404 |
| 2 | 按 token 查到行 | 404 |
| 3 | `status = 'active'` | 410 |
| 4 | `expires_at` 为空，或晚于当前时间 | 410 |
| 5 | `ip_allow` 为空，或 `client_ip()` 命中（单 IP 或 CIDR） | 403 |
| 6 | `node_get($userId, $nodeId)` 拿得到，且 `deleted_at IS NULL` | 410 |
| 7 | `node_in_private()` 为假 | 403 |
| 8 | `kind === 'file'` 且 `blob_id` 非空 | 404 |
| 9 | `hit_count++`、`last_access_at = now()`、`traffic_add($userId, 'down', (int) $node['size'], 1)` | — |
| 10 | 交付（见 §7） | — |

第 1–8 步由 `direct_resolve()`（§8.1）一次算完，路由里不出现 `if`。第 0 步的方法检查留在路由里，因为它不属于「这条链接能不能用」。

**撤销 / 过期 / 进回收站一律返回 410 而非 404。** token 本身就是密钥，持有 token 的人已经知道它存在过，不存在枚举风险。而 410 让「Jellyfin 突然不工作了」能一眼定位——是链接被撤销了，不是 URL 打错了。

第 6 步实现「回收站立即失效」：token 不动，只依赖节点的 `deleted_at`。文件恢复后原链接自动复活，不需要重签。

第 7 步是防御性的：`direct_create()` 本来就不允许给私密空间的文件建直链，但文件可能在建链之后被移入私密空间，所以读取路径必须再查一次。

### 5.1 来源 IP 匹配规则

`ip_allow` 的接受形式：

| 输入 | 行为 |
|---|---|
| 空 | 不限来源 |
| IPv4 单地址，如 `203.0.113.7` | 精确匹配 |
| IPv4 CIDR，如 `203.0.113.0/24` | 网段匹配 |
| IPv6 单地址，如 `2001:db8::1` | 精确匹配 |
| IPv6 CIDR | **不支持**，创建时拒绝 |

IPv6 CIDR 不做：写错一位就整段失效，且实际几乎用不到；真要放行一批 IPv6 就建多条直链。

匹配用 `inet_pton()` 把两侧都转成二进制再比位，避免字符串前缀比较踩坑（`203.0.113.7` 不该匹配 `203.0.113.70`）。

`direct_normalize_ip()` 在 `direct_create()` 里做输入校验：非法输入直接拒绝建链，不存进库——否则会出现一条「看起来有限制、实际永远匹配不上」的死链。`client_ip()` 拿到的地址同样先规范化，再参与匹配。

`ip_allow` 存**规范化后的字面量**，读取时不做二次解析以外的处理。

## 6. 内联与下载策略

- `&dl=1` → 强制 `Content-Disposition: attachment`
- 否则 → 查 §6 的白名单决定

| 内联 | 一律 attachment |
|---|---|
| `image/jpeg` `image/png` `image/gif` `image/webp` `image/avif` `image/bmp` | `text/html` |
| `video/*` `audio/*` | `image/svg+xml` |
| `application/pdf` `text/plain` | `application/xhtml+xml` |
| | `application/javascript` `application/xml` |
| | 其余全部（含未知 MIME） |

**白名单而非黑名单**——黑名单永远漏。能进白名单的必须是可以被浏览器安全地当作「数据」渲染的类型，而不是「程序」。

白名单落成一个**无依赖的纯函数**，放在 `app/helpers.php`：

```php
mime_inline_ok(string $mime): bool
```

放 `helpers.php` 而不是 `lib/directs.php`，是因为它有两个互不相关的使用方：`blob_stream()`（在 `storage.php`，不该反向依赖 `directs.php`）和直链路由（用来决定请求哪种 disposition）。放中立位置，两边共用同一份真相。

两处用法：

- **`blob_stream()` 内部作守卫**：当 `$inline === true` 但 `mime_inline_ok($mime)` 为假时，降级为 `attachment`。函数签名不变，调用方零改动，三处调用（`preview.raw` / `shares.raw` / `direct.serve`）同时获得修复。
- **直链路由用来选 disposition**：`$inline = !$dl && mime_inline_ok($mime)`，再据此决定传给 `blob_deliver()` 的 `$disposition`。对象存储的 302 分支不经过 `blob_stream()`，必须在这里就定下来。

**代价**：`.svg` 与 `.html` 直接打开会变成下载，不再在浏览器里渲染。两点缓解：

- 嵌在 `<img>` 里不受影响。`Content-Disposition` 只作用于顶层导航，不作用于子资源，所以图片预览照常工作。**此前提必须实测确认**（见 §10）。
- 文本 / 代码预览走的是 `preview.text`（返回 JSON，前端渲染），不走 `blob_stream`，不受影响。

## 7. 交付路径

### 7.1 对象存储

`blob_direct_url()` 目前把 `response-content-disposition` 硬编码为 `attachment`，内联直链用不了。加一个参数，默认值不变，现有调用方零改动：

```php
blob_direct_url(int $userId, int $blobId, string $mime, string $filename, string $disposition = 'attachment'): ?string
blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0, string $disposition = 'attachment'): never
```

命中对象存储副本时 `302` 过去，字节不经过本机；否则回退 `blob_stream()` 本地流式。

### 7.2 补 HEAD 支持

`blob_stream()` 目前对 HEAD 请求照常输出 body。需要补上：只发头（`Content-Length` / `Accept-Ranges` / `Content-Range`），不打开文件，直接 `exit`。媒体播放器与下载工具依赖这个。改在 `blob_stream()` 里，所有调用方一起受益。

302 到对象存储的分支天然支持 HEAD（由 COS 处理），无需额外改动。

### 7.3 限速与流量

- **不限速**。见 §2。
- 流量照记 `traffic_add($userId, 'down', (int) $node['size'], 1)`，与 `download.file` 一致。否则直链产生的出网流量在统计页里完全看不见。

## 8. 界面

### 8.1 文件划分

| 文件 | 职责 |
|---|---|
| `app/lib/directs.php`（新） | 领域逻辑：token 生成、查找、§5 的准入判定、IP 匹配、对外形状 |
| `app/modules/direct.php`（新） | 路由：`direct_serve()`（公开）、`direct_create()`、`direct_revoke()` |
| `app/schema.php` | 加 `direct_links` 表，`SCHEMA_VERSION` 6 → 7 |
| `app/helpers.php` | 新增 `mime_inline_ok()`（§6 的白名单，中立位置，两处共用） |
| `app/lib/storage.php` | `blob_stream()` 补 HEAD + 用 `mime_inline_ok()` 作内联守卫；`blob_direct_url()` / `blob_deliver()` 加 `$disposition` |
| `app/lib/nodes.php` | `$sharedMap`（`app/lib/nodes.php:176`）旁产出 `direct_count` → `node_public()`（`app/lib/nodes.php:758`） |
| `app/router.php` | `PUBLIC_ROUTES` 加 `direct.serve` |
| `app/views/shares.php`、`assets/js/console.js` | 分享页的第二个标签 |
| `assets/js/files.js` | 右键菜单「直链」+ 生成面板 + 卡片角标 |

**为什么判断逻辑全放 `lib/directs.php`**：`direct_serve()` 末尾是 `exit`，无法单测。把判断逻辑抽干后，路由只剩「查表 → 判断 → 交付」的壳，测试才有东西可测。这与 `app/lib/shares.php` 的既有分工一致。

对外接口（供路由与测试共用）：

```php
direct_find(string $token): ?array            // 查表，含 token 格式校验；未命中返回 null
direct_normalize_ip(string $input): ?string   // 校验并规范化 ip_allow 输入；非法返回 null
direct_ip_ok(array $row, string $ip): bool    // 按 §5.1 匹配；ip_allow 为空 → true
direct_is_live(array $row): bool              // status + expires_at（direct_resolve 内部用）

/**
 * 汇总 §5 第 1–8 步的准入判定。
 * 返回 ['ok' => bool, 'code' => int, 'message' => string,
 *       'row' => ?array, 'node' => ?array]
 * ok 为真时 code 为 200，row / node 已填好；为假时 message 是要直接吐出的纯文本。
 */
direct_resolve(string $token, string $ip): array

direct_public(array $row): array              // 对外形状
```

`direct_resolve()` 是这一层的核心：它把「token 格式 → 查表 → 状态 → 过期 → IP → 节点存在性 → 回收站 → 私密空间 → 是不是文件」整条链一次算完。`direct_serve()` 因此塌缩成：

```php
$r = direct_resolve((string) ($_GET['t'] ?? ''), client_ip());
if (!$r['ok']) {
    http_response_code($r['code']);
    exit($r['message']);
}
// 计数 → 交付
```

这样做有两个好处：路由里没有一处 `if`，而 §5 那张表的每一行都能被单测逐条打勾。

### 8.2 路由表

| 路由 | 可见性 | 方法 |
|---|---|---|
| `direct.serve` | 公开 | GET / HEAD |
| `direct.create` | 需登录 | POST + CSRF |
| `direct.revoke` | 需登录 | POST + CSRF |

列表数据不单开路由，并进 `shares.list`。

### 8.3 入口一：文件列表右键「直链」

- `assets/js/files.js:759` 的菜单项数组加 `['direct', 'plug', '直链']`，`files.js:1016` 的 `case` 里接 `doDirect(ids)`
- **只在单选文件时出现**（多选不出现——直链只给单个文件）
- 面板内容：该文件已有的直链（可复制、可作废）+「新建直链」表单
- 表单两项：来源 IP（留空 = 不限）、有效期（永久 / 7 天 / 30 天 / 1 年，默认永久）
- 生成后并排给出两条：**直链**（内联）与 **下载直链**（`&dl=1`），各带复制按钮

### 8.4 入口二：分享页的第二个标签

- `?r=shares.list&tab=direct`，服务端渲染，禁用 JS 也能切换
- 顶部复用 `.seg2` 分段控件（`assets/css/console.css:51`）：分享链接 / 直链
- 直链视图：4 个统计块（生效中 / 累计命中 / 带来源限制 / 历史总数）+ 表格
- 表格列：文件名 · 直链 · 来源限制 · 有效期 · 命中 · 最近访问 · 作废
- 复用 `.tbl` `.tbl__url` `.minibtn`

### 8.5 卡片角标

`app/lib/nodes.php:176` 的 `$sharedMap` 旁再查一次 `direct_links` → `direct_count` → `node_public()` 出 `directCount`；`assets/js/files.js:72` 那行旁加一个 `zap` 角标，title「有直链」。（`zap` 与 `plug` 均已确认存在于 `app/icons.php`。）

## 9. 错误处理

- 所有失败路径直接设状态码并 `exit`，返回极简纯文本，不渲染页面——直链的消费者是 `<img>`、播放器、脚本，不是浏览器里的人类。
- 数据库不可用等异常沿用全局异常处理器（`app/bootstrap.php`）。
- 直链请求**不写 `activity_log`**：它可能被高频调用（视频拖动进度条），写日志会污染操作日志。计数只走 `hit_count`。

## 10. 测试

### 10.1 单元测试 `tests/t15_direct.php`

沿用现有测试风格（`tests/lib.php` 的 `check()`，直接调函数，不走 HTTP）：

1. 建表 → 插入 → 查回
2. `direct_normalize_ip()`：合法 IPv4 单地址 / IPv4 CIDR / IPv6 单地址原样通过；`203.0.113.0/33`、`999.1.1.1`、`2001:db8::/32`、空串、乱码返回 null
3. `direct_ip_ok()`：`ip_allow` 为空放行；单 IP 命中与不中；CIDR 命中与不中；**`203.0.113.7` 不得匹配 `203.0.113.70`**（前缀比较陷阱）；IPv6 精确匹配
4. `mime_inline_ok()`：`image/png` 真、`application/pdf` 真、`text/plain` 真、`text/html` 假、`image/svg+xml` 假、未知 MIME 假
5. `direct_resolve()` 逐条对齐 §5 的表——这是主测试，§5 每一行各一条断言：

   | 构造 | 期望 |
   |---|---|
   | token 格式非法 | `ok=false`，code 404 |
   | token 不存在 | 404 |
   | `status = revoked` | 410 |
   | 已过期 | 410 |
   | IP 不匹配 | 403 |
   | IP 匹配 | `ok=true` |
   | 节点已软删除 | 410 |
   | 节点在私密空间 | 403 |
   | 节点是文件夹 | 404 |
   | 全部通过 | `ok=true`，且 `row` / `node` 已填好 |

6. 回收站复活：软删除 → 410；清空 `deleted_at` → 200，且 token 未变
7. 跨用户隔离：别人的 token 取不到我的文件
8. 同一文件多条直链互不影响

方法检查（§5 第 0 步的 405）在路由里，不进 `direct_resolve()`，因此由 §10.2 的 HTTP 实测覆盖。

### 10.2 HTTP 层验证（不写进 `tests/`）

现有测试全是 CLI 直调，没有 HTTP 客户端；为直链单引一套不值。状态码、`Content-Disposition`、Range、HEAD、302 到对象存储，用现成的 CDP 探针实测（站点在 15244）。必须实测确认的项：

- 未登录浏览器打开直链 → 200 且字节正确
- `POST` 到 `direct.serve` → 405
- `Range: bytes=0-9` → 206 且 `Content-Range` 正确
- `HEAD` → 有 `Content-Length`、无 body
- `.html` 直链 → `Content-Disposition: attachment`
- **`<img>` 子资源不受 `attachment` 影响**（§6 的降级策略依赖此前提，不凭记忆断言）
- 命中对象存储副本 → 302 到预签名 URL
- 撤销后 → 410；进回收站 → 410；恢复 → 200

### 10.3 回归

`blob_stream()` 的改动影响 `preview.raw` / `shares.raw` / `download.file` 三处，需回归：

- 图片 / 视频 / 音频 / PDF 预览仍正常
- 分享页内预览仍正常
- 普通下载的文件名与 MIME 不变

## 11. 已知限制

- 直链一旦泄露，在所有者发现并撤销之前一直可用。缓解手段是 `hit_count` + `last_access_at` 提供的异常信号，以及可选 IP 绑定。
- `ip_allow` 只支持单个 IP 或单个 CIDR 段，不支持多值列表。需要多来源时建多条直链。
- 无法区分「同一条直链的正常 Range 请求」与「滥用」，`hit_count` 只能作为粗粒度信号。
- 直链不经过 `download_speed_limit`，大文件可能吃满上行带宽。

## 12. 涉及组件

- `app/schema.php`、`app/router.php`
- `app/lib/directs.php`（新）、`app/modules/direct.php`（新）
- `app/lib/storage.php`、`app/lib/nodes.php`、`app/helpers.php`
- `app/views/shares.php`、`assets/js/console.js`、`assets/js/files.js`
- `tests/t15_direct.php`（新）
