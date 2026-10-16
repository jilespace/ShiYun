# 拾云 · SHIYUN

自托管的个人网盘。上传、整理、预览、分享、挂载，一套 PHP 跑完——**没有构建步骤，没有 Composer 依赖，不需要 Node**。

面向「一台自己的服务器 + 自己的文件」这个场景：把散落的文件收进同一个地方，按自己的规则管理，需要时再发一个链接出去。

---

## 功能

**文件管理**
- 列表 / 网格两种视图，按名称、时间、大小、类型排序
- 拖拽上传、整目录上传；大文件自动分片续传
- 内容寻址存储（SHA-256）：同一份内容只落一份盘，重复文件秒传
- 新建、重命名、移动、复制、批量操作、回收站（保留期可配）
- 收藏、标签、颜色标记、备注、隐藏、锁定（防误删误改）
- 版本历史：可列出、下载、还原、删除历史版本

**预览**
- 图片、视频、音频、PDF、文本 / 代码、Markdown
- Office 文档（`.docx` / `.xlsx` / `.pptx`）服务端解析后在浏览器内渲染，不依赖外部服务

**分享**
- 生成分享链接，可设提取码、有效期、权限（仅预览 / 可下载 / 可上传）
- 访问记录：谁在什么时候看过、下载过

**存储源**
- 内置本地仓 + 可挂载 S3 兼容对象存储（腾讯云 COS、阿里云 OSS、MinIO 等）
- 上传时按文件选择落到哪个存储源；重要文件可把副本推送到外部存储做冗余
- 对象存储直链：预签名 URL 直连，不占本机带宽

**其它**
- 离线下载：HTTP / FTP 直链进队列；装了 aria2 还能下 BT / 磁力
- 在线解压 / 压缩（zip 等）
- 自动化规则：按扩展名、文件名、类型、大小命中，自动打标签、上色、移动、隐藏、锁定、写备注
- 全文搜索：文件名用 LIKE，文本内容走 MySQL ngram 全文索引
- 统计：容量趋势、类型分布、上下行流量
- 私密空间：独立密码，锁定后不出现在列表、搜索与 WebDAV 中
- 登录日志 / 操作日志
- REST API、WebDAV 挂载、RSS 订阅（用 API 令牌访问）

---

## 环境要求

| | |
|---|---|
| PHP | **≥ 8.1**（用到 `never` 返回类型与 `match`） |
| PHP 扩展 | `pdo_mysql`、`curl`、`gd`、`zip`、`mbstring`、`json`、`openssl` |
| MySQL | **5.7+ / 8.0**（用到 JSON 列与 ngram 全文索引） |
| Web 服务器 | Nginx 或 Apache |

开发与验证环境：PHP 8.5 + MySQL 8.0 + Nginx。

---

## 安装

