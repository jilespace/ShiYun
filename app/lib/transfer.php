<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   免登录临时传输的领域逻辑。
   房间/文件只存在 runtime/transfer/ 下，不进 nodes/blobs。
   ========================================================================= */

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
