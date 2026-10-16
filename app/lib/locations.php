<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   内容副本位置。
   blobs 表只记「内容是什么」，这里记「这份内容在哪个存储源上也有一份」。
   粒度是 blob 级：去重下同一份内容可能被多个节点引用，推一次即可全部受益。
   ========================================================================= */

/** 登记一条位置记录（幂等），返回 location id */
function blob_location_record(int $blobId, int $sourceId, string $objectKey, int $size): int
{
    $existing = blob_location_find($blobId, $sourceId);
    if ($existing !== null) {
        q_update('blob_locations', [
            'object_key' => $objectKey,
            'size'       => $size,
            'status'     => 'pending',
            'error'      => null,
            'updated_at' => now(),
        ], 'id = ?', [(int) $existing['id']]);
        return (int) $existing['id'];
    }

    return q_insert('blob_locations', [
        'blob_id'    => $blobId,
        'source_id'  => $sourceId,
        'object_key' => $objectKey,
        'size'       => $size,
        'status'     => 'pending',
        'error'      => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function blob_location_mark_ok(int $blobId, int $sourceId, string $objectKey): void
{
    q_exec(
        'UPDATE blob_locations SET status = ?, object_key = ?, error = NULL, updated_at = ?
         WHERE blob_id = ? AND source_id = ?',
        ['ok', $objectKey, now(), $blobId, $sourceId]
    );
}

function blob_location_mark_failed(int $blobId, int $sourceId, string $error): void
{
    q_exec(
        'UPDATE blob_locations SET status = ?, error = ?, updated_at = ?
         WHERE blob_id = ? AND source_id = ?',
        ['failed', mb_substr($error, 0, 480), now(), $blobId, $sourceId]
    );
}

function blob_location_mark_stale(int $locationId, string $error): void
{
    q_update('blob_locations', [
        'status'     => 'stale',
        'error'      => mb_substr($error, 0, 480),
        'updated_at' => now(),
    ], 'id = ?', [$locationId]);
}

function blob_location_find(int $blobId, int $sourceId): ?array
{
    return q_one(
        'SELECT * FROM blob_locations WHERE blob_id = ? AND source_id = ? LIMIT 1',
        [$blobId, $sourceId]
    );
}

function blob_locations_of_blob(int $blobId): array
{
    return q_all('SELECT * FROM blob_locations WHERE blob_id = ?', [$blobId]);
}

function blob_location_forget(int $locationId): void
{
    q_exec('DELETE FROM blob_locations WHERE id = ?', [$locationId]);
}

/**
 * 找出该用户可用的直链。
 * 必须按 source 归属过滤：blobs 跨用户去重，不过滤会把别人的流量算到自己头上。
 */
function blob_location_direct(int $userId, int $blobId): ?array
{
    return q_one(
        "SELECT bl.*, s.config, s.driver, s.priority
         FROM blob_locations bl
         JOIN storage_sources s ON s.id = bl.source_id
         WHERE bl.blob_id = ? AND bl.status = 'ok' AND s.driver = 's3' AND s.user_id = ?
         ORDER BY s.priority DESC, bl.id ASC
         LIMIT 1",
        [$blobId, $userId]
    );
}
