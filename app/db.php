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

/** 按 schema 版本补建缺失的表 */
function db_migrate(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_meta (
            k VARCHAR(64) NOT NULL,
            v VARCHAR(255) NOT NULL,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $stmt = $pdo->prepare('SELECT v FROM app_meta WHERE k = ?');
    $stmt->execute(['schema_version']);
    $current = (int) ($stmt->fetchColumn() ?: 0);

    if ($current >= SCHEMA_VERSION) {
        return;
    }

    foreach (schema_statements() as $ddl) {
        $pdo->exec($ddl);
    }

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

    // upload_sessions 记录目标存储源
    $usCols = array_column($pdo->query('SHOW COLUMNS FROM upload_sessions')->fetchAll(), 'Field');
    if (!in_array('source_id', $usCols, true)) {
        $pdo->exec('ALTER TABLE upload_sessions ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER parent_id');
    }

    // user_settings 记住上次选择的上传目标存储源（0 = 仅本地）
    $usetCols = array_column($pdo->query('SHOW COLUMNS FROM user_settings')->fetchAll(), 'Field');
    if (!in_array('upload_source_id', $usetCols, true)) {
        $pdo->exec('ALTER TABLE user_settings ADD COLUMN upload_source_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER notify_login');
    }

    // nodes.source_id 已被 blob_locations 取代（node 级标记在去重下语义错误）。
    // 放在这里而不是 Task 1：sources.php 直到本任务才停止使用该列。
    $hasOld = $pdo->query("SHOW COLUMNS FROM nodes LIKE 'source_id'")->fetch();
    if ($hasOld !== false) {
        $pdo->exec('ALTER TABLE nodes DROP COLUMN source_id');
    }

    $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute(['schema_version', (string) SCHEMA_VERSION]);
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
