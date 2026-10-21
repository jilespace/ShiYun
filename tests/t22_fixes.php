<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';
ob_start();

/* =========================================================================
   审查修复的回归测试。

   覆盖 2026-10-21 审查报告里的高危与中危修复：
   - H2/H4  内容引用的加减必须成对，删一个文件不能把共享内容删掉
   - H3     私密空间标记要随移动/还原传播到整棵子树
   - H1/M9  在飞分片计入配额；配额占用是原子操作
   - M2     客户端声明的哈希必须与内容核对
   - M8     卡死在 running 的任务会被回收
   - M13    数据库异常不得把 SQL 回显给客户端
   - M14    登录锁定按「邮箱 + 来源」成对计数，不再能定向锁死别人
   - M4     改密/改两步验证会让其它会话失效
   - M3     停用账号的令牌不得通过 WebDAV 认证
   - M12    shares_list 不再用 $row + [...]（node.id 会被分享 id 顶掉）
   ========================================================================= */

$email = 't22-fixes@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM blobs WHERE id NOT IN (SELECT blob_id FROM nodes WHERE blob_id IS NOT NULL)', []);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}
$uid = create_user($email, 'fixt-pass-123', '修复验证');
$root = user_root_id($uid);
$privateRoot = user_private_id($uid);

/** 造一份新内容，返回 blob 数组 */
$makeBlob = static function (string $content): array {
    $tmp = tempnam((string) config('chunk_path'), 't22');
    file_put_contents($tmp, $content);
    return blob_ingest_file($tmp, null, 'text/plain');
};

/* -------------------------------------------------- H2 / H4 引用计数 */

echo "\n· 内容引用\n";
$blob = $makeBlob('t22-shared-' . bin2hex(random_bytes(4)));
$hash = (string) $blob['hash'];

// 第二个引用走秒传那条路（blob_acquire），它必须自己把 ref_count 加上
$blobId2 = blob_acquire($hash, (int) $blob['size'], 'text/plain');
$nA = node_create_file($uid, $root, 'a.txt', $blob, 'text/plain');
$nB = node_create_file($uid, $root, 'b.txt', ['blob_id' => $blobId2, 'size' => (int) $blob['size'], 'hash' => $hash], 'text/plain');

check((int) q_val('SELECT ref_count FROM blobs WHERE id = ?', [(int) $blob['blob_id']]) === 2,
    '两份文件共享同一内容时 ref_count 为 2（秒传也加了引用）');

node_purge($uid, node_require($uid, (int) $nA['id']));
check(q_one('SELECT id FROM blobs WHERE id = ?', [(int) $blob['blob_id']]) !== null,
    '删掉其中一个后，共享的内容仍然存在');
check(is_file(blob_path($hash)), '共享内容的物理文件没有被删');

// 再删一次同一个节点：空结果集不能让 IN () 变成语法错误，也不能重复释放
node_purge($uid, $nA);
check((int) q_val('SELECT ref_count FROM blobs WHERE id = ?', [(int) $blob['blob_id']]) === 1,
    '重复 purge 同一个节点不会把引用多减一次');

$still = node_require($uid, (int) $nB['id']);
check($still['blob_id'] !== null && is_file(blob_path((string) $hash)),
    '另一个文件仍然指向可读的内容');

/* -------------------------------------------------- H3 私密标记传播 */

echo "\n· 私密空间标记\n";
$folder = node_create_folder($uid, $root, 't22-搬进私密');
$child = node_create_file($uid, (int) $folder['id'], 'inner.txt', $makeBlob('t22-inner'), 'text/plain');

node_move($uid, node_require($uid, (int) $folder['id']), $privateRoot);
$childAfter = node_require($uid, (int) $child['id']);
check((int) $childAfter['is_private'] === 1, '移入私密空间后，子项的 is_private 也变成 1');

node_move($uid, node_require($uid, (int) $folder['id']), $root);
$childBack = node_require($uid, (int) $child['id']);
check((int) $childBack['is_private'] === 0, '移出私密空间后，子项的 is_private 恢复为 0');

/* -------------------------------------------------- H1 / M9 配额 */

echo "\n· 配额\n";
q_update('users', ['used_bytes' => 900, 'quota_bytes' => 1000], 'id = ?', [$uid]);

