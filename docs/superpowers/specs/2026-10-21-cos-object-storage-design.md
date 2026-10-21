# 对象存储（COS / S3）管理页设计

日期：2026-10-21
状态：待实现
范围：只做「桶与对象的管理界面」；把桶挂载成网盘另立规格。

## 1. 目标

把现有「存储源」页里那块简陋的桶面板，升级成一个独立、可日常使用的对象存储管理页，对腾讯云 COS 优先适配，同时兼容 MinIO、阿里云 OSS 等 S3 协议实现。

成功标准：

- 用一组 COS 子账号密钥接入后，能列出账号下的桶（需要 `ListBuckets` 权限；最小权限密钥没有该权限时，可直接输入桶名进入该桶）
- 能建桶、删空桶、清空桶（删/清都必须原样输入桶名）
- 能像 COS 控制台一样按前缀逐层浏览对象，翻页、按前缀搜索
- 能拖放上传（大文件自动分片）、预签名直连下载、复制、重命名/移动、批量删除
- 能读写版本控制、静态网站、CORS、生命周期、标签、ACL；能只读查看桶策略
- 桶一旦被绑定为存储源，对象页对它只读——桶内对象是本地仓的副本记录
- 凭据永不出现在浏览器可读的响应里；下载/上传走预签名，字节不经过本机
- 归档/深度归档对象能提示并触发解冻
- 测试存根覆盖所有新增协议形状；真实 COS 联调由用户在页面上完成

## 2. 非目标

以下明确不做，避免范围蔓延：

- **不做「把桶挂载成网盘」。** 那需要给 `nodes` 增加远程直连模式，并让文件列表、预览、分享、WebDAV、回收站全部认识它，是另一份规格。
- **不实现 COS XML API v5 签名。** 只用 COS 的 S3 兼容端点（SigV4）。现有 `s3_request` / `s3_presign` 已经是这套算法，重复实现一套 COS 原生签名只会增加回归面。
- **不做自定义域名/CDN 下载。** 预签名 URL 的 Host 参与签名，替换成 CDN 域名需要换一套签名或回源配置，容易做出「看起来能用、实际 403」的坑。
- **不做对象版本列表/恢复。** 版本控制只做开关与状态展示；逐版本浏览是后续独立能力。
- **不做服务端加密（SSE）、跨区域复制、清单、图片处理、批量任务。** 都是 COS 私有或低频能力。
- **不做桶策略写入。** 只读展示 JSON，避免一次误配把桶开成公网可写。
- **不做服务器中转上传兜底。** 上传一律浏览器直传预签名 URL；CORS 未放行时给出明确提示与一键配置入口，而不是偷偷改走本机中转（不透明、翻倍带宽、还要占本机临时盘）。
- **不替代「存储源」页。** 存储源继续管本地/FTP/SFTP 与副本推送；对象页管 S3/COS 的桶和对象。

## 3. 现状与可复用部分

现有实现集中在：

- `app/modules/sources.php`：桶列表/建桶/删桶/绑定/看前 200 个对象/删对象/清空桶
- `app/views/sources.php`：`[data-bucket-panel]` 面板
- `assets/js/console.js`：`pageSources()` 里的桶面板逻辑
- `app/lib/storage_drivers.php`：`s3_request`（SigV4 请求头签名）、`s3_presign`（查询串签名）、`s3_list_buckets`、`s3_create_bucket`、`s3_delete_bucket`、`s3_list_objects`、`s3_delete_objects`、`s3_purge_objects`
- `tests/stub_s3.php` + `tests/t16_bucket.php`：本地 S3 存根与协议形状测试

可直接复用：

- 虚拟主机风格寻址已实现，注释明确写了「COS 拒绝 path-style」
- `s3_request` 已支持 `opts.query`、`opts.body`、`opts.file`、`opts.download`、`opts.content_type`、`opts.content_md5`、`opts.payload_hash`
- `s3_delete_objects` 已按 `DeleteObjects` 批量删并做 XML 转义
- `s3_error_message` 已把常见 S3 错误翻成中文
- `s3_bucket_config` 已处理「换桶、清 prefix」
- 页面骨架 `.pagehead` / `.panel` / `.tbl` / `.chipbtn` / `.blank` / `.pill`、`SY.api` / `SY.openModal` / `SY.prompt` / `SY.toast` 现成可用

需要补齐：

