<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   文件版本：同名文件覆盖上传时，旧内容先存成版本，再替换当前内容。
   ========================================================================= */

function versions_next_no(int $nodeId): int
{
    return (int) q_val('SELECT COALESCE(MAX(version_no), 0) + 1 FROM node_versions WHERE node_id = ?', [$nodeId]) + 1;
}

/** 记录一个版本（引用同一份 blob，ref_count +1） */
function version_snapshot(int $nodeId, int $blobId, int $size, ?string $mime, int $userId): int
{
    q_exec('UPDATE blobs SET ref_count = ref_count + 1 WHERE id = ?', [$blobId]);

    return q_insert('node_versions', [
        'node_id'    => $nodeId,
        'version_no' => versions_next_no($nodeId),
        'blob_id'    => $blobId,
        'size'       => $size,
        'mime'       => $mime,
        'created_by' => $userId,
        'created_at' => now(),
    ]);
}

/**
 * 用新内容替换文件节点的当前内容，旧内容留档。
 *
 * @param array{blob_id:int,size:int,hash:string} $blob
 */
function node_replace_content(int $userId, array $node, array $blob, ?string $mime = null): array
{
    if ($node['kind'] !== 'file') {
        throw new RuntimeException('只有文件才有版本。');
    }

    $oldBlob = $node['blob_id'] !== null ? (int) $node['blob_id'] : null;
    $oldSize = (int) $node['size'];

    db_tx(static function () use ($userId, $node, $blob, $mime, $oldBlob, $oldSize): void {
        if ($oldBlob !== null) {
            version_snapshot((int) $node['id'], $oldBlob, $oldSize, (string) ($node['mime'] ?? ''), $userId);
        }

        q_update('nodes', [
            'blob_id'    => (int) $blob['blob_id'],
            'size'       => (int) $blob['size'],
            'mime'       => $mime ?? $node['mime'],
            'updated_at' => now(),
        ], 'id = ? AND user_id = ?', [(int) $node['id'], $userId]);

        // 旧内容在节点上不再引用，释放一次（版本记录仍持有引用时会保留物理文件）
        if ($oldBlob !== null) {
            blob_release($oldBlob);
        }
    });

    user_add_usage($userId, (int) $blob['size'] - $oldSize);

    return node_require($userId, (int) $node['id']);
}

/** 版本占用（版本里独占的字节数） */
function version_usage(int $nodeId, int $currentBlobId): int
{
    return (int) q_val(
        'SELECT COALESCE(SUM(size), 0) FROM node_versions WHERE node_id = ? AND blob_id <> ?',
        [$nodeId, $currentBlobId]
    );
}

function version_rows(int $nodeId): array
{
    return q_all(
        'SELECT * FROM node_versions WHERE node_id = ? ORDER BY version_no DESC LIMIT 100',
        [$nodeId]
    );
}

/** 把某个版本恢复成当前内容 */
function version_restore(int $userId, array $node, int $versionId): array
{
    $version = q_one(
        'SELECT * FROM node_versions WHERE id = ? AND node_id = ?',
        [$versionId, (int) $node['id']]
    );
    if ($version === null) {
        throw new RuntimeException('版本不存在。');
    }

    return node_replace_content($userId, $node, [
        'blob_id' => (int) $version['blob_id'],
        'size'    => (int) $version['size'],
        'hash'    => '',
    ], $version['mime'] !== null ? (string) $version['mime'] : null);
}

function version_delete(int $userId, int $versionId): bool
{
    $version = q_one(
        'SELECT v.* FROM node_versions v JOIN nodes n ON n.id = v.node_id
         WHERE v.id = ? AND n.user_id = ?',
        [$versionId, $userId]
    );
    if ($version === null) {
        return false;
    }

    q_exec('DELETE FROM node_versions WHERE id = ?', [$versionId]);
    blob_release((int) $version['blob_id']);

    return true;
}

/** 保留最近 N 个版本，更早的清理掉 */
function versions_prune(int $userId, int $nodeId, int $keep = 10): int
{
    $rows = q_all(
        'SELECT id, blob_id FROM node_versions WHERE node_id = ?
         ORDER BY version_no DESC LIMIT 200 OFFSET ' . max(1, $keep),
        [$nodeId]
    );

    foreach ($rows as $row) {
        q_exec('DELETE FROM node_versions WHERE id = ?', [(int) $row['id']]);
        blob_release((int) $row['blob_id']);
    }

    return count($rows);
}
