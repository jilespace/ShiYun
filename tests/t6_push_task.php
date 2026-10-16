<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
// 模块不在 bootstrap 里加载（HTTP 场景由 router 按需 require），CLI 测试需自行引入。
require_once BASE_PATH . '/app/modules/tasks.php';

$userId = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$dir = sys_get_temp_dir() . '/t6-src-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

$sourceId = q_insert('storage_sources', [
    'user_id' => $userId, 'name' => 't6-local', 'driver' => 'local',
    'config' => json_encode(['root' => $dir]),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok',
    'used_bytes' => 0, 'created_at' => now(),
]);

$tmp = sys_get_temp_dir() . '/t6-payload.bin';
file_put_contents($tmp, 'push-via-task');
$blob = blob_ingest_file($tmp, null, 'text/plain');
$blobId = (int) $blob['blob_id'];

$taskId = tasks_enqueue_push($userId, $blobId, $sourceId, 't6.bin');
check($taskId > 0, 'enqueue 应返回任务 id');
check((string) q_val('SELECT status FROM transfer_tasks WHERE id = ?', [$taskId]) === 'queued',
    '新任务应为 queued');
check((string) q_val('SELECT kind FROM transfer_tasks WHERE id = ?', [$taskId]) === 'push',
    'kind 应为 push');

// 推进队列
tasks_tick($userId, 10);

$row = q_one('SELECT status, error FROM transfer_tasks WHERE id = ?', [$taskId]);
check($row['status'] === 'done', '推进后任务应为 done，实际：' . $row['status'] . ' ' . (string) $row['error']);
$loc = blob_location_find($blobId, $sourceId);
check($loc !== null && $loc['status'] === 'ok', '推进后 location 应为 ok');

// 清理
q_exec('DELETE FROM transfer_tasks WHERE id = ?', [$taskId]);
q_exec('DELETE FROM blob_locations WHERE blob_id = ?', [$blobId]);
q_exec('DELETE FROM storage_sources WHERE id = ?', [$sourceId]);
q_exec('DELETE FROM blobs WHERE id = ?', [$blobId]);
@unlink(blob_path((string) $blob['hash']));
array_map('unlink', glob($dir . '/*/*') ?: []);
array_map('rmdir', glob($dir . '/*') ?: []);
@rmdir($dir);

finish();