- `s3_request` 不能带任意签名头（Copy、ACL、StorageClass 都需要）
- `s3_presign` 不支持 STS `session_token`，也不支持 `response-content-disposition`
- 对象列表只解析 `Contents`，没有 `CommonPrefixes` / `NextContinuationToken` / `ETag` / `StorageClass`
- 没有 HEAD 对象、Copy、Restore、Multipart、子资源读写

## 4. 数据模型

### 4.1 新表 `object_accounts`

一组凭据 = 一个账号。与 `storage_sources` 解耦：账号只负责「能访问哪些桶」，存储源负责「副本落到哪里」。

```sql
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
```

- `SCHEMA_VERSION` 升到 18。新表由 `schema_statements()` 的 `CREATE TABLE IF NOT EXISTS` 自动建立，不需要 `ALTER`。
- 密钥明文存储，与现有 `storage_sources.config` 一致。页面提示：请使用只授予目标桶权限的子账号密钥。
- `provider` 只影响界面预设与校验（地域下拉、endpoint 自动填充、APPID 校验），协议层一律按 S3 处理。

### 4.2 绑定关系

- 绑定桶 = 在 `storage_sources` 里插一条 `driver='s3'` 的记录，`config` 为 `{endpoint, region, bucket, access_key, secret_key, prefix, [session_token]}`。
- 判断「已绑定」沿用现有做法：取出该用户所有 s3 源，比较 `config.bucket`。
- 已绑定的桶在对象页 **只读**：允许列对象、HEAD、下载、看设置；禁止上传、复制、删除、建文件夹、解冻、清空桶。服务端在每个写接口统一守卫，不能只靠前端隐藏按钮。

## 5. 页面与交互

### 5.1 导航与页面结构

- `app/lib/nav.php` 增加：`['id' => 'objects', 'label' => '对象存储', 'icon' => 'database', 'href' => url('objects.index')]`，排在「存储源」前。
- 新页面 `app/views/objects.php`，沿用 `.spine` + `.stage` + `.page` 骨架。
- 页面分三级，全部在同一页内由 JS 切换，不做多路由：

```
页头：对象存储                     [账号选择] [刷新] [新建桶]
────────────────────────────────────────────────────────
桶卡片网格（点击进入）
  桶名 / 地域 / 绑定状态 / 创建时间
────────────────────────────────────────────────────────
桶详情
  ← 返回全部桶    桶名 · 地域 · 已绑定存储源
  [对象] [设置]
  对象页：面包屑 / 搜索 / 上传 / 新建文件夹 / 多选操作
  设置页：版本控制 / 静态网站 / CORS / 生命周期 / 标签 / ACL / 策略
```

- 地址栏保留深链接：`?r=objects.index&account=<id>&bucket=<name>&prefix=<prefix>`。刷新后回到同一个桶和前缀；桶名只在 URL 中出现，不携带密钥。
- 旧 `sources` 页的桶面板删除，替换为一条入口：指向对象存储页。`sources` 页继续负责本地/FTP/SFTP 源与副本推送。

### 5.2 账号管理

- 页头账号下拉旁提供「添加账号 / 管理账号」。
- 账号表单字段：名称、服务商（腾讯云 COS / 通用 S3）、Endpoint、地域、Access Key、Secret Key、临时令牌（可选）。
- 选「腾讯云 COS」时：
  - 地域下拉：`ap-guangzhou`、`ap-shanghai`、`ap-beijing`、`ap-chengdu`、`ap-chongqing`、`ap-nanjing`、`ap-hongkong`、`ap-singapore`、`ap-bangkok`、`ap-tokyo`、`ap-seoul`、`ap-mumbai`、`na-siliconvalley`、`na-ashburn`、`na-toronto`、`eu-frankfurt`、`eu-moscow`、`sa-saopaulo`。
  - Endpoint 自动填 `cos.<region>.myqcloud.com`，允许手改。
- 保存时做基本校验：endpoint 只接受 `http` / `https`，且必须能解析出 host；region 非空；access_key / secret_key 非空。
- 新建账号保存后自动试列一次桶，成功提示桶数量、失败提示错误但不回滚已保存的账号（便于先存后改）。
- 已有账号提供独立「测试连接」按钮，调用 `s3_list_buckets` 并更新状态提示。
- 删除账号不影响已绑定的存储源——绑定是复制一份凭据，不是引用。
- 编辑账号时密钥留空或保持掩码 = 不修改；只回传 `secret_key: "••••••"` 不会被当成新值写回。

### 5.3 桶概览

