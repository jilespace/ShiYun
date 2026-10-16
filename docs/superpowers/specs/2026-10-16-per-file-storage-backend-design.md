# 每文件可选存储后端（本地 / 腾讯云 COS）设计

日期：2026-10-16
状态：待实现

## 1. 目标

让用户在**上传时**为每个文件选择存储后端：本地磁盘，或已配置的 S3 兼容存储源（腾讯云 COS）。网盘本身退居「管理平面」；需要大带宽的文件托管到 COS，下载时客户端直连 COS，不占用本机上行带宽。

成功标准：

- 上传时可选「本地」或某个已配置的 s3 存储源
- 选 COS 的文件，点下载时 `302` 到预签名直链，字节不经过本机，且下载文件名保持原名
- COS 上的文件，**分享、版本、API 下载**等既有功能全部可用
- 本地始终保留一份副本，因此预览、缩略图、在线解压、全文检索**零改动**

## 2. 非目标

以下明确不做，避免范围蔓延：

- **不把 COS 当唯一存储**。本地不留副本的形态（用户已否决）不做。
- **内联预览与 WebDAV 不做直链**。视频/音频/图片在浏览器里的播放、以及 WebDAV GET 仍走本地代理。
- **不做 S3 分片上传**。单次 PUT 是原子的，超大文件失败后从头重试。
- **FTP / 本地类型存储源不做直链**。它们无法预签名。
- **不做历史数据的自动搬迁工具**。已有文件靠界面上的「补推」动作处理。

## 3. 现状

- `blobs` 表只有 `hash / size / mime / ref_count`，**没有「存在哪儿」这个字段**；物理文件恒在 `runtime/storage/{hash[0:2]}/{hash[2:4]}/{hash}`。
- `nodes.source_id` 目前只是「这份内容已推过副本到某个源」的**标记**，读的时候并不去那儿读。
- 全部 blob 读写收口在 6 个函数：`blob_ingest_file()`（写入）、`blob_path()` / `blob_open()` / `blob_stream()` / `blob_size()`（读取）、`make_thumbnail()`。
- `blob_path()` 在 12 处被调用（预览、解压、检索、WebDAV、秒传检查、缩略图生成），**全部假设本地文件存在**。本设计因为「本地始终保留副本」，这些调用点**一处都不用改**。
- 推送用的 key 是 `{hash[0:2]}/{hash}`，内容寻址、天然幂等。
- `storage_drivers.php` 已有 `s3_request()`，做的是**请求头**签名（`Authorization: ...`）。

## 4. 数据模型

### 4.1 新表 `blob_locations`

