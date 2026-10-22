# 免登录临时传输（双向房间）设计

日期：2026-10-22
状态：待实现
范围：公开的「临时传输」页面 + 房间/分片上传/下载/清理；不复用网盘的节点与内容仓。

## 1. 目标

给两台电脑之间提供一个**双方都不登录**的临时文件中转：局域网优先，公网只要服务器可达也能用。

成功标准：

- 打开公开页即可创建房间，无需账号；创建后得到**链接 + 6 位取件码 + 二维码**
- 另一方凭链接直接进入，或输入 6 位码（可选提取密码）进入
- 房间内双方都能上传、下载，能看到彼此上传的文件；上传者可选昵称
- 局域网内两台机器用 `http://<服务器局域网IP>:15244` 打开即可互传，不依赖公网
- 房间默认 24 小时有效、总容量 10 GB、最多 200 个文件；创建者可随时关闭
- 到期/关闭/超时的房间与文件自动删除，不留痕、不占盘
- 匿名上传不会成为磁盘耗尽入口：房间总容量、单文件、分片、并发都有限制
- 6 位码不可被低成本暴力枚举：按 IP 限速并锁定

## 2. 非目标

- **不做浏览器点对点（WebRTC）**：服务器中转是已确定方案；不引入 TURN/WebSocket 服务。
- **不做端到端加密**：服务器能看到文件内容；公网场景要求 HTTPS，但不承诺零知识。
- **不做在线预览**：图片/视频也走下载；避免同源内容执行与预览解码开销。
- **不做断点续传**：分片会话 1 小时未完成即清理，刷新后需重新上传。
- **不做文件夹上传/打包下载**：一次一个文件，多选即多个文件。
- **不做跨房间互转、与「我的文件」互转、收藏/标签/搜索**。
- **不做实时推送**：房间页每 3 秒轮询一次文件列表（页面隐藏时暂停）。
- **不做永久保存**：临时传输不是网盘；要长期保存请用「我的文件」。

## 3. 现状与可复用部分

- 公开路由白名单：`app/router.php` 的 `PUBLIC_ROUTES`；分享落地页 `shares.open` 已有「免登录页面」的渲染模式。
- 分片上传：`assets/js/share.js` 的 `postChunk()` 使用裸二进制 + `X-Upload-Id` / `X-Chunk-Index` 头；`app/modules/upload.php` 的 `upload_store_chunk()` 已有单分片上限、汇总行锁、`upload_merge_chunks()` 合并。临时传输沿用同样的协议形状，但写入 `runtime/transfer/`，不碰 `upload_sessions`/`blobs`。
- 二维码：`app/lib/qr.php` 的 `qr_svg($text, ...)`；`shares_qr` 已有调用样板。
- 公开页布局：`app/views/share.php` + `render('share', ...)` 是现成的免登录页面样板；`layout.php` 已带 CSRF meta，`SY.api` 可直接用。
- 运行时目录：`runtime/` 已被 nginx 拒绝直访；新增 `runtime/transfer/` 同样在保护范围内。
- 计划任务：`tasks.cron` 每分钟执行一次，可挂接房间清理。

## 4. 数据模型

`SCHEMA_VERSION` 升到 19。新表全部用 `CREATE TABLE IF NOT EXISTS`，由 `db_migrate()` 自动建立，不需要 `ALTER`。

### 4.1 `transfer_rooms`