- 卡片显示：桶名、地域、创建时间、绑定状态（未绑定 / 已绑定「存储源名称」）。
- 不在列表阶段逐桶 HEAD，避免几百个桶打出几百个请求。访问失败在进入桶详情时再报。
- 列桶失败（常见于子账号缺少 `ListBuckets`）时，提供一个「直接输入桶名」入口；只要密钥对该桶有权限，浏览、上传、下载都能照常使用。
- 卡片点击进入详情；绑定为存储源、清空桶、删除桶放在详情页头部（未绑定的桶才显示写操作）。
- 「新建桶」弹窗输入桶名：
  - 通用校验：3–63 位，小写字母/数字/`-`/`.`，首尾为字母或数字。
  - COS 账号额外校验 `^[a-z0-9][a-z0-9-]*-\d{10}$`；缺 APPID 时提示「COS 桶名必须带 10 位 APPID 后缀，例如 photos-1250000000」。
- 「删除桶」：必须原样输入桶名；只允许空桶。已绑定的桶服务端直接拒绝。

### 5.4 对象浏览

- 列表默认 `delimiter=/`，只显示当前前缀下的直接子项：
  - `CommonPrefixes` → 文件夹行，点击进入。
  - `Contents` → 文件行。
  - 以 `/` 结尾的 0 字节对象显示为文件夹（COS 控制台习惯），但删除时按普通对象处理。
- 每页 100 个键。`NextContinuationToken` 存在时显示「下一页」；前端维护 token 栈实现「上一页」。
- 桶详情头部提供「统计」按钮：按需扫描对象数与总用量，最多 20 次列表请求（约 2000 个对象）或 20 秒，先到先停；结果标注「按需统计，非实时」，超限时显示「已扫描前 N 个」。
- 面包屑从桶根到当前前缀，每段可点。
- 排序：名称 / 大小 / 更新时间，仅对当前页排序，界面注明「当前页」。
- 搜索：输入关键字后以递归模式请求（`delimiter` 为空，`prefix = 当前前缀 + 关键字`），最多 200 条，显示「已截断」。
- 多选：表头全选当前页；底部出现操作条（选中数量、合计大小、批量删除）。
- 行操作：下载、复制、重命名/移动、详情、删除。
- 已绑定桶：隐藏全部写操作，页面顶部显示说明条。

### 5.5 上传

浏览器直传，凭据不出服务端：

- 单个文件 ≤ 5 GiB：服务端签一个 PUT URL，浏览器 `fetch(url, {method:'PUT', body:file, headers})`。
- 文件 > 64 MiB：走 Multipart。
  - 服务端 `CreateMultipartUpload` 拿 `UploadId`。
  - 分片默认 8 MiB；文件很大时按 `ceil(size / 10000)` 向上取整到 MiB 上调，保证分片数不超过 S3/COS 的 10000 上限。并发 3，每一片按需向服务端要一个预签名 PUT URL（`partNumber` + `uploadId`）。
  - 浏览器从响应头读 `ETag`，全部完成后把 `[{partNumber, etag}]` 交给服务端 `CompleteMultipartUpload`。
  - 任一分片失败重试 3 次；用户取消或最终失败时调用 `AbortMultipartUpload`。
- 上传前服务端读一次桶 CORS：
  - 若没有规则放行本站源（`base_url` 或当前 `scheme://host`），返回 `cors_blocked`，前端显示横幅与「一键允许本站」按钮。
  - 一键配置会保留现有规则，只追加/修正本站源与 `PUT, GET, HEAD`、`ExposeHeader: ETag`。
- **CSP 必须先放行对象存储域名**：页面默认 `connect-src 'self'`，浏览器直传会被 CSP 拦在请求发出之前（不是 CORS 报错）。`objects.index` 渲染前重新下发 CSP，把当前用户所有账号的 endpoint 主机加入 `connect-src`，形如 `https://cos.ap-guangzhou.myqcloud.com` 与 `https://*.cos.ap-guangzhou.myqcloud.com`（后者匹配虚拟主机风格的桶名子域）。下载用浏览器导航，不受 `connect-src` 限制。
- 可选：目标前缀（默认当前前缀）、存储类型（默认标准）。
- 空文件允许上传（0 字节 PUT）。
- 同名对象直接覆盖（S3 语义）。界面上传前不额外 HEAD；覆盖行为在提示里说明。
- 上传进度：单文件用 `XMLHttpRequest.upload.onprogress`；分片按完成片数计算总进度。

### 5.6 下载

