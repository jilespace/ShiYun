<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$tmp = sys_get_temp_dir() . '/t12.bin';
file_put_contents($tmp, 'status-check');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't12-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://x', 'region' => 'r',
        'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);
blob_location_record($blobId, $sourceId, 'aa/x', 12);

$row = q_one('SELECT * FROM blobs WHERE id = ?', [$blobId]);
$node = ['id' => 0, 'parent_id' => null, 'user_id' => $userId, 'blob_id' => $blobId, 'kind' => 'file',
    'name' => 't12.bin', 'name_key' => 't12.bin', 'size' => 12, 'mime' => 'text/plain',
    'ext' => 'bin', 'path' => '/1/', 'depth' => 1, 'is_favorite' => 0, 'is_hidden' => 0,
    'is_locked' => 0, 'is_private' => 0, 'color' => null, 'note' => null,
    'child_count' => 0, 'deleted_at' => null, 'created_at' => now(), 'updated_at' => now()];

$pub = node_public(node_attach_meta($userId, [$node])[0]);
check(array_key_exists('cosStatus', $pub), 'node_public 应含 cosStatus');
check($pub['cosStatus'] === 'pending', 'pending 的 location 应反映为 pending');

blob_location_mark_failed($blobId, $sourceId, 'x');
$pub2 = node_public(node_attach_meta($userId, [$node])[0]);
check($pub2['cosStatus'] === 'failed', 'failed 的 location 应反映为 failed');

// 未挂 meta 的裸节点必须安全降级为空状态，而不是抛错、也不是偷偷查库
$bare = node_public($node);
check($bare['cosStatus'] === '' && $bare['cosSourceId'] === 0,
    '未附加 meta 时应安全降级为空状态');

// 跨用户隔离：blobs 跨用户去重，别人推到他自己的源上的副本，本用户不应看到
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
$otherId = q_insert('users', [
    'email' => 't12other@example.test', 'username' => 't12other',
    'display_name' => 't12other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024, 'used_bytes' => 0, 'created_at' => now(),
    'updated_at' => now(),
]);
$otherSource = q_insert('storage_sources', [
    'user_id' => $otherId, 'name' => 't12-other', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://x', 'region' => 'r',
        'bucket' => 'b', 'access_key' => 'a', 'secret_key' => 's']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);
blob_location_record($blobId, $otherSource, 'aa/x', 12);
blob_location_mark_ok($blobId, $otherSource, 'aa/x');

$iso = node_public(node_attach_meta($userId, [$node])[0]);
check($iso['cosStatus'] === '' && $iso['cosSourceId'] === 0, '别人的副本状态不可见');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$otherSource]);
q_exec('DELETE FROM users WHERE id = ?', [$otherId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));

finish();