```sql
CREATE TABLE IF NOT EXISTS transfer_rooms (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token            CHAR(48)        NOT NULL,
    code             CHAR(6)         NOT NULL,
    password_hash    VARCHAR(255)    NULL,
    creator_key_hash CHAR(64)        NOT NULL,
    max_bytes        BIGINT UNSIGNED NOT NULL,
    used_bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    max_files        INT UNSIGNED    NOT NULL,
    file_count       INT UNSIGNED    NOT NULL DEFAULT 0,
    created_ip       VARCHAR(45)     NOT NULL,
    created_at       DATETIME        NOT NULL,
    expires_at       DATETIME        NOT NULL,
    last_access_at   DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_transfer_token (token),
    UNIQUE KEY uk_transfer_code (code),
    KEY idx_transfer_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- `token`：`bin2hex(random_bytes(24))`，48 位，链接用。
- `code`：6 位数字，零填充；在**全部房间行**内唯一，生成时冲突重试最多 10 次。关闭/过期清理后码位释放。
- `creator_key_hash`：`hash('sha256', $creatorKey)`；创建响应只返回一次明文 key。
- `reserved_bytes`：在飞上传预留的字节，用于防止并发上传突破总容量。
- `created_ip`：只用于限速与审计，不在页面展示。

### 4.2 `transfer_files`

```sql
CREATE TABLE IF NOT EXISTS transfer_files (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id      BIGINT UNSIGNED NOT NULL,
    name         VARCHAR(255)    NOT NULL,
    stored_name  CHAR(32)        NOT NULL,
    size         BIGINT UNSIGNED NOT NULL,
    mime         VARCHAR(128)    NULL,
    uploader_name VARCHAR(40)    NULL,
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_transfer_files_room (room_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- `stored_name`：`bin2hex(random_bytes(16))`，不带扩展名；物理路径只由它推导。
- `name`：原始文件名，经 `safe_filename()`；下载时放在 `Content-Disposition` 里。
- `uploader_name`：可选昵称，最多 40 字；页面显示「来自 X」。

### 4.3 `transfer_uploads`

```sql
CREATE TABLE IF NOT EXISTS transfer_uploads (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    upload_id      CHAR(32)        NOT NULL,
    room_id        BIGINT UNSIGNED NOT NULL,
    filename       VARCHAR(255)    NOT NULL,
    size           BIGINT UNSIGNED NOT NULL,
    chunk_size     INT UNSIGNED    NOT NULL,
    total_chunks   INT UNSIGNED    NOT NULL,
    received       JSON            NULL,
    received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploader_name  VARCHAR(40)     NULL,
    status         ENUM('pending','uploading','merging','done') NOT NULL DEFAULT 'pending',
    created_at     DATETIME        NOT NULL,
    updated_at     DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_transfer_upload (upload_id),
    KEY idx_transfer_upload_room (room_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- 只记录「正在进行」的上传；合并成功写入 `transfer_files` 后删除会话行。
- 超过 1 小时未完成的会话由清理逻辑删除，并释放对应的 `reserved_bytes`。

### 4.4 `transfer_attempts`

```sql
CREATE TABLE IF NOT EXISTS transfer_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARCHAR(45)     NOT NULL,
    kind       ENUM('create','join') NOT NULL,
    code       CHAR(6)         NULL,
    created_at DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY idx_transfer_attempt (ip, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- `create`：每次建房成功或失败都记一行，用于每小时限额。
- `join`：**只在试码失败时**记一行；用于 10 次/10 分钟锁定。

## 5. 页面与流程

### 5.1 入口

- 新公开路由 `transfer.index`，登录页底部加「临时传输（免登录）」链接；首页页脚也放一个。
- 页面独立于应用侧栏，沿用分享落地页的公开布局；顶部只有品牌、「临时传输」标题。
- 直接访问 `?r=transfer.index` 也可用；无需任何账号。

### 5.2 创建房间

表单只有一项可选：**提取密码**（留空则不需要）。点「创建房间」后：

- 返回并展示：**取件码（6 位，大字）**、**链接**、**二维码**、**24 小时有效/10 GB** 说明；
- 创建者的 `creator_key` 存进 `localStorage['transfer.ck.' + token]`，页面据此显示「关闭房间」「删除文件」；
- 创建成功时同时把 `$_SESSION['transfer_unlock'][roomId]` 置为已解锁，创建者不需要再输一次自己设的密码；
- 创建者随后进入房间页，可直接上传。

### 5.3 加入房间

- 打开链接 `?r=transfer.room&t=<token>` 直接进入。
- 或在 `transfer.index` 输入 6 位码 + 密码（若设置了）→ `transfer.join` 校验 → 跳到房间页。
- 密码房未解锁时，房间页只显示密码输入框；解锁状态存 `$_SESSION['transfer_unlock'][roomId]`。密码最长 32 位。
- `transfer.room` 缺少 `t` 时回到 `transfer.index`。
- 试码失败按 IP 限速；锁定期间返回「尝试次数过多，请稍后再试」。

### 5.4 房间页

- 顶部：取件码、复制链接、二维码按钮、剩余有效期倒计时、已用/总容量、文件数。
- 上传区：拖放或点击选择，多选；显示每个文件的进度；可选昵称（存 localStorage，随下次上传带上）。
- 文件列表：名称、大小、上传时间、上传者昵称、「来自 X」；每行「下载」。
- 创建者额外看到：每个文件的「删除」、顶部「关闭房间」。
- 轮询：页面可见时每 3 秒请求 `transfer.files`；`document.hidden` 时暂停，恢复可见时立即拉一次。
- 房间关闭/过期：房间行已删除，页面统一显示「房间不存在或已关闭」，上传与下载禁用；提供「再建一个」入口。
- 容量/文件数用尽：上传区显示「房间已满」，禁用上传。

### 5.5 链接地址

- 创建时链接用**创建者当前访问的 Host** 拼接（`new URL(url('transfer.room', ['t'=>token]), location.href)`）。
- 局域网互传：在局域网机器上用 `http://<服务器IP>:15244` 打开本站再创建，链接就是局域网地址。
- 公网：用隧道/公网域名打开再创建，链接就是公网地址；HTTPS 由反向代理/隧道提供。
- 页面提示：链接使用当前访问地址；换网络环境请重新创建或手动替换域名。
- 如果当前 Host 是 `localhost` / `127.0.0.1` / `::1`，创建结果必须显著提示「这个链接只在本机有效；局域网互传请用服务器局域网 IP 打开本站后重新创建」，避免把 localhost 链接发给另一台电脑。
- 如果配置了 `base_url`，创建结果可以额外给出公网链接（与当前 Host 链接并列）；未配置时只给当前 Host 链接。

## 6. 路由与接口

全部加入 `PUBLIC_ROUTES`；写接口使用 `require_csrf()`（匿名用户也有 PHP 会话与 CSRF token）。`transfer.upload_chunk` 是裸二进制请求，CSRF 通过 `X-CSRF-Token` 头传递（与 `shares.upload_chunk` 一致）。

`transfer.files` 返回：

```php
[
    'room' => [
        'token' => '...', 'code' => '123456',
        'expiresAt' => '2026-10-23 12:00:00', 'expiresIn' => 86399,
        'maxBytes' => 10737418240, 'usedBytes' => 123, 'reservedBytes' => 0,
        'maxFiles' => 200, 'fileCount' => 1,
    ],
    'files' => [[
        'id' => 1, 'name' => 'a.zip', 'size' => 123,
        'mime' => 'application/zip', 'uploader' => 'Alice',
        'createdAt' => '2026-10-22 12:00:00',
    ]],
]
```

| 路由 | 方法 | 入参 | 说明 |
|---|---|---|---|
| `transfer.index` | GET | — | 公开首页（创建/输入取件码） |
| `transfer.room` | GET | `t` | 房间页；密码房未解锁时渲染密码框 |
| `transfer.create` | POST | `password?` | 建房；返回 `{room:{token,code,url}, creator_key}`；按 IP 限速 |
| `transfer.join` | POST | `code`, `password?` | 取件码进入；返回 `{token,url}`；失败限速 |
| `transfer.unlock` | POST | `t`, `password` | 链接进入的密码房解锁；失败限速 |
| `transfer.files` | GET | `t` | 房间状态 + 文件列表；需已解锁 |
| `transfer.upload_init` | POST | `t`, `name`, `size`, `sender?` | 初始化分片；预留容量 |
| `transfer.upload_chunk` | POST | `t` + 头 `X-Upload-Id`/`X-Chunk-Index` | 裸二进制分片；单分片上限 |
| `transfer.upload_complete` | POST | `t`, `uploadId` | 合并并落库；原子扣容量/文件数 |
| `transfer.upload_abort` | POST | `t`, `uploadId` | 放弃并释放预留 |
| `transfer.download` | GET | `t`, `id` | 流式下载，支持 Range；需已解锁 |
| `transfer.close` | POST | `t`, `creator_key` | 创建者关闭房间，立即删文件 |
| `transfer.file_delete` | POST | `t`, `creator_key`, `id` | 创建者删除单个文件 |
| `transfer.qr` | GET | `t` | 返回房间链接的 SVG 二维码（复用 `qr_svg()`；密码不在码里） |

错误码补充：`room_gone`（不存在/已关闭/已过期，统一不区分）、`room_full`、`password_required`、`bad_password`、`rate_limited`、`no_session`、`chunk_failed`。

## 7. 上传协议与容量

### 7.1 初始化

`transfer.upload_init`：

1. 校验房间 active、未过期、已解锁。
2. `safe_filename()` 清洗文件名（≤255 字节）；`size` 必须 ≥0 且 ≤ 房间剩余容量；超过 `max_upload` 也拒绝。空文件允许：`total_chunks = 1`，客户端发一个 0 字节分片。
3. 单文件上限 = `min(config('max_upload'), 房间剩余容量)`。
4. 原子预留：
   ```sql
   UPDATE transfer_rooms
      SET reserved_bytes = reserved_bytes + :size
    WHERE token = :token AND expires_at > NOW()
      AND used_bytes + reserved_bytes + :size <= max_bytes
   ```
   影响行数为 0 → `409 room_full`。
5. 生成 `upload_id`（32 位 hex）、`chunk_size = config('chunk_size')`（5 MiB）、`total_chunks = max(1, ceil(size/chunk_size))`；`total_chunks > 10000` 拒绝。
6. 插入 `transfer_uploads`，返回 `{uploadId, chunkSize, totalChunks}`。

### 7.2 分片

`transfer.upload_chunk` 沿用分享上传的形状：

- 请求体是裸二进制；`Content-Type: application/octet-stream`；头 `X-Upload-Id`、`X-Chunk-Index`。
- 只接受属于本房间、状态为 pending/uploading 的会话。
- 分片序号 `0 <= index < total_chunks`。
- 单分片实际写入字节 `<= chunk_size`；累计写入 `<= size`。
- 写入 `runtime/transfer/<token>/<upload_id>/<index>.part`；写后 `chmod 0600`。
- 用行锁更新 `received` JSON 与 `received_bytes`，避免并发上报互相覆盖；重复上传同一序号按覆盖处理并回正字节数。
- 单房间同时进行的分片会话上限 20；超过返回 `429 too_many`。

### 7.3 合并

`transfer.upload_complete`：

1. CAS 抢占会话：`UPDATE transfer_uploads SET status='merging' WHERE upload_id=? AND room_id=? AND status IN ('pending','uploading')`；抢不到返回 `409 in_progress`。
2. 校验所有分片都在，按序号顺序合并到 `runtime/transfer/<token>/<stored_name>`，边合并边累计实际字节；实际字节不得超过声明 `size`。
3. 原子落账：
   ```sql
   UPDATE transfer_rooms
      SET used_bytes = used_bytes + :actual,
          reserved_bytes = GREATEST(0, reserved_bytes - :declared),
          file_count = file_count + 1
    WHERE token = :token
      AND used_bytes + :actual <= max_bytes
      AND file_count < max_files
   ```
   影响行数为 0 → 删除合并文件、释放预留、返回 `409 room_full`。
4. 插入 `transfer_files`，删除 `transfer_uploads` 行与分片目录。
5. 重复 complete：会话已不存在时返回 `404 no_session`，由前端忽略。

### 7.4 放弃/超时

- `transfer.upload_abort`：删除分片目录、删除会话、`reserved_bytes -= 声明 size`（`GREATEST(0, …)`）。
- 清理任务：删除 `updated_at` 超过 1 小时的 `pending/uploading` 会话，删除分片目录并释放预留。

### 7.5 下载

- 校验房间 active/未过期/已解锁；文件必须属于该房间。
- 以 `application/octet-stream` 流式输出（不内联），`Content-Disposition: attachment; filename*=UTF-8''<rawurlencode(name)>`。
- 支持 `Range`（单段），返回 `206`/`Content-Range`；不支持多段 Range。
- 不记录逐次下载明细；只更新 `transfer_rooms.last_access_at`（节流，最多每分钟一次）。

## 8. 生命周期与清理

- 创建时 `expires_at = now + config('transfer_ttl_hours')`（默认 24 小时）。
- 创建者关闭：立即删除房间目录、`transfer_files`/`transfer_uploads` 行与 `transfer_rooms` 行（不保留 tombstone）；页面统一显示「房间不存在或已关闭」；码位随行删除而释放。
- 到期：任何 transfer 请求前先做一次惰性清理；计划任务每分钟也调用 `transfer_reap_expired()`。
- 清理内容：过期/关闭房间 → 删除目录、DB 行；超时分片会话 → 删除目录、释放预留；`transfer_attempts` 只保留 1 天。
- 目录删除必须限制在 `runtime/transfer/` 内：房间 token 是 `[a-f0-9]{48}`，路径只由它推导；不跟随符号链接。

## 9. 权限与身份

- **创建者**：创建响应返回一次性 `creator_key`；浏览器存 `localStorage['transfer.ck.'+token]`。关闭房间、删除任意文件都需要它在请求体里回传，服务端 `hash_equals(sha256(key), creator_key_hash)`。
- **其他参与者**：凭链接/码进入；可上传、下载；不能删除文件、不能关闭房间。
- 创建者删除单个文件时，同步 `used_bytes -= size`、`file_count -= 1`（`GREATEST(0, …)`）并删除物理文件。
- **昵称**：可选，存 `localStorage['transfer.name']`，随 `upload_init` 发送，最多 40 字；只展示，不做身份凭证，渲染时 `escapeHtml`。
- 密码房：解锁状态按房间记在 `$_SESSION`；下载接口同样检查。
- 换设备/清 localStorage 会失去创建者权限；房间仍会在 24 小时后自动清理（页面明确提示）。

## 10. 安全与防滥用

- **限速**：
  - 建房：每 IP 每小时最多 `transfer_create_per_hour`（默认 5）次；失败也计数。
  - 试码/试密码：每 IP 每 10 分钟最多 10 次失败；超过锁 15 分钟；返回 `429 rate_limited`。`transfer.join` 与 `transfer.unlock` 的失败共用这个计数。
  - 分片会话：每房间同时最多 20 个。
- **容量**：房间 10 GB、200 文件、单文件 ≤ 剩余容量；`reserved_bytes` 防止并发上传超卖。
- **全局上限**：`transfer_global_max_bytes`（所有房间 `max_bytes` 之和，默认 50 GiB）与 `transfer_global_max_rooms`（默认 20）；建房时用 MySQL 命名锁串行化检查，防止刷房间把磁盘写满。
- **代理后的真实 IP**：站点在 frp/反向代理后面时直连 peer 是回环；只有 `transfer_trust_proxy=true` 且 peer 是回环时才采信 `X-Forwarded-For` / `X-Real-IP`，否则按 `REMOTE_ADDR`。生产部署在代理后必须开启，否则所有用户共用一个限速桶。
- **磁盘**：分片单请求上限 `chunk_size`；合并后实际字节不得超声明值；超时会话释放预留；`transfer_enabled=false` 只阻止**新建房间**，已有房间仍可访问到到期或创建者关闭（要立即停服需手动删除房间行/目录）。
- **路径安全**：文件名只用于展示/下载头；物理名是随机 32 位 hex；房间目录只由 token 推导；不跟随符号链接。
- **XSS**：所有文件名/昵称/错误文案经 `escapeHtml`；房间页不内联展示文件内容；下载一律 `attachment` + `X-Content-Type-Options: nosniff`。
- **暴力枚举**：6 位码有 100 万空间，靠 IP 限速 + 15 分钟锁定；高价值场景建议加密码。
- **隐私提示**：页面明确写「文件临时保存在本服务器，到期自动删除；服务器管理员理论上可读取内容」。公网请使用 HTTPS。
- **不引入新依赖/服务**：纯 PHP + 现有分片/二维码/计划任务；不加 WebSocket/TURN。

## 11. 配置

在 `app/config.example.php` 增加（代码里都有默认值，不填也能跑）：

```php
'transfer_enabled'        => true,
'transfer_ttl_hours'      => 24,
'transfer_max_bytes'      => 10 * 1024 ** 3,  // 10 GiB
'transfer_max_files'      => 200,
'transfer_create_per_hour'=> 5,
'transfer_code_attempts'  => 10,
'transfer_storage_path'   => BASE_PATH . '/runtime/transfer',
'transfer_trust_proxy'      => false,   // 在可信 frp/反向代理后面时设为 true
'transfer_global_max_bytes' => 50 * 1024 ** 3,
'transfer_global_max_rooms' => 20,
```

## 12. 错误处理

- 房间不存在/已关闭：页面显示「房间不存在或已关闭」，不区分具体原因（避免探测）。
- 密码错误：`bad_password`，计入试码失败；连续失败锁定。
- 容量不足：`room_full`；上传区提示「房间已满」。
- 分片越界/超限：`chunk_failed`，删除该分片并提示重试。
- 合并失败：会话回到 `uploading`（允许重试），已写分片保留；超过 1 小时由清理任务回收。
- 网络中断：前端保留失败文件在队列里，提供「重试」；刷新后需重新上传（非目标：断点续传）。

## 13. 测试

新增 `tests/t24_transfer.php`，以 `www` 用户运行：

- 建房：token 48 位、码 6 位数字、creator key 哈希、过期时间、容量默认值。
- 码唯一：连续建房 50 次无重复；冲突重试逻辑。
- 密码：正确/错误、解锁会话、错误计数与锁定。
- 容量：预留、并发预留超卖拒绝、合并后 used/reserved 正确、文件数上限。
- 分片：越界序号、超分片大小、累计超 size、重复序号、合并字节校验。
- 路径安全：文件名 `../../x`、超长、控制字符被清洗；存储名随机。
- 清理：过期房间删除目录/DB；超时会话释放预留；attempts 只留 1 天。
- 权限：无 creator key 不能关闭/删除；错误 key 拒绝；密码房未解锁不能列表/下载。
- 公开路由与静态检查：`PUBLIC_ROUTES` 含 transfer.*；视图/JS 关键文案存在。
- 手工/CDP：创建房间 → 上传 → 下载 → 第二浏览器用码加入 → 双方互传 → 关闭；两台局域网机器实测由用户完成。

## 14. 实施分期

- **第 1 期：地基**——4 张表、`app/lib/transfer.php`（建房/加入/容量/清理）、`tests/t24_transfer.php` 的域逻辑测试。
- **第 2 期：公开页**——`transfer.index`/`transfer.room` 视图、创建/加入/链接/二维码/轮询、入口链接。
- **第 3 期：上传下载**——分片初始化/上传/合并/放弃、流式下载（Range）、创建者关闭/删除。
- **第 4 期：收尾**——限速、清理任务挂接、README、CDP 实测。

## 15. 文件清单

新增：

- `app/lib/transfer.php`：房间/文件/上传/清理领域逻辑
- `app/modules/transfer.php`：公开路由与接口
- `app/views/transfer.php`：创建/加入页
- `app/views/transfer_room.php`：房间页
- `assets/js/transfer.js`：创建/加入交互
- `assets/js/transfer_room.js`：房间轮询/上传/下载/创建者操作
- `assets/css/transfer.css`
- `tests/t24_transfer.php`

修改：

- `app/schema.php`：4 张新表，`SCHEMA_VERSION` 19
- `app/router.php`：`PUBLIC_ROUTES` 增加 transfer.*
- `app/modules/tasks.php`：cron 调用 `transfer_reap_expired()`
- `app/views/auth.php`：登录页加入口
- `app/views/home.php`：页脚加入口（可选）
- `app/config.example.php`：配置项
- `README.md`：使用说明与安全提示

不改：

- `nodes` / `blobs` / `blob_locations` / 网盘下载链路——临时传输完全独立。