- 服务端签 300 秒有效的 GET URL，查询串带 `response-content-disposition=attachment; filename*=UTF-8''…`。
- 浏览器打开该 URL；不在前端暴露密钥。
- 归档 / 深度归档对象：点击下载先弹提示，提供「解冻」动作（天数默认 1，`Tier=Standard`），解冻后提示稍后重试。
- 已绑定桶允许下载（只读）。

### 5.7 复制 / 重命名 / 移动

- 服务端 `CopyObject`（`x-amz-copy-source`），支持 ≤5 GiB 的对象。
- 重命名 = 同前缀下改最后一段；移动 = 改完整目标键。
- 移动实现为「复制成功后再删源」；复制失败不删源。
- 目标键已存在时前端二次确认覆盖。
- >5 GiB 返回明确错误（Multipart Copy 不在本期）。

### 5.8 新建文件夹

- 创建以 `/` 结尾的 0 字节对象，例如 `photos/2026/`。
- 名称校验：非空、≤255 字节、不含控制字符；允许中文与空格。

### 5.9 桶设置

每个设置项独立请求、独立报错。缺少权限时只影响该卡片。

| 设置 | 读取 | 写入 |
|---|---|---|
| 版本控制 | `GET ?versioning`，显示 启用 / 暂停 / 从未启用 | 启用 / 暂停 |
| 静态网站 | `GET ?website` | 索引文档、错误文档、启用/停用（启用 = PUT 配置，停用 = DELETE 配置） |
| CORS | `GET ?cors`，结构化规则表 | 增删改规则、一键允许本站 |
| 生命周期 | `GET ?lifecycle`，显示现有 XML | 常用规则生成器 + 高级 XML 编辑；保存前校验 XML |
| 标签 | `GET ?tagging`，键值对 | 键值对编辑 |
| ACL | `GET ?acl`，解析授权列表 | canned ACL：`private` / `public-read`（写入用 `x-amz-acl` 头） |
| 策略 | `GET ?policy`，只读 JSON | 不做 |

生命周期表单支持生成以下规则：

- 指定前缀，N 天后转为低频 / 智能分层 / 归档 / 深度归档
- 指定前缀，N 天后过期删除
- 清理 N 天前未完成的分片上传

高级模式直接编辑 `<LifecycleConfiguration>` XML。保存前服务端校验：XML 可解析、根节点正确、规则数 ≤1000。保存会整体替换现有配置，界面必须写明这一点。

ACL 设为 `public-read` 前弹出强提示：对象可被任何人读取。策略页只读，显示「未设置策略」或格式化后的 JSON。

设置项的 `section` 取值与写入字段：

| section | 读取返回 | 写入 payload |
|---|---|---|
| `versioning` | `{status: 'Enabled'\|'Suspended'\|''}` | `{status: 'Enabled'\|'Suspended'}` |
| `website` | `{enabled, index, error}` | `{enabled, index, error}` |
| `cors` | `{rules: CORS规则[]}` | `{rules: CORS规则[]}` |
| `lifecycle` | `{xml, rules: 常用规则[]}` | `{mode: 'rules'\|'xml', rules?, xml?}` |
| `tagging` | `{tags: [{key, value}]}` | `{tags: [{key, value}]}` |
| `acl` | `{canned, grants: [{type, name, permission}]}` | `{canned: 'private'\|'public-read'}` |
| `policy` | `{json, raw}` | 只读，不接受写入 |

`CORS规则` 结构：

```php
['id' => '', 'origins' => ['https://example.com'], 'methods' => ['GET','PUT','HEAD'],
 'headers' => ['*'], 'expose' => ['ETag'], 'max_age' => 3600]
```

- `origins`、`methods` 至少各一项。
- 方法只允许 `GET` / `PUT` / `POST` / `DELETE` / `HEAD`。
- `expose` 含 `ETag` 时分片上传才能在浏览器读到 ETag。

### 5.10 危险操作

- 清空桶：原样输入桶名；每轮最多删 1000 个对象，删完探测是否还有剩余，提示可再点一次。
- 删除桶：原样输入桶名；只允许空桶；已绑定桶拒绝。
- 批量删除对象：二次确认，显示数量；超过 1000 个分批，逐批报告成功/失败数。

## 6. 协议层扩展

全部加在 `app/lib/storage_drivers.php`，保持「纯函数 + 抛 RuntimeException」的风格。

### 6.1 `s3_request`

- 新增 `opts['headers']`：额外请求头，全部加入 SigV4 的 signed headers。
- 需要用它发送：
  - `x-amz-copy-source`（复制）
  - `x-amz-metadata-directive: COPY`（复制时保留元数据）
  - `x-amz-acl`（canned ACL）
  - `x-amz-storage-class`（上传/复制）
  - `x-amz-restore`（解冻）
