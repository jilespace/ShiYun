<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

require_once BASE_PATH . '/app/modules/tasks.php';

/* =========================================================================
   分片上传：init → chunk×N → complete。
   会话落库（upload_sessions），断线后可用 status 查询已收分片继续传。
   ========================================================================= */

function upload_init(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $name = safe_filename((string) input('name', ''));
    if ($name === null) {
        json_fail('文件名不合法。');
    }

    $size = input_int('size');
    if ($size < 0) {
        json_fail('文件大小不合法。');
    }
    if ($size > (int) config('max_upload')) {
        json_fail('文件超过单文件上限 ' . format_bytes((float) config('max_upload')) . '。');
    }

    $parentId = input_int('parent', user_root_id($userId));
    $parent = node_require($userId, $parentId);
    if ($parent['kind'] !== 'folder') {
        json_fail('上传目标不是文件夹。');
    }
    if (node_in_private($userId, $parent) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    try {
        assert_quota($userId, $size);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 413, 'quota');
    }

    // 可选目标存储源：0/缺省 = 仅本地。非零时必须是本人可用的 COS（s3）源。
    $sourceId = input_int('source');
    if ($sourceId > 0) {
        $ok = q_one('SELECT id FROM storage_sources WHERE id = ? AND user_id = ? AND driver = ?',
            [$sourceId, $userId, 's3']);
        if ($ok === null) {
            json_fail('存储源不存在或不可用。');
        }
    }

    $hash = strtolower(trim((string) input('hash', '')));
    $hasHash = preg_match('/^[0-9a-f]{64}$/', $hash) === 1;

    // 秒传：内容已在仓库里，直接建节点
    if ($hasHash) {
        $blob = blob_find($hash);
        if ($blob !== null && is_file(blob_path($hash))) {
            // 必须自己取得一次引用。blob_ingest_file() 会做这件事，这条路径绕过了它，
            // 不补的话 ref_count 少算一次：日后删除这个节点，blob_release() 会以为
            // 引用已归零，把仍被别的文件引用的内容连同物理文件一起删掉。
            $blobId = blob_acquire($hash, (int) $blob['size'], $blob['mime'] !== null ? (string) $blob['mime'] : null);
            $node = node_create_file(
                $userId,
                $parentId,
                $name,
                ['blob_id' => $blobId, 'size' => (int) $blob['size'], 'hash' => $hash]
            );
            activity_log($userId, 'file.upload', (int) $node['id'], $name . '（秒传）');
            traffic_add($userId, 'up', 0, 1);

            // 秒传命中只是说明本地有；用户选了 COS 的话，副本仍要推上去
            if ($sourceId > 0) {
                tasks_enqueue_push($userId, (int) $blob['id'], $sourceId, $name);
            }

            json_ok([
                'instant' => true,
                'node'    => node_public(node_attach_meta($userId, [$node])[0]),
            ]);
        }
    }

    $chunkSize = (int) config('chunk_size');
    $totalChunks = $size === 0 ? 1 : (int) ceil($size / $chunkSize);

    // 同一文件重复发起 → 复用未完成的会话，实现刷新后续传
    $existing = q_one(
        'SELECT * FROM upload_sessions
         WHERE user_id = ? AND filename = ? AND size = ? AND parent_id <=> ? AND status IN (?, ?)
         ORDER BY id DESC LIMIT 1',
        [$userId, $name, $size, $parentId, 'pending', 'uploading']
    );
    if ($existing !== null) {
        $received = json_decode((string) $existing['received'], true);
        json_ok([
            'instant'     => false,
            'resumed'     => true,
            'uploadId'    => (string) $existing['upload_id'],
            'chunkSize'   => (int) $existing['chunk_size'],
            'totalChunks' => (int) $existing['total_chunks'],
            'received'    => is_array($received) ? array_map('intval', array_keys($received)) : [],
        ]);
    }

    $uploadId = bin2hex(random_bytes(16));
    $relative = trim((string) input('relativePath', ''), '/');

    q_insert('upload_sessions', [
        'user_id'        => $userId,
        'upload_id'      => $uploadId,
        'filename'       => $name,
        'relative_path'  => $relative !== '' ? mb_substr($relative, 0, 500) : null,
        'size'           => $size,
        'chunk_size'     => $chunkSize,
        'total_chunks'   => $totalChunks,
        'received'       => json_encode([]),
        'received_bytes' => 0,
        'parent_id'      => $parentId,
        'source_id'      => $sourceId > 0 ? $sourceId : null,
        'hash'           => $hasHash ? $hash : null,
        'status'         => 'pending',
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    chunk_dir($uploadId);

    json_ok([
        'instant'     => false,
        'uploadId'    => $uploadId,
        'chunkSize'   => $chunkSize,
        'totalChunks' => $totalChunks,
    ]);
}

/**
 * 把一个分片写进会话目录并更新计数。
 * 登录上传与公开收取箱共用——两边只差「怎么找到 session」，写入逻辑必须一致，
 * 否则续传、并发、空分片这些边界会在两套代码里各错一次。
 *
 * @return array{received:int,total:int,bytes:int}
 */
function upload_store_chunk(array $session, int $index): array
{
    if ($index < 0 || $index >= (int) $session['total_chunks']) {
        throw new RuntimeException('分片序号越界。');
    }

    $chunkSize = (int) $session['chunk_size'];
    // 声明 size 为 0（或省略）时没有可比的总量，用分片大小兜底，
    // 免得一个「未知大小」的会话成为无限容量的垃圾桶。
    $cap = (int) $session['size'] > 0 ? (int) $session['size'] : $chunkSize;

    $dir = chunk_dir((string) $session['upload_id']);
    $target = $dir . '/' . $index . '.part';

    $in = fopen('php://input', 'rb');
    if ($in === false) {
        throw new RuntimeException('无法读取上传数据。');
    }
    $out = fopen($target, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('无法写入分片。');
    }
    $written = (int) stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($written === 0 && $declared > 0) {
        @unlink($target);
        throw new RuntimeException('分片为空，请重试。');
    }
    // 单个分片不得超过约定的分片大小。没有这道闸，客户端可以声明一个很小的
    // size（拿到很少的 total_chunks），却给每个分片塞满整个请求体——分片躺在
    // runtime/chunks 里、不计入配额，反复如此就能把磁盘写满。
    if ($chunkSize > 0 && $written > $chunkSize) {
        @unlink($target);
        throw new RuntimeException('分片超过约定的分片大小。');
    }
    @chmod($target, 0600);

    // 分片汇总用行锁串行化，避免并发上报时 read-modify-write 互相覆盖丢分片
    $received = [];
    $totalBytes = 0;
    $tooBig = false;
    db_tx(static function () use ($session, $index, $written, $cap, &$received, &$totalBytes, &$tooBig): void {
        $row = q_one('SELECT received, total_chunks FROM upload_sessions WHERE id = ? FOR UPDATE', [(int) $session['id']]);
        if ($row === null) {
            return;
        }
        $buf = json_decode((string) $row['received'], true);
        $buf = is_array($buf) ? $buf : [];

        // 已收总量不得超过声明的大小（重传同一分片时扣掉旧值再算）
        $prospective = array_sum($buf) - (int) ($buf[$index] ?? 0) + $written;
        if ($prospective > $cap) {
            $tooBig = true;
            return;
        }

        $buf[$index] = $written;
        $received = $buf;
        $totalBytes = array_sum($buf);
        q_update('upload_sessions', [
            'received'       => json_encode($buf),
            'received_bytes' => $totalBytes,
            'status'         => count($buf) >= (int) $row['total_chunks'] ? 'uploading' : 'pending',
            'updated_at'     => now(),
        ], 'id = ?', [(int) $session['id']]);
    });

    if ($tooBig) {
        @unlink($target);
        throw new RuntimeException('已收分片总量超过声明的大小。');
    }

    return [
        'received' => count($received),
        'total'    => (int) $session['total_chunks'],
        'bytes'    => $totalBytes,
    ];
}

/**
 * 合并会话的全部分片，返回合并后的临时文件路径。
 * 缺片、IO 失败、大小不符都抛 RuntimeException，由调用方决定状态码。
 */
function upload_merge_chunks(array $session): string
{
    $received = json_decode((string) $session['received'], true);
    $received = is_array($received) ? $received : [];

    $missing = [];
    for ($i = 0; $i < (int) $session['total_chunks']; $i++) {
        if (!isset($received[$i])) {
            $missing[] = $i;
        }
    }
    if ($missing !== []) {
        throw new RuntimeException('还有 ' . count($missing) . ' 个分片未收到。');
    }

    $dir = chunk_dir((string) $session['upload_id']);
    $merged = $dir . '/merged.bin';

    $out = fopen($merged, 'wb');
    if ($out === false) {
        throw new RuntimeException('无法合并分片。');
    }

    $expected = 0;
    for ($i = 0; $i < (int) $session['total_chunks']; $i++) {
        $part = $dir . '/' . $i . '.part';
        if (!is_file($part)) {
            fclose($out);
            throw new RuntimeException('分片 ' . $i . ' 丢失，请重传。');
        }
        $in = fopen($part, 'rb');
        if ($in === false) {
            fclose($out);
            throw new RuntimeException('无法读取分片 ' . $i . '。');
        }
        $expected += (int) stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    $size = (int) filesize($merged);
    if ($size !== (int) $session['size'] && (int) $session['size'] > 0) {
        @unlink($merged);
        throw new RuntimeException('合并后大小与声明不符，请重新上传。');
    }

    return $merged;
}

function upload_chunk(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    // 分片请求体是裸二进制，标识放在请求头里
    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) ($_SERVER['HTTP_X_UPLOAD_ID'] ?? '')) ?? '';
    $index = (int) ($_SERVER['HTTP_X_CHUNK_INDEX'] ?? -1);

    $session = q_one('SELECT * FROM upload_sessions WHERE upload_id = ? AND user_id = ? LIMIT 1', [$uploadId, $userId]);
    if ($session === null) {
        json_fail('上传会话不存在或已过期。', 404, 'no_session');
    }

    try {
        json_ok(upload_store_chunk($session, $index));
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 409, 'chunk_failed');
    }
}

