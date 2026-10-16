<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   内容寻址存储：物理文件以 SHA-256 命名并去重，blobs 表用引用计数管理生命周期。
   同一份内容被多个节点引用时只落一份盘，这是「秒传」的基础。
   ========================================================================= */

function storage_root(): string
{
    $root = (string) config('storage_path');
    if (!is_dir($root)) {
        @mkdir($root, 0700, true);
    }
    return $root;
}

/** 按哈希分片，避免单目录文件过多 */
function blob_path(string $hash): string
{
    return storage_root() . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash;
}

/** 查询已存在的内容（秒传用） */
function blob_find(string $hash): ?array
{
    return q_one('SELECT * FROM blobs WHERE hash = ? LIMIT 1', [$hash]);
}

/**
 * 取得 blob 的引用（存在则复用，不存在则登记），返回 blob id。
 */
function blob_acquire(string $hash, int $size, ?string $mime = null): int
{
    $existing = blob_find($hash);
    if ($existing !== null) {
        q_exec('UPDATE blobs SET ref_count = ref_count + 1 WHERE id = ?', [(int) $existing['id']]);
        return (int) $existing['id'];
    }

    return (int) db_tx(static function () use ($hash, $size, $mime): int {
        return q_insert('blobs', [
            'hash'       => $hash,
            'size'       => $size,
            'mime'       => $mime,
            'ref_count'  => 1,
            'created_at' => now(),
        ]);
    });
}

/** 释放一次引用；归零时删除本地文件，并把远程副本的清理入队 */
function blob_release(?int $blobId): void
{
    if ($blobId === null || $blobId <= 0) {
        return;
    }

    $result = db_tx(static function () use ($blobId): ?array {
        $row = q_one('SELECT hash, ref_count FROM blobs WHERE id = ? FOR UPDATE', [$blobId]);
        if ($row === null) {
            return null;
        }
        if ((int) $row['ref_count'] > 1) {
            q_exec('UPDATE blobs SET ref_count = ref_count - 1 WHERE id = ?', [$blobId]);
            return null;
        }

        // 引用归零：记下所有远程位置，再连同本地一起清掉
        $locations = blob_locations_of_blob($blobId);
        foreach ($locations as $loc) {
            blob_location_forget((int) $loc['id']);
        }
        q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);

        return ['hash' => (string) $row['hash'], 'locations' => $locations];
    });

    if ($result === null) {
        return;
    }

    $path = blob_path($result['hash']);
    if (is_file($path)) {
        @unlink($path);
    }

    // 远程删除走队列：清空回收站可能有几十个 COS 对象，同步做会卡住请求
    foreach ($result['locations'] as $loc) {
        $sid = (int) $loc['source_id'];
        $owner = (int) q_val('SELECT user_id FROM storage_sources WHERE id = ?', [$sid]);
        if ($owner <= 0) {
            continue;
        }
        q_insert('transfer_tasks', [
            'user_id'    => $owner,
            'kind'       => 'purge',
            'title'      => '清理 ' . mb_substr((string) $loc['object_key'], -16),
            'payload'    => json_encode([
                'source_id'  => $sid,
                'object_key' => (string) $loc['object_key'],
            ], JSON_UNESCAPED_UNICODE),
            'progress'   => 0,
            'status'     => 'queued',
            'priority'   => 0,
            'created_at' => now(),
        ]);
    }
}

/** 打开 blob 供读取 */
function blob_open(int $blobId)
{
    $row = q_one('SELECT hash FROM blobs WHERE id = ?', [$blobId]);
    if ($row === null) {
        return null;
    }
    $path = blob_path((string) $row['hash']);
    if (!is_file($path)) {
        return null;
    }
    $fh = @fopen($path, 'rb');
    return $fh === false ? null : $fh;
}

function blob_size(int $blobId): int
{
    return (int) q_val('SELECT size FROM blobs WHERE id = ?', [$blobId]);
}

/* -------------------------------------------------------------- 写入路径 */

/**
 * 把一个本地临时文件收进内容仓（按内容哈希去重）。
 *
 * @return array{hash:string,size:int,blob_id:int,dedup:bool}
 */