- `opts['headers']` 不得覆盖保留头：`host`、`x-amz-date`、`x-amz-content-sha256`、`x-amz-security-token`、`authorization`。实现顺序上先合并调用方头，再写入这些保留头，保留头始终以函数计算结果为准。
- 现有 `content_type` / `content_md5` 保留，不迁移调用方。

### 6.2 `s3_presign`

- `$config['session_token']` 非空时，把 `X-Amz-Security-Token` 加入查询串并参与签名。
- 允许 `$query` 传 `response-content-disposition` / `response-content-type`；它们已经会进入 canonical query，无需额外逻辑。
- 新增 `s3_presign_url($config, $method, $key, $ttl, $query = [])` 的统一出口（现有 `s3_presign` 直接复用）。
- TTL 在调用方钳制到 `[60, 3600]`。

### 6.3 ListObjectsV2

新增 `s3_list_objects_page(array $config, string $bucket, array $opts): array`：

```php
$opts = [
    'prefix'             => '',
    'delimiter'          => '/',
    'continuation_token' => '',
    'start_after'        => '',
    'max_keys'           => 100,
];
```

返回：

```php
[
    'folders'    => [['prefix' => 'photos/2026/', 'name' => '2026']],
    'objects'    => [[
        'key' => 'photos/a.jpg', 'name' => 'a.jpg', 'size' => 123,
        'etag' => '"..."', 'modified' => '2026-...Z', 'storage_class' => 'STANDARD',
    ]],
    'truncated'  => true,
    'next_token' => '...',
    'key_count'  => 100,
]
```

- 用 DOMDocument 解析，`LIBXML_NONET`，与现有解析器一致。
- 文件夹名取 `prefix` 去掉当前前缀与末尾 `/`。
- 0 字节且以 `/` 结尾的对象归入 objects（前端按文件夹渲染），避免与真实 CommonPrefixes 混淆。

### 6.4 对象级操作

- `s3_head_object($config, $bucket, $key): ?array`：返回 `size`、`content_type`、`etag`、`last_modified`、`storage_class`、`restore`（`x-amz-restore` 响应头）；404 返回 null。
- `s3_copy_object($config, $bucket, $source, $target, ?string $storageClass = null): array`：`PUT` 目标键，带 `x-amz-copy-source`。
- `s3_restore_object($config, $bucket, $key, int $days, string $tier = 'Standard'): array`：`POST ?restore`。`Tier` 不能放在顶层：COS 用 `<RestoreRequest><Days>…</Days><CASJobParameters><Tier>…</Tier></CASJobParameters></RestoreRequest>`，通用 S3 用 `<GlacierJobParameters>`。按 `$config['provider']`（`cos` / 其他）选择外层节点。
- 对象键编码：
  - 请求路径沿用现有 `rawurlencode` + `%2F` 还原。
  - `x-amz-copy-source` 的值：`/` . 桶名 . `/` . 按路径段编码的键。
  - 键长度上限 1024 字节，拒绝空键、含 NUL 的键。

### 6.5 子资源读写

新增通用封装，避免每个设置写一套签名逻辑：

```php
s3_get_subresource(array $config, string $bucket, string $sub, array $query = []): array
s3_put_subresource(array $config, string $bucket, string $sub, string $xml, array $headers = []): array
s3_delete_subresource(array $config, string $bucket, string $sub): array
```

- 子资源作为 query 键，值为空字符串，例如 `['versioning' => '']`。
- PUT 一律 `content-type: application/xml`，payload hash 按 body 计算。
- 调用方决定 XML 内容；本层不做业务校验（校验在各 `objects_*` 模块函数里）。
- 特殊项：ACL 写入走 `x-amz-acl` 头 + `?acl`，不需要 XML。
- 策略读取：`GET ?policy` 返回 JSON，不走 XML 解析。

### 6.6 Multipart

- `s3_multipart_init($config, $bucket, $key, ?string $storageClass): string`：`POST ?uploads`，解析 `<UploadId>`。
- `s3_multipart_part_url($config, $bucket, $key, string $uploadId, int $partNumber, int $ttl): string`：预签名 PUT，查询串含 `partNumber` 与 `uploadId`。
- `s3_multipart_complete($config, $bucket, $key, string $uploadId, array $parts): array`：`POST ?uploadId=…`，body 为 `<CompleteMultipartUpload>`，每项 `<Part><PartNumber>…</PartNumber><ETag>…</ETag></Part>`。
- `s3_multipart_abort($config, $bucket, $key, string $uploadId): void`：`DELETE ?uploadId=…`。
- 分片大小与阈值由模块层决定（8 MiB / 64 MiB），协议层只接收参数。

