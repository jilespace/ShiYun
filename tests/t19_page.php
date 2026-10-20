<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   大目录分页 + 元信息分批查询。

   此前 node_children 一次把整个目录查出来，随后 node_attach_meta 用
   IN (...) 一次性补元信息——目录上万条时，渲染、占位符数量都会失控。
   这里验分页行为、页脚统计口径，以及分批后元信息仍然完整。
   ========================================================================= */

const PAGE_TEST_COUNT = 650;   // 跨 3 页（页大小 300），且跨多个 400 一批的元信息分组

$email = 't19-page@example.test';
$u = find_user_by_email($email);
if ($u !== null) {
    $oid = (int) $u['id'];
    q_exec('DELETE FROM node_tags WHERE node_id IN (SELECT id FROM nodes WHERE user_id = ?)', [$oid]);
    q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM tags WHERE user_id = ?', [$oid]);
    q_exec('DELETE FROM users WHERE id = ?', [$oid]);
}
$uid = create_user($email, 'fixt-pass-123', '分页测试');
$root = user_root_id($uid);
$now = now();

$ph = [];
$params = [];
for ($i = 0; $i < PAGE_TEST_COUNT; $i++) {
    $ph[] = '(?,?,?,?,?,?,?,?,?,?)';
    array_push($params, $uid, $root, sprintf('f-%04d.txt', $i), sprintf('f-%04d.txt', $i),
        'file', 10, 'text/plain', 'txt', $now, $now);
}
q_exec('INSERT INTO nodes (user_id,parent_id,name,name_key,kind,size,mime,ext,created_at,updated_at) VALUES '
    . implode(',', $ph), $params);

// 会话必须在任何输出之前建立：check() 会写 stdout，
// 一旦有输出，ini_set('session.*') 就会失败（headers already sent）。
$ssid = 't19page' . bin2hex(random_bytes(8));
session_write_close();
ini_set('session.use_strict_mode', '0');
session_id($ssid);
session_start();
$_SESSION['user_id'] = $uid;
// 必须连会话纪元一起造，否则 Auth::user() 会把它当成改密前的旧 cookie 直接登出
$_SESSION['auth_epoch'] = (int) q_val('SELECT session_epoch FROM users WHERE id = ?', [$uid]);
session_write_close();

/* ------------------------------------------------------------- 分页行为 */

[$page1, $more1] = node_children_page($uid, $root, [], 300, 0);
check(count($page1) === 300, '第一页 300 条（实际 ' . count($page1) . '）');
check($more1 === true, '还有下一页');

[$page2, $more2] = node_children_page($uid, $root, [], 300, 300);
check(count($page2) === 300, '第二页 300 条');
check($more2 === true, '第二页之后仍有下一页');

[$page3, $more3] = node_children_page($uid, $root, [], 300, 600);
check(count($page3) === PAGE_TEST_COUNT - 600, '第三页只剩 50 条（实际 ' . count($page3) . '）');
check($more3 === false, '末页 hasMore 为 false');

// 页与页之间不能重复、也不能漏
$ids = array_merge(
    array_column($page1, 'id'),
    array_column($page2, 'id'),
    array_column($page3, 'id')
);
check(count($ids) === PAGE_TEST_COUNT, '三页拼起来正好是全部条目（无重无漏）');
check(count(array_unique($ids)) === PAGE_TEST_COUNT, '分页之间没有重复条目');

// 不分页的旧入口必须保持原样：回收站 / 收藏 / 搜索都依赖它
check(count(node_children($uid, $root, [])) === PAGE_TEST_COUNT, 'node_children 不传分页仍返回全部');

/* --------------------------------------------------------- 页脚统计口径 */

$stats = node_child_stats($uid, $root);
check($stats['files'] === PAGE_TEST_COUNT, '目录统计给出真实文件数（' . $stats['files'] . '），不随分页缩水');
check($stats['folders'] === 0, '目录统计里文件夹数为 0');
check($stats['bytes'] === PAGE_TEST_COUNT * 10, '目录统计给出真实字节数');

/* --------------------------------- 元信息分批后仍然完整（跨分组边界） */

$tagId = q_insert('tags', ['user_id' => $uid, 'name' => '跨组标签', 'color' => '#3d3aff', 'created_at' => $now]);
// 第 1 条、第 401 条、第 600 条分别落在不同的 400 分组里
$marked = [];
foreach ([0, 400, 600] as $idx) {
    $nodeId = (int) q_val(
        'SELECT id FROM nodes WHERE user_id = ? AND name = ?',
        [$uid, sprintf('f-%04d.txt', $idx)]
    );
    q_insert('node_tags', ['node_id' => $nodeId, 'tag_id' => $tagId]);
    $marked[$nodeId] = true;
}

$all = node_children($uid, $root, []);
$attached = 0;
foreach ($all as $row) {
    if (isset($marked[(int) $row['id']]) && ($row['tags'][0]['name'] ?? '') === '跨组标签') {
        $attached++;
    }
}
check($attached === 3, '分批查询后标签仍然全部挂上（跨 400 分组边界，实际 ' . $attached . '/3）');

/* ------------------------------------------------- 首屏只渲染一页（HTTP） */

$base = 'http://127.0.0.1:' . (getenv('TEST_HTTP_PORT') ?: '15244');
$get = static function (string $url, string $sid): string {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => 'Cookie: shiyun_sid=' . $sid,
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    return (string) @file_get_contents($url, false, $ctx);
};

$html = $get($base . '/index.php?r=files.list', $ssid);
check($html !== '', '首页请求成功（' . strlen($html) . ' 字节）');
check(substr_count($html, 'class="row"') === 300, '首屏只渲染一页（300 行），实际 ' . substr_count($html, 'class="row"'));
check(str_contains($html, 'data-listmore'), '首屏给出了「加载更多」哨兵');
check(str_contains($html, 'data-page-size="300"'), '前端能读到页大小');
check(str_contains($html, sprintf('%d 个文件', PAGE_TEST_COUNT)), '页脚用的是整体统计而不是当前页');

$json = $get($base . '/index.php?r=files.data&offset=300&limit=300', $ssid);
$data = json_decode($json, true)['data'] ?? [];
check(count($data['children'] ?? []) === 300, '按 offset 续取第二页正常');
check(($data['hasMore'] ?? null) === true, '续取响应带 hasMore');

$jsonAll = $get($base . '/index.php?r=files.data', $ssid);
check(count(json_decode($jsonAll, true)['data']['children'] ?? []) === PAGE_TEST_COUNT,
    '不传 offset 时仍返回全部（兼容页面上的常规刷新）');

/* --------------------------------------------------------------- 收尾 */

q_exec('DELETE FROM node_tags WHERE node_id IN (SELECT id FROM nodes WHERE user_id = ?)', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM tags WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM users WHERE id = ?', [$uid]);

finish();
