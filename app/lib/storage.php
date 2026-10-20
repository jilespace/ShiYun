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
function blob_direct_url(int $userId, int $blobId, string $mime, string $filename, string $disposition = 'attachment'): ?string
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
                $disposition . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'response-content-type' => $mime,
        ]
    );
}

/**
 * 下载分发：命中可用直链则 302 到 COS，否则回退本地流式。
 * 调用方需自行完成鉴权与计数。
 */
function blob_deliver(int $userId, int $blobId, string $mime, string $filename, int $speedLimitKB = 0, string $disposition = 'attachment'): never
{
    $url = blob_direct_url($userId, $blobId, $mime, $filename, $disposition);
    if ($url !== null) {
        if (!headers_sent()) {
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Location: ' . $url, true, 302);
        }
        exit;
    }

    blob_stream($blobId, $mime, $filename, $disposition === 'inline', $speedLimitKB);
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

    // 调用方传 inline=true 不代表就能内联：text/html、image/svg+xml 这类
    // 会在本域名下执行脚本。白名单不放行的一律降级为下载。
    $inline = $inline && mime_inline_ok($mime);

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

    // HEAD 只要元信息：头部已经发完，不读文件
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
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

/**
 * 缩略图缓存版本。改动缩略图生成逻辑（例如下面新增的 EXIF 方向校正）时必须加一，
 * 否则旧缓存会继续以错误的方向命中 —— 缓存键就是 blobs.hash，不看生成逻辑。
 */
const THUMB_REVISION = 2;

function thumb_path(string $hash): string
{
    return thumb_dir() . '/' . substr($hash, 0, 2) . '/' . $hash . '-r' . THUMB_REVISION . '.jpg';
}

/**
 * 读取 JPEG 的 EXIF Orientation（1–8）；读不到返回 1（即「无需旋转」）。
 *
 * 本机没有 exif 扩展（php -m 里只有 gd），所以按 JPEG 段结构自己走一遍：
 * APP1 段以 "Exif\0\0" 开头，随后是 TIFF 头，IFD0 里找 0x0112 标签。
 * 手机竖拍照片靠的就是这个标签，GD 不读它，缩略图就会躺倒。
 */
function jpeg_exif_orientation(string $path): int
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return 1;
    }

    $orientation = 1;
    $head = (string) fread($fh, 2);
    if ($head !== "\xFF\xD8") {          // 不是 JPEG
        fclose($fh);
        return 1;
    }

    while (!feof($fh)) {
        $marker = (string) fread($fh, 2);
        if (strlen($marker) < 2 || $marker[0] !== "\xFF") {
            break;
        }
        $type = ord($marker[1]);
        // 填充字节 / 无长度字段的独立标记
        if ($type === 0xFF || $type === 0x01 || ($type >= 0xD0 && $type <= 0xD9)) {
            continue;
        }

        $lenBytes = (string) fread($fh, 2);
        if (strlen($lenBytes) < 2) {
            break;
        }
        $len = (ord($lenBytes[0]) << 8) | ord($lenBytes[1]);
        if ($len < 2) {
            break;
        }
        $payloadLen = $len - 2;

        if ($type !== 0xE1) {            // 只关心 APP1
            if (fseek($fh, $payloadLen, SEEK_CUR) !== 0) {
                break;
            }
            continue;
        }

        // APP1：先确认是 EXIF，再解析 TIFF 头
        $app1 = (string) fread($fh, min($payloadLen, 65535));
        if (str_starts_with($app1, "Exif\x00\x00")) {
            $orientation = jpeg_orientation_from_tiff(substr($app1, 6));
        }
        break;
    }

    fclose($fh);

    return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
}

/** 从 TIFF 块里取 Orientation；大小端都支持 */
function jpeg_orientation_from_tiff(string $tiff): int
{
    if (strlen($tiff) < 8) {
        return 1;
    }

    $byteOrder = substr($tiff, 0, 2);
    if ($byteOrder === 'II') {
        $little = true;
    } elseif ($byteOrder === 'MM') {
        $little = false;
    } else {
        return 1;
    }

    $u16 = static function (string $s, int $at) use ($little): int {
        $a = ord($s[$at]);
        $b = ord($s[$at + 1]);
        return $little ? (($b << 8) | $a) : (($a << 8) | $b);
    };
    $u32 = static function (string $s, int $at) use ($little): int {
        $a = ord($s[$at]);
        $b = ord($s[$at + 1]);
        $c = ord($s[$at + 2]);
        $d = ord($s[$at + 3]);
        return $little
            ? (($d << 24) | ($c << 16) | ($b << 8) | $a)
            : (($a << 24) | ($b << 16) | ($c << 8) | $d);
    };

    if ($u16($tiff, 2) !== 0x002A) {     // TIFF 魔数
        return 1;
    }

    $ifdOffset = $u32($tiff, 4);
    if ($ifdOffset < 8 || $ifdOffset + 2 > strlen($tiff)) {
        return 1;
    }

    $count = $u16($tiff, $ifdOffset);
    for ($i = 0; $i < $count; $i++) {
        $entry = $ifdOffset + 2 + $i * 12;
        if ($entry + 12 > strlen($tiff)) {
            return 1;
        }
        if ($u16($tiff, $entry) !== 0x0112) {   // Orientation
            continue;
        }
        // 类型应为 SHORT(3)、数量 1，取值就在值字段的前两字节
        if ($u16($tiff, $entry + 2) !== 3 || $u32($tiff, $entry + 4) !== 1) {
            return 1;
        }
        return $u16($tiff, $entry + 8);
    }

    return 1;
}