function blob_ingest_file(string $tmpPath, ?string $knownHash = null, ?string $mime = null): array
{
    if (!is_file($tmpPath)) {
        throw new RuntimeException('待入库文件不存在');
    }

    $size = (int) filesize($tmpPath);
    $hash = $knownHash !== null && preg_match('/^[0-9a-f]{64}$/', $knownHash) === 1
        ? $knownHash
        : hash_file('sha256', $tmpPath);

    if ($hash === false) {
        throw new RuntimeException('计算文件指纹失败');
    }

    // 内容已存在：秒传，丢弃临时文件
    $existing = blob_find($hash);
    if ($existing !== null && is_file(blob_path($hash))) {
        @unlink($tmpPath);
        q_exec('UPDATE blobs SET ref_count = ref_count + 1 WHERE id = ?', [(int) $existing['id']]);
        return ['hash' => $hash, 'size' => (int) $existing['size'], 'blob_id' => (int) $existing['id'], 'dedup' => true];
    }

    $dest = blob_path($hash);
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建存储目录');
    }

    if (is_file($dest)) {
        @unlink($tmpPath);
    } elseif (!@rename($tmpPath, $dest)) {
        if (!@copy($tmpPath, $dest)) {
            throw new RuntimeException('写入存储仓失败');
        }
        @unlink($tmpPath);
    }
    @chmod($dest, 0600);

    $blobId = blob_acquire($hash, $size, $mime);

    return ['hash' => $hash, 'size' => $size, 'blob_id' => $blobId, 'dedup' => false];
}

/* -------------------------------------------------------------- 推送副本 */

/**
 * 把一份 blob 推送到指定存储源。幂等：已同步则跳过。
 * 永不抛异常——本地那份始终是好的，推送失败只意味着少了一份副本。
 *
 * @return array{ok:bool,skipped:bool,error:string}
 */
function blob_push_to_source(int $blobId, int $sourceId): array
{
    $source = q_one('SELECT * FROM storage_sources WHERE id = ?', [$sourceId]);
    if ($source === null) {
        return ['ok' => false, 'skipped' => false, 'error' => '存储源不存在'];
    }

    $blob = q_one('SELECT * FROM blobs WHERE id = ?', [$blobId]);
    if ($blob === null) {
        return ['ok' => false, 'skipped' => false, 'error' => '内容不存在'];
    }

    $existing = blob_location_find($blobId, $sourceId);
    if ($existing !== null && (string) $existing['status'] === 'ok') {
        return ['ok' => true, 'skipped' => true, 'error' => ''];
    }

    $hash = (string) $blob['hash'];
    $path = blob_path($hash);
    if (!is_file($path)) {
        blob_location_mark_failed($blobId, $sourceId, '本地副本不存在');
        return ['ok' => false, 'skipped' => false, 'error' => '本地副本不存在'];
    }

    $key = substr($hash, 0, 2) . '/' . $hash;
    blob_location_record($blobId, $sourceId, $key, (int) $blob['size']);

    try {
        $storedKey = storage_source_put($source, $hash, $path);
        blob_location_mark_ok($blobId, $sourceId, $storedKey);
        return ['ok' => true, 'skipped' => false, 'error' => ''];
    } catch (Throwable $e) {
        blob_location_mark_failed($blobId, $sourceId, $e->getMessage());
        return ['ok' => false, 'skipped' => false, 'error' => $e->getMessage()];
    }
}

/* ------------------------------------------------------------ 下载分发 */

/**
 * 若该内容在某个 s3 源上有可用副本，返回预签名直链；否则返回 null。
 *
 * HEAD 失败即把 location 标为 stale，之后直接跳过——否则 COS 挂掉时每次下载
 * 都要白等一次超时。
 */
function blob_direct_url(int $userId, int $blobId, string $mime, string $filename): ?string
{
    $loc = blob_location_direct($userId, $blobId);
    if ($loc === null) {
        return null;
    }

    $config = json_decode((string) $loc['config'], true) ?: [];
    $config['bucket'] = (string) ($config['bucket'] ?? '');
    $config['access_key'] = (string) ($config['access_key'] ?? '');
    $config['secret_key'] = (string) ($config['secret_key'] ?? '');

    $head = s3_request($config, 'HEAD', (string) $loc['object_key'], [
        'payload_hash' => hash('sha256', ''),
        'timeout'      => 3,
    ]);
    if ($head['status'] < 200 || $head['status'] >= 400) {
        blob_location_mark_stale((int) $loc['id'], 'HEAD ' . $head['status']
            . ($head['status'] === 0 && $head['body'] !== '' ? '（' . mb_substr((string) $head['body'], 0, 160) . '）' : ''));
        return null;
    }

    return s3_presign(
        $config,
        'GET',
        (string) $loc['object_key'],
        (int) config('presign_ttl', 7200),
        [
            'response-content-disposition' =>
                'attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'response-content-type' => $mime,
        ]
    );
}