/** 查询已收分片，供断点续传 */
function upload_status(): void
{
    $userId = require_auth_api();
    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';

    $session = q_one('SELECT * FROM upload_sessions WHERE upload_id = ? AND user_id = ? LIMIT 1', [$uploadId, $userId]);
    if ($session === null) {
        json_fail('上传会话不存在。', 404, 'no_session');
    }

    $received = json_decode((string) $session['received'], true);
    $received = is_array($received) ? $received : [];

    json_ok([
        'uploadId'    => $uploadId,
        'status'      => (string) $session['status'],
        'chunkSize'   => (int) $session['chunk_size'],
        'totalChunks' => (int) $session['total_chunks'],
        'received'    => array_map('intval', array_keys($received)),
    ]);
}

function upload_complete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';
    $session = q_one('SELECT * FROM upload_sessions WHERE upload_id = ? AND user_id = ? LIMIT 1', [$uploadId, $userId]);
    if ($session === null) {
        json_fail('上传会话不存在。', 404, 'no_session');
    }
    if ($session['status'] === 'done' && $session['node_id'] !== null) {
        json_ok(['node' => node_public(node_attach_meta($userId, [node_require($userId, (int) $session['node_id'])])[0])]);
    }

    try {
        $merged = upload_merge_chunks($session);
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 409, 'incomplete');
    }

    $size = (int) filesize($merged);

    // 合并已开始，这些分片马上要折算成节点用量。先把会话标成 merging 让它退出
    // 「在飞占用」的统计，否则配额会被算两遍（分片一次、节点一次）。
    q_update('upload_sessions', ['status' => 'merging', 'updated_at' => now()], 'id = ?', [(int) $session['id']]);

    // 目标目录（可能来自文件夹上传的相对路径）
    $parentId = (int) $session['parent_id'];
    if (!empty($session['relative_path'])) {
        $dirPart = trim(str_replace('\\', '/', (string) $session['relative_path']), '/');
        $dirPart = (string) preg_replace('#/[^/]*$#', '', '/' . $dirPart);
        if ($dirPart !== '' && $dirPart !== '/') {
            $parentId = node_ensure_path($userId, $parentId, trim($dirPart, '/'));
        }
    }

    $mime = mime_of((string) $session['filename'], (string) @file_get_contents($merged, false, null, 0, 12));
    $blob = blob_ingest_file($merged, $session['hash'] !== null ? (string) $session['hash'] : null, $mime);

    // 同名文件重复上传 → 覆盖当前内容并把旧内容留成版本
    $existing = q_one(
        'SELECT * FROM nodes WHERE user_id = ? AND parent_id = ? AND name_key = ? AND kind = ? AND deleted_at IS NULL LIMIT 1',
        [$userId, $parentId, mb_strtolower((string) $session['filename']), 'file']
    );

    if ($existing !== null) {
        $node = node_replace_content($userId, $existing, $blob, $mime);
        versions_prune($userId, (int) $node['id'], 10);
    } else {
        $node = node_create_file($userId, $parentId, (string) $session['filename'], $blob, $mime);
    }

    q_update('upload_sessions', [
        'status'         => 'done',
        'node_id'        => (int) $node['id'],
        'received_bytes' => $size,
        'updated_at'     => now(),
    ], 'id = ?', [(int) $session['id']]);

    chunk_remove_dir($uploadId);
    activity_log($userId, 'file.upload', (int) $node['id'], (string) $node['name']);
    traffic_add($userId, 'up', $size, 1);

    // 图片顺手生成缩略图
    if (str_starts_with($mime, 'image/')) {
        $path = blob_path((string) $blob['hash']);
        make_thumbnail($path, (string) $blob['hash']);
    }

    // 文本类内容进全文索引，再套一遍自动化规则
    search_index_node($userId, $node);
    rules_apply_to_node($userId, (int) $node['id']);

    // 选了 COS：入队推送，由计划任务驱动的队列执行，不阻塞本次上传
    $targetSource = (int) ($session['source_id'] ?? 0);
    if ($targetSource > 0) {
        tasks_enqueue_push($userId, (int) $blob['blob_id'], $targetSource, (string) $node['name']);
    }

    json_ok([
        'node'  => node_public(node_attach_meta($userId, [$node])[0]),
        'dedup' => (bool) $blob['dedup'],
    ]);
}

function upload_cancel(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $uploadId = preg_replace('/[^a-f0-9]/', '', (string) input('uploadId', '')) ?? '';
    $session = q_one('SELECT * FROM upload_sessions WHERE upload_id = ? AND user_id = ? LIMIT 1', [$uploadId, $userId]);
    if ($session === null) {
        json_ok(['canceled' => false]);
    }

    q_exec('DELETE FROM upload_sessions WHERE id = ?', [(int) $session['id']]);
    chunk_remove_dir($uploadId);

    json_ok(['canceled' => true]);
}
