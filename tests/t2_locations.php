<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't2-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
        'region' => 'ap-guangzhou', 'bucket' => 'test-1250000000',
        'access_key' => 'AK', 'secret_key' => 'SK']),
    'is_default' => 0, 'priority' => 5, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

// 造一个假 blob
$blobId = q_insert('blobs', ['hash' => str_repeat('a', 64), 'size' => 100,
    'mime' => 'text/plain', 'ref_count' => 1, 'created_at' => now()]);

$id = blob_location_record($blobId, $sourceId, 'aa/' . str_repeat('a', 64), 100);
check($id > 0, 'record 应返回 location id');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$id]) === 'pending',
    '新记录状态应为 pending');

// 幂等：同 (blob, source) 再记一次不应新增行
$id2 = blob_location_record($blobId, $sourceId, 'aa/' . str_repeat('a', 64), 100);
check($id2 === $id, '重复 record 应复用同一行（幂等）');
check((int) q_val('SELECT COUNT(*) FROM blob_locations WHERE blob_id = ? AND source_id = ?',
    [$blobId, $sourceId]) === 1, '同 (blob,source) 只应有一行');

blob_location_mark_ok($blobId, $sourceId, 'aa/' . str_repeat('a', 64));
$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', 'mark_ok 后状态应为 ok');

$direct = blob_location_direct($userId, $blobId);
check($direct !== null && isset($direct['config']), 'direct 应返回带 config 的记录');
check((int) $direct['source_id'] === $sourceId, 'direct 应返回刚建的源');

// 另一个用户的 direct 必须为 null（跨用户隔离）
$otherId = q_insert('users', [
    'email' => 't2other@example.test', 'username' => 't2other',
    'display_name' => 't2other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024, 'used_bytes' => 0, 'created_at' => now(),
    'updated_at' => now(),
]);
check(blob_location_direct($otherId, $blobId) === null, '其它用户不得看到该 location');

blob_location_mark_stale($id, '测试');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$id]) === 'stale',
    'mark_stale 后状态应为 stale');

blob_location_forget($id);
check(blob_location_find($blobId, $sourceId) === null, 'forget 后应查不到');

// 清理
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM users WHERE id = ?', [$otherId]);

finish();