$threw = false;
try { assert_quota($uid, 200); } catch (Throwable $e) { $threw = true; }
check($threw, '已用 900 / 配额 1000，再传 200 被拒');

check(quota_claim($uid, 200) === false, 'quota_claim 超额时返回 false 且不占用');
check(quota_claim($uid, 50) === true, 'quota_claim 在额度内返回 true');
check((int) q_val('SELECT used_bytes FROM users WHERE id = ?', [$uid]) === 950, 'quota_claim 成功时确实加上了用量');

// 在飞分片同样占盘，必须计入配额
$sid = bin2hex(random_bytes(16));
q_insert('upload_sessions', [
    'user_id' => $uid, 'upload_id' => $sid, 'filename' => 'inflight.bin',
    'size' => 100, 'chunk_size' => 100, 'total_chunks' => 1,
    'received' => json_encode([0 => 100]), 'received_bytes' => 100,
    'status' => 'uploading', 'created_at' => now(), 'updated_at' => now(),
]);
$threw = false;
try { assert_quota($uid, 0); } catch (Throwable $e) { $threw = true; }
check($threw, '在飞分片计入配额：950 已用 + 100 在飞 > 1000 应被拒');

q_exec('DELETE FROM upload_sessions WHERE upload_id = ?', [$sid]);
q_update('users', ['used_bytes' => 0, 'quota_bytes' => 1024 ** 4], 'id = ?', [$uid]);

/* -------------------------------------------------- M2 哈希核对 */

echo "\n· 内容指纹\n";
$tmpBad = tempnam((string) config('chunk_path'), 't22');
file_put_contents($tmpBad, 't22-not-matching');
$threw = false;
try {
    blob_ingest_file($tmpBad, str_repeat('0', 64), 'text/plain');
} catch (Throwable $e) {
    $threw = true;
}
@unlink($tmpBad);
check($threw, '声明的哈希与内容不符时拒绝入库（不能污染内容寻址仓）');

$tmpGood = tempnam((string) config('chunk_path'), 't22');
file_put_contents($tmpGood, 't22-matching');
$good = blob_ingest_file($tmpGood, hash('sha256', 't22-matching'), 'text/plain');
check($good['hash'] === hash('sha256', 't22-matching'), '哈希相符时正常入库');

/* -------------------------------------------------- M8 卡死任务回收 */

echo "\n· 任务队列\n";
require_once BASE_PATH . '/app/modules/tasks.php';
$taskId = q_insert('transfer_tasks', [
    'user_id' => $uid, 'kind' => 'offline', 'title' => 't22-stuck',
    'payload' => json_encode(['url' => 'http://example.test/x']),
    'progress' => 0, 'status' => 'running', 'retries' => 0,
    'started_at' => date('Y-m-d H:i:s', time() - 7200),
    'created_at' => now(),
]);
tasks_reap_stuck($uid);
$task = q_one('SELECT status, started_at FROM transfer_tasks WHERE id = ?', [$taskId]);
check(($task['status'] ?? '') === 'queued', '卡在 running 两小时的任务被重新排队');
check($task['started_at'] === null, '重排时清空 started_at，否则下一轮又会被判成卡死');

q_update('transfer_tasks', ['status' => 'running', 'started_at' => date('Y-m-d H:i:s', time() - 7200), 'retries' => 5], 'id = ?', [$taskId]);
tasks_reap_stuck($uid);
check((string) q_val('SELECT status FROM transfer_tasks WHERE id = ?', [$taskId]) === 'failed',
    '反复卡死的任务直接判失败，不在队列里空转');
q_exec('DELETE FROM transfer_tasks WHERE id = ?', [$taskId]);

/* -------------------------------------------------- M13 错误回显 */

echo "\n· 错误回显\n";
check(safe_error_message(new PDOException('SQLSTATE[42S22]: Column not found')) === '服务器内部错误',
    'PDO 异常不回显 SQL');
check(safe_error_message(new RuntimeException('存储空间不足')) === '存储空间不足',
    '业务异常原样回显给用户');
check(safe_error_message(new InvalidArgumentException('参数不合法')) === '参数不合法',
    '参数异常原样回显给用户');
check(safe_error_message(new Error('boom'), '兜底') === '兜底', '未知异常走兜底文案');

/* -------------------------------------------------- M14 登录锁定 */

