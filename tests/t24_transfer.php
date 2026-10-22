<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   免登录临时传输：房间、限速、上传容量、清理。
   测试使用独立临时目录，不碰 runtime/transfer。
   ========================================================================= */

$tmpRoot = sys_get_temp_dir() . '/shiyun-transfer-' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0700, true);
$GLOBALS['config']['transfer_storage_path'] = $tmpRoot;
$GLOBALS['config']['transfer_create_per_hour'] = 1000;
$GLOBALS['config']['transfer_code_attempts'] = 10;
$GLOBALS['config']['transfer_max_bytes'] = 1024 * 1024;
$GLOBALS['config']['transfer_max_files'] = 200;
$GLOBALS['config']['chunk_size'] = 5 * 1024 * 1024;
$GLOBALS['config']['max_upload'] = 200 * 1024 ** 3;
$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);

$created = transfer_room_create('');
$room = transfer_room_by_token((string) $created['token']);
check($room !== null, '建房后能按 token 找到房间');
check(strlen((string) $created['token']) === 48, 'token 是 48 位 hex');
check(preg_match('/^\d{6}$/', (string) $created['code']) === 1, '取件码是 6 位数字');
check(transfer_verify_creator($room ?? [], (string) $created['creator_key']), '创建者 key 校验通过');
check(!transfer_verify_creator($room ?? [], 'wrong-key'), '错误创建者 key 被拒');
check(strtotime((string) ($room['expires_at'] ?? '')) > time() + 23 * 3600, '有效期约 24 小时');
$firstRoomId = (int) $created['id'];

echo "\n· 取件码唯一\n";
$codes = [];
for ($i = 0; $i < 20; $i++) {
    $r = transfer_room_create('');
    $codes[] = (string) $r['code'];
    transfer_room_destroy((int) $r['id']);
}
check(count(array_unique($codes)) === 20, '连续建房取件码不重复');

echo "\n· 建房限速\n";
$GLOBALS['config']['transfer_create_per_hour'] = 2;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$a = transfer_room_create('');
$b = transfer_room_create('');
$threw = false;
try { transfer_room_create(''); } catch (TransferRateLimitException $e) { $threw = true; }
check($threw, '超过每小时建房上限被拒');
transfer_room_destroy((int) $a['id']);
transfer_room_destroy((int) $b['id']);
$GLOBALS['config']['transfer_create_per_hour'] = 1000;

echo "\n· 过期清理\n";
q_update('transfer_rooms', ['expires_at' => date('Y-m-d H:i:s', time() - 10)], 'id = ?', [$firstRoomId]);
check(transfer_room_by_token((string) $created['token']) === null, '过期房间按 token 找不到');
check(q_one('SELECT id FROM transfer_rooms WHERE id = ?', [$firstRoomId]) === null, '过期房间行被删除');
check(!is_dir($tmpRoot . '/' . $created['token']), '过期房间目录被删除');

echo "\n· 路径删除\n";
$dir = transfer_room_dir('a' . str_repeat('0', 47));
mkdir($dir . '/u_test', 0700, true);
file_put_contents($dir . '/u_test/0.part', 'x');
transfer_rmdir($dir);
check(!is_dir($dir), '房间目录递归删除');

