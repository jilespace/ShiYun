<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------- 用户读取 */

function find_user_by_email(string $email): ?array
{
    return q_one('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
}

function find_user_by_id(int $id): ?array
{
    return q_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
}

function find_user_by_login(string $login): ?array
{
    return q_one('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1', [$login, $login]);
}

/* ------------------------------------------------------------- 用户创建 */

/**
 * 建号：写入用户 + 初始化个人空间（根目录 / 回收站 / 私密空间）+ 默认设置。
 */
function create_user(string $email, string $password, string $displayName, ?string $username = null): int
{
    $username = $username !== null && $username !== ''
        ? $username
        : unique_username(display_name_from_email($email));

    return db_tx(static function () use ($email, $password, $displayName, $username): int {
        $userId = q_insert('users', [
            'email'         => $email,
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name'  => $displayName,
            'quota_bytes'   => (int) config('default_quota'),
            'used_bytes'    => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        q_insert('user_settings', [
            'user_id'              => $userId,
            'trash_retention_days' => (int) config('trash_retention'),
        ]);

        // 个人空间三根：根目录、回收站、私密空间
        $rootId = create_space_folder($userId, null, '我的文件', '');
        $trashId = create_space_folder($userId, null, '回收站', '', 'trash_root_id');
        $privId = create_space_folder($userId, null, '私密空间', '', 'private_root_id');

        q_update('users', ['root_id' => $rootId, 'trash_root_id' => $trashId, 'private_root_id' => $privId], 'id = ?', [$userId]);

        return $userId;
    });
}

/**
 * 建一个空间根目录节点（我的文件 / 回收站 / 私密空间）。
 * path 为 "/{id}/"，使其子节点路径形如 "/{根}/{子}/"，子树前缀匹配与面包屑都依赖这个形状。
 */
function create_space_folder(int $userId, ?int $parentId, string $name, string $unused = ''): int
{
    $id = q_insert('nodes', [
        'user_id'    => $userId,
        'parent_id'  => $parentId,
        'name'       => $name,
        'name_key'   => mb_strtolower($name),
        'kind'       => 'folder',
        'size'       => 0,
        'path'       => '',
        'depth'      => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    q_update('nodes', ['path' => '/' . $id . '/'], 'id = ?', [$id]);

    return $id;
}

/** 昵称转用户名，冲突则加后缀 */
function unique_username(string $base): string
{
    $base = preg_replace('/[^A-Za-z0-9_.-]/', '', $base) ?? '';
    $base = trim($base, '._-');
    if (mb_strlen($base) < 3) {
        $base = 'user' . $base;
    }
    $base = mb_substr($base, 0, 40);

    $candidate = $base;
    for ($i = 2; $i < 500; $i++) {
        if (q_val('SELECT id FROM users WHERE username = ?', [$candidate]) === null) {
            return $candidate;
        }
        $candidate = $base . $i;
    }
    return $base . random_token(3);
}

/* ------------------------------------------------------------- 用户设置 */

function user_settings(int $userId): array
{
    $row = q_one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
    if ($row === null) {
        q_insert('user_settings', ['user_id' => $userId, 'trash_retention_days' => (int) config('trash_retention')]);
        $row = q_one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]) ?? [];
    }
    return $row;
}

function user_setting(int $userId, string $key, mixed $default = null): mixed
{
    return user_settings($userId)[$key] ?? $default;
}

function save_user_settings(int $userId, array $patch): void
{
    user_settings($userId); // 确保存在
    if ($patch === []) {
        return;
    }
    q_update('user_settings', $patch, 'user_id = ?', [$userId]);
}

/* ------------------------------------------------------------- 空间根目录 */

function user_root_id(int $userId): int
{
    return (int) q_val('SELECT root_id FROM users WHERE id = ?', [$userId]);
}

function user_trash_id(int $userId): int
{
    return (int) q_val('SELECT trash_root_id FROM users WHERE id = ?', [$userId]);
}

function user_private_id(int $userId): int
{
    return (int) q_val('SELECT private_root_id FROM users WHERE id = ?', [$userId]);
}

/* ------------------------------------------------------------- 容量统计 */

function user_recompute_usage(int $userId): int
{
    // 回收站与私密空间同样占用配额，避免用删除来绕过容量限制
    $nodeBytes = (int) q_val(
        'SELECT COALESCE(SUM(size), 0) FROM nodes WHERE user_id = ? AND kind = ?',
        [$userId, 'file']
    );
    // 历史版本同样占盘，必须计入；否则反复覆盖上传可无限占用磁盘而不涨配额
    $versionBytes = (int) q_val(
        'SELECT COALESCE(SUM(v.size), 0) FROM node_versions v
         JOIN nodes n ON n.id = v.node_id WHERE n.user_id = ?',
        [$userId]
    );
    $used = $nodeBytes + $versionBytes;
    q_update('users', ['used_bytes' => $used], 'id = ?', [$userId]);
    return $used;
}

function user_add_usage(int $userId, int $delta): void
{
    q_exec(
        'UPDATE users SET used_bytes = GREATEST(0, CAST(used_bytes AS SIGNED) + ?) WHERE id = ?',
        [$delta, $userId]
    );
}

/**
 * 已收但尚未合并的分片占用的磁盘。
 *
 * 这些字节躺在 runtime/chunks 里，不计入 users.used_bytes，却实实在在占着盘。
 * 不把它们算进配额，就能靠「反复初始化分片会话、永不合并」把磁盘写满而配额毫不知情。
 * status='merging' 的会话已停止累积（正在合并），不计入。
 */
function in_flight_chunk_bytes(int $userId): int
{
    return (int) q_val(
        "SELECT COALESCE(SUM(received_bytes), 0) FROM upload_sessions
         WHERE user_id = ? AND status IN ('pending', 'uploading')",
        [$userId]
    );
}

/**
 * 原子地占用配额：够则加、不够则不加，返回是否成功。
 *
 * 与 assert_quota() 的区别是「先写后判」——条件写在 UPDATE 的 WHERE 里，
 * 并发的两个请求不可能同时通过。assert_quota() 是先看后写，只适合当作
 * 动手之前的友好预检。
 */
function quota_claim(int $userId, int $bytes): bool
{
    if ($bytes <= 0) {
        return true;
    }
    return q_exec(
        'UPDATE users SET used_bytes = used_bytes + ?
         WHERE id = ? AND (quota_bytes = 0 OR used_bytes + ? <= quota_bytes)',
        [$bytes, $userId, $bytes]
    ) === 1;
}

/** 剩余空间不足则抛错（软预检；真正的把关在 quota_claim()） */
function assert_quota(int $userId, int $incomingBytes): void
{
    $u = find_user_by_id($userId);
    if ($u === null) {
        throw new RuntimeException('用户不存在');
    }
    $quota = (int) $u['quota_bytes'];
    if ($quota <= 0) {
        return;
    }
    $used = (int) $u['used_bytes'] + in_flight_chunk_bytes($userId);
    if ($used + $incomingBytes > $quota) {
        throw new RuntimeException('存储空间不足，请清理文件或扩容。');
    }
}

/* ------------------------------------------------------------- 密码 */

function change_password(int $userId, string $newPassword): void
{
    q_update('users', [
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'updated_at'    => now(),
    ], 'id = ?', [$userId]);
}

/**
 * 让该账号在其它设备上的会话失效。
 *
 * 做法是把 users.session_epoch 加一：每个会话在登录时记下当时的纪元，
 * Auth::user() 每次都会比对，对不上就登出。当前这个会话要留在登录态，
 * 所以同步把 $_SESSION 里的纪元也改成新值——否则用户改完密码先把自己踢下线。
 */
function revoke_other_sessions(int $userId): void
{
    q_exec('UPDATE users SET session_epoch = session_epoch + 1 WHERE id = ?', [$userId]);
    $_SESSION['auth_epoch'] = (int) q_val('SELECT session_epoch FROM users WHERE id = ?', [$userId]);
    Auth::refresh();
}