echo "\n· 登录锁定\n";
$lockEmail = 't22-lock@example.test';
$ipA = '203.0.113.9';
$ipB = '198.51.100.7';
for ($i = 0; $i < 5; $i++) {
    q_insert('login_logs', [
        'user_id' => null, 'email' => $lockEmail, 'ip' => $ipA,
        'ua' => 't22', 'device' => 't22', 'status' => 'fail', 'reason' => 't22',
        'created_at' => now(),
    ]);
}
check(login_locked($lockEmail, $ipA) === true, '同一来源连续失败会被锁定');
check(login_locked($lockEmail, $ipB) === false, '换个来源不会波及受害者本人（不再被定向锁死）');
q_exec('DELETE FROM login_logs WHERE reason = ?', ['t22']);

/* -------------------------------------------------- M4 会话失效 */

echo "\n· 会话失效\n";
$_SESSION['auth_epoch'] = -1;
$before = (int) q_val('SELECT session_epoch FROM users WHERE id = ?', [$uid]);
revoke_other_sessions($uid);
$after = (int) q_val('SELECT session_epoch FROM users WHERE id = ?', [$uid]);
check($after === $before + 1, 'revoke_other_sessions 把会话纪元加一（旧 cookie 随即失效）');
check((int) $_SESSION['auth_epoch'] === $after, '当前会话的纪元同步到新值，不把自己踢下线');

/* -------------------------------------------------- M3 停用账号的令牌 */

echo "\n· 停用账号\n";
require_once BASE_PATH . '/app/modules/webdav.php';
$disabledId = create_user('t22-disabled@example.test', 'fixt-pass-123', '停用者');
q_update('users', ['status' => 'disabled'], 'id = ?', [$disabledId]);
$token = random_token(24);
q_insert('api_tokens', [
    'user_id' => $disabledId, 'name' => 't22', 'token_hash' => hash('sha256', $token),
    'scopes' => '*', 'created_at' => now(),
]);
check(api_token_user_from_string($token) === null, '停用账号的令牌不能通过 WebDAV 认证');
q_exec('DELETE FROM api_tokens WHERE user_id = ?', [$disabledId]);
q_exec('DELETE FROM nodes WHERE user_id = ?', [$disabledId]);
q_exec('DELETE FROM users WHERE id = ?', [$disabledId]);

/* -------------------------------------------------- 迁移自愈 */

echo "\n· 迁移自愈\n";
// 线上出过的事故：app_meta 记着 schema_version=17，但 totp_last_step 根本没建，
// 于是两步验证一提交就报 Unknown column。根因是「版本号够了就整体跳过迁移」的闸门，
// 而版本号定义在 schema.php、补列语句在 db.php，两个文件不同步就会永久漏建。
// 现在改成每次请求核对 information_schema 快照，缺什么补什么。
q_exec('ALTER TABLE users DROP COLUMN totp_last_step');
$colsNow = array_column(q_all('SHOW COLUMNS FROM users'), 'Field');
check(!in_array('totp_last_step', $colsNow, true), '前置：把 totp_last_step 删掉');

db_migrate(db());
$colsNow = array_column(q_all('SHOW COLUMNS FROM users'), 'Field');
check(in_array('totp_last_step', $colsNow, true), '即使版本号已是最新，缺失的列也会被补回来');

q_exec('ALTER TABLE nodes DROP INDEX idx_node_size');
$idxNow = array_column(q_all('SHOW INDEX FROM nodes'), 'Key_name');
check(!in_array('idx_node_size', $idxNow, true), '前置：把 idx_node_size 删掉');
db_migrate(db());
$idxNow = array_column(q_all('SHOW INDEX FROM nodes'), 'Key_name');
check(in_array('idx_node_size', $idxNow, true), '缺失的索引同样会被补回来');

/* -------------------------------------------------- M12 静态检查 */

echo "\n· 结构检查\n";
$sharesSrc = (string) file_get_contents(BASE_PATH . '/app/modules/shares.php');
check(!str_contains($sharesSrc, 'node_public($row +'), 'shares_list 不再用 $row + [...]（node.id 不会被分享 id 顶掉）');
check(str_contains($sharesSrc, "input_int('id')"), 'shares_download 认得 id 参数（下载文件夹内的单个文件）');

/* ------------------------------------------------------------ 收尾 */

q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM blobs WHERE ref_count <= 0', []);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
