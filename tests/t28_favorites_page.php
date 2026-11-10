<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   收藏分页。
   收藏此前固定 LIMIT 500，超过后静默截断；这里验分页与整体统计。
   ========================================================================= */

$email = 't28-favorites@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM node_tags WHERE node_id IN (SELECT id FROM nodes WHERE user_id = ?)', [$oid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}

$uid = create_user($email, 'fixt-pass-123', '收藏分页测试');
$root = user_root_id($uid);
$now = now();

$ph = [];
$params = [];
for ($i = 1; $i <= 350; $i++) {
    $ph[] = '(?,?,?,?,?,?,?,?,?,?,?)';
    array_push(
        $params,
        $uid,
        $root,
        sprintf('fav-%03d.txt', $i),
        sprintf('fav-%03d.txt', $i),
        'file',
        7,
        'text/plain',
        'txt',
        $now,
        $now,
        1
    );
}
q_exec(
    'INSERT INTO nodes (user_id,parent_id,name,name_key,kind,size,mime,ext,created_at,updated_at,is_favorite) VALUES '
    . implode(',', $ph),
    $params
);

[$page1, $more1] = node_favorites_page($uid, 300, 0);
check(count($page1) === 300, '收藏第一页 300 条（实际 ' . count($page1) . '）');
check($more1 === true, '收藏第一页之后仍有下一页');

[$page2, $more2] = node_favorites_page($uid, 300, 300);
check(count($page2) === 50, '收藏第二页 50 条');
check($more2 === false, '收藏末页 hasMore=false');

$stats = node_favorites_stats($uid);
check($stats['files'] === 350, '收藏统计按整体计数（不随分页变化）');
check($stats['bytes'] === 350 * 7, '收藏统计体积正确');

// 私密空间锁定时，收藏列表不能泄漏私密条目
$privateFavId = q_insert('nodes', [
    'user_id'     => $uid,
    'parent_id'   => user_private_id($uid),
    'name'        => 'private-fav.txt',
    'name_key'    => 'private-fav.txt',
    'kind'        => 'file',
    'size'        => 7,
    'mime'        => 'text/plain',
    'ext'         => 'txt',
    'is_favorite' => 1,
    'is_private'  => 1,
    'created_at'  => $now,
    'updated_at'  => $now,
]);
Auth::lockPrivate();
check(node_favorites_stats($uid)['files'] === 350, '锁定私密空间时收藏统计不含私密条目');
Auth::unlockPrivate();
check(node_favorites_stats($uid)['files'] === 351, '解锁后收藏统计恢复私密条目');

// 操作日志同样不能带出私密文件名
q_insert('activities', [
    'user_id' => $uid, 'node_id' => null, 'action' => 'profile.update',
    'detail' => 'public-log', 'ip' => '127.0.0.1', 'created_at' => $now,
]);
q_insert('activities', [
    'user_id' => $uid, 'node_id' => $privateFavId, 'action' => 'file.rename',
    'detail' => 'private-log', 'ip' => '127.0.0.1', 'created_at' => $now,
]);
$details = static fn (array $rows): array => array_map(static fn (array $r): string => (string) $r['detail'], $rows);
Auth::lockPrivate();
$lockedDetails = $details(activity_list($uid, 50));
check(in_array('public-log', $lockedDetails, true), '锁定状态下普通日志仍可见');
check(!in_array('private-log', $lockedDetails, true), '锁定状态下私密节点日志被过滤');
Auth::unlockPrivate();
check(in_array('private-log', $details(activity_list($uid, 50)), true), '解锁后私密节点日志恢复');

q_exec('DELETE FROM activities WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM user_settings WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
