<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';
require_once BASE_PATH . '/app/modules/rss.php';

/* =========================================================================
   RSS 是可用令牌转发的公开凭据，不能成为绕过私密空间的入口。
   文件列表与动态都必须过滤 is_private。
   ========================================================================= */

$email = 't29-rss@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM activities WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}

$uid = create_user($email, 'fixt-pass-123', 'RSS 私密过滤测试');
$root = user_root_id($uid);
$privateRoot = user_private_id($uid);
$now = now();

$publicId = q_insert('nodes', [
    'user_id' => $uid, 'parent_id' => $root, 'name' => 'public-rss.txt',
    'name_key' => 'public-rss.txt', 'kind' => 'file', 'size' => 3,
    'mime' => 'text/plain', 'ext' => 'txt', 'is_private' => 0,
    'created_at' => $now, 'updated_at' => $now,
]);
$privateId = q_insert('nodes', [
    'user_id' => $uid, 'parent_id' => $privateRoot, 'name' => 'private-rss.txt',
    'name_key' => 'private-rss.txt', 'kind' => 'file', 'size' => 3,
    'mime' => 'text/plain', 'ext' => 'txt', 'is_private' => 1,
    'created_at' => $now, 'updated_at' => $now,
]);

$files = array_column(rss_file_items($uid, 50, 'http://example.test', 'token'), 'title');
check(in_array('public-rss.txt', $files, true), 'RSS 文件列表包含普通文件');
check(!in_array('private-rss.txt', $files, true), 'RSS 文件列表不包含私密文件');

q_insert('activities', [
    'user_id' => $uid, 'node_id' => $publicId, 'action' => 'file.favorite',
    'detail' => 'public-act', 'ip' => '127.0.0.1', 'created_at' => $now,
]);
q_insert('activities', [
    'user_id' => $uid, 'node_id' => $privateId, 'action' => 'file.favorite',
    'detail' => 'private-act', 'ip' => '127.0.0.1', 'created_at' => $now,
]);
$acts = array_column(rss_activity_items($uid, 50), 'title');
$flat = implode("\n", $acts);
check(str_contains($flat, 'public-act'), 'RSS 动态包含普通文件记录');
check(!str_contains($flat, 'private-act'), 'RSS 动态不包含私密文件记录');

// 搜索与搜索分面本来就隐藏私密内容，这里锁住这个契约
$facets = search_facets($uid);
check($facets['files'] === 1, '搜索分面只统计非私密文件');
$privateHits = search_nodes($uid, ['q' => 'private-rss', 'scope' => 'name']);
check($privateHits['total'] === 0, '搜索不返回私密文件');
$publicHits = search_nodes($uid, ['q' => 'public-rss', 'scope' => 'name']);
check($publicHits['total'] === 1, '搜索正常返回普通文件');

q_exec('DELETE FROM activities WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM user_settings WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
