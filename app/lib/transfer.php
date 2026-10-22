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

    $chunkSize = max(1, (int) config('chunk_size', 5 * 1024 * 1024));
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