1. **建库**。建一个空数据库，字符集用 `utf8mb4`：

   ```sql
   CREATE DATABASE shiyun DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **写配置**。把模板复制成正式配置，填上数据库账号密码：

   ```bash
   cp app/config.example.php app/config.php
   ```

   `app/config.php` 含数据库密码与计划任务密钥，已被 `.gitignore` 排除，**不要提交**。

3. **指向站点**。把 Web 服务器的站点根目录指到项目根（本项目不需要 `public/` 子目录，入口就是根下的 `index.php`）。

4. **打开站点**。首次访问会显示「创建第一个账号」——数据库表结构会自动建好，不用手动导入 SQL。

   > 建出第一个账号后，自助注册会自动关闭。之后想再加账号，把 `app/config.php` 里的
   > `allow_registration` 临时改成 `true`，加完再改回来。

---

## 配置

全部配置项在 `app/config.php`（对照 `app/config.example.php` 的注释）。常用的几个：

| 配置项 | 默认 | 说明 |
|---|---|---|
| `db.*` | — | 数据库连接。**必填** |
| `cron_key` | `''` | 计划任务密钥，随便一串随机 hex。**建议填** |
| `allow_registration` | `false` | 是否开放自助注册。个人自用保持 `false` |
| `default_quota` | 1 TiB | 新用户默认配额 |
| `max_upload` | 200 GiB | 单文件上限 |
| `chunk_size` | 5 MiB | 分片大小。每片是一次独立请求，所以单文件上限不受 `post_max_size` 约束；但 `post_max_size` / `upload_max_filesize` 必须大于本值，否则每片都会被拒 |
| `trash_retention` | 30 | 回收站保留天数 |
| `max_login_attempts` / `lockout_seconds` | 5 / 900 | 登录失败几次后锁定多久 |
| `reset_link_in_response` | `false` | 找回密码是否把重置链接直接回显在页面。**仅单机自用可开**，多用户环境开着等于任何人凭邮箱即可接管账号 |
| `presign_ttl` | 7200 | 对象存储直链有效期。媒体拖动进度条会连续发 Range 请求，别设太短 |
| `aria2_rpc` / `aria2_secret` | `''` | 可选。填了才能下 BT / 磁力 |
| `timezone` | `Asia/Shanghai` | PHP 与 MySQL 会话统一时区 |

---

## Web 服务器

`runtime/` 下是用户文件、会话、上传分片——**必须禁止 Web 直接访问**，否则任何人可以绕过鉴权拿到原文件。目录里已带 `.htaccess` 与 `index.php` 两层兜底，但 Nginx 不吃 `.htaccess`，需要显式配置：

```nginx
location ^~ /runtime/ {
    deny all;
    return 404;
}
```

Apache 下 `.htaccess` 已覆盖，确认 `AllowOverride` 没有关掉即可。

---

## 计划任务

传输队列默认只在页面加载时分段推进。想让它在没人开页面时也继续跑（离线下载、推送到对象存储、清理过期分片等），加一条每分钟执行的任务：

```
https://你的域名/index.php?r=tasks.cron&key=<config 里的 cron_key>
```

宝塔面板：「计划任务」→ 类型选「访问 URL」→ 每分钟。地址在该页面的「传输」页里可以直接复制。

---

## 外部接口

在「设置 → API 令牌」里生成令牌后，可以：

- **REST API** — `index.php?r=api.handle&path=list` 等，用 `Authorization: Bearer <令牌>` 或 `?token=` 认证。该页面列出了全部路径
- **WebDAV** — 挂载地址 `index.php?r=webdav.handle`，用户名填邮箱或用户名，密码填网盘密码或 API 令牌
- **RSS** — `index.php?r=rss.feed&token=<令牌>`，加 `&kind=activity` 可订阅操作动态

---

## 测试

`tests/` 下是自带的功能测试，直接跑 PHP 文件即可，无需 PHPUnit：

```bash
# 必须以 Web 服务的运行身份执行（宝塔/nginx 通常是 www），
# 用 root 跑会在 runtime/storage 里留下 root 属主的目录，
# 之后 www 写不进去，表现为「无法创建存储目录」。
runuser -u www -- php tests/t1_schema.php
```

一次跑完所有：

```bash
for t in tests/t*.php; do runuser -u www -- php "$t" || echo "失败: $t"; done
```

---

## 目录结构

```
app/
  bootstrap.php      初始化：配置、错误处理、安全响应头
  config.php         本地配置（含凭据，不入库）
  config.example.php 配置模板
  schema.php         表结构定义 + SCHEMA_VERSION（改结构就加一，下次请求自动迁移）
  db.php             PDO 单例与迁移
  helpers.php        视图与通用工具
  icons.php          Lucide 图标（内联 SVG，无运行时依赖）
  auth.php           Auth 类与登录限流
  lib/               领域逻辑：存储、分享、搜索、规则、活动日志……
  modules/           路由处理：一个文件对应一组页面/接口
  views/             模板
assets/
  css/ js/           样式与前端脚本，无构建步骤，改完刷新即可
runtime/             运行时数据（用户文件、会话、分片、缩略图、日志）——不入库，禁止 Web 访问
tests/               功能测试
docs/                设计与实施文档
```

路由是查询串形式：`index.php?r=模块.动作` → `app/modules/模块.php` 里的 `模块_动作()`。未登录可访问的地址列在 `app/router.php` 的 `PUBLIC_ROUTES` 中。

---

## 安全说明

已经做的：

- 密码 `password_hash()` 存储；登录失败计数 + 锁定，WebDAV 同样限流
- 所有 SQL 走预处理语句
- 输出统一 HTML 转义；用户文件名不会被当作 HTML 执行
- 响应头带 `X-Content-Type-Options`、`X-Frame-Options`、`Referrer-Policy`、`Permissions-Policy`，HTTPS 下带 HSTS
- 上传内容按 SHA-256 寻址，原文件名不参与落盘路径，避免路径穿越
- 私密空间锁定后，列表、搜索、下载、预览、API、WebDAV 全部拒绝
- 对外分享可设提取码与有效期

需要你自己把关的：

- **`app/config.php` 不要提交到版本库**（`.gitignore` 已排除，但别手动加进去）
- **`runtime/` 必须禁止 Web 访问**，见上面「Web 服务器」
- 生产环境请走 HTTPS
- 公网部署前确认 `allow_registration` 是 `false`、`reset_link_in_response` 是 `false`

---

## 许可

未指定。仓库目前没有 LICENSE 文件——在明确授权之前，默认保留所有权利。如果打算让别人用，建议补一个（MIT / Apache-2.0 都合适）。
