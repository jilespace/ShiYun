<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$otherId = q_insert('users', [
    'email' => 't8other@example.test', 'username' => 't8other',
    'display_name' => 't8other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t8.bin';
file_put_contents($tmp, 'deliver-me');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

// 无 location：不应有直链
check(blob_direct_url($userId, $blobId, 'text/plain', 'a.txt') === null,
    '没有 location 时不应返回直链');

// 造一条 ok 的 s3 location（endpoint 不可达，用于验证 HEAD 失败会回退）
$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't8-cos', 'driver' => 's3',
    'config' => json_encode(['endpoint' => 'https://127.0.0.1:1',
        'region' => 'ap-guangzhou', 'bucket' => 'b-1',
        'access_key' => 'AK', 'secret_key' => 'SK']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);
$locId = blob_location_record($blobId, $sourceId, 'aa/x', 10);
blob_location_mark_ok($blobId, $sourceId, 'aa/x');

// 别的用户：不得拿到直链（跨用户隔离）
check(blob_direct_url($otherId, $blobId, 'text/plain', 'a.txt') === null,
    '其它用户不得获得该直链');

// 本用户：HEAD 不可达 → 应回退（返回 null）并把 location 标 stale
$url = blob_direct_url($userId, $blobId, 'text/plain', 'a.txt');
check($url === null, 'HEAD 失败时应回退（返回 null）');
check((string) q_val('SELECT status FROM blob_locations WHERE id = ?', [$locId]) === 'stale',
    'HEAD 失败后 location 应标为 stale');

// 已 stale：后续调用应直接跳过，不再尝试 HEAD（用耗时粗判）
$t0 = microtime(true);
blob_direct_url($userId, $blobId, 'text/plain', 'a.txt');
check(microtime(true) - $t0 < 1.0, 'stale 后应跳过 HEAD，立即返回');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
q_exec('DELETE FROM users WHERE id = ?', [$otherId]);
@unlink(blob_path((string) $blob['hash']));

finish();