echo "\n· 密码与加入\n";
$GLOBALS['config']['transfer_code_attempts'] = 10;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$pw = transfer_room_create('secret-123');
$pwRoom = transfer_room_by_token((string) $pw['token']);
// 创建者会话在创建时就解锁了；这里模拟另一位参与者
unset($_SESSION['transfer_unlock'][(int) $pw['id']]);
check(!transfer_room_unlocked($pwRoom ?? []), '密码房对未解锁的参与者仍锁定');
$threw = false;
try { transfer_join_by_code((string) $pw['code'], 'wrong'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '错误密码被拒');
$joined = transfer_join_by_code((string) $pw['code'], 'secret-123');
check((int) $joined['id'] === (int) $pw['id'] && transfer_room_unlocked($joined), '正确密码加入并解锁');
transfer_room_destroy((int) $pw['id']);

echo "\n· 试码锁定\n";
$GLOBALS['config']['transfer_code_attempts'] = 3;
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
for ($i = 0; $i < 3; $i++) {
    try { transfer_join_by_code('000000', ''); } catch (Throwable $e) { /* expected */ }
}
$threw = false;
try { transfer_join_by_code('000000', ''); } catch (TransferRateLimitException $e) { $threw = true; }
check($threw, '连续错误试码后被锁定');
q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
$GLOBALS['config']['transfer_code_attempts'] = 10;

echo "\n· 创建者权限\n";
$own = transfer_room_create('');
$ownRoom = transfer_room_by_token((string) $own['token']);
$threw = false;
try { transfer_room_close($ownRoom ?? [], 'nope'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '错误 key 关闭被拒');
transfer_room_close($ownRoom ?? [], (string) $own['creator_key']);
check(transfer_room_by_token((string) $own['token']) === null, '正确 key 关闭后房间消失');

echo "\n· 文件删除\n";
$del = transfer_room_create('');
$delRoom = transfer_room_by_token((string) $del['token']);
$stored = bin2hex(random_bytes(16));
file_put_contents(transfer_room_dir((string) $del['token']) . '/' . $stored, 'hello');
$fid = q_insert('transfer_files', [
    'room_id' => (int) $del['id'], 'name' => 'a.txt', 'stored_name' => $stored,
    'size' => 5, 'mime' => 'text/plain', 'uploader_name' => null, 'created_at' => now(),
]);
q_exec('UPDATE transfer_rooms SET used_bytes = 5, file_count = 1 WHERE id = ?', [(int) $del['id']]);
transfer_file_remove($delRoom ?? [], (int) $fid);
$afterDel = transfer_room_by_token((string) $del['token']);
check((int) $afterDel['used_bytes'] === 0 && (int) $afterDel['file_count'] === 0, '删除文件回退容量与计数');
check(!is_file(transfer_room_dir((string) $del['token']) . '/' . $stored), '删除文件物理文件');
transfer_room_destroy((int) $del['id']);

echo "\n· 分片上传\n";
$GLOBALS['config']['chunk_size'] = 4;
$GLOBALS['config']['transfer_max_bytes'] = 20;
$up = transfer_room_create('');
$upRoom = transfer_room_by_token((string) $up['token']);
$init = transfer_upload_start($upRoom ?? [], 'hello.txt', 10, 'Alice');
check($init['totalChunks'] === 3 && $init['chunkSize'] === 4, '初始化返回 3 片 × 4 字节');
check((int) q_val('SELECT reserved_bytes FROM transfer_rooms WHERE id = ?', [(int)$up['id']]) === 10, '初始化预留 10 字节');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 0, 'abcd');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 1, 'efgh');
transfer_store_chunk_bytes($upRoom ?? [], (string) $init['uploadId'], 2, 'ij');
$done = transfer_upload_finish($upRoom ?? [], (string) $init['uploadId']);
check($done['size'] === 10 && $done['name'] === 'hello.txt', '合并成功并返回文件信息');
$fresh = transfer_room_by_token((string) $up['token']);
check((int) $fresh['used_bytes'] === 10 && (int) $fresh['reserved_bytes'] === 0, '完成时扣容量、释放预留');
check(is_file(transfer_room_dir((string)$up['token']) . '/' . $done['stored_name']), '合并文件落盘');
$files = transfer_files_list($fresh);
check(count($files) === 1 && $files[0]['uploader'] === 'Alice', '文件列表包含上传者昵称');

$roomForCap = transfer_room_by_token((string) $up['token']);
$threw = false;
try { transfer_upload_start($roomForCap ?? [], 'too-big.bin', 11, ''); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '剩余容量不足时初始化被拒');

$conc = transfer_room_create('');
$concRoom = transfer_room_by_token((string) $conc['token']);
$hold = transfer_upload_start($concRoom ?? [], 'hold.bin', 12, '');
$threw = false;
try { transfer_upload_start($concRoom ?? [], 'second.bin', 9, ''); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '两笔预留叠加后超过总容量被拒');
transfer_upload_cancel($concRoom ?? [], (string) $hold['uploadId']);
transfer_room_destroy((int) $conc['id']);

