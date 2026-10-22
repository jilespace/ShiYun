# 免登录临时传输（双向房间）实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增公开的「临时传输」页面：双方不登录，创建/加入房间，凭链接或 6 位码互传文件；房间 24 小时/10 GB 上限，到期或创建者关闭即删。

**Architecture:** 独立于网盘节点与 blob 仓。新表 `transfer_rooms/files/uploads/attempts`；文件存 `runtime/transfer/<token>/`，分片写 `u_<upload_id>/`；领域逻辑在 `app/lib/transfer.php`，公开路由在 `app/modules/transfer.php`，页面/JS/CSS 独立；清理挂到 `tasks.cron` 并在建房时惰性执行。

**Tech Stack:** PHP 8.1+（无框架、无 Composer）、MySQL 8、原生 JS/CSS、现有分片上传/二维码/计划任务。不新增依赖。

**Spec:** `docs/superpowers/specs/2026-10-22-anonymous-transfer-design.md`

## Global Constraints

- 所有新增 PHP 文件：`declare(strict_types=1);` + `if (!defined('BASE_PATH')) { http_response_code(404); exit; }`。
- 公开路由必须加入 `app/router.php` 的 `PUBLIC_ROUTES`；写接口必须 `require_csrf()`（匿名用户也有会话与 CSRF token）。
- 文件只存 `runtime/transfer/`；物理名随机 32 位 hex；路径只由房间 token/上传 id 推导；删除时不跟随符号链接。
- 房间默认：24 小时、10 GiB、200 文件、分片 5 MiB、同时分片会话 20 个、单文件 ≤ 剩余容量。
- 建房每 IP 5 次/小时；试码/试密码每 IP 15 分钟内 10 次失败即锁定。
- 6 位码在全部房间行内唯一；创建冲突重试最多 10 次。
- 不修改 `nodes` / `blobs` / `blob_locations` / 网盘下载链路。
- 前端只用现有 Lucide 图标与主题 token（`--bg/--panel/--ink/--dim/--line/--signal`），不使用 emoji。
- 测试必须用 `runuser -u www -- php tests/...` 运行。
- **命名约束**：路由函数名 = `模块_动作`（如 `transfer_upload_init`），因此 lib 里的同名领域函数必须换名（本计划用 `transfer_upload_start` / `transfer_upload_finish` / `transfer_upload_cancel` / `transfer_file_remove`），避免 PHP 函数重定义。

## Review Focus

1. 匿名磁盘耗尽：超长分片、声明小 size 塞大请求体、并发上传绕过总容量、建房刷量、超时会话不释放预留。
2. 6 位码暴力枚举：连续错误是否被限速/锁定；密码错误是否同样计数。
3. 路径安全：文件名含 `../`、控制字符、超长；房间目录删除是否可能越出 `runtime/transfer/`。
4. 上传边界：0 字节文件、分片越界/重复/缺失、合并时实际字节超过声明、并发 complete。
5. 权限：创建者 key 是否服务端校验（不只是隐藏按钮）；密码房未解锁时文件列表/下载是否真的被拒。

---

### Task 1: 数据表、房间领域层与测试骨架

**Files:**
- Modify: `app/schema.php`
- Modify: `app/bootstrap.php`
- Create: `app/lib/transfer.php`
- Create: `tests/t24_transfer.php`

**Interfaces:**
- Consumes: `config()`, `q_one/q_all/q_insert/q_exec/q_val`, `db_tx`, `now()`, `client_ip()`, `safe_filename()`, `PDOException`.
- Produces:
  - `TransferRateLimitException`, `TransferCapacityException`
  - `transfer_enabled(): bool`
  - `transfer_root(): string`
  - `transfer_ttl_hours(): int`, `transfer_max_bytes(): int`, `transfer_max_files(): int`
  - `transfer_ip(): string`
  - `transfer_token(): string`, `transfer_code(): string`, `transfer_new_code(): string`
  - `transfer_creator_hash(string $key): string`, `transfer_verify_creator(array $room, string $key): bool`
  - `transfer_rate_count(string $kind, int $seconds): int`, `transfer_rate_add(string $kind, ?string $code = null): void`
  - `transfer_assert_can_create(): void`, `transfer_assert_can_guess(): void`
  - `transfer_room_create(string $password = ''): array`
  - `transfer_room_by_token(string $token): ?array`
  - `transfer_room_state(array $room): array`
  - `transfer_room_dir(string $token): string`
  - `transfer_file_path(array $room, array $file): string`
  - `transfer_upload_dir(string $token, string $uploadId): string`
  - `transfer_rmdir(string $dir): void`
  - `transfer_room_destroy(int $id): void`
  - `transfer_reap_rooms(): void`

- [ ] **Step 1: 写失败测试**

Create `tests/t24_transfer.php`:

