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
    $used = (int) q_val(
        'SELECT COALESCE(SUM(size), 0) FROM nodes WHERE user_id = ? AND kind = ?',
        [$userId, 'file']
    );
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

/** 剩余空间不足则抛错 */
function assert_quota(int $userId, int $incomingBytes): void
{
    $u = find_user_by_id($userId);
    if ($u === null) {
        throw new RuntimeException('用户不存在');
    }
    $quota = (int) $u['quota_bytes'];
    if ($quota > 0 && (int) $u['used_bytes'] + $incomingBytes > $quota) {
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

/** 修改密码后使其他会话失效 */
function revoke_other_sessions(int $userId): void
{
    $_SESSION['auth_epoch'] = time();
}