echo "\n· 分片边界\n";
$init2 = transfer_upload_start($roomForCap ?? [], 'edge.bin', 5, '');
$threw = false;
try { transfer_store_chunk_bytes($roomForCap ?? [], (string)$init2['uploadId'], 0, '12345'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '分片超过 chunk_size 被拒');
transfer_upload_cancel($roomForCap ?? [], (string)$init2['uploadId']);

$dupInit = transfer_upload_start($roomForCap ?? [], 'dup.bin', 8, '');
transfer_store_chunk_bytes($roomForCap ?? [], (string)$dupInit['uploadId'], 0, 'abcd');
transfer_store_chunk_bytes($roomForCap ?? [], (string)$dupInit['uploadId'], 0, 'ab');
check((int) q_val('SELECT received_bytes FROM transfer_uploads WHERE upload_id = ?', [(string)$dupInit['uploadId']]) === 2,
    '重复分片按覆盖回正字节数');
transfer_upload_cancel($roomForCap ?? [], (string)$dupInit['uploadId']);

$afterAbort = transfer_room_by_token((string) $up['token']);

echo "\n· 空文件\n";
$init3 = transfer_upload_start($afterAbort ?? [], 'empty.txt', 0, '');
$empty = transfer_upload_finish($afterAbort ?? [], (string)$init3['uploadId']);
check($empty['size'] === 0 && is_file(transfer_room_dir((string)$up['token']) . '/' . $empty['stored_name']), '0 字节文件可创建');

echo "\n· 路径与缺片\n";
$evilInit = transfer_upload_start($afterAbort ?? [], '../../evil.txt', 1, '');
$evilName = (string) q_val('SELECT filename FROM transfer_uploads WHERE upload_id = ?', [(string)$evilInit['uploadId']]);
check($evilName !== '' && !str_contains($evilName, '/') && !str_contains($evilName, '\\'),
    '文件名路径分隔符被清洗');
transfer_upload_cancel($afterAbort ?? [], (string)$evilInit['uploadId']);

$edgeInit = transfer_upload_start($afterAbort ?? [], 'edge2.txt', 4, '');
$threw = false;
try { transfer_store_chunk_bytes($afterAbort ?? [], (string)$edgeInit['uploadId'], 99, 'x'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '分片序号越界被拒');
$threw = false;
try { transfer_upload_finish($afterAbort ?? [], (string)$edgeInit['uploadId']); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, '缺片时合并被拒');
transfer_upload_cancel($afterAbort ?? [], (string)$edgeInit['uploadId']);

echo "\n· Range\n";
check(transfer_parse_range('bytes=0-4', 10) === [0, 4], '解析前 5 字节');
check(transfer_parse_range('bytes=-3', 10) === [7, 9], '解析后缀范围');
check(transfer_parse_range('bytes=8-', 10) === [8, 9], '解析开放结尾');
check(transfer_parse_range('bytes=10-', 10) === null, '起点越界返回 null');
check(transfer_parse_range('', 10) === null, '空头返回 null');

echo "\n· 超时会话\n";
q_insert('transfer_uploads', [
    'upload_id' => bin2hex(random_bytes(16)), 'room_id' => (int)$up['id'],
    'filename' => 'stale.bin', 'size' => 4, 'chunk_size' => 4, 'total_chunks' => 1,
    'received' => json_encode([]), 'received_bytes' => 0, 'uploader_name' => null,
    'status' => 'uploading', 'created_at' => date('Y-m-d H:i:s', time() - 7200),
    'updated_at' => date('Y-m-d H:i:s', time() - 7200),
]);
q_exec('UPDATE transfer_rooms SET reserved_bytes = reserved_bytes + 4 WHERE id = ?', [(int)$up['id']]);
transfer_reap_stale_uploads();
$reaped = transfer_room_by_token((string)$up['token']);
check((int)$reaped['reserved_bytes'] === 0, '超时会话释放预留');

echo "\n· 文件数上限\n";
$GLOBALS['config']['transfer_max_files'] = 1;
$one = transfer_room_create('');
$oneRoom = transfer_room_by_token((string) $one['token']);
$u1 = transfer_upload_start($oneRoom ?? [], '1.txt', 1, '');
transfer_store_chunk_bytes($oneRoom ?? [], (string) $u1['uploadId'], 0, 'a');
transfer_upload_finish($oneRoom ?? [], (string) $u1['uploadId']);
$u2 = transfer_upload_start($oneRoom ?? [], '2.txt', 1, '');
transfer_store_chunk_bytes($oneRoom ?? [], (string) $u2['uploadId'], 0, 'b');
$threw = false;
try { transfer_upload_finish($oneRoom ?? [], (string) $u2['uploadId']); } catch (TransferCapacityException $e) { $threw = true; }
check($threw, '超过文件数上限时合并不落库');
transfer_room_destroy((int) $one['id']);

transfer_room_destroy((int)$up['id']);
$GLOBALS['config']['chunk_size'] = 5 * 1024 * 1024;
$GLOBALS['config']['transfer_max_bytes'] = 1024 * 1024;
$GLOBALS['config']['transfer_max_files'] = 200;

echo "\n· 公开页\n";
$routes = (string) file_get_contents(BASE_PATH . '/app/router.php');
foreach (['transfer.index', 'transfer.room', 'transfer.create', 'transfer.join',
          'transfer.upload_init', 'transfer.upload_chunk', 'transfer.upload_complete',
          'transfer.download', 'transfer.close'] as $r) {
    check(str_contains($routes, "'" . $r . "'"), '公开路由包含 ' . $r);
}
$view = (string) file_get_contents(BASE_PATH . '/app/views/transfer.php');
check(str_contains($view, 'data-page="transfer"'), '首页视图标记页面');
check(str_contains($view, 'data-create'), '首页有创建入口');
check(str_contains($view, 'data-join'), '首页有取件码入口');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/transfer.js');
check(str_contains($js, 'transfer.create') && str_contains($js, 'transfer.join'), '首页 JS 调用创建/加入');
check(str_contains($js, 'localStorage'), '创建者 key 存 localStorage');

q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
foreach ((array) glob($tmpRoot . '/*') as $p) {
    transfer_rmdir($p);
}
@rmdir($tmpRoot);
finish();