### 6.7 错误翻译

在 `s3_error_detail()` / `s3_error_message()` 里补充：

| 代码 | 文案 |
|---|---|
| `SignatureDoesNotMatch` | 签名不匹配：检查 Secret Key 与地域是否一致 |
| `InvalidAccessKeyId` | Access Key 无效或已停用 |
| `NoSuchBucket` | 桶不存在 |
| `AccessDenied` | 当前密钥无权执行该操作 |
| `EntityTooLarge` | 对象超过单次上传上限 |
| `InvalidStorageClass` | 该存储类型在此服务商不可用 |
| `CORSNotAllowed` | 桶的 CORS 未放行本站 |
| `NoSuchCORSConfiguration` | 桶未配置 CORS |
| `NoSuchLifecycleConfiguration` | 桶未配置生命周期 |
| `NoSuchBucketPolicy` | 桶未设置策略 |

## 7. 路由与接口清单

新模块 `app/modules/objects.php`。所有接口除页面本身外都要求登录；写操作要求 CSRF（`require_csrf()`），读接口用 `require_auth_api()`。

| 路由 | 方法 | 入参 | 说明 |
|---|---|---|---|
| `objects.index` | GET | `account?`, `bucket?`, `prefix?` | 渲染页面 |
| `objects.accounts` | GET | — | 账号列表（密钥掩码） |
| `objects.account_save` | POST | `id?`, `name`, `provider`, `endpoint`, `region`, `access_key`, `secret_key?`, `session_token?` | 新建/更新 |
| `objects.account_delete` | POST | `id` | 删除账号（不影响存储源） |
| `objects.account_test` | POST | `id` | 试列桶 |
| `objects.buckets` | GET | `account` | 桶列表 + 绑定状态 |
| `objects.bucket_create` | POST | `account`, `bucket` | 建桶 |
| `objects.bucket_delete` | POST | `account`, `bucket`, `confirm` | 删空桶 |
| `objects.bucket_purge` | POST | `account`, `bucket`, `confirm` | 清空桶（每轮 ≤1000） |
| `objects.bucket_bind` | POST | `account`, `bucket`, `name?`, `prefix?` | 绑定为存储源 |
| `objects.list` | GET | `account`, `bucket`, `prefix`, `delimiter`, `cursor`, `max_keys`, `q?` | 列对象（`max_keys` 服务端钳到 1000） |
| `objects.stat` | POST | `account`, `bucket` | 扫描统计（20 次列表 / 20 秒上限） |
| `objects.head` | POST | `account`, `bucket`, `key` | 对象元数据 |
| `objects.upload_sign` | POST | `account`, `bucket`, `key`, `content_type`, `storage_class` | 单次 PUT 预签名 |
| `objects.upload_init` | POST | `account`, `bucket`, `key`, `size`, `content_type`, `storage_class` | 初始化分片（`size` 用来计算分片大小） |
| `objects.upload_part` | POST | `account`, `bucket`, `key`, `upload_id`, `part_number`, `ttl?` | 分片预签名 |
| `objects.upload_complete` | POST | `account`, `bucket`, `key`, `upload_id`, `parts[]` | 完成分片 |
| `objects.upload_abort` | POST | `account`, `bucket`, `key`, `upload_id` | 放弃分片 |
| `objects.download_sign` | POST | `account`, `bucket`, `key`, `ttl?` | 下载预签名 |
| `objects.copy` | POST | `account`, `bucket`, `source`, `target`, `mode` (`copy|move`), `storage_class?` | 复制/移动 |
| `objects.delete` | POST | `account`, `bucket`, `keys[]` | 批量删除（≤1000/批） |
| `objects.mkdir` | POST | `account`, `bucket`, `prefix`, `name` | 新建文件夹 |
| `objects.restore` | POST | `account`, `bucket`, `key`, `days`, `tier?` | 解冻 |
| `objects.settings` | GET | `account`, `bucket`, `section` | 读取一项设置 |
| `objects.setting_save` | POST | `account`, `bucket`, `section`, `payload` | 保存一项设置 |
| `objects.cors_allow` | POST | `account`, `bucket` | 一键允许本站源 |
| `objects.cors_check` | GET | `account`, `bucket` | 上传前 CORS 预检 |

