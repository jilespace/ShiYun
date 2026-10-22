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

q_exec('DELETE FROM transfer_attempts WHERE ip = ?', ['198.51.100.23']);
foreach ((array) glob($tmpRoot . '/*') as $p) {
    transfer_rmdir($p);
}
@rmdir($tmpRoot);
finish();
