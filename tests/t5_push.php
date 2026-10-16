<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t5-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't5-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

// 造一份真实内容进本地仓
$tmp = sys_get_temp_dir() . '/t5-payload.bin';
file_put_contents($tmp, 'hello-push');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$r = blob_push_to_source($blobId, $sourceId);
check($r['ok'] === true && $r['skipped'] === false, '首次推送应成功且非跳过');

$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', '推送后状态应为 ok');
check(is_file($dir . '/' . substr($blob['hash'], 0, 2) . '/' . $blob['hash']),
    '目标源上应出现对象');

// 第二次：已 ok，应跳过而不是重传
$r2 = blob_push_to_source($blobId, $sourceId);
check($r2['ok'] === true && $r2['skipped'] === true, '已同步时应跳过');

// 指向不存在的源：不得抛异常，应返回失败
$r3 = blob_push_to_source($blobId, 999999);
check($r3['ok'] === false && $r3['error'] !== '', '无效源应返回失败而不是抛异常');

// 清理
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