所有响应沿用 `json_ok` / `json_fail`。错误码补充：

- `no_account`：没有可用账号
- `in_use`：桶已绑定存储源
- `cors_blocked`：CORS 未放行
- `restore_required`：归档对象需先解冻
- `too_large`：超过单次操作上限

## 8. 安全

- **凭据隔离**：账号按 `user_id` 查询；所有接口在解析账号时同时匹配 `id` 与 `user_id`，越权返回 404。
- **密钥不回传**：列表/详情只回 `secret_key: "••••••"`；更新时掩码值跳过；`session_token` 同样掩码。
- **签名全在服务端**：浏览器只拿预签名 URL，URL 有 TTL，不含密钥。
- **TTL 钳制**：预签名 `[60, 3600]`；下载默认 300。
- **已绑定桶只读**：所有写接口先查绑定关系，命中即 409 `in_use`。前端隐藏按钮只是体验，不是防线。
- **破坏性操作**：删桶/清空桶必须原样输入桶名；批量删除显示数量并二次确认。
- **键名转义**：列表渲染全部经 `escapeHtml`；`data-*` 属性与 JSON 内嵌同样转义；不使用 `innerHTML` 拼接未转义键名。
- **XML 注入**：所有写进 XML 的用户输入经 `htmlspecialchars(..., ENT_QUOTES | ENT_XML1)`；XML 解析用 `LIBXML_NONET`，不加载外部实体。
- **设置体积**：`objects.setting_save` 的 XML 上限 256 KB，超过直接拒绝，避免用超大生命周期配置拖垮解析。
- **内网 endpoint**：继续用 `source_risk_note()` 给出告警但不硬拦——现有 README 支持局域网 MinIO，硬拦会打掉正经用法。告警只影响展示，不阻断保存。
- **活动日志**：记录账号名、桶名、对象键前 120 字，不记录密钥与预签名 URL。
- **CSP 放行面**：只把当前用户自己配置的 endpoint 主机加入 `connect-src`，不放成 `https:` 全域；没有对象账号时页面维持 `'self'`。

## 9. 错误处理

- **列表/操作失败**：`json_fail(message, 502, 's3_error')`，前端用 `toast` 或卡片内错误条展示。
- **设置项独立降级**：某项 `AccessDenied` 只让该卡片显示「当前密钥无权读取」，其他卡片照常。
- **上传**：单次 PUT 失败重试 2 次；分片失败重试 3 次；最终失败调用 abort，并提示已完成/失败分片数。
- **CORS 预检失败**：返回 `cors_blocked` + 本站源，前端展示一键配置；不静默回退服务器中转。
- **部分成功**：批量删除按批返回 `{deleted, failed, errors[]}`；清空桶返回 `remaining`，提示可再点一次。
- **网络超时**：`s3_request` 默认 20 秒；列表/设置用 20 秒；Multipart 的 Complete 放宽到 60 秒。

## 10. 测试

### 10.1 存根扩展（`tests/stub_s3.php`）

按请求方法 + 查询串 + 路径返回固定形状，并记录请求。新增形状一律按桶名或路径分支，**不改动 `objbucket` / `purgebucket` / `full-bucket` / `empty-bucket` 的现有响应**，确保 `t16_bucket.php` 继续通过；桶级 PUT（路径为 `/`）与对象 PUT（路径非 `/`）也必须分开处理。

- `GET list-type=2`：`photos-1250000000` 桶返回一层文件夹 `2026/`、文件 `a.jpg` / `notes.txt`；支持 `prefix`、`delimiter`、`continuation-token`，第二页返回 `NextContinuationToken`。
- `GET ?versioning` / `?cors` / `?lifecycle` / `?website` / `?tagging` / `?acl` / `?policy`：返回对应 XML/JSON；未配置的子资源返回 404 + 对应错误码。
- `PUT` 子资源：记录 query、body、headers，返回 200。
- `PUT` 对象（含 `file`）：记录 `Content-SHA256` 与长度，返回 200 + `ETag`。
- `POST ?uploads`：返回 `<UploadId>stub-upload-1</UploadId>`。
- `PUT ?partNumber=…&uploadId=…`：返回 200 + `ETag: "part-N"`。
- `POST ?uploadId=…`：返回 `<CompleteMultipartUploadResult>`。
- `DELETE ?uploadId=…`：204。
- `PUT` + `x-amz-copy-source`：返回 `<CopyObjectResult>`。
- `HEAD` 对象：返回 `Content-Length`、`Content-Type`、`x-amz-storage-class`；`missing.txt` 返回 404。
- `POST ?delete`：返回 `<Deleted>` 列表。
- `POST ?restore`：202。