/** 按 EXIF Orientation 把 GD 图像摆正（就地改写并返回新句柄） */
function image_apply_orientation($img, int $orientation)
{
    switch ($orientation) {
        case 2:
            imageflip($img, IMG_FLIP_HORIZONTAL);
            return $img;
        case 3:
            return imagerotate($img, 180, 0) ?: $img;
        case 4:
            imageflip($img, IMG_FLIP_VERTICAL);
            return $img;
        case 5:
            $rot = imagerotate($img, -90, 0) ?: $img;
            imageflip($rot, IMG_FLIP_HORIZONTAL);
            return $rot;
        case 6:
            // 手机竖拍最常见的一种：顺时针 90°。imagerotate 正角是逆时针，所以取负。
            return imagerotate($img, -90, 0) ?: $img;
        case 7:
            $rot = imagerotate($img, 90, 0) ?: $img;
            imageflip($rot, IMG_FLIP_HORIZONTAL);
            return $rot;
        case 8:
            return imagerotate($img, 90, 0) ?: $img;
        default:
            return $img;   // 1 或未知：原样
    }
}

/** 把 php.ini 的 memory_limit 换算成字节；-1（不限）返回 PHP_INT_MAX */
function memory_limit_bytes(): int
{
    $raw = trim((string) ini_get('memory_limit'));
    if ($raw === '' || $raw === '-1') {
        return PHP_INT_MAX;
    }

    $unit = strtolower(substr($raw, -1));
    $value = (float) $raw;

    return (int) match ($unit) {
        'g' => $value * 1024 ** 3,
        'm' => $value * 1024 ** 2,
        'k' => $value * 1024,
        default => $value,
    };
}

/**
 * 估算解码这张图需要多少内存。
 * GD 解码后是 32 位真彩：4 字节/像素；旋转 90/270 还要再开一张同尺寸的图（峰值翻倍）。
 */
function thumb_memory_estimate(int $w, int $h, bool $rotating): int
{
    $one = $w * $h * 4;

    return $one * ($rotating ? 2 : 1) + 8 * 1024 * 1024;
}

/**
 * 生成缩略图前的内存闸门 —— 这是「上传大照片直接 500」的根因。
 *
 * GD 会一次性把整张图铺成真彩位图，默认 128M 连一张 48MP 手机照片（约 190MB）
 * 都放不下。硬解的结果是内存耗尽 fatal，把整个上传请求带崩，文件也存不进去。
 * 所以先估算：能用就把上限临时抬到刚够，太离谱就干脆跳过——
 * 相册前端在缩略图 404 时会回退到原图，不会开天窗。
 *
 * @return array{ok:bool,need:int,reason:string}
 */
function thumb_memory_gate(int $w, int $h, bool $rotating): array
{
    $need = thumb_memory_estimate($w, $h, $rotating);
    $ceiling = max(32, (int) config('thumb_max_mb', 256)) * 1024 * 1024;

    if ($need > $ceiling) {
        return ['ok' => false, 'need' => $need, 'reason' => sprintf(
            '原图 %d×%d 解码约需 %s，超过 thumb_max_mb（%s）。如需生成请调高该配置。',
            $w,
            $h,
            format_bytes((float) $need),
            format_bytes((float) $ceiling)
        )];
    }

    $limit = memory_limit_bytes();
    if ($limit !== PHP_INT_MAX && $need > $limit * 0.6) {
        // 只抬本次请求的上限，不做还原：上限本来就是请求级的，
        // 而还原会让下面每处提前 return 都得记着恢复，得不偿失。
        ini_set('memory_limit', ((int) ceil($need * 1.2 / 1024 / 1024)) . 'M');
    }

    return ['ok' => true, 'need' => $need, 'reason' => ''];
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

    // 先在解码之前把方向读出来：它决定旋转时会不会多开一张同尺寸的图
    $orientation = $info[2] === IMAGETYPE_JPEG ? jpeg_exif_orientation($sourcePath) : 1;

    $gate = thumb_memory_gate($w, $h, in_array($orientation, [5, 6, 7, 8], true));
    if (!$gate['ok']) {
        error_log('[thumb] ' . basename($sourcePath) . ' 跳过生成：' . $gate['reason']);
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

    // 手机照片常带 Orientation 标签而像素其实是躺着的，GD 不会自动摆正；
    // 不处理的话相册里全是歪的。
    if ($orientation !== 1) {
        $src = image_apply_orientation($src, $orientation);
        // 旋转 90° 的那几种会把宽高对调，必须以摆正后的尺寸重新取值
        $w = imagesx($src);
        $h = imagesy($src);
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
