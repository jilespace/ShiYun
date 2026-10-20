<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   日志保留期清理。
   activities 每打开一次文件就写一行、login_logs 每次登录尝试也写一行，
   此前没有任何清理逻辑。这里同时验「该删的删掉、不该动的别动」。
   ========================================================================= */

$email = 't18-sweep@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM activities WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM login_logs WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}
$uid = create_user($email, 'fixt-pass-123', '日志清理测试');

$activityDays = max(7, (int) config('activity_retention_days', 90));
$loginDays = max(30, (int) config('login_log_retention_days', 365));

$overdue = date('Y-m-d H:i:s', time() - ($activityDays + 10) * 86400);
$overdueLogin = date('Y-m-d H:i:s', time() - ($loginDays + 10) * 86400);
$fresh = now();

q_insert('activities', ['user_id' => $uid, 'node_id' => null, 'action' => 'sweep.old', 'detail' => null, 'ip' => null, 'created_at' => $overdue]);
q_insert('activities', ['user_id' => $uid, 'node_id' => null, 'action' => 'sweep.fresh', 'detail' => null, 'ip' => null, 'created_at' => $fresh]);
q_insert('login_logs', ['user_id' => $uid, 'email' => $email, 'ip' => '127.0.0.1', 'ua' => 't', 'device' => 't', 'status' => 'success', 'reason' => 'sweep.old', 'created_at' => $overdueLogin]);
q_insert('login_logs', ['user_id' => $uid, 'email' => $email, 'ip' => '127.0.0.1', 'ua' => 't', 'device' => 't', 'status' => 'success', 'reason' => 'sweep.fresh', 'created_at' => $fresh]);

require_once BASE_PATH . '/app/modules/tasks.php';
$swept = tasks_sweep_logs();

check((int) q_val("SELECT COUNT(*) FROM activities WHERE user_id = ? AND action = 'sweep.old'", [$uid]) === 0,
    '超过保留期的操作日志被清理');
check((int) q_val("SELECT COUNT(*) FROM activities WHERE user_id = ? AND action = 'sweep.fresh'", [$uid]) === 1,
    '保留期内的操作日志未被动到');
check((int) q_val("SELECT COUNT(*) FROM login_logs WHERE user_id = ? AND reason = 'sweep.old'", [$uid]) === 0,
    '超过保留期的登录日志被清理');
check((int) q_val("SELECT COUNT(*) FROM login_logs WHERE user_id = ? AND reason = 'sweep.fresh'", [$uid]) === 1,
    '保留期内的登录日志未被动到');

// 清理靠 created_at 走索引分批删；没有索引就是每分钟一次全表扫
$actCols = array_column(q_all('SHOW INDEX FROM activities'), 'Key_name');
$logCols = array_column(q_all('SHOW INDEX FROM login_logs'), 'Key_name');
check(in_array('idx_act_created', $actCols, true), 'activities 有 created_at 索引（清理的前提）');
check(in_array('idx_login_created', $logCols, true), 'login_logs 有 created_at 索引（清理的前提）');

foreach (q_all('EXPLAIN SELECT id FROM activities WHERE created_at < ? LIMIT 1', [now()]) as $r) {
    check($r['key'] === 'idx_act_created', '清理条件确实走 created_at 索引（key=' . var_export($r['key'], true) . '）');
}

// 定时任务必须真的调用它，否则清理逻辑等于没接上
$cronSrc = (string) file_get_contents(BASE_PATH . '/app/modules/tasks.php');
check(str_contains($cronSrc, 'tasks_sweep_logs()'), '计划任务里调用了日志清理');
check(str_contains($cronSrc, "'logs'      => tasks_sweep_logs()"), '清理结果出现在 cron 的返回里');

q_exec('DELETE FROM activities WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM login_logs WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
