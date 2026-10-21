<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 全局 PDO 单例（MySQL） */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = (array) config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int) $c['port'],
        $c['name'],
        $c['charset']
    );

    $pdo = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $tz = new DateTimeZone((string) config('timezone', 'Asia/Shanghai'));
    $pdo->exec('SET time_zone = ' . $pdo->quote((new DateTime('now', $tz))->format('P')));

    db_migrate($pdo);

    return $pdo;
}

/**
 * 当前库的结构快照：列（含类型）与索引，键形如 "col:users.totp_last_step"。
 * 一次 information_schema 查询取回，供 db_migrate() 逐条判断缺什么。
 *
 * @param list<string> $tables 只取这些表；空数组表示全部
 * @return array{0:array<string,string>,1:list<string>} [快照, 实际存在的表名]
 */
function db_schema_snapshot(PDO $pdo, array $tables = []): array
{
    $filter = '';
    if ($tables !== []) {
        // 表名来自结构声明里的 \w+，不含可注入字符
        $filter = ' AND TABLE_NAME IN (' . implode(',', array_map(
            static fn (string $t): string => "'" . $t . "'",
            $tables
        )) . ')';
    }

    $snapshot = [];
    $present = [];
    $rows = $pdo->query(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS n, COLUMN_TYPE AS ty, 'col' AS k
           FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE(){$filter}
         UNION ALL
         SELECT TABLE_NAME, INDEX_NAME, '', 'idx'
           FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE(){$filter}"
    )->fetchAll();
    foreach ($rows as $r) {
        $snapshot[$r['k'] . ':' . $r['t'] . '.' . $r['n']] = (string) $r['ty'];
        $present[(string) $r['t']] = true;
    }
    return [$snapshot, array_keys($present)];
}

/**
 * 补齐数据库结构。
 *
 * 这里**没有**「版本号够了就整个跳过」的闸门，是刻意的。那种闸门踩过一次坑：
 * 版本号定义在 schema.php、补列语句写在 db.php，两个文件一旦不同步
 * （opcache 还在发旧的 db.php，或者加了 ALTER 却忘了把版本号加一），
 * 补列会被永久跳过，而 app_meta 里记着「已是最新」——库里就永远少那么几列，
 * 直到某个请求撞上 Unknown column 报 500。
 *
 * 所以改成：建表只在真有表缺失时才跑（那二十来条 CREATE TABLE 实测约 90ms，
 * 不能每条请求都执行），补列/补索引则每次请求都核对一遍——它们本身幂等，
 * 核对代价是一次 information_schema 查询。正确性优先于这几毫秒。
 */
