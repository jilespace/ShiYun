<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
// 同 T6：tasks_* 函数在模块文件里，bootstrap 不加载，测试需自行引入。
require_once BASE_PATH . '/app/modules/tasks.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t7-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't7-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t7-payload.bin';
file_put_contents($tmp, 'purge-me');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];
$hash = (string) $blob['hash'];
blob_push_to_source($blobId, $sourceId);

$objectPath = $dir . '/' . substr($hash, 0, 2) . '/' . $hash;
check(is_file($objectPath), '前置：对象应已推送到源上');

// 引用归零 → 应入队一个 purge 任务，且本地文件消失
blob_release($blobId);

check(!is_file(blob_path($hash)), '本地 blob 应已删除');
$purge = q_one("SELECT * FROM transfer_tasks WHERE kind = 'purge' AND status = 'queued'
                ORDER BY id DESC LIMIT 1");
check($purge !== null, '应入队一个 purge 任务');

// 推进 → 远程对象应被删除
tasks_tick($userId, 10);
check(!is_file($objectPath), '推进后远程对象应被删除');

// ---- 护栏：key 复用不得误删新对象 ----
$tmp2 = sys_get_temp_dir() . '/t7-payload2.bin';
file_put_contents($tmp2, 'reuse-me');
$blob2 = blob_ingest_file($tmp2, null, 'text/plain');
$blobId2 = (int) $blob2['blob_id'];
$hash2 = (string) $blob2['hash'];
$key2 = substr($hash2, 0, 2) . '/' . $hash2;
blob_push_to_source($blobId2, $sourceId);
$objectPath2 = $dir . '/' . $key2;
check(is_file($objectPath2), '前置：第二个对象应已推送');

// 手工造一个「指向同一 key 的待执行 purge 任务」
$staleId = q_insert('transfer_tasks', [
    'user_id' => $userId, 'kind' => 'purge', 'title' => 'stale-purge',
    'payload' => json_encode(['source_id' => $sourceId, 'object_key' => $key2]),
    'progress' => 0, 'status' => 'queued', 'priority' => 0, 'created_at' => now(),
]);
tasks_tick($userId, 10);

check(is_file($objectPath2), '护栏：对象仍被 blob_locations 引用，不得被删除');
check((string) q_val('SELECT status FROM transfer_tasks WHERE id = ?', [$staleId]) === 'done',
    '跳过删除的任务应标为 done');

// 清理
q_exec('DELETE FROM transfer_tasks WHERE kind IN (?, ?)', ['push', 'purge']);
q_exec('DELETE FROM blob_locations WHERE blob_id IN (?, ?)', [$blobId, $blobId2]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id IN (?, ?)', [$blobId, $blobId2]);
@unlink(blob_path($hash));
@unlink(blob_path($hash2));
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
