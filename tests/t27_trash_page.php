<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   回收站分页。
   此前回收站一次查全部条目；删得多了会和普通目录一样把响应撑爆。
   这里验分页、hasMore、整体统计口径与排序。
   ========================================================================= */

$email = 't27-trash-page@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM node_tags WHERE node_id IN (SELECT id FROM nodes WHERE user_id = ?)', [$oid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM tags WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}

$uid = create_user($email, 'fixt-pass-123', '回收站分页测试');
$trash = user_trash_id($uid);
$now = now();

$ph = [];
$params = [];
for ($i = 1; $i <= 350; $i++) {
    $ph[] = '(?,?,?,?,?,?,?,?,?,?,?)';
    array_push(
        $params,
        $uid,
        $trash,
        sprintf('trash-%03d.txt', $i),
        sprintf('trash-%03d.txt', $i),
        'file',
        10,
        'text/plain',
        'txt',
        $now,
        $now,
        date('Y-m-d H:i:s', time() - $i * 60)
    );
}
q_exec(
    'INSERT INTO nodes (user_id,parent_id,name,name_key,kind,size,mime,ext,created_at,updated_at,deleted_at) VALUES '
    . implode(',', $ph),
    $params
);

[$page1, $more1] = node_trash_page($uid, 300, 0);
check(count($page1) === 300, '回收站第一页 300 条（实际 ' . count($page1) . '）');
check($more1 === true, '回收站第一页之后仍有下一页');
check((string) $page1[0]['name'] === 'trash-001.txt', '按删除时间倒序：最近删除的在前');

[$page2, $more2] = node_trash_page($uid, 300, 300);
check(count($page2) === 50, '回收站第二页 50 条');
check($more2 === false, '回收站末页 hasMore=false');

$stats = node_trash_stats($uid);
check($stats['files'] === 350, '回收站统计按整体计数（不随分页变化）');
check($stats['folders'] === 0, '回收站统计文件夹数正确');
check($stats['bytes'] === 350 * 10, '回收站统计体积正确');

// 私密空间锁定时，回收站不能泄漏私密条目的名称/体积
q_insert('nodes', [
    'user_id'    => $uid,
    'parent_id'  => $trash,
    'name'       => 'private-deleted.txt',
    'name_key'   => 'private-deleted.txt',
    'kind'       => 'file',
    'size'       => 10,
    'mime'       => 'text/plain',
    'ext'        => 'txt',
    'is_private' => 1,
    'created_at' => $now,
    'updated_at' => $now,
    'deleted_at' => $now,
]);
Auth::lockPrivate();
check(node_trash_stats($uid)['files'] === 350, '锁定私密空间时回收站统计不含私密条目');
Auth::unlockPrivate();
check(node_trash_stats($uid)['files'] === 351, '解锁后回收站统计恢复私密条目');

q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM user_settings WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