```php
<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   免登录临时传输：房间、限速、上传容量、清理。
   测试使用独立临时目录，不碰 runtime/transfer。
   ========================================================================= */

$tmpRoot = sys_get_temp_dir() . '/shiyun-transfer-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0700, true);
$GLOBALS['config']['transfer_storage_path'] = $tmpRoot;
$GLOBALS['config']['transfer_create_per_hour'] = 1000;
$GLOBALS['config']['transfer_code_attempts'] = 10;
$GLOBALS['config']['transfer_max_bytes'] = 1024 * 1024;
$GLOBALS['config']['transfer_max_files'] = 200;
$GLOBALS['config']['chunk_size'] = 5 * 1024 * 1024;
$GLOBALS['config']['max_upload'] = 200 * 1024 ** 3;
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);

$created = transfer_room_create('');
$room = transfer_room_by_token((string) $created['token']);
check($room !== null, '建房后能按 token 找到房间');
check(strlen((string) $created['token']) === 48, 'token 是 48 位 hex');
check(preg_match('/^\d{6}$/', (string) $created['code']) === 1, '取件码是 6 位数字');
check(transfer_verify_creator($room ?? [], (string) $created['creator_key']), '创建者 key 校验通过');
check(!transfer_verify_creator($room ?? [], 'wrong-key'), '错误创建者 key 被拒');
check(strtotime((string) ($room['expires_at'] ?? '')) > time() + 23 * 3600, '有效期约 24 小时');
$firstRoomId = (int) $created['id'];

echo "\n· 取件码唯一\n";
$codes = [];
for ($i = 0; $i < 20; $i++) {
    $r = transfer_room_create('');
    $codes[] = (string) $r['code'];
    transfer_room_destroy((int) $r['id']);
}
check(count(array_unique($codes)) === 20, '连续建房取件码不重复');

echo "\n· 建房限速\n";
$GLOBALS['config']['transfer_create_per_hour'] = 2;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$a = transfer_room_create('');
$b = transfer_room_create('');
$threw = false;
try { transfer_room_create(''); } catch (TransferRateLimitException $e) { $threw = true; }
check($threw, '超过每小时建房上限被拒');
transfer_room_destroy((int) $a['id']);
transfer_room_destroy((int) $b['id']);
$GLOBALS['config']['transfer_create_per_hour'] = 1000;

echo "\n· 过期清理\n";
q_update('transfer_rooms', ['expires_at' => date('Y-m-d H:i:s', time() - 10)], 'id = ?', [$firstRoomId]);
check(transfer_room_by_token((string) $created['token']) === null, '过期房间按 token 找不到');
check(q_one('SELECT id FROM transfer_rooms WHERE id = ?', [$firstRoomId]) === null, '过期房间行被删除');
check(!is_dir($tmpRoot . '/' . $created['token']), '过期房间目录被删除');

echo "\n· 路径删除\n";
$dir = transfer_room_dir('a' . str_repeat('0', 47));
mkdir($dir . '/u_test', 0700, true);
file_put_contents($dir . '/u_test/0.part', 'x');
transfer_rmdir($dir);
check(!is_dir($dir), '房间目录递归删除');

q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
foreach ((array) glob($tmpRoot . '/*') as $p) {
    transfer_rmdir($p);
}
@rmdir($tmpRoot);
finish();
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: FAIL `Call to undefined function transfer_room_create()`。

- [ ] **Step 3: 建表与加载**

In `app/schema.php` change `const SCHEMA_VERSION = 18;` to `19`, then before `return $sql;` add:

```php
    $sql[] = <<<SQL
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
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS transfer_files (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            room_id       BIGINT UNSIGNED NOT NULL,
            name          VARCHAR(255)    NOT NULL,
            stored_name   CHAR(32)        NOT NULL,
            size          BIGINT UNSIGNED NOT NULL,
            mime          VARCHAR(128)    NULL,
            uploader_name VARCHAR(40)     NULL,
            created_at    DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_transfer_files_room (room_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    $sql[] = <<<SQL
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
        SQL;

    $sql[] = <<<SQL
        CREATE TABLE IF NOT EXISTS transfer_attempts (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip         VARCHAR(45)     NOT NULL,
            kind       ENUM('create','join') NOT NULL,
            code       CHAR(6)         NULL,
            created_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            KEY idx_transfer_attempt (ip, kind, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;
```

In `app/bootstrap.php` after `require BASE_PATH . '/app/lib/object_settings.php';` add:

```php
require BASE_PATH . '/app/lib/transfer.php';
```

- [ ] **Step 4: 写房间领域层**

Create `app/lib/transfer.php` with the full code in Appendix A of this plan. Appendix A is the single source for Task 1 functions; copy it verbatim.

- [ ] **Step 5: 运行测试确认通过**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: `全部通过`。

- [ ] **Step 6: 提交**

```bash
git add app/schema.php app/bootstrap.php app/lib/transfer.php tests/t24_transfer.php
git commit -m "feat: 临时传输房间表与领域层"
```

---

### Task 2: 房间访问、密码、关闭与文件删除

**Files:**
- Modify: `app/lib/transfer.php`
- Test: `tests/t24_transfer.php`

**Interfaces:**
- Produces:
  - `transfer_room_unlocked(array $room): bool`
  - `transfer_join_by_code(string $code, string $password): array`
  - `transfer_unlock_room(array $room, string $password): void`
  - `transfer_files_list(array $room): array`
  - `transfer_file_remove(array $room, int $fileId): void`
  - `transfer_room_close(array $room, string $creatorKey): void`

- [ ] **Step 1: 加失败测试**

Append to `tests/t24_transfer.php` before cleanup:

```php
echo "\n· 密码与加入\n";
$GLOBALS['config']['transfer_code_attempts'] = 10;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$pw = transfer_room_create('secret-123');
$pwRoom = transfer_room_by_token((string) $pw['token']);
// 创建者会话在创建时就解锁了；这里模拟另一位参与者
unset($_SESSION['transfer_unlock'][(int) $pw['id']]);
check(!transfer_room_unlocked($pwRoom ?? []), '密码房对未解锁的参与者仍锁定');
$threw = false;
try { transfer_join_by_code((string) $pw['code'], 'wrong'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '错误密码被拒');
$joined = transfer_join_by_code((string) $pw['code'], 'secret-123');
check((int) $joined['id'] === (int) $pw['id'] && transfer_room_unlocked($joined), '正确密码加入并解锁');
transfer_room_destroy((int) $pw['id']);

echo "\n· 试码锁定\n";
$GLOBALS['config']['transfer_code_attempts'] = 3;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
for ($i = 0; $i < 3; $i++) {
    try { transfer_join_by_code('000000', ''); } catch (Throwable $e) { /* expected */ }
}
$threw = false;
try { transfer_join_by_code('000000', ''); } catch (TransferRateLimitException $e) { $threw = true; }
check($threw, '连续错误试码后被锁定');
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$GLOBALS['config']['transfer_code_attempts'] = 10;

echo "\n· 创建者权限\n";
$own = transfer_room_create('');
$ownRoom = transfer_room_by_token((string) $own['token']);
$threw = false;
try { transfer_room_close($ownRoom ?? [], 'nope'); } catch (RuntimeException $e) { $threw = true; }
check($threw, '错误 key 关闭被拒');
transfer_room_close($ownRoom ?? [], (string) $own['creator_key']);
check(transfer_room_by_token((string) $own['token']) === null, '正确 key 关闭后房间消失');

echo "\n· 文件删除\n";
$del = transfer_room_create('');
$delRoom = transfer_room_by_token((string) $del['token']);
$stored = bin2hex(random_bytes(16));
file_put_contents(transfer_room_dir((string) $del['token']) . '/' . $stored, 'hello');
$fid = q_insert('transfer_files', [
    'room_id' => (int) $del['id'], 'name' => 'a.txt', 'stored_name' => $stored,
    'size' => 5, 'mime' => 'text/plain', 'uploader_name' => null, 'created_at' => now(),
]);
q_exec('UPDATE transfer_rooms SET used_bytes = 5, file_count = 1 WHERE id = ?', [(int) $del['id']]);
transfer_file_remove($delRoom ?? [], (int) $fid);
$afterDel = transfer_room_by_token((string) $del['token']);
check((int) $afterDel['used_bytes'] === 0 && (int) $afterDel['file_count'] === 0, '删除文件回退容量与计数');
check(!is_file(transfer_room_dir((string) $del['token']) . '/' . $stored), '删除文件物理文件');
transfer_room_destroy((int) $del['id']);
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: FAIL `Call to undefined function transfer_room_unlocked()`。

- [ ] **Step 3: 实现**

Append to `app/lib/transfer.php`:

```php
function transfer_room_unlocked(array $room): bool
{
    if (($room['password_hash'] ?? null) === null) {
        return true;
    }
    return !empty($_SESSION['transfer_unlock'][(int) $room['id']]);
}

function transfer_join_by_code(string $code, string $password): array
{
    transfer_assert_can_guess();
    $code = trim($code);
    if (preg_match('/^\d{6}$/', $code) !== 1) {
        transfer_rate_add('join', $code);
        throw new InvalidArgumentException('取件码不正确。');
    }

    $room = q_one('SELECT * FROM transfer_rooms WHERE code = ?', [$code]);
    if ($room === null || strtotime((string) $room['expires_at']) <= time()) {
        transfer_rate_add('join', $code);
        if ($room !== null) {
            transfer_room_destroy((int) $room['id']);
        }
        throw new InvalidArgumentException('取件码不正确或房间已过期。');
    }

    if (($room['password_hash'] ?? null) !== null
        && ($password === '' || !password_verify($password, (string) $room['password_hash']))) {
        transfer_rate_add('join', $code);
        throw new InvalidArgumentException('取件码或密码不正确。');
    }

    $_SESSION['transfer_unlock'][(int) $room['id']] = true;
    return $room;
}

function transfer_unlock_room(array $room, string $password): void
{
    transfer_assert_can_guess();
    if (($room['password_hash'] ?? null) === null) {
        $_SESSION['transfer_unlock'][(int) $room['id']] = true;
        return;
    }
    if ($password === '' || !password_verify($password, (string) $room['password_hash'])) {
        transfer_rate_add('join', (string) $room['code']);
        throw new InvalidArgumentException('密码不正确。');
    }
    $_SESSION['transfer_unlock'][(int) $room['id']] = true;
}

function transfer_files_list(array $room): array
{
    $rows = q_all(
        'SELECT id, name, size, mime, uploader_name, created_at
           FROM transfer_files WHERE room_id = ? ORDER BY id ASC',
        [(int) $room['id']]
    );
    return array_map(static fn (array $r): array => [
        'id'        => (int) $r['id'],
        'name'      => (string) $r['name'],
        'size'      => (int) $r['size'],
        'mime'      => (string) ($r['mime'] ?? ''),
        'uploader'  => (string) ($r['uploader_name'] ?? ''),
        'createdAt' => (string) $r['created_at'],
    ], $rows);
}

function transfer_file_remove(array $room, int $fileId): void
{
    $file = q_one(
        'SELECT * FROM transfer_files WHERE id = ? AND room_id = ?',
        [$fileId, (int) $room['id']]
    );
    if ($file === null) {
        throw new InvalidArgumentException('文件不存在。');
    }
    $path = transfer_file_path($room, $file);
    if (is_file($path)) {
        @unlink($path);
    }
    db_tx(static function () use ($room, $file, $fileId): void {
        q_exec('DELETE FROM transfer_files WHERE id = ?', [$fileId]);
        q_exec(
            'UPDATE transfer_rooms
                SET used_bytes = GREATEST(0, used_bytes - ?),
                    file_count = GREATEST(0, file_count - 1)
              WHERE id = ?',
            [(int) $file['size'], (int) $room['id']]
        );
    });
}

function transfer_room_close(array $room, string $creatorKey): void
{
    if (!transfer_verify_creator($room, $creatorKey)) {
        throw new InvalidArgumentException('没有权限关闭这个房间。');
    }
    transfer_room_destroy((int) $room['id']);
}
```

- [ ] **Step 4: 运行测试确认通过**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: `全部通过`。

- [ ] **Step 5: 提交**

```bash
git add app/lib/transfer.php tests/t24_transfer.php
git commit -m "feat: 临时传输房间加入、密码与关闭"
```

---

### Task 3: 分片上传、容量预留与合并

**Files:**
- Modify: `app/lib/transfer.php`
- Test: `tests/t24_transfer.php`

**Interfaces:**
- Produces:
  - `transfer_upload_start(array $room, string $name, int $size, string $sender = ''): array`
  - `transfer_store_chunk_bytes(array $room, string $uploadId, int $index, string $bytes, int $declaredLength = 0): array`
  - `transfer_upload_finish(array $room, string $uploadId): array`
  - `transfer_upload_cancel(array $room, string $uploadId): void`
  - `transfer_reap_stale_uploads(): void`
  - `transfer_reap_expired(): void`
  - `transfer_parse_range(string $header, int $size): ?array`

- [ ] **Step 1: 加失败测试**

Append to `tests/t24_transfer.php` before cleanup:

```php
echo "\n· 分片上传\n";
$GLOBALS['config']['chunk_size'] = 4;
$GLOBALS['config']['transfer_max_bytes'] = 20;
$up = transfer_room_create('');
$upRoom = transfer_room_by_token((string) $up['token']);
$init = transfer_upload_start($upRoom ?? [], 'hello.txt', 10, 'Alice');
check($init['totalChunks'] === 3 && $init['chunkSize'] === 4, '初始化返回 3 片 × 4 字节');
check((int) q_val('SELECT reserved_bytes FROM transfer_rooms WHERE id = ?', [(int)$up['id']]) === 10, '初始化预留 10 字节');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 0, 'abcd');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 1, 'efgh');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 2, 'ij');
$done = transfer_upload_finish($upRoom ?? [], (string) $init['uploadId']);
check($done['size'] === 10 && $done['name'] === 'hello.txt', '合并成功并返回文件信息');
$fresh = transfer_room_by_token((string) $up['token']);
check((int) $fresh['used_bytes'] === 10 && (int) $fresh['reserved_bytes'] === 0, '完成时扣容量、释放预留');
check(is_file(transfer_room_dir((string)$up['token']) . '/' . $done['stored_name']), '合并文件落盘');
$files = transfer_files_list($fresh);
check(count($files) === 1 && $files[0]['uploader'] === 'Alice', '文件列表包含上传者昵称');

$roomForCap = transfer_room_by_token((string) $up['token']);
$threw = false;
try { transfer_upload_start($roomForCap ?? [], 'too-big.bin', 11, ''); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '剩余容量不足时初始化被拒');

$conc = transfer_room_create('');
$concRoom = transfer_room_by_token((string) $conc['token']);
$hold = transfer_upload_start($concRoom ?? [], 'hold.bin', 12, '');
$threw = false;
try { transfer_upload_start($concRoom ?? [], 'second.bin', 9, ''); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '两笔预留叠加后超过总容量被拒');
transfer_upload_cancel($concRoom ?? [], (string) $hold['uploadId']);
transfer_room_destroy((int) $conc['id']);

echo "\n· 分片边界\n";
$init2 = transfer_upload_start($roomForCap ?? [], 'edge.bin', 5, '');
$threw = false;
try { transfer_store_chunk_bytes($roomForCap ?? [], (string)$init2['uploadId'], 0, '12345'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '分片超过 chunk_size 被拒');
transfer_upload_cancel($roomForCap ?? [], (string)$init2['uploadId']);

$dupInit = transfer_upload_start($roomForCap ?? [], 'dup.bin', 8, '');
transfer_store_chunk_bytes($roomForCap ?? [], (string)$dupInit['uploadId'], 0, 'abcd');
transfer_store_chunk_bytes($roomForCap ?? [], (string)$dupInit['uploadId'], 0, 'ab');
check((int) q_val('SELECT received_bytes FROM transfer_uploads WHERE upload_id = ?', [(string)$dupInit['uploadId']]) === 2,
    '重复分片按覆盖回正字节数');
transfer_upload_cancel($roomForCap ?? [], (string)$dupInit['uploadId']);
$afterAbort = transfer_room_by_token((string)$up['token']);
check((int)$afterAbort['reserved_bytes'] === 0, '放弃上传释放预留');

echo "\n· 空文件\n";
$init3 = transfer_upload_start($afterAbort ?? [], 'empty.txt', 0, '');
$empty = transfer_upload_finish($afterAbort ?? [], (string)$init3['uploadId']);
check($empty['size'] === 0 && is_file(transfer_room_dir((string)$up['token']) . '/' . $empty['stored_name']), '0 字节文件可创建');

echo "\n· 路径与缺片\n";
$evilInit = transfer_upload_start($afterAbort ?? [], '../../evil.txt', 1, '');
$evilName = (string) q_val('SELECT filename FROM transfer_uploads WHERE upload_id = ?', [(string)$evilInit['uploadId']]);
check($evilName !== '' && !str_contains($evilName, '/') && !str_contains($evilName, '\\'),
    '文件名路径分隔符被清洗');
transfer_upload_cancel($afterAbort ?? [], (string)$evilInit['uploadId']);

$edgeInit = transfer_upload_start($afterAbort ?? [], 'edge2.txt', 4, '');
$threw = false;
try { transfer_store_chunk_bytes($afterAbort ?? [], (string)$edgeInit['uploadId'], 99, 'x'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '分片序号越界被拒');
$threw = false;
try { transfer_upload_finish($afterAbort ?? [], (string)$edgeInit['uploadId']); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '缺片时合并被拒');
transfer_upload_cancel($afterAbort ?? [], (string)$edgeInit['uploadId']);

echo "\n· Range\n";
check(transfer_parse_range('bytes=0-4', 10) === [0, 4], '解析前 5 字节');
check(transfer_parse_range('bytes=-3', 10) === [7, 9], '解析后缀范围');
check(transfer_parse_range('bytes=8-', 10) === [8, 9], '解析开放结尾');
check(transfer_parse_range('bytes=10-', 10) === null, '起点越界返回 null');
check(transfer_parse_range('', 10) === null, '空头返回 null');

echo "\n· 超时会话\n";
q_insert('transfer_uploads', [
    'upload_id' => bin2hex(random_bytes(16)), 'room_id' => (int)$up['id'],
    'filename' => 'stale.bin', 'size' => 4, 'chunk_size' => 4, 'total_chunks' => 1,
    'received' => json_encode([]), 'received_bytes' => 0, 'uploader_name' => null,
    'status' => 'uploading', 'created_at' => date('Y-m-d H:i:s', time() - 7200),
    'updated_at' => date('Y-m-d H:i:s', time() - 7200),
]);
q_exec('UPDATE transfer_rooms SET reserved_bytes = reserved_bytes + 4 WHERE id = ?', [(int)$up['id']]);
transfer_reap_stale_uploads();
$reaped = transfer_room_by_token((string)$up['token']);
check((int)$reaped['reserved_bytes'] === 0, '超时会话释放预留');

echo "\n· 文件数上限\n";
$GLOBALS['config']['transfer_max_files'] = 1;
$one = transfer_room_create('');
$oneRoom = transfer_room_by_token((string) $one['token']);
$u1 = transfer_upload_start($oneRoom ?? [], '1.txt', 1, '');
transfer_store_chunk_bytes($oneRoom ?? [], (string) $u1['uploadId'], 0, 'a');
transfer_upload_finish($oneRoom ?? [], (string) $u1['uploadId']);
$u2 = transfer_upload_start($oneRoom ?? [], '2.txt', 1, '');
transfer_store_chunk_bytes($oneRoom ?? [], (string) $u2['uploadId'], 0, 'b');
$threw = false;
try { transfer_upload_finish($oneRoom ?? [], (string) $u2['uploadId']); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '超过文件数上限时合并不落库');
transfer_room_destroy((int) $one['id']);

transfer_room_destroy((int)$up['id']);
$GLOBALS['config']['chunk_size'] = 5 * 1024 * 1024;
$GLOBALS['config']['transfer_max_bytes'] = 1024 * 1024;
$GLOBALS['config']['transfer_max_files'] = 200;
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: FAIL `Call to undefined function transfer_upload_start()`。

- [ ] **Step 3: 实现**

Append to `app/lib/transfer.php` the code in Appendix B (upload start/chunk/finish/cancel/stale/range). Copy it verbatim; the function names must stay exactly `transfer_upload_start`, `transfer_store_chunk_bytes`, `transfer_upload_finish`, `transfer_upload_cancel`, `transfer_reap_stale_uploads`, `transfer_reap_expired`, `transfer_parse_range`.

- [ ] **Step 4: 运行测试确认通过**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: `全部通过`。

- [ ] **Step 5: 提交**

```bash
git add app/lib/transfer.php tests/t24_transfer.php
git commit -m "feat: 临时传输分片上传、容量预留与合并"
```

---

### Task 4: 公开首页、创建/加入接口与入口

**Files:**
- Create: `app/modules/transfer.php`
- Create: `app/views/transfer.php`
- Create: `assets/js/transfer.js`
- Create: `assets/css/transfer.css`
- Modify: `app/router.php`
- Modify: `app/views/auth.php`
- Test: `tests/t24_transfer.php`

**Interfaces:**
- Produces routes: `transfer.index`、`transfer.create`、`transfer.join`、`transfer.qr`.
- JS uses `SY.api` / `SY.toast`; page root `[data-page="transfer"]`.

- [ ] **Step 1: 加静态测试**

Append before cleanup:

```php
echo "\n· 公开页\n";
$routes = (string) file_get_contents(BASE_PATH . '/app/router.php');
foreach (['transfer.index', 'transfer.room', 'transfer.create', 'transfer.join',
          'transfer.upload_init', 'transfer.upload_chunk', 'transfer.upload_complete',
          'transfer.download', 'transfer.close'] as $r) {
    check(str_contains($routes, "'" . $r . "'"), '公开路由包含 ' . $r);
}
$view = (string) file_get_contents(BASE_PATH . '/app/views/transfer.php');
check(str_contains($view, 'data-page="transfer"'), '首页视图标记页面');
check(str_contains($view, 'data-create'), '首页有创建入口');
check(str_contains($view, 'data-join'), '首页有取件码入口');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/transfer.js');
check(str_contains($js, 'transfer.create') && str_contains($js, 'transfer.join'), '首页 JS 调用创建/加入');
check(str_contains($js, 'localStorage'), '创建者 key 存 localStorage');
```

- [ ] **Step 2: 运行确认失败**

Run: `runuser -u www -- php tests/t24_transfer.php`
Expected: FAIL（文件不存在）。

- [ ] **Step 3: 路由与控制器**

Add to `app/router.php` `PUBLIC_ROUTES` exactly:

```php
    'transfer.index',
    'transfer.room',
    'transfer.create',
    'transfer.join',
    'transfer.unlock',
    'transfer.files',
    'transfer.upload_init',
    'transfer.upload_chunk',
    'transfer.upload_complete',
    'transfer.upload_abort',
    'transfer.download',
    'transfer.close',
    'transfer.file_delete',
    'transfer.qr',
```

Create `app/modules/transfer.php` with Appendix C's “Task 4” section (index/create/join/qr + `transfer_is_local_host`). Copy verbatim.

- [ ] **Step 4: 首页视图与 JS**

Create `app/views/transfer.php` with the markup in Appendix D (index page). Create `assets/js/transfer.js` with Appendix E (index JS). Create `assets/css/transfer.css` with Appendix F (it contains both the index and room styles).

- [ ] **Step 5: 登录页入口**

In `app/views/auth.php` add near the login form's secondary links:

```php
<p class="mhint" style="margin-top:14px;text-align:center">
    <a href="<?= e(url('transfer.index')) ?>">临时传输（免登录）</a>
</p>
```

In `app/views/home.php`, add a footer link near the existing footer nav:

```php
<a href="<?= e(url('transfer.index')) ?>">临时传输</a>
```

- [ ] **Step 6: 运行测试并提交**

Run: `runuser -u www -- php tests/t24_transfer.php`; `php -l app/modules/transfer.php`; `node --check assets/js/transfer.js`.
Expected: `全部通过`。

```bash
git add app/router.php app/modules/transfer.php app/views/transfer.php \
        assets/js/transfer.js assets/css/transfer.css app/views/auth.php tests/t24_transfer.php
git commit -m "feat: 临时传输公开首页、创建与加入"
```

---

### Task 5: 房间页、文件轮询与创建者操作

**Files:**
- Modify: `app/modules/transfer.php`
- Create: `app/views/transfer_room.php`
- Create: `assets/js/transfer_room.js`
- Test: `tests/t24_transfer.php`

**Interfaces:**
- Produces routes: `transfer.room`、`transfer.unlock`、`transfer.files`、`transfer.close`、`transfer.file_delete`.
- JS root `[data-page="transfer-room"]`; `data-token` on the root.

- [ ] **Step 1: 加静态测试**

Append before cleanup:

```php
$roomView = (string) file_get_contents(BASE_PATH . '/app/views/transfer_room.php');
check(str_contains($roomView, 'data-page="transfer-room"'), '房间视图标记页面');
check(str_contains($roomView, 'data-files'), '房间视图有文件列表');
$roomJs = (string) file_get_contents(BASE_PATH . '/assets/js/transfer_room.js');
check(str_contains($roomJs, 'transfer.files') && str_contains($roomJs, 'transfer.upload_init'), '房间 JS 轮询并上传');
check(str_contains($roomJs, 'transfer.ck.'), '房间 JS 读取创建者 key');
check(str_contains($roomJs, 'transfer.download'), '房间 JS 生成下载链接');
```

- [ ] **Step 2: 控制器**

Append to `app/modules/transfer.php` the “Task 5” section in Appendix C (room/unlock/files/close/file_delete + `transfer_require_room`/`transfer_require_unlocked`). Copy verbatim.

- [ ] **Step 3: 房间视图与 JS**

Create `app/views/transfer_room.php` with Appendix G (room view). Create `assets/js/transfer_room.js` with Appendix H (room JS). The room styles are already in `assets/css/transfer.css` from Task 4 (Appendix F).

- [ ] **Step 4: 运行测试并提交**

Run: `runuser -u www -- php tests/t24_transfer.php`; `node --check assets/js/transfer_room.js`.
Expected: `全部通过`。

```bash
git add app/modules/transfer.php app/views/transfer_room.php \
        assets/js/transfer_room.js assets/css/transfer.css tests/t24_transfer.php
git commit -m "feat: 临时传输房间页、轮询与创建者操作"
```

---

### Task 6: 上传/下载控制器与 Range 流式输出

**Files:**
- Modify: `app/modules/transfer.php`
- Modify: `app/lib/transfer.php`
- Test: `tests/t24_transfer.php`

**Interfaces:**
- Produces routes: `transfer.upload_init`、`transfer.upload_chunk`、`transfer.upload_complete`、`transfer.upload_abort`、`transfer.download`.
- Produces `transfer_stream(array $room, array $file): never`.

- [ ] **Step 1: 加静态测试**

Append before cleanup:

```php
$module = (string) file_get_contents(BASE_PATH . '/app/modules/transfer.php');
check(str_contains($module, 'function transfer_upload_chunk'), '上传分片接口存在');
check(str_contains($module, 'function transfer_download'), '下载接口存在');
check(str_contains($module, 'transfer_upload_start'), '上传控制器调用领域函数');
```

- [ ] **Step 2: 控制器与流式输出**

Append to `app/modules/transfer.php` the “Task 6” section in Appendix C (upload init/chunk/complete/abort + download). Append `transfer_stream()` from Appendix I to `app/lib/transfer.php`.

- [ ] **Step 3: 运行测试并提交**

Run: `runuser -u www -- php tests/t24_transfer.php`; `php -l app/modules/transfer.php`; `php -l app/lib/transfer.php`.
Expected: `全部通过`。

```bash
git add app/modules/transfer.php app/lib/transfer.php tests/t24_transfer.php
git commit -m "feat: 临时传输上传下载接口与 Range 输出"
```

---

### Task 7: 清理任务、配置、README、全量回归与页面验证

**Files:**
- Modify: `app/modules/tasks.php`
- Modify: `app/config.example.php`
- Modify: `README.md`
- Test: `tests/t1…t24`

- [ ] **Step 1: 挂接清理任务**

In `app/modules/tasks.php`’s cron handler (the function handling `tasks.cron`), after the existing queue cleanup, add:

```php
    require_once BASE_PATH . '/app/lib/transfer.php';
    transfer_reap_expired();
```

Use the existing cron handler’s location; do not change its key check.

- [ ] **Step 2: 配置模板**

In `app/config.example.php` add:

```php
    // ------------------------------------------------------------ 临时传输
    // 免登录的双向临时房间；关闭后公开页仍在，但不能再建房。
    'transfer_enabled'         => true,
    'transfer_ttl_hours'       => 24,
    'transfer_max_bytes'       => 10 * 1024 ** 3,
    'transfer_max_files'       => 200,
    'transfer_create_per_hour' => 5,
    'transfer_code_attempts'   => 10,
    'transfer_storage_path'    => BASE_PATH . '/runtime/transfer',
```

- [ ] **Step 3: README**

Add a section:

```markdown
**临时传输（免登录）**
- 打开 `index.php?r=transfer.index`，创建房间后得到链接、6 位取件码和二维码
- 双方不登录，凭链接/码进出，都能上传和下载；上传者可选昵称
- 房间默认 24 小时、10 GB、200 个文件；创建者可随时关闭
- 文件只临时存 `runtime/transfer/`，不进网盘、不占用户配额；到期/关闭自动删除
- 6 位码有 IP 限速与锁定；敏感场景建议设置提取密码
- 服务器中转意味着管理员理论上可读取内容；公网请使用 HTTPS
```

- [ ] **Step 4: 全量测试**

```bash
for f in tests/t*.php; do runuser -u www -- php "$f" >/tmp/tout 2>&1 || { echo "FAILED: $f"; tail -20 /tmp/tout; }; done
```

Expected: no `FAILED:` lines.

- [ ] **Step 5: 页面与局域网验证**

- 用 CDP 打开 `?r=transfer.index`，确认页面渲染、无 JS 异常；点「创建房间」确认结果卡显示取件码/链接/二维码。
- 复制房间链接，在第二个浏览器上下文打开，确认能进入房间并看到上传区；上传一个小文件，确认列表出现、另一侧轮询能看到。
- 点下载确认文件可取；创建者点关闭确认房间失效。
- 两台局域网机器实测由用户完成：在 A 上用 `http://<服务器局域网IP>:15244` 创建房间，把链接发给 B，B 上传/下载。

- [ ] **Step 6: 提交**

```bash
git add app/modules/tasks.php app/config.example.php README.md
git commit -m "chore: 临时传输清理任务、配置与文档"
```

---

## Appendix A — `app/lib/transfer.php`（Task 1）

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

final class TransferRateLimitException extends RuntimeException
{
}

final class TransferCapacityException extends RuntimeException
{
}

function transfer_enabled(): bool
{
    return (bool) config('transfer_enabled', true);
}

function transfer_root(): string
{
    $root = rtrim((string) config('transfer_storage_path', BASE_PATH . '/runtime/transfer'), '/');
    if (!is_dir($root)) {
        @mkdir($root, 0700, true);
    }
    return $root;
}

function transfer_ttl_hours(): int
{
    return max(1, min(168, (int) config('transfer_ttl_hours', 24)));
}

function transfer_max_bytes(): int
{
    return max(1, (int) config('transfer_max_bytes', 10 * 1024 ** 3));
}

function transfer_max_files(): int
{
    return max(1, min(5000, (int) config('transfer_max_files', 200)));
}

function transfer_ip(): string
{
    return mb_substr(client_ip(), 0, 45);
}

function transfer_token(): string
{
    return bin2hex(random_bytes(24));
}

function transfer_code(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function transfer_new_code(): string
{
    for ($i = 0; $i < 10; $i++) {
        $code = transfer_code();
        if (q_val('SELECT 1 FROM transfer_rooms WHERE code = ?', [$code]) === null) {
            return $code;
        }
    }
    throw new RuntimeException('无法生成取件码，请重试。');
}

function transfer_creator_hash(string $key): string
{
    return hash('sha256', $key);
}

function transfer_verify_creator(array $room, string $key): bool
{
    return $key !== ''
        && hash_equals((string) ($room['creator_key_hash'] ?? ''), transfer_creator_hash($key));
}

function transfer_rate_count(string $kind, int $seconds): int
{
    return (int) q_val(
        'SELECT COUNT(*) FROM transfer_attempts WHERE ip = ? AND kind = ? AND created_at >= ?',
        [transfer_ip(), $kind, date('Y-m-d H:i:s', time() - $seconds)]
    );
}

function transfer_rate_add(string $kind, ?string $code = null): void
{
    q_insert('transfer_attempts', [
        'ip'         => transfer_ip(),
        'kind'       => $kind,
        // code 列是 CHAR(6)；恶意长输入不能把 INSERT 撑成 500
        'code'       => $code !== null ? mb_substr($code, 0, 6) : null,
        'created_at' => now(),
    ]);
}

function transfer_assert_can_create(): void
{
    $limit = max(1, (int) config('transfer_create_per_hour', 5));
    if (transfer_rate_count('create', 3600) >= $limit) {
        throw new TransferRateLimitException('创建太频繁，请稍后再试。');
    }
}

function transfer_assert_can_guess(): void
{
    $limit = max(1, (int) config('transfer_code_attempts', 10));
    if (transfer_rate_count('join', 900) >= $limit) {
        throw new TransferRateLimitException('尝试次数过多，请 15 分钟后再试。');
    }
}

function transfer_room_create(string $password = ''): array
{
    if (!transfer_enabled()) {
        throw new RuntimeException('临时传输已关闭。');
    }
    transfer_assert_can_create();
    transfer_rate_add('create');

    $password = trim($password);
    if ($password !== '' && mb_strlen($password) > 32) {
        throw new InvalidArgumentException('提取密码最多 32 位。');
    }

    $expires = date('Y-m-d H:i:s', time() + transfer_ttl_hours() * 3600);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $token = transfer_token();
        $code = transfer_new_code();
        $creatorKey = bin2hex(random_bytes(24));
        try {
            $id = q_insert('transfer_rooms', [
                'token'            => $token,
                'code'             => $code,
                'password_hash'    => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null,
                'creator_key_hash' => transfer_creator_hash($creatorKey),
                'max_bytes'        => transfer_max_bytes(),
                'max_files'        => transfer_max_files(),
                'created_ip'       => transfer_ip(),
                'created_at'       => now(),
                'expires_at'       => $expires,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                continue;
            }
            throw new RuntimeException('创建房间失败，请重试。');
        }

        $dir = transfer_room_dir($token);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            q_exec('DELETE FROM transfer_rooms WHERE id = ?', [$id]);
            throw new RuntimeException('无法创建房间目录。');
        }
        $_SESSION['transfer_unlock'][$id] = true;

        return [
            'id'          => $id,
            'token'       => $token,
            'code'        => $code,
            'creator_key' => $creatorKey,
            'expires_at'  => $expires,
        ];
    }

    throw new RuntimeException('创建房间失败，请重试。');
}

function transfer_room_by_token(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
        return null;
    }
    $room = q_one('SELECT * FROM transfer_rooms WHERE token = ?', [$token]);
    if ($room === null) {
        return null;
    }
    if (strtotime((string) $room['expires_at']) <= time()) {
        transfer_room_destroy((int) $room['id']);
        return null;
    }
    return $room;
}

function transfer_room_state(array $room): array
{
    $expires = strtotime((string) $room['expires_at']);
    return [
        'token'         => (string) $room['token'],
        'code'          => (string) $room['code'],
        'expiresAt'     => (string) $room['expires_at'],
        'expiresIn'     => max(0, $expires - time()),
        'maxBytes'      => (int) $room['max_bytes'],
        'usedBytes'     => (int) $room['used_bytes'],
        'reservedBytes' => (int) $room['reserved_bytes'],
        'maxFiles'      => (int) $room['max_files'],
        'fileCount'     => (int) $room['file_count'],
    ];
}

function transfer_room_dir(string $token): string
{
    return transfer_root() . '/' . $token;
}

function transfer_file_path(array $room, array $file): string
{
    return transfer_room_dir((string) $room['token']) . '/' . (string) $file['stored_name'];
}

function transfer_upload_dir(string $token, string $uploadId): string
{
    return transfer_room_dir($token) . '/u_' . $uploadId;
}

function transfer_rmdir(string $dir): void
{
    $root = transfer_root();
    $real = realpath($dir);
    if ($real === false) {
        return;
    }
    // 只允许删根目录下的房间/分片目录；根目录本身永远不删
    if ($real === $root || !str_starts_with($real . '/', $root . '/')) {
        return;
    }
    $items = @scandir($real);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $real . '/' . $item;
        if (is_link($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            transfer_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($real);
}

function transfer_room_destroy(int $id): void
{
    $room = q_one('SELECT token FROM transfer_rooms WHERE id = ?', [$id]);
    if ($room !== null) {
        transfer_rmdir(transfer_room_dir((string) $room['token']));
    }
    unset($_SESSION['transfer_unlock'][$id]);
    db_tx(static function () use ($id): void {
        q_exec('DELETE FROM transfer_files WHERE room_id = ?', [$id]);
        q_exec('DELETE FROM transfer_uploads WHERE room_id = ?', [$id]);
        q_exec('DELETE FROM transfer_rooms WHERE id = ?', [$id]);
    });
}

function transfer_reap_rooms(): void
{
    foreach (q_all('SELECT id FROM transfer_rooms WHERE expires_at <= ?', [now()]) as $row) {
        transfer_room_destroy((int) $row['id']);
    }
    q_exec('DELETE FROM transfer_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}
```

## Appendix B — 上传领域函数（Task 3）

```php
function transfer_upload_start(array $room, string $name, int $size, string $sender = ''): array
{
    if (strtotime((string) $room['expires_at']) <= time()) {
        throw new InvalidArgumentException('房间已过期。');
    }
    $name = safe_filename($name);
    if ($name === null) {
        throw new InvalidArgumentException('文件名不合法。');
    }
    if ($size < 0) {
        throw new InvalidArgumentException('文件大小不合法。');
    }
    if ($size > (int) config('max_upload')) {
        throw new InvalidArgumentException('文件超过单文件上限。');
    }

    $inflight = (int) q_val(
        "SELECT COUNT(*) FROM transfer_uploads
          WHERE room_id = ? AND status IN ('pending','uploading') AND updated_at >= ?",
        [(int) $room['id'], date('Y-m-d H:i:s', time() - 3600)]
    );
    if ($inflight >= 20) {
        throw new TransferRateLimitException('同时进行的上传太多了，请稍后再试。');
    }

    // 0 字节文件不需要预留；也避免 UPDATE 不改变行导致 rowCount=0 的误判
    if ($size > 0) {
        $reserved = q_exec(
            'UPDATE transfer_rooms
                SET reserved_bytes = reserved_bytes + ?
              WHERE id = ? AND expires_at > ?
                AND used_bytes + reserved_bytes + ? <= max_bytes',
            [$size, (int) $room['id'], now(), $size]
        );
        if ($reserved !== 1) {
            throw new TransferCapacityException('房间剩余容量不足。');
        }
    }

    $chunkSize = max(1024, (int) config('chunk_size', 5 * 1024 * 1024));
    $total = $size === 0 ? 1 : (int) ceil($size / $chunkSize);
    if ($total > 10000) {
        q_exec('UPDATE transfer_rooms SET reserved_bytes = GREATEST(0, reserved_bytes - ?) WHERE id = ?', [$size, (int) $room['id']]);
        throw new InvalidArgumentException('文件太大，分片数量超过上限。');
    }

    $uploadId = bin2hex(random_bytes(16));
    try {
        q_insert('transfer_uploads', [
            'upload_id'      => $uploadId,
            'room_id'        => (int) $room['id'],
            'filename'       => $name,
            'size'           => $size,
            'chunk_size'     => $chunkSize,
            'total_chunks'   => $total,
            'received'       => json_encode([]),
            'received_bytes' => 0,
            'uploader_name'  => $sender !== '' ? mb_substr($sender, 0, 40) : null,
            'status'         => 'pending',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    } catch (Throwable $e) {
        q_exec('UPDATE transfer_rooms SET reserved_bytes = GREATEST(0, reserved_bytes - ?) WHERE id = ?', [$size, (int) $room['id']]);
        throw new RuntimeException('无法初始化上传。');
    }

    return ['uploadId' => $uploadId, 'chunkSize' => $chunkSize, 'totalChunks' => $total];
}

function transfer_store_chunk_bytes(
    array $room,
    string $uploadId,
    int $index,
    string $bytes,
    int $declaredLength = 0
): array {
    $session = q_one(
        'SELECT * FROM transfer_uploads WHERE upload_id = ? AND room_id = ? LIMIT 1',
        [$uploadId, (int) $room['id']]
    );
    if ($session === null || !in_array((string) $session['status'], ['pending', 'uploading'], true)) {
        throw new InvalidArgumentException('上传会话不存在或已过期。');
    }
    $total = (int) $session['total_chunks'];
    if ($index < 0 || $index >= $total) {
        throw new InvalidArgumentException('分片序号越界。');
    }
    $chunkSize = (int) $session['chunk_size'];
    $size = (int) $session['size'];
    $written = strlen($bytes);
    if ($chunkSize > 0 && $written > $chunkSize) {
        throw new InvalidArgumentException('分片超过约定的分片大小。');
    }
    if ($written === 0 && $declaredLength > 0) {
        throw new InvalidArgumentException('分片为空，请重试。');
    }
    if ($size === 0 && $written > 0) {
        throw new InvalidArgumentException('空文件不应包含分片数据。');
    }
    if ($size > 0 && $written > $size) {
        throw new InvalidArgumentException('分片超过文件声明大小。');
    }

    $dir = transfer_upload_dir((string) $room['token'], $uploadId);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException('无法创建分片目录。');
    }
    $target = $dir . '/' . $index . '.part';
    if (file_put_contents($target, $bytes) === false) {
        throw new RuntimeException('无法写入分片。');
    }
    @chmod($target, 0600);

    $result = null;
    db_tx(static function () use ($session, $index, $written, $size, $chunkSize, &$result): void {
        $row = q_one('SELECT received, received_bytes FROM transfer_uploads WHERE id = ? FOR UPDATE', [(int) $session['id']]);
        if ($row === null) {
            throw new RuntimeException('上传会话已不存在。');
        }
        $received = json_decode((string) $row['received'], true);
        if (!is_array($received)) {
            $received = [];
        }
        $prev = (int) ($received[$index] ?? 0);
        $nextTotal = (int) $row['received_bytes'] - $prev + $written;
        $cap = $size > 0 ? $size : $chunkSize;
        if ($cap > 0 && $nextTotal > $cap) {
            throw new InvalidArgumentException('累计分片超过文件声明大小。');
        }
        $received[$index] = $written;
        q_update('transfer_uploads', [
            'received'       => json_encode($received),
            'received_bytes' => $nextTotal,
            'status'         => 'uploading',
            'updated_at'     => now(),
        ], 'id = ?', [(int) $session['id']]);
        $result = ['received' => count($received), 'total' => (int) $session['total_chunks']];
    });

    return $result ?? ['received' => 0, 'total' => $total];
}

function transfer_upload_finish(array $room, string $uploadId): array
{
    $session = q_one(
        'SELECT * FROM transfer_uploads WHERE upload_id = ? AND room_id = ? LIMIT 1',
        [$uploadId, (int) $room['id']]
    );
    if ($session === null) {
        throw new InvalidArgumentException('上传会话不存在或已过期。');
    }

    $declared = (int) $session['size'];
    $total = (int) $session['total_chunks'];
    $received = json_decode((string) $session['received'], true);
    if (!is_array($received)) {
        $received = [];
    }
    $dir = transfer_upload_dir((string) $room['token'], $uploadId);
    if ($declared > 0) {
        for ($i = 0; $i < $total; $i++) {
            if (!isset($received[$i]) || !is_file($dir . '/' . $i . '.part')) {
                throw new InvalidArgumentException('还有分片没有上传完成。');
            }
        }
    }

    $claimed = q_exec(
        "UPDATE transfer_uploads SET status = 'merging', updated_at = ?
          WHERE id = ? AND (status IN ('pending','uploading') OR (status = 'merging' AND updated_at < ?))",
        [now(), (int) $session['id'], date('Y-m-d H:i:s', time() - 600)]
    );
    if ($claimed !== 1) {
        throw new RuntimeException('这次上传正在处理中，请稍候重试。');
    }

    $storedName = bin2hex(random_bytes(16));
    $target = transfer_room_dir((string) $room['token']) . '/' . $storedName;
    $actual = 0;
    $fh = fopen($target, 'wb');
    if ($fh === false) {
        q_exec("UPDATE transfer_uploads SET status='uploading' WHERE id = ?", [(int) $session['id']]);
        throw new RuntimeException('无法写入合并文件。');
    }
    try {
        for ($i = 0; $i < $total; $i++) {
            $part = $dir . '/' . $i . '.part';
            if (!is_file($part)) {
                continue;
            }
            $in = fopen($part, 'rb');
            if ($in === false) {
                throw new RuntimeException('无法读取分片。');
            }
            $actual += (int) stream_copy_to_stream($in, $fh);
            fclose($in);
        }
    } catch (Throwable $e) {
        fclose($fh);
        @unlink($target);
        q_exec("UPDATE transfer_uploads SET status='uploading' WHERE id = ?", [(int) $session['id']]);
        throw new RuntimeException('合并分片失败。');
    }
    fclose($fh);

    if ($actual !== $declared) {
        @unlink($target);
        q_exec("UPDATE transfer_uploads SET status='uploading' WHERE id = ?", [(int) $session['id']]);
        throw new InvalidArgumentException('合并后的文件大小与声明不一致。');
    }

    $name = (string) $session['filename'];
    $mime = mime_of($name);
    $fileId = 0;
    try {
        db_tx(static function () use ($room, $session, $storedName, $actual, $name, $mime, $declared, &$fileId): void {
            $ok = q_exec(
                'UPDATE transfer_rooms
                    SET used_bytes = used_bytes + ?,
                        reserved_bytes = GREATEST(0, reserved_bytes - ?),
                        file_count = file_count + 1
                  WHERE id = ?
                    AND used_bytes + ? <= max_bytes
                    AND file_count < max_files',
                [$actual, $declared, (int) $room['id'], $actual]
            );
            if ($ok !== 1) {
                throw new TransferCapacityException('房间已满。');
            }
            $fileId = q_insert('transfer_files', [
                'room_id'       => (int) $room['id'],
                'name'          => $name,
                'stored_name'   => $storedName,
                'size'          => $actual,
                'mime'          => $mime,
                'uploader_name' => $session['uploader_name'] !== null ? (string) $session['uploader_name'] : null,
                'created_at'    => now(),
            ]);
            q_exec('DELETE FROM transfer_uploads WHERE id = ?', [(int) $session['id']]);
        });
    } catch (Throwable $e) {
        @unlink($target);
        q_exec(
            'UPDATE transfer_rooms SET reserved_bytes = GREATEST(0, reserved_bytes - ?) WHERE id = ?',
            [$declared, (int) $room['id']]
        );
        q_exec('DELETE FROM transfer_uploads WHERE id = ?', [(int) $session['id']]);
        throw $e;
    }

    transfer_rmdir($dir);

    return [
        'id'          => $fileId,
        'name'        => $name,
        'size'        => $actual,
        'stored_name' => $storedName,
        'uploader'    => (string) ($session['uploader_name'] ?? ''),
    ];
}

function transfer_upload_cancel(array $room, string $uploadId): void
{
    $session = q_one(
        'SELECT * FROM transfer_uploads WHERE upload_id = ? AND room_id = ? LIMIT 1',
        [$uploadId, (int) $room['id']]
    );
    if ($session === null) {
        return;
    }
    if ((string) $session['status'] === 'merging') {
        throw new RuntimeException('上传正在合并，暂不能放弃。');
    }
    transfer_rmdir(transfer_upload_dir((string) $room['token'], $uploadId));
    q_exec('DELETE FROM transfer_uploads WHERE id = ?', [(int) $session['id']]);
    q_exec(
        'UPDATE transfer_rooms SET reserved_bytes = GREATEST(0, reserved_bytes - ?) WHERE id = ?',
        [(int) $session['size'], (int) $room['id']]
    );
}

function transfer_reap_stale_uploads(): void
{
    $rows = q_all(
        "SELECT * FROM transfer_uploads
          WHERE status IN ('pending','uploading','merging') AND updated_at < ?",
        [date('Y-m-d H:i:s', time() - 3600)]
    );
    foreach ($rows as $session) {
        $room = q_one('SELECT * FROM transfer_rooms WHERE id = ?', [(int) $session['room_id']]);
        if ($room !== null) {
            transfer_rmdir(transfer_upload_dir((string) $room['token'], (string) $session['upload_id']));
            q_exec(
                'UPDATE transfer_rooms SET reserved_bytes = GREATEST(0, reserved_bytes - ?) WHERE id = ?',
                [(int) $session['size'], (int) $room['id']]
            );
        }
        q_exec('DELETE FROM transfer_uploads WHERE id = ?', [(int) $session['id']]);
    }
}

function transfer_reap_expired(): void
{
    transfer_reap_rooms();
    transfer_reap_stale_uploads();
}

function transfer_parse_range(string $header, int $size): ?array
{
    if ($header === '' || $size <= 0) {
        return null;
    }
    if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
        return null;
    }
    if ($m[1] === '' && $m[2] === '') {
        return null;
    }
    if ($m[1] === '') {
        $len = (int) $m[2];
        if ($len <= 0) {
            return null;
        }
        return [max(0, $size - $len), $size - 1];
    }
    $start = (int) $m[1];
    if ($start >= $size) {
        return null;
    }
    $end = $m[2] === '' ? $size - 1 : (int) $m[2];
    $end = min($end, $size - 1);
    if ($end < $start) {
        return null;
    }
    return [$start, $end];
}
```

## Appendix C — `app/modules/transfer.php`

### Task 4 section

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   免登录临时传输：公开路由。领域逻辑在 app/lib/transfer.php。
   ========================================================================= */

function transfer_is_local_host(): bool
{
    $host = strtolower((string) parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
}

function transfer_index(): void
{
    transfer_reap_rooms();
    render('transfer', [
        'title'     => '临时传输 · 拾云',
        'bodyClass' => 'is-transfer',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css', '/assets/css/transfer.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/transfer.js'],
        'enabled'   => transfer_enabled(),
        'localOnly' => transfer_is_local_host(),
    ]);
}

function transfer_create(): void
{
    require_method('POST');
    require_csrf();
    try {
        $created = transfer_room_create((string) input('password', ''));
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'rate_limited');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '创建房间失败，请重试。'), 400, 'error');
    }

    $token = (string) $created['token'];
    json_ok([
        'room' => [
            'token'       => $token,
            'code'        => (string) $created['code'],
            'url'         => url('transfer.room', ['t' => $token]),
            'absoluteUrl' => absolute_url('transfer.room', ['t' => $token]),
            'expiresAt'   => (string) $created['expires_at'],
        ],
        'creator_key' => (string) $created['creator_key'],
    ]);
}

function transfer_join(): void
{
    require_method('POST');
    require_csrf();
    try {
        $room = transfer_join_by_code((string) input('code', ''), (string) input('password', ''));
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'rate_limited');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'bad_password');
    }

    json_ok([
        'token' => (string) $room['token'],
        'url'   => url('transfer.room', ['t' => (string) $room['token']]),
    ]);
}

function transfer_qr(): void
{
    $token = (string) input('t', '');
    $room = transfer_room_by_token($token);
    if ($room === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo qr_svg(absolute_url('transfer.room', ['t' => $token]), 6, 2);
    exit;
}
```

### Task 5 section

```php
function transfer_require_room(): array
{
    $room = transfer_room_by_token((string) input('t', ''));
    if ($room === null) {
        json_fail('房间不存在或已关闭。', 404, 'room_gone');
    }
    return $room;
}

function transfer_require_unlocked(array $room): void
{
    if (!transfer_room_unlocked($room)) {
        json_fail('需要提取密码。', 401, 'password_required');
    }
}

function transfer_room(): void
{
    $token = (string) input('t', '');
    if ($token === '') {
        redirect(url('transfer.index'));
    }
    $room = transfer_room_by_token($token);
    $locked = $room !== null && !transfer_room_unlocked($room);
    render('transfer_room', [
        'title'     => $room !== null ? '临时传输 · ' . (string) $room['code'] : '房间不存在 · 临时传输',
        'bodyClass' => 'is-transfer',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css', '/assets/css/transfer.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/transfer_room.js'],
        'gone'      => $room === null,
        'locked'    => $locked,
        'token'     => $token,
        'room'      => $locked ? null : $room,
        'files'     => $room === null || $locked ? [] : transfer_files_list($room),
        'roomUrl'   => $room !== null ? absolute_url('transfer.room', ['t' => $token]) : '',
    ]);
}

function transfer_unlock(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    try {
        transfer_unlock_room($room, (string) input('password', ''));
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'rate_limited');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'bad_password');
    }
    json_ok(['unlocked' => true]);
}

function transfer_files(): void
{
    $room = transfer_require_room();
    transfer_require_unlocked($room);
    json_ok([
        'room'  => transfer_room_state($room),
        'files' => transfer_files_list($room),
    ]);
}

function transfer_close(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    try {
        transfer_room_close($room, (string) input('creator_key', ''));
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 403, 'forbidden');
    }
    json_ok(['closed' => true]);
}

function transfer_file_delete(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    if (!transfer_verify_creator($room, (string) input('creator_key', ''))) {
        json_fail('没有权限删除文件。', 403, 'forbidden');
    }
    try {
        transfer_file_remove($room, input_int('id'));
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 404, 'not_found');
    }
    json_ok(['deleted' => true]);
}
```

### Task 6 section

```php
function transfer_upload_init(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    transfer_require_unlocked($room);
    try {
        $init = transfer_upload_start(
            $room,
            (string) input('name', ''),
            input_int('size'),
            (string) input('sender', '')
        );
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'too_many');
    } catch (TransferCapacityException $e) {
        json_fail($e->getMessage(), 409, 'room_full');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '上传初始化失败，请重试。'), 400, 'error');
    }
    json_ok($init);
}

function transfer_upload_chunk(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    transfer_require_unlocked($room);

    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) ($_SERVER['HTTP_X_UPLOAD_ID'] ?? '')) ?? '';
    $index = (int) ($_SERVER['HTTP_X_CHUNK_INDEX'] ?? -1);
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($uploadId === '' || $index < 0) {
        json_fail('分片参数不完整。', 422, 'invalid');
    }
    if ($declared > (int) config('chunk_size', 5 * 1024 * 1024) + 1024) {
        json_fail('分片超过约定大小。', 413, 'chunk_failed');
    }
    $bytes = (string) file_get_contents('php://input');
    try {
        json_ok(transfer_store_chunk_bytes($room, $uploadId, $index, $bytes, $declared));
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 409, 'chunk_failed');
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '分片上传失败，请重试。'), 409, 'chunk_failed');
    }
}

function transfer_upload_complete(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    transfer_require_unlocked($room);
    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';
    if ($uploadId === '') {
        json_fail('缺少上传会话。', 422, 'invalid');
    }
    try {
        json_ok(transfer_upload_finish($room, $uploadId));
    } catch (TransferCapacityException $e) {
        json_fail($e->getMessage(), 409, 'room_full');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 409, 'incomplete');
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '上传处理失败，请重试。'), 409, 'in_progress');
    }
}

function transfer_upload_abort(): void
{
    require_method('POST');
    require_csrf();
    $room = transfer_require_room();
    transfer_require_unlocked($room);
    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';
    if ($uploadId === '') {
        json_fail('缺少上传会话。', 422, 'invalid');
    }
    try {
        transfer_upload_cancel($room, $uploadId);
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '放弃上传失败，请重试。'), 409, 'in_progress');
    }
    json_ok(['aborted' => true]);
}

function transfer_download(): void
{
    $room = transfer_room_by_token((string) input('t', ''));
    if ($room === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('房间不存在或已关闭。');
    }
    if (!transfer_room_unlocked($room)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('需要提取密码。');
    }
    $file = q_one(
        'SELECT * FROM transfer_files WHERE id = ? AND room_id = ?',
        [input_int('id'), (int) $room['id']]
    );
    if ($file === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('文件不存在。');
    }
    transfer_stream($room, $file);
}
```

## Appendix D — `app/views/transfer.php`（首页）

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var bool $enabled */
/** @var bool $localOnly */
$appName = (string) config('app_name');
?>
<div class="tp" data-page="transfer">
    <header class="tp__head">
        <a class="tp__brand" href="<?= e(url('')) ?>">
            <?= lucide('archive', 'tp__mark') ?>
            <span><?= e($appName) ?> · 临时传输</span>
        </a>
        <a class="tp__login" href="<?= e(url('auth.login')) ?>">登录</a>
    </header>

    <main class="tp__main">
        <section class="tp__intro">
            <h1>两台电脑，<br>直接传文件。</h1>
            <p>不用登录。创建一个房间，把链接或 6 位取件码给对方；双方都能上传和下载，24 小时后自动清空。</p>
            <?php if ($localOnly): ?>
                <div class="mwarn">
                    <?= lucide('alert-triangle') ?>
                    <span>你正在用 localhost 访问。这个地址只在本机有效；局域网互传请用服务器的局域网 IP 打开本站再创建房间。</span>
                </div>
            <?php endif; ?>
            <?php if (!$enabled): ?>
                <div class="mwarn"><?= lucide('alert-triangle') ?><span>临时传输当前已关闭。</span></div>
            <?php endif; ?>
        </section>

        <div class="tp__grid">
            <section class="tp__card">
                <h2>创建房间</h2>
                <p class="tp__hint">创建一个双方都能上传/下载的临时空间。</p>
                <label class="mfield">
                    <span class="mfield__label">提取密码（可选）</span>
                    <input class="minput" type="password" data-create-pass maxlength="32" autocomplete="new-password">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-create<?= $enabled ? '' : ' disabled' ?>>创建房间</button>
            </section>

            <section class="tp__card">
                <h2>输入取件码</h2>
                <p class="tp__hint">输入对方给你的 6 位数字。</p>
                <label class="mfield">
                    <span class="mfield__label">取件码</span>
                    <input class="minput tp__code-input" inputmode="numeric" maxlength="6" data-join-code placeholder="000000">
                </label>
                <label class="mfield">
                    <span class="mfield__label">提取密码（如有）</span>
                    <input class="minput" type="password" data-join-pass maxlength="32" autocomplete="off">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-join>进入房间</button>
            </section>
        </div>

        <section class="tp__result" data-create-result hidden>
            <div class="tp__result-main">
                <span class="tp__label">取件码</span>
                <b class="tp__bigcode" data-result-code></b>
                <span class="tp__label">房间链接</span>
                <input class="minput" readonly data-result-link>
                <div class="tp__row">
                    <button class="chipbtn" type="button" data-result-copy><?= lucide('copy', 'chipbtn__ico') ?>复制链接</button>
                    <a class="chipbtn" data-result-enter href="#">进入房间</a>
                </div>
                <p class="tp__hint">链接使用你当前访问的地址；局域网互传请用服务器局域网 IP 打开本站再创建。</p>
            </div>
            <img class="tp__qr" data-result-qr alt="房间二维码">
        </section>
    </main>

    <footer class="tp__foot">
        <span>文件临时保存在本服务器，到期或关闭后删除；管理员理论上可读取内容。</span>
        <span>公网使用请确保站点启用 HTTPS。</span>
    </footer>
</div>

<?php view('partials/modal') ?>
```

## Appendix E — `assets/js/transfer.js`（首页）

```js
/* 拾云 · 临时传输首页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const CFG = window.__SHIYUN__ || {};
    const $ = SY.$;
    const api = SY.api;
    const toast = SY.toast;

    const root = $('[data-page="transfer"]');
    if (!root) return;

    const result = $('[data-create-result]');
    const codeEl = $('[data-result-code]');
    const linkEl = $('[data-result-link]');
    const qrEl = $('[data-result-qr]');
    const enterEl = $('[data-result-enter]');

    function qrUrl(token) {
        return (CFG.base || 'index.php') + '?r=transfer.qr&t=' + encodeURIComponent(token);
    }

    function showResult(data) {
        const token = data.room.token;
        localStorage.setItem('transfer.ck.' + token, data.creator_key);
        codeEl.textContent = data.room.code;
        linkEl.value = data.room.absoluteUrl;
        qrEl.src = qrUrl(token);
        enterEl.href = data.room.url;
        result.hidden = false;
        result.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    $('[data-create]')?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        btn.disabled = true;
        try {
            const data = await api('transfer.create', {
                password: $('[data-create-pass]')?.value || '',
            });
            showResult(data);
            toast('房间已创建');
        } catch (err) {
            toast(err.message, 'err');
        } finally {
            btn.disabled = false;
        }
    });

    $('[data-join]')?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const code = ($('[data-join-code]')?.value || '').trim();
        if (!/^\d{6}$/.test(code)) {
            toast('请输入 6 位取件码', 'err');
            return;
        }
        btn.disabled = true;
        try {
            const data = await api('transfer.join', {
                code: code,
                password: $('[data-join-pass]')?.value || '',
            });
            location.href = data.url;
        } catch (err) {
            toast(err.message, 'err');
            btn.disabled = false;
        }
    });

    $('[data-join-code]')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') $('[data-join]')?.click();
    });

    $('[data-result-copy]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(linkEl.value);
            toast('链接已复制');
        } catch (e) {
            linkEl.select();
            if (document.execCommand) document.execCommand('copy');
        }
    });
})();
```

## Appendix F — `assets/css/transfer.css`

```css
/* 拾云 · 临时传输（公开页） */
.tp {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    padding: 0 clamp(18px, 4vw, 56px);
}

.tp__head {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 22px 0;
    border-bottom: 1px solid var(--line);
}

.tp__brand {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--ink);
    text-decoration: none;
    font-size: 0.82rem;
    letter-spacing: 0.04em;
}

.tp__mark {
    width: 20px;
    height: 20px;
    color: var(--signal-2);
}

.tp__login {
    margin-left: auto;
    color: var(--dim-2);
    font-size: 0.8rem;
    text-decoration: none;
}

.tp__login:hover { color: var(--ink); }

.tp__main {
    width: min(1080px, 100%);
    margin: 0 auto;
    padding: clamp(40px, 7vh, 84px) 0 60px;
}

.tp__intro h1 {
    margin: 0;
    font-size: clamp(2rem, 4.6vw, 3.6rem);
    line-height: 1.04;
    letter-spacing: -0.045em;
    font-family: var(--font-display);
}

.tp__intro p {
    max-width: 52ch;
    margin: 20px 0 0;
    color: var(--dim);
    line-height: 1.7;
    font-size: 0.95rem;
}

.tp__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-top: 40px;
}

.tp__card {
    padding: 24px;
    border: 1px solid var(--line);
    border-radius: 3px;
    background: var(--panel);
}

.tp__card h2 {
    margin: 0 0 6px;
    font-size: 1.05rem;
    letter-spacing: -0.02em;
}

.tp__hint {
    margin: 0 0 18px;
    color: var(--dim-2);
    font-size: 0.8rem;
    line-height: 1.6;
}

.tp__card .mfield { margin-bottom: 14px; }
.tp__card .mbtn { width: 100%; }

.tp__code-input {
    font-family: var(--font-mono);
    font-size: 1.4rem;
    letter-spacing: 0.32em;
    text-align: center;
}

.tp__result {
    display: flex;
    align-items: center;
    gap: 28px;
    margin-top: 28px;
    padding: 24px;
    border: 1px solid var(--line-2);
    border-radius: 3px;
    background: var(--panel);
}

.tp__result-main { flex: 1; min-width: 0; }

.tp__label {
    display: block;
    margin: 0 0 6px;
    color: var(--dim-2);
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.tp__bigcode {
    display: block;
    margin-bottom: 18px;
    font-family: var(--font-mono);
    font-size: clamp(2rem, 5vw, 3rem);
    letter-spacing: 0.24em;
    color: var(--signal-2);
}

.tp__row {
    display: flex;
    gap: 8px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.tp__qr {
    width: 160px;
    height: 160px;
    background: #fff;
    padding: 8px;
    border-radius: 3px;
    flex: none;
}

.tp__foot {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: auto;
    padding: 22px 0;
    border-top: 1px solid var(--line);
    color: var(--dim-2);
    font-size: 0.72rem;
}

/* 房间页 */
.tp-room__head {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.tp-room__code {
    font-family: var(--font-mono);
    font-size: 1.5rem;
    letter-spacing: 0.18em;
    color: var(--signal-2);
}

.tp-room__stats {
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    color: var(--dim-2);
    font-size: 0.78rem;
}

.tp-drop {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 36px 20px;
    border: 1px dashed var(--line-2);
    border-radius: 3px;
    background: var(--panel);
    cursor: pointer;
    text-align: center;
}

.tp-drop.is-hot { border-color: var(--signal-2); color: var(--ink); }
.tp-drop__title { margin: 0; font-size: 0.92rem; }
.tp-drop__hint { margin: 0; color: var(--dim-2); font-size: 0.78rem; }

.tp-queue {
    list-style: none;
    margin: 14px 0 0;
    padding: 0;
    font-size: 0.78rem;
    color: var(--dim);
}

.tp-queue li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--line);
}

.tp-files { margin-top: 26px; }

.tp-files a { color: var(--ink); text-decoration: none; }
.tp-files a:hover { color: var(--signal-2); }

.tp-lock {
    max-width: 360px;
    margin: 10vh auto;
    padding: 26px;
    border: 1px solid var(--line);
    border-radius: 3px;
    background: var(--panel);
}

@media (max-width: 760px) {
    .tp__grid { grid-template-columns: 1fr; }
    .tp__result { flex-direction: column-reverse; align-items: flex-start; }
    .tp__qr { width: 128px; height: 128px; }
}
```

## Appendix G — `app/views/transfer_room.php`

```php
<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var bool $gone */
/** @var bool $locked */
/** @var string $token */
/** @var array|null $room */
/** @var list<array> $files */
/** @var string $roomUrl */
$appName = (string) config('app_name');
?>
<div class="tp" data-page="transfer-room" data-token="<?= e($token) ?>" data-room-url="<?= e($roomUrl) ?>">
    <header class="tp__head">
        <a class="tp__brand" href="<?= e(url('transfer.index')) ?>">
            <?= lucide('archive', 'tp__mark') ?>
            <span><?= e($appName) ?> · 临时传输</span>
        </a>
        <a class="tp__login" href="<?= e(url('transfer.index')) ?>">再建一个</a>
    </header>

    <main class="tp__main">
        <?php if ($gone): ?>
            <section class="tp-lock">
                <h2>房间不存在或已关闭</h2>
                <p class="tp__hint">可能已过期、被创建者关闭，或链接不完整。</p>
                <a class="mbtn mbtn--primary" href="<?= e(url('transfer.index')) ?>">创建一个新房间</a>
            </section>
        <?php elseif ($locked): ?>
            <section class="tp-lock">
                <h2>需要提取密码</h2>
                <p class="tp__hint">这个房间设置了密码，输入后才能查看文件。</p>
                <label class="mfield">
                    <span class="mfield__label">提取密码</span>
                    <input class="minput" type="password" data-unlock-pass maxlength="32" autocomplete="off">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-unlock><?= lucide('unlock', 'mbtn__ico') ?>解锁</button>
            </section>
        <?php else: ?>
            <section class="tp-room__head">
                <div>
                    <span class="tp__label">取件码</span>
                    <b class="tp-room__code"><?= e((string) $room['code']) ?></b>
                </div>
                <div class="tp-room__stats">
                    <span>剩余 <b data-expires></b></span>
                    <span><b data-used></b> / <b data-max></b></span>
                    <span><b data-count></b> 个文件</span>
                </div>
                <div class="tp__row" style="margin-left:auto">
                    <button class="chipbtn" type="button" data-copy-link><?= lucide('copy', 'chipbtn__ico') ?>复制链接</button>
                    <a class="chipbtn" href="?r=transfer.qr&amp;t=<?= e($token) ?>" target="_blank" rel="noopener">二维码</a>
                </div>
            </section>

            <label class="mfield" style="max-width:280px;margin-bottom:16px">
                <span class="mfield__label">你的称呼（可选）</span>
                <input class="minput" data-nickname maxlength="40" placeholder="让主人知道是谁传的">
            </label>

            <div class="tp-drop" data-drop role="button" tabindex="0" aria-label="选择要上传的文件">
                <?= lucide('cloud-upload') ?>
                <p class="tp-drop__title">把文件拖到这里，或点击选择</p>
                <p class="tp-drop__hint">双方都能上传和下载；单个文件不超过房间剩余容量。</p>
                <input type="file" multiple hidden data-file>
            </div>
            <ul class="tp-queue" data-queue></ul>

            <section class="tp-files">
                <div class="panel__head">
                    <h2 class="panel__title">文件</h2>
                    <span class="panel__note" data-files-note>每 3 秒自动刷新</span>
                </div>
                <div class="tblwrap">
                    <table class="tbl">
                        <thead><tr><th>名称</th><th class="num">大小</th><th>上传者</th><th>时间</th><th></th></tr></thead>
                        <tbody data-files></tbody>
                    </table>
                </div>
            </section>

            <div class="tp__row" data-creator-actions hidden style="margin-top:24px">
                <button class="mbtn mbtn--danger" type="button" data-close><?= lucide('trash-2', 'mbtn__ico') ?>关闭房间</button>
                <span class="tp__hint">关闭后链接立即失效，文件会被删除。</span>
            </div>
        <?php endif; ?>
    </main>

    <footer class="tp__foot">
        <span>文件临时保存在本服务器，到期或关闭后删除；管理员理论上可读取内容。</span>
        <span>公网使用请确保站点启用 HTTPS。</span>
    </footer>
</div>

<?php view('partials/modal') ?>
```

## Appendix H — `assets/js/transfer_room.js`

```js
/* 拾云 · 临时传输房间页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const CFG = window.__SHIYUN__ || {};
    const $ = SY.$;
    const api = SY.api;
    const toast = SY.toast;
    const escapeHtml = SY.escapeHtml;
    const humanSize = SY.humanSize;

    const root = $('[data-page="transfer-room"]');
    if (!root) return;

    const token = root.dataset.token || '';
    const roomUrl = root.dataset.roomUrl || location.href;
    const creatorKey = localStorage.getItem('transfer.ck.' + token) || '';
    const isCreator = creatorKey !== '';

    const filesHost = $('[data-files]');
    const queueHost = $('[data-queue]');
    const drop = $('[data-drop]');
    const fileInput = $('[data-file]');
    const nickname = $('[data-nickname]');

    if (nickname) {
        nickname.value = localStorage.getItem('transfer.name') || '';
        nickname.addEventListener('input', () => localStorage.setItem('transfer.name', nickname.value));
    }

    if (isCreator) {
        $('[data-creator-actions]')?.removeAttribute('hidden');
    }

    function downloadUrl(id) {
        return (CFG.base || 'index.php') + '?r=transfer.download&t=' + encodeURIComponent(token) + '&id=' + encodeURIComponent(id);
    }

    function renderFiles(files) {
        if (!filesHost) return;
        if (!files.length) {
            filesHost.innerHTML = '<tr><td colspan="5" class="muted">还没有文件，先传一个吧。</td></tr>';
            return;
        }
        filesHost.innerHTML = files.map((f) => {
            const name = escapeHtml(f.name);
            const who = f.uploader ? '来自 ' + escapeHtml(f.uploader) : '—';
            const del = isCreator
                ? '<button class="minibtn" type="button" data-del="' + Number(f.id) + '" aria-label="删除">' + SY.icon('trash-2') + '</button>'
                : '';
            return '<tr>'
                + '<td><div class="tbl__name">' + SY.icon('file') + '<a href="' + downloadUrl(f.id) + '">' + name + '</a></div></td>'
                + '<td class="num">' + humanSize(f.size) + '</td>'
                + '<td class="muted">' + who + '</td>'
                + '<td class="muted nowrap">' + escapeHtml((f.createdAt || '').slice(0, 16)) + '</td>'
                + '<td><div class="tbl__acts">'
                + '<a class="chipbtn" href="' + downloadUrl(f.id) + '">下载</a>' + del
                + '</div></td></tr>';
        }).join('');
    }

    function renderState(room) {
        if (!room) return;
        const left = Math.max(0, Number(room.expiresIn) || 0);
        const h = Math.floor(left / 3600);
        const m = Math.floor((left % 3600) / 60);
        const el = $('[data-expires]');
        if (el) el.textContent = h > 0 ? h + ' 小时 ' + m + ' 分' : m + ' 分钟';
        const used = $('[data-used]');
        if (used) used.textContent = humanSize(room.usedBytes);
        const max = $('[data-max]');
        if (max) max.textContent = humanSize(room.maxBytes);
        const count = $('[data-count]');
        if (count) count.textContent = String(room.fileCount);
    }

    let lastIds = null;

    async function load() {
        if (!token || document.hidden) return;
        try {
            const data = await api('transfer.files', { t: token }, { method: 'GET' });
            renderState(data.room);
            const ids = (data.files || []).map((f) => f.id).join(',');
            if (ids !== lastIds) {
                lastIds = ids;
                renderFiles(data.files || []);
            }
        } catch (err) {
            if (err.code === 'room_gone' || err.code === 'password_required') {
                location.reload();
            }
        }
    }

    if (filesHost) {
        load();
        setInterval(load, 3000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) load();
        });
    }

    function postChunk(uploadId, index, blob, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', (CFG.base || 'index.php') + '?r=transfer.upload_chunk&t=' + encodeURIComponent(token));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('X-CSRF-Token', CFG.csrf || '');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-Upload-Id', uploadId);
            xhr.setRequestHeader('X-Chunk-Index', String(index));
            xhr.upload.onprogress = (e) => {
                if (onProgress && e.lengthComputable) onProgress(e.loaded);
            };
            xhr.onload = () => {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data.data || {});
                } else {
                    const err = new Error((data && data.error) || ('上传失败（' + xhr.status + '）'));
                    err.code = data && data.code;
                    reject(err);
                }
            };
            xhr.onerror = () => reject(new Error('网络异常，请检查连接。'));
            xhr.send(blob);
        });
    }

    async function uploadOne(file, row) {
        const sender = (nickname?.value || '').trim();
        const init = await api('transfer.upload_init', {
            t: token, name: file.name, size: file.size, sender: sender,
        });
        const size = Number(init.chunkSize) || 5 * 1024 * 1024;
        const total = Number(init.totalChunks) || 1;
        let sent = 0;
        try {
            for (let i = 0; i < total; i++) {
                if (file.size === 0) break;
                const start = i * size;
                const blob = file.slice(start, Math.min(file.size, start + size));
                let ok = false;
                let lastErr = null;
                for (let attempt = 0; attempt < 3 && !ok; attempt++) {
                    try {
                        await postChunk(init.uploadId, i, blob, (loaded) => {
                            row.textContent = file.name + ' · ' + Math.min(99, Math.round((sent + loaded) / file.size * 100)) + '%';
                        });
                        ok = true;
                    } catch (err) {
                        lastErr = err;
                    }
                }
                if (!ok) throw lastErr || new Error('分片上传失败');
                sent += blob.size;
                row.textContent = file.name + ' · ' + Math.min(99, Math.round(sent / file.size * 100)) + '%';
            }
            await api('transfer.upload_complete', { t: token, uploadId: init.uploadId });
            row.textContent = file.name + ' · 完成';
        } catch (err) {
            await api('transfer.upload_abort', { t: token, uploadId: init.uploadId }).catch(() => {});
            row.textContent = file.name + ' · 失败：' + err.message;
            throw err;
        }
    }

    async function uploadFiles(list) {
        if (!list || !list.length) return;
        for (const file of Array.from(list)) {
            const row = document.createElement('li');
            row.textContent = file.name + ' · 准备中';
            queueHost?.appendChild(row);
            try {
                await uploadOne(file, row);
            } catch (err) {
                toast(err.message, 'err');
            }
        }
        lastIds = null;
        load();
        setTimeout(() => { if (queueHost) queueHost.innerHTML = ''; }, 2500);
    }

    fileInput?.addEventListener('change', () => {
        uploadFiles(fileInput.files);
        fileInput.value = '';
    });

    drop?.addEventListener('click', () => fileInput?.click());
    drop?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fileInput?.click();
        }
    });
    document.addEventListener('dragover', (e) => {
        if (!drop) return;
        e.preventDefault();
        drop.classList.add('is-hot');
    });
    document.addEventListener('dragleave', () => drop?.classList.remove('is-hot'));
    document.addEventListener('drop', (e) => {
        if (!drop) return;
        e.preventDefault();
        drop.classList.remove('is-hot');
        if (e.dataTransfer?.files?.length) uploadFiles(e.dataTransfer.files);
    });

    filesHost?.addEventListener('click', async (e) => {
        const del = e.target.closest('[data-del]');
        if (!del || !isCreator) return;
        del.disabled = true;
        try {
            await api('transfer.file_delete', { t: token, creator_key: creatorKey, id: Number(del.dataset.del) });
            toast('已删除');
            lastIds = null;
            load();
        } catch (err) {
            toast(err.message, 'err');
            del.disabled = false;
        }
    });

    $('[data-copy-link]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(roomUrl);
            toast('链接已复制');
        } catch (e) {
            toast('复制失败，请手动选择地址栏', 'err');
        }
    });

    $('[data-close]')?.addEventListener('click', async () => {
        const ok = await SY.confirm('关闭房间', '链接会立即失效，房间内文件将被删除。', '关闭并删除', true);
        if (!ok) return;
        try {
            await api('transfer.close', { t: token, creator_key: creatorKey });
            location.href = (CFG.base || 'index.php') + '?r=transfer.index';
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    $('[data-unlock]')?.addEventListener('click', async () => {
        try {
            await api('transfer.unlock', {
                t: token,
                password: $('[data-unlock-pass]')?.value || '',
            });
            location.reload();
        } catch (err) {
            toast(err.message, 'err');
        }
    });
    $('[data-unlock-pass]')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') $('[data-unlock]')?.click();
    });
})();
```

## Appendix I — `transfer_stream()`（Task 6）

```php
function transfer_stream(array $room, array $file): never
{
    $path = transfer_file_path($room, $file);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $size = (int) filesize($path);
    $start = 0;
    $end = max(0, $size - 1);
    $partial = false;
    $range = transfer_parse_range((string) ($_SERVER['HTTP_RANGE'] ?? ''), $size);
    if ($range !== null) {
        [$start, $end] = $range;
        $partial = true;
    }
    $length = $size === 0 ? 0 : $end - $start + 1;

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($partial ? 206 : 200);
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string) $file['name']));
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    if ($partial) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . $length);

    if ($length === 0) {
        exit;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        exit;
    }
    if ($start > 0) {
        fseek($fh, $start);
    }
    $left = $length;
    while ($left > 0 && !feof($fh)) {
        $buf = fread($fh, min(8192, $left));
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $left -= strlen($buf);
        flush();
    }
    fclose($fh);

    q_exec(
        'UPDATE transfer_rooms SET last_access_at = ? WHERE id = ? AND (last_access_at IS NULL OR last_access_at < ?)',
        [now(), (int) $room['id'], date('Y-m-d H:i:s', time() - 60)]
    );
    exit;
}
```