```sql
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
    KEY idx_blobloc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

语义规则：

- **粒度是 blob 级，不是 node 级。** 去重意味着同一份内容可能被多个节点引用；记在 blob 上，推一次，所有引用它的节点下载时都能走直链。这是选这个模型而非复用 `nodes.source_id` 的根本原因。
- `object_key` 存**逻辑 key**（`{hash[0:2]}/{hash}`），不含桶内路径前缀——前缀由 `s3_request()` 在请求时从 config 施加，与现有 `storage_source_put()` 的返回值保持一致。
- `status`：`pending` 排队中 / `ok` 可用 / `failed` 推送失败（可重试）/ `stale` 推送成功过但对象已不在（HEAD 探测失败）。
- 唯一键保证「一份内容对一个源只有一条记录」，重复推送是幂等的。

### 4.2 跨用户去重（重要）

`blobs` 表没有 `user_id`，`UNIQUE(hash)`，`blob_find()` 不带用户过滤——**两个用户上传同一份内容会共用同一个 blob**。

因此读取时**必须按 source 的归属过滤**，只认 `storage_sources.user_id = 当前用户` 的 location。否则 B 用户下载时可能被 302 到 A 用户的 COS 桶，消耗 A 的流量。这条规则贯穿读写两处。

### 4.3 迁移

- `SCHEMA_VERSION` 1 → 2。新表靠现有 `db_migrate()` 的 `CREATE TABLE IF NOT EXISTS` 机制自动创建。
- `nodes.source_id` 被 `blob_locations` 完全取代（它是 node 级的、去重下语义错误）。因现有迁移机制不支持 ALTER，在 `db_migrate()` 中加一段**幂等**收尾：`SHOW COLUMNS FROM nodes LIKE 'source_id'` 探测存在后再 `DROP COLUMN`，只在版本跃迁时执行一次。
- `sources.php` 里的用量统计与删除前检查改走 `blob_locations`。

## 5. 写入路径

### 5.1 后端选择的发生点

- **支持选后端**：主上传（`upload.php`）、API 上传（`api.php` 新增 `source` 参数）。这两处是「用户主动上传文件」的场景。
- **一律本地**：WebDAV PUT（客户端无法表达该意图）、分享页匿名上传、在线解压/压缩的产物、离线下载任务（后续可再评估）。

界面：上传按钮旁一个「存到 ▾」下拉，列出「本地」+ 已配置的 s3 源；拖拽上传沿用当前选择；上传队列中每一项仍可单独修改。**记住上次选择**——存进现有的 `user_settings`（键名 `upload_source_id`，0 表示本地），沿用既有用户设置读写机制。

### 5.2 `blob_push_to_source($blobId, $sourceId)`

上传流程不变——先 `blob_ingest_file()` 收进本地内容仓（保证本地永远有一份），然后按需推送。

**分工**：上传请求只负责**入队**一个 `push` 任务（payload 带 `blob_id` + `source_id`），真正的 PUT 由队列执行时调用的 `blob_push_to_source()` 完成。这样请求线程不等网络。

`blob_push_to_source()` 的行为：

1. 查 `blob_locations` 中 `(blob_id, source_id)` 的记录：`status='ok'` → 直接返回（幂等跳过）；`failed` / `stale` / `pending` / 无记录 → 推送。
2. 先写一条 `pending`，再调 `storage_source_put()`；成功改 `ok` 并记 `object_key`，失败改 `failed` 并记 `error`。
3. **不向调用方抛异常**——文件在本地是好的，推送失败只意味着「少了一份 COS 副本」。失败信息通过 location 行与任务行呈现。

去重交互：

- 本地秒传命中（`dedup=true`）时，**推送照样要跑**——去重命中的是本地，不代表 COS 上有。
- COS 上已有（`status='ok'`）时跳过，不做无谓的重复上传。

### 5.3 推送异步执行

同步在上传请求里 PUT 到 COS，等于传一个 2 GB 文件要等两遍完整上传，且大概率撞上 PHP 请求超时。因此**新增 `push` 任务类型**丢进既有的 `transfer_tasks` 队列：

- 复用刚配好的 cron 驱动，上传请求立刻返回
- 复用队列已有的重试（最多 3 次）、暂停/取消/进度/错误展示
- 失败后用户在任务页或文件信息里点「重试」

已知代价：S3 单次 PUT 不可续传，超大文件失败重试是**从头再来**。几百 MB 无碍，数 GB 且网络不稳时会比较难受。

## 6. 读取路径

### 6.1 统一入口 `blob_deliver()`

4 处下载现在都调 `blob_stream()`，改为调用 `blob_deliver($userId, $blobId, $mime, $filename, $speedLimit)`：

1. 找可用直链：`blob_locations` JOIN `storage_sources`，条件 `blob_id=? AND status='ok' AND driver='s3' AND sources.user_id=?`，取优先级最高的一条。
2. 命中 → 生成预签名 URL → `302`。
3. 未命中 → 原样走 `blob_stream()` 本地流式。

改动的 4 处：

| 文件 | 位置 | 说明 |
|---|---|---|
| `download.php` | `:29` | 主下载 |
| `shares.php` | `:351` | 分享文件下载 |
| `versions.php` | `:108` | 版本下载 |
| `api.php` | `:277` | API 下载 |

**不改**的 3 处：`preview.php:66`（内联播放）、`shares.php:376`（分享页内联预览）、`webdav.php:326`（WebDAV GET）。分享文件夹的打包下载是动态 zip，也留在本地。

### 6.2 `s3_presign()`

查询串签名（`X-Amz-Algorithm` / `Credential` / `Date` / `Expires` / `SignedHeaders` / `Signature`），与现有 `s3_request()` 的请求头签名是两套算法。附带 `response-content-disposition`，保证下载文件名为原名而非 COS 上的哈希名。

有效期来自新增配置 `presign_ttl`（秒），默认 7200（2 小时）。选 2 小时是因为浏览器媒体元素/下载器在开始拉取后会用同一个 URL 持续发 Range 请求，URL 中途过期会断流。

### 6.3 回退：HEAD 探测 + 失败即记坏

跳转前先对 COS 发一次轻量 HEAD（超时 3 秒），避免用户撞上 403/404。但每次下载都多一次往返会拖慢响应，因此：**HEAD 一旦失败，就把这条 location 标成 `stale`，之后所有下载直接跳过 COS 走本地**——惩罚只吃一次。重新推送成功后恢复 `ok`。

### 6.4 边界

- **私密空间的文件不 302**，仍走本地。直链在有效期内等于「谁拿到 URL 谁能下」，与私密空间的语义正面冲突。
- **限速对 COS 直链失效**（字节不经过本机）。界面上明说，避免用户误以为限速仍生效。
- **API 下载也 302**，走标准路径（S3 自身亦如此）。代价是调用方需跟重定向（`curl` 要带 `-L`）。在令牌页的接口说明里写清楚。

## 7. 生命周期

`blob_release()` 在引用归零时现在只删本地文件。改为：

1. 取出该 blob 的 `blob_locations`
2. 删除本地文件与 location 行
3. 把 `(source_id, object_key)` 丢进队列做远程 DELETE（新增 `purge` 任务类型）

**不能同步做**：清空回收站若有 50 个 COS 文件，每个等数秒就是几分钟。

**必须写死的护栏**：远程删除任务执行前，要**再查一次有没有 blob 仍引用这个 `(source_id, object_key)`**。因为 key 是内容寻址的，用户删完又传同一份内容，新对象会落在同一个 key 上，而队列里待执行的删除任务会把**新的**对象误删。这是整个设计里最容易踩的坑。

## 8. 界面

- **上传区**：「存到 ▾」下拉（本地 + 已配置 s3 源），记住上次选择；拖拽沿用当前值；队列每项可单独改
- **文件信息 / 右键菜单**：「COS 副本：已同步 / 推送中 / 失败 [重试]」
- **存储源页**：用量统计改走 `blob_locations`；「补推未同步的文件」批量动作取代现有 `sources_push`
- **任务页**：新增 `push` / `purge` 两种类型的展示
- **下载相关处**：说明「COS 直链不受限速影响」

## 9. 错误处理

| 场景 | 行为 |
|---|---|
| 推送失败（网络/AK/权限） | 文件已存本地；location 记 `failed` + 原因；界面显示失败徽标与重试入口 |
| 下载时 COS 对象不存在 | HEAD 失败 → 标 `stale` → 本次及后续回退本地流式 |
| 下载时 COS 暂时不可达 | HEAD 超时 3 秒 → 同上回退本地 |
| 远程删除失败 | `purge` 任务记失败，任务列表可见 |
| 内容寻址 key 被复用 | `purge` 执行前复查引用，避免误删新对象 |

## 10. 测试

项目无测试框架，延续端到端手测，分两层：

**预签名算法** —— 最容易写错的一块（SigV4 查询串签名与请求头签名是两套算法）。无真实凭证时只能做结构校验，**无法证明 COS 会接受**。需要用户提供一个测试用桶 + AK/SK 才能验证。

**端到端**：

1. 上传选 COS → 在 COS 控制台确认对象存在
2. 点下载 → 确认 `302` 且文件名正确 → 校验字节与源文件一致
3. 后台把对象删掉 → 再下载 → 确认自动回退本地且 location 变 `stale`
4. 同一份内容传两次（一次本地、一次 COS）→ 确认只产生一个 blob 且直链可用
5. 彻底删除 → 确认 COS 上对象也被清除
6. 删除后立刻重传同一份内容 → 确认 `purge` 任务没有误删新对象

## 11. 已知限制

- 预签名功能**已用真实 COS 凭证实测通过**（2026-10-16）：真实 PUT、直链下载字节一致、
  远端失效后正确回退本地并标 `stale`。实测过程中还发现并修掉两处只有真机才能暴露的缺陷：
  (1) COS 拒绝 path-style 寻址（`PathStyleDomainForbidden`），已改为虚拟主机风格；
  (2) `s3_request()` 对 HEAD 误用 `CURLOPT_CUSTOMREQUEST` 而非 `CURLOPT_NOBODY`，
  导致 curl 死等一个 HEAD 永不发来的响应体、5 秒后超时——**这曾使直链功能整体不生效**
  （每次下载都误判远端已死并回退本地）
- 超大文件（数 GB）推送失败后从头重试
- 内联预览与 WebDAV 不享受 COS 带宽
- 跨用户去重导致 blob 共享，已用「按 source 归属过滤」规避流量串用，但**去重本身泄露「某人拥有某文件」这一事实**是既有问题，不在本次范围

## 12. 涉及组件

| 文件 | 改动 |
|---|---|
| `app/schema.php` | 新表 `blob_locations`；`SCHEMA_VERSION` 1→2 |
| `app/db.php` | 幂等删除 `nodes.source_id` |
| `app/lib/storage.php` | `blob_deliver()`；`blob_release()` 增加远程清理入队 |
| `app/lib/storage_drivers.php` | `s3_presign()`；远程删除助手 |
| `app/lib/locations.php`（新增） | `blob_location_*` 查询助手 |
| `app/modules/upload.php` | 接收 `source` 参数 |
| `app/modules/api.php` | 接收 `source` 参数；下载改 `blob_deliver()` |
| `app/modules/download.php` | 改 `blob_deliver()` |
| `app/modules/shares.php` | 分享下载改 `blob_deliver()` |
| `app/modules/versions.php` | 版本下载改 `blob_deliver()` |
| `app/modules/tasks.php` | `push` / `purge` 任务类型 |
| `app/modules/sources.php` | 推送流程改走 `blob_locations` |
| `app/modules/files.php` | 节点信息展示 COS 状态 |
| `app/bootstrap.php` | 引入新 lib |
| 视图 + `assets/js/upload.js` | 后端选择器、状态徽标、重试入口 |