function db_migrate(PDO $pdo): void
{
    $ddl = schema_statements();

    // 期望有哪些表，直接从结构声明里抠出来，新增表不必另外登记
    $expected = [];
    foreach ($ddl as $stmt) {
        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $stmt, $m) === 1) {
            $expected[] = $m[1];
        }
    }

    // 先用一次 SELECT 探一下 app_meta 在不在。在的话就只花这一次读——
    // 直接写 CREATE TABLE IF NOT EXISTS 的话，每条请求都要付一次 DDL（实测约 3ms）。
    try {
        $stmt = $pdo->prepare('SELECT v FROM app_meta WHERE k = ?');
        $stmt->execute(['schema_version']);
    } catch (PDOException $e) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS app_meta (
                k VARCHAR(64) NOT NULL,
                v VARCHAR(255) NOT NULL,
                PRIMARY KEY (k)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $stmt = $pdo->prepare('SELECT v FROM app_meta WHERE k = ?');
        $stmt->execute(['schema_version']);
    }
    $storedVersion = (string) ($stmt->fetchColumn() ?: '');

    [$snapshot, $tables] = db_schema_snapshot($pdo, $expected);

    if (array_diff($expected, $tables) !== []) {
        foreach ($ddl as $stmt2) {
            $pdo->exec($stmt2);
        }
        // 新表带着完整结构，快照要重取，否则下面会把已有的列当成缺失再 ADD 一次
        [$snapshot] = db_schema_snapshot($pdo, $expected);
    }

    $hasCol = static fn (string $t, string $c): bool => isset($snapshot['col:' . $t . '.' . $c]);
    $colType = static fn (string $t, string $c): string => $snapshot['col:' . $t . '.' . $c] ?? '';
    $hasIdx = static fn (string $t, string $i): bool => isset($snapshot['idx:' . $t . '.' . $i]);

    // ------------------------------------------------------------------ 账户
    if (!$hasCol('users', 'totp_secret')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER private_hash');
    }
    if (!$hasCol('users', 'totp_enabled')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret');
    }
    // 改密 / 改两步验证时加一，使其它会话失效
    if (!$hasCol('users', 'session_epoch')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN session_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_enabled');
    }
    // 两步验证码的防重放游标
    if (!$hasCol('users', 'totp_last_step')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER session_epoch');
    }

    // ------------------------------------------------------------ 文件与目录
    // nodes.source_id 已被 blob_locations 取代（node 级标记在去重下语义错误）
    if ($hasCol('nodes', 'source_id')) {
        $pdo->exec('ALTER TABLE nodes DROP COLUMN source_id');
    }
    // 冷数据识别：最近一次被预览/下载的时间
    if (!$hasCol('nodes', 'last_access_at')) {
        $pdo->exec('ALTER TABLE nodes ADD COLUMN last_access_at DATETIME NULL AFTER updated_at');
    }
    // CREATE TABLE IF NOT EXISTS 不会给已存在的表补索引，所以索引都要单独判断
    if (!$hasIdx('nodes', 'idx_node_created')) {
        $pdo->exec('ALTER TABLE nodes ADD INDEX idx_node_created (user_id, created_at)');
    }
    if (!$hasIdx('nodes', 'idx_node_access')) {
        $pdo->exec('ALTER TABLE nodes ADD INDEX idx_node_access (user_id, last_access_at)');
    }
    if (!$hasIdx('nodes', 'idx_node_size')) {
        $pdo->exec('ALTER TABLE nodes ADD INDEX idx_node_size (user_id, size)');
    }

    // -------------------------------------------------------------- 存储底层
    // 照片指纹与拍摄信息（相册智能整理）
    if (!$hasCol('blobs', 'phash')) {
        $pdo->exec('ALTER TABLE blobs ADD COLUMN phash BIGINT NULL AFTER ref_count');
    }
    if (!$hasCol('blobs', 'taken_at')) {
        $pdo->exec('ALTER TABLE blobs ADD COLUMN taken_at DATETIME NULL AFTER phash');
    }
    if (!$hasCol('blobs', 'camera')) {
        $pdo->exec('ALTER TABLE blobs ADD COLUMN camera VARCHAR(120) NULL AFTER taken_at');
    }

    // ---------------------------------------------------------------- 版本
    // (node_id, version_no) 升级为唯一键。老库里若已有重复版本，加键会失败——
    // 记一笔跳过即可，取号处的行锁已经能防住新的重复。
    if (!$hasIdx('node_versions', 'uk_ver_node')) {
        try {
            $pdo->exec('ALTER TABLE node_versions ADD UNIQUE KEY uk_ver_node (node_id, version_no)');
            if ($hasIdx('node_versions', 'idx_ver_node')) {
                $pdo->exec('ALTER TABLE node_versions DROP INDEX idx_ver_node');
            }
        } catch (Throwable $e) {
            error_log('node_versions 唯一键未建立（可能存在历史重复版本）：' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------ 传输与任务
    // transfer_tasks.kind 扩充 push / purge 两种类型
    if (!str_contains($colType('transfer_tasks', 'kind'), 'push')) {
        $pdo->exec("ALTER TABLE transfer_tasks MODIFY COLUMN kind
            ENUM('offline','bt','archive','copy','move','download','push','purge') NOT NULL");
    }
    // upload_sessions：目标存储源；公开收取箱的分片会话
    if (!$hasCol('upload_sessions', 'source_id')) {
        $pdo->exec('ALTER TABLE upload_sessions ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER parent_id');
    }
    if (!$hasCol('upload_sessions', 'share_id')) {
        $pdo->exec('ALTER TABLE upload_sessions ADD COLUMN share_id BIGINT UNSIGNED NULL AFTER source_id');
    }
    // user_settings：记住上次选择的上传目标存储源（0 = 仅本地）
    if (!$hasCol('user_settings', 'upload_source_id')) {
        $pdo->exec('ALTER TABLE user_settings ADD COLUMN upload_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER notify_login');
    }

    // ------------------------------------------------------------------ 分享
    // 收取箱（只收不发）
    if (!$hasCol('shares', 'inbox_note')) {
        $pdo->exec('ALTER TABLE shares ADD COLUMN inbox_note VARCHAR(500) NULL AFTER view_count');
    }
    if (!$hasCol('shares', 'max_files')) {
        $pdo->exec('ALTER TABLE shares ADD COLUMN max_files INT NULL AFTER inbox_note');
    }
    if (!$hasCol('shares', 'upload_count')) {
        $pdo->exec('ALTER TABLE shares ADD COLUMN upload_count INT NOT NULL DEFAULT 0 AFTER max_files');
    }
    if (!str_contains($colType('shares', 'permission'), 'inbox')) {
        $pdo->exec("ALTER TABLE shares MODIFY COLUMN permission
            ENUM('preview','download','upload','inbox') NOT NULL DEFAULT 'download'");
    }

    // ------------------------------------------------------------------ 日志
    // 定时清理按时间删，没有索引就得全表扫
    if (!$hasIdx('activities', 'idx_act_created')) {
        $pdo->exec('ALTER TABLE activities ADD INDEX idx_act_created (created_at)');
    }
    if (!$hasIdx('login_logs', 'idx_login_created')) {
        $pdo->exec('ALTER TABLE login_logs ADD INDEX idx_login_created (created_at)');
    }

    // 记一个版本号，纯供人查看（判断要不要迁移靠上面的快照，不看它）。
    // 值没变就不写——否则每条请求都要做一次写操作。
    if ($storedVersion !== (string) SCHEMA_VERSION) {
        $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute(['schema_version', (string) SCHEMA_VERSION]);
    }
}

/* --------------------------------------------------------------- 查询封装 */

/** 取一行 */
function q_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** 取多行 */
function q_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** 取单值 */
function q_val(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

/** 执行写入，返回受影响行数 */
function q_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/** 插入并返回自增 ID */
function q_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(static fn ($c) => "`{$c}`", $cols)),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}

/** 按主键更新 */
function q_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = implode(', ', array_map(static fn ($c) => "`{$c}` = ?", array_keys($data)));
    $sql = "UPDATE {$table} SET {$sets} WHERE {$where}";
    $stmt = db()->prepare($sql);
    $stmt->execute([...array_values($data), ...$whereParams]);
    return $stmt->rowCount();
}

/**
 * 事务包裹；回调抛异常则回滚。
 * 支持嵌套调用（内层用 SAVEPOINT），因为像 node_purge → blob_release 这样的组合
 * 会自然地形成嵌套事务。
 */
function db_tx(callable $fn): mixed
{
    static $depth = 0;
    $pdo = db();

    if ($depth > 0) {
        $depth++;
        $savepoint = 'sp' . $depth;
        $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $fn($pdo);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            return $result;
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            throw $e;
        } finally {
            $depth--;
        }
    }

    $pdo->beginTransaction();
    $depth++;
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $depth--;
    }
}