### 10.2 新测试 `tests/t23_objects.php`

用 `php -S` 拉起存根，断言：

- 账号 CRUD：A 用户的账号不能被 B 用户读取/使用。
- COS 桶名校验：缺 APPID 拒绝；合法名通过。
- `s3_request` 的额外签名头出现在 `SignedHeaders` 与 `Authorization` 里。
- `s3_presign` 在带 `session_token` 时查询串含 `X-Amz-Security-Token`。
- ListObjectsV2：文件夹/文件/`next_token` 解析正确，`prefix` 与 `delimiter` 出现在请求里。
- 复制：`x-amz-copy-source` 的键名编码正确（空格、中文、`+`）。
- 批量删除：键名含 `<`、`&`、引号时 XML 正确转义。
- 已绑定桶：上传/复制/删除/建文件夹/清空都被拒绝（`in_use`）。
- 设置序列化：版本控制、CORS、生命周期、标签 XML 形状正确；非法 XML 被拒。
- TTL 钳制：传 10 秒被抬到 60，传 7200 被压到 3600。
- 键名校验：空键、超 1024 字节、含 NUL 被拒。
- CSP 来源：`object_account_origins()` 对 `https://cos.ap-guangzhou.myqcloud.com` 同时给出精确与通配子域两条来源；无账号时返回空。
- 回归：`t1…t22` 全部通过，尤其 `t16_bucket.php` 的桶级/对象级请求形状不受存根扩展影响。

运行方式：`runuser -u www -- php tests/t23_objects.php`。

### 10.3 真实 COS 验收清单（用户执行）

1. 添加一个只授予测试桶权限的子账号密钥，测试连接成功。
2. 建桶 `shiyun-test-<appid>`，出现在列表。
3. 上传一个小文件、一个大文件（>64 MiB），都能完成。
4. 列对象能看到两者；进入文件夹、返回上级正常。
5. 预签名下载能打开；`curl -I` 返回 200。
6. 复制、重命名、移动、批量删除都生效。
7. 版本控制启用后状态变为「已启用」；暂停后变为「已暂停」。
8. CORS 一键配置后，上传不再提示跨域。
9. 绑定为存储源后，对象页对该桶只读。
10. 归档对象下载前提示解冻，解冻后稍后可下载。

## 11. 实施分期

- **第 1 期：地基**——`object_accounts` 表、协议层扩展、账号 CRUD、页面骨架、桶列表/建/删/清空/绑定。
- **第 2 期：对象**——ListObjectsV2 浏览、搜索、翻页、上传（单次 + 分片）、下载、复制/移动、批量删除、文件夹、详情。
- **第 3 期：设置**——版本控制、静态网站、CORS、生命周期、标签、ACL、策略只读、危险操作。
- **第 4 期：收尾**——存根与 `t23`、删除旧桶面板、README、真实 COS 验收。

每期结束都应能独立运行并通过既有 `t1…t22` 与新增 `t23`。

## 12. 改动清单

新增：

- `app/lib/object_store.php`：账号、绑定守卫、键名规则、CSP 来源
- `app/lib/object_settings.php`：桶设置 XML 的读写与校验
- `app/modules/objects.php`：路由处理器
- `app/views/objects.php`：页面骨架
- `assets/js/objects.js`：页面交互
- `tests/t23_objects.php`

修改：

- `app/schema.php`：`object_accounts` 表，`SCHEMA_VERSION` 18
- `app/bootstrap.php`：CSP 拼接抽成可追加 `connect-src` 的函数
- `app/lib/storage_drivers.php`：签名头、预签名 STS、ListObjectsV2、对象操作、子资源、Multipart、错误翻译
- `app/lib/storage_drivers.php`：签名头、预签名 STS、ListObjectsV2、对象操作、子资源、Multipart、错误翻译
- `app/lib/nav.php`：新增导航项
- `app/modules/sources.php`：删除 `sources_bucket_*` 相关函数
- `app/views/sources.php`：删除旧桶面板，加对象存储入口
- `assets/js/console.js`：删除桶面板逻辑
- `assets/css/console.css`：对象页样式（复用现有变量与组件）
- `tests/stub_s3.php`：新协议形状
- `README.md`：对象存储页与 COS 配置说明

不改：

- `nodes` / `blobs` / `blob_locations` / 下载链路——这是与「桶挂载」规格的分界线。