/**
 * 下载分发：命中可用直链则 302 到 COS，否则回退本地流式。
 * 调用方需自行完成鉴权与计数。
 */
function blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0): never
{
    $url = blob_direct_url($userId, $blobId, $mime, $filename);
    if ($url !== null) {
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Location: ' . $url, true, 302);
        }
        exit;
    }

    blob_stream($blobId, $mime, $filename, false, $speedLimitKB);
}

/* -------------------------------------------------------------- 输出路径 */

/**
 * 带 Range 支持地输出 blob（视频/音频拖动进度条需要）。
 * 会按 speedLimitKB 限速（0 = 不限）。
 */
function blob_stream(int $blobId, string $mime, string $filename, bool $inline, int $speedLimitKB = 0): never
{
    $row = q_one('SELECT hash, size FROM blobs WHERE id = ?', [$blobId]);
    if ($row === null) {
        http_response_code(404);
        exit;
    }

    $path = blob_path((string) $row['hash']);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $size = (int) filesize($path);
    $start = 0;
    $end = $size - 1;
    $partial = false;

    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m) === 1) {
        if ($m[1] !== '') {
            $start = (int) $m[1];
        }
        if ($m[2] !== '') {
            $end = (int) $m[2];
        }
        if ($m[1] === '' && $m[2] !== '') {   // 后缀范围 bytes=-500
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        }
        $start = max(0, min($start, $size - 1));
        $end = max($start, min($end, $size - 1));
        $partial = true;
    }

    $length = $end - $start + 1;

    if (!headers_sent()) {
        header_remove('Content-Type');
        http_response_code($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="'
            . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, max-age=0, must-revalidate');
        if ($partial) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
    }

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        exit;
    }
    fseek($fh, $start);

    // 关掉输出缓冲，边读边发
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @set_time_limit(0);

    $chunk = 256 * 1024;
    $sent = 0;
    $throttleFrom = microtime(true);
    $throttleBytes = 0;

    while (!feof($fh) && $sent < $length) {
        $read = (int) min($chunk, $length - $sent);
        $buf = fread($fh, $read);
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $sent += strlen($buf);
        flush();

        if ($speedLimitKB > 0) {
            $throttleBytes += strlen($buf);
            $elapsed = microtime(true) - $throttleFrom;
            $expected = $throttleBytes / ($speedLimitKB * 1024);
            if ($expected > $elapsed) {
                usleep((int) (($expected - $elapsed) * 1_000_000));
            }
            if ($throttleBytes > 4 * 1024 * 1024) {
                $throttleBytes = 0;
                $throttleFrom = microtime(true);
            }
        }

        if (connection_aborted()) {
            break;
        }
    }

    fclose($fh);
    exit;
}

/* -------------------------------------------------------------- 分片暂存 */

function chunk_dir(string $uploadId): string
{
    $dir = (string) config('chunk_path') . '/' . substr($uploadId, 0, 2) . '/' . $uploadId;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function chunk_remove_dir(string $uploadId): void
{
    $dir = chunk_dir($uploadId);
    if (!is_dir($dir)) {
        return;
    }
    foreach ((array) glob($dir . '/*') as $file) {
        @unlink((string) $file);
    }
    @rmdir($dir);
}

/* -------------------------------------------------------------- 缩略图 */

function thumb_dir(): string
{
    $dir = (string) config('thumb_path');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function thumb_path(string $hash): string
{
    return thumb_dir() . '/' . substr($hash, 0, 2) . '/' . $hash . '.jpg';
}

/** 用 GD 生成图片缩略图；失败返回 null */
function make_thumbnail(string $sourcePath, string $hash, int $max = 480): ?string
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $info = @getimagesize($sourcePath);
    if ($info === false) {
        return null;
    }
    [$w, $h] = $info;
    if ($w < 1 || $h < 1) {
        return null;
    }

    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
        IMAGETYPE_GIF  => @imagecreatefromgif($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
        IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($sourcePath) : null,
        default        => null,
    };
    if ($src === false || $src === null) {
        return null;
    }

    $scale = min(1.0, $max / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));

    $dst = imagecreatetruecolor($tw, $th);
    // 保留 PNG/GIF 透明通道
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $tw, $th, $transparent);
    imagealphablending($dst, true);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

    $out = thumb_path($hash);
    $dir = dirname($out);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }

    $ok = @imagejpeg($dst, $out, 82);

    return $ok ? $out : null;
}
