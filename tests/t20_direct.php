<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

/* 直链（direct_links）：免登录、可撤销、可选绑来源 IP 的单文件字节流入口。 */

// ---------------------------------------------------------------- 内联白名单

check(mime_inline_ok('image/jpeg'), 'jpeg 应可内联');
check(mime_inline_ok('image/png'), 'png 应可内联');
check(mime_inline_ok('image/gif'), 'gif 应可内联');
check(mime_inline_ok('image/webp'), 'webp 应可内联');
check(mime_inline_ok('image/avif'), 'avif 应可内联');
check(mime_inline_ok('image/bmp'), 'bmp 应可内联');
check(mime_inline_ok('video/mp4'), 'mp4 应可内联');
check(mime_inline_ok('audio/mpeg'), 'mp3 应可内联');
check(mime_inline_ok('application/pdf'), 'pdf 应可内联');
check(mime_inline_ok('text/plain'), '纯文本应可内联');

// 带参数（真实响应里常见 image/jpeg; charset=...）
check(mime_inline_ok('text/plain; charset=utf-8'), '带参数的 MIME 应能识别');
check(mime_inline_ok('IMAGE/PNG'), '大小写不敏感');

// 这些能执行脚本，绝不能内联
check(!mime_inline_ok('text/html'), 'html 不得内联');
check(!mime_inline_ok('image/svg+xml'), 'svg 不得内联（可含 script）');
check(!mime_inline_ok('application/xhtml+xml'), 'xhtml 不得内联');
check(!mime_inline_ok('application/javascript'), 'js 不得内联');
check(!mime_inline_ok('application/xml'), 'xml 不得内联');
check(!mime_inline_ok('text/xml'), 'text/xml 不得内联');

// 未知 / 空
check(!mime_inline_ok('application/octet-stream'), '未知类型不得内联');
check(!mime_inline_ok(''), '空 MIME 不得内联');

// ------------------------------------------------- blob_stream 的守卫与 HEAD

$storage = (string) file_get_contents(BASE_PATH . '/app/lib/storage.php');
$guardPos = strpos($storage, 'mime_inline_ok($mime)');
$dispPos  = strpos($storage, "header('Content-Disposition: '");
check(str_contains($storage, 'mime_inline_ok($mime)'),
    'blob_stream 应用 mime_inline_ok 守卫内联');
// 必须两端都存在才比较：strpos 未命中返回 false，false 参与 < 会被当成 0，
// 顺序断言会在守卫缺失时假过——正是它该报错的场景。
check($guardPos !== false && $dispPos !== false && $guardPos < $dispPos,
    '守卫必须在写 Content-Disposition 之前生效'
);

// 必须匹配完整的 HEAD 守卫表达式。只找 "'HEAD'" 会命中 blob_direct_url 里的
// s3_request($config, 'HEAD', ...)，那处在 Content-Disposition 之前，顺序断言会假挂。
$headGuard = "\$_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD'";
$headPos = strpos($storage, $headGuard);
check($headPos !== false, 'blob_stream 应短路 HEAD');
check($headPos !== false && $headPos > $dispPos,
    'HEAD 短路必须写在 header 块之后（否则会漏掉 Content-Length）'
);

// ------------------------------------------- blob_direct_url 的 disposition

$tmpT3 = sys_get_temp_dir() . '/t15-t3.bin';
file_put_contents($tmpT3, 'inline-me');
$blobT3 = blob_ingest_file($tmpT3, null, 'text/plain');
$blobT3Id = (int) $blobT3['blob_id'];
$userT3 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');

// 无可用的对象存储副本 → 两个 disposition 都返回 null（回退本地）
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt', 'inline') === null,
    '无副本时 inline 请求也应回退');
check(blob_direct_url($userT3, $blobT3Id, 'text/plain', 'a.txt') === null,
    '默认参数应保持既有行为（attachment）');

$ref = new ReflectionFunction('blob_direct_url');
check($ref->getParameters()[4]->getName() === 'disposition',
    'blob_direct_url 第 5 个参数应为 disposition');
check($ref->getParameters()[4]->getDefaultValue() === 'attachment',
    'blob_direct_url 的 disposition 默认值应为 attachment');

$refD = new ReflectionFunction('blob_deliver');
check($refD->getParameters()[5]->getName() === 'disposition',
    'blob_deliver 第 6 个参数应为 disposition');
check($refD->getParameters()[5]->getDefaultValue() === 'attachment',
    'blob_deliver 的 disposition 默认值应为 attachment');

// 签名存在 ≠ 参数被使用：把实现那两行也钉住
check(strpos($storage, "\$disposition . '; filename") !== false,
    'blob_direct_url 应把 disposition 用在 presign 的 response-content-disposition 上');
check(str_contains($storage, '$disposition === \'inline\''),
    'blob_deliver 的本地回退应由 disposition 推导 inline');

// 302 路径必须与 blob_stream() 共用同一份内联白名单：否则 text/html 会被
// 当作 inline 从本域发出，正是本功能要防的存储型 XSS。
check(str_contains($storage, 'mime_inline_ok($mime) ? $disposition : '),
    'blob_direct_url 应对非白名单类型强制降级为 attachment');

// ------------------------------------------------------------ direct_links 表

db();

check((bool) q_val("SHOW TABLES LIKE 'direct_links'"), 'direct_links 表应已创建');

$colsT4 = array_column(q_all('SHOW COLUMNS FROM direct_links'), 'Field');
foreach (['user_id', 'node_id', 'token', 'ip_allow', 'expires_at',
          'status', 'hit_count', 'last_access_at', 'created_at'] as $c) {
    check(in_array($c, $colsT4, true), "direct_links 应有列 {$c}");
}

$statusTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'status') {
        $statusTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($statusTypeT4, 'active') && str_contains($statusTypeT4, 'revoked'),
    'status 枚举应为 active / revoked');

$tokTypeT4 = '';
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    if ($row['Field'] === 'token') {
        $tokTypeT4 = (string) $row['Type'];
    }
}
check(str_contains($tokTypeT4, 'char(32)'), 'token 应为 CHAR(32)');

$idxT4 = array_column(q_all('SHOW INDEX FROM direct_links'), 'Key_name');
check(in_array('uk_direct_token', $idxT4, true), 'token 应有唯一索引');

// ip_allow 与 expires_at 必须可空（不限来源 / 永久有效）
$nullsT4 = [];
foreach (q_all('SHOW COLUMNS FROM direct_links') as $row) {
    $nullsT4[$row['Field']] = (string) $row['Null'];
}
check($nullsT4['ip_allow'] === 'YES', 'ip_allow 应可空');
check($nullsT4['expires_at'] === 'YES', 'expires_at 应可空');

// ---------------------------------------------------------- directs 基础件

// 令牌
$tk = direct_token();
check(preg_match('/^[a-f0-9]{32}$/', $tk) === 1, '令牌应为 32 位小写十六进制');
check(direct_token() !== direct_token(), '两次令牌应不同');

// IP 规范化
check(direct_normalize_ip('203.0.113.7') === '203.0.113.7', 'IPv4 单地址应原样通过');
check(direct_normalize_ip('203.0.113.0/24') === '203.0.113.0/24', 'IPv4 CIDR 应通过');
check(direct_normalize_ip('  203.0.113.7  ') === '203.0.113.7', '应去除首尾空白');
check(direct_normalize_ip('2001:db8::1') === '2001:db8::1', 'IPv6 单地址应通过');
check(direct_normalize_ip('') === null, '空串应返回 null');
check(direct_normalize_ip('203.0.113.0/33') === null, '掩码超 32 应拒绝');
check(direct_normalize_ip('999.1.1.1') === null, '非法 IPv4 应拒绝');
check(direct_normalize_ip('2001:db8::/32') === null, 'IPv6 CIDR 应拒绝');
check(direct_normalize_ip('随便写点中文') === null, '乱码应拒绝');
check(direct_normalize_ip('203.0.113.0/abc') === null, '非数字掩码应拒绝');

// IP 匹配
$rowNoIp = ['ip_allow' => null];
check(direct_ip_ok($rowNoIp, '198.51.100.9'), 'ip_allow 为空应放行任何来源');
check(direct_ip_ok(['ip_allow' => ''], '198.51.100.9'), 'ip_allow 为空串应放行');

$rowIp = ['ip_allow' => '203.0.113.7'];
check(direct_ip_ok($rowIp, '203.0.113.7'), '单 IP 命中');
check(!direct_ip_ok($rowIp, '203.0.113.8'), '单 IP 不中');
// 前缀比较陷阱：203.0.113.7 不得匹配 203.0.113.70
check(!direct_ip_ok($rowIp, '203.0.113.70'), '不得退化为字符串前缀比较');

$rowCidr = ['ip_allow' => '203.0.113.0/24'];
check(direct_ip_ok($rowCidr, '203.0.113.7'), 'CIDR 内应命中');
check(direct_ip_ok($rowCidr, '203.0.113.255'), 'CIDR 边界应命中');
check(!direct_ip_ok($rowCidr, '203.0.114.1'), 'CIDR 外不应命中');

// CIDR 边界：/0 全放行，/32 只放行一个
check(direct_ip_ok(['ip_allow' => '0.0.0.0/0'], '198.51.100.9'), '/0 应放行任何 IPv4');
check(direct_ip_ok(['ip_allow' => '203.0.113.7/32'], '203.0.113.7'), '/32 应命中自身');
check(!direct_ip_ok(['ip_allow' => '203.0.113.7/32'], '203.0.113.8'), '/32 不应命中邻居');

// 非字节对齐的掩码：/25
check(direct_ip_ok(['ip_allow' => '203.0.113.0/25'], '203.0.113.127'), '/25 上半段应命中');
check(!direct_ip_ok(['ip_allow' => '203.0.113.0/25'], '203.0.113.128'), '/25 下半段不应命中');

// IPv6
check(direct_ip_ok(['ip_allow' => '2001:db8::1'], '2001:db8::1'), 'IPv6 精确命中');
check(!direct_ip_ok(['ip_allow' => '2001:db8::1'], '2001:db8::2'), 'IPv6 不中');

// 存活判定
check(direct_is_live(['status' => 'active', 'expires_at' => null]), '无过期应存活');
check(!direct_is_live(['status' => 'revoked', 'expires_at' => null]), '已撤销不应存活');
check(direct_is_live(['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]),
    '未过期应存活');
check(!direct_is_live(['status' => 'active', 'expires_at' => date('Y-m-d H:i:s', time() - 60)]),
    '已过期不应存活');

// 查找
check(direct_find('') === null, '空令牌应返回 null');
check(direct_find('zzzz') === null, '格式非法应返回 null');
check(direct_find(strtoupper($tk)) === null,
    '大写令牌返回 null——random_token 只产小写，校验正则也拒绝大写');
check(direct_find(str_repeat('a', 32)) === null, '未入库的令牌应返回 null');

// -------------------------------------------------------- direct_resolve

$uidT6 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
// 本块会被反复执行（RED → GREEN → 复审复跑），而 users.email 与同目录下的文件夹名
// 都是唯一的：先清掉上一轮的测试用户与同名文件夹，否则第二次运行会撞唯一键。
// 文件节点不必清理——node_create_file() 遇到同名会自动改名。
q_exec('DELETE FROM users WHERE email = ?', ['t15other@example.test']);
q_exec('DELETE FROM nodes WHERE user_id = ? AND name = ?', [$uidT6, 't15-folder']);
$otherT6 = q_insert('users', [
    'email' => 't15other@example.test', 'username' => 't15other',
    'display_name' => 't15other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'quota_bytes' => 1024 ** 3, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now(),
]);

$tmpT6 = sys_get_temp_dir() . '/t15-t6.txt';
file_put_contents($tmpT6, 'hello direct');
$blobT6 = blob_ingest_file($tmpT6, null, 'text/plain');
$nodeT6 = node_create_file($uidT6, user_root_id($uidT6), 't15-direct.txt', $blobT6, 'text/plain');
$nodeT6Id = (int) $nodeT6['id'];

/** 造一条直链，返回 [token, id] */
$mkLink = static function (array $over = []) use ($uidT6, $nodeT6Id): array {
    $token = direct_token();
    $id = q_insert('direct_links', $over + [
        'user_id'    => $uidT6,
        'node_id'    => $nodeT6Id,
        'token'      => $token,
        'ip_allow'   => null,
        'expires_at' => null,
        'status'     => 'active',
        'hit_count'  => 0,
        'created_at' => now(),
    ]);
    return [$token, (int) $id];
};

// 1. token 格式非法
$r = direct_resolve('nope', '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '格式非法 → 404');

// 2. 未入库
$r = direct_resolve(str_repeat('a', 32), '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '未入库 → 404');

// 3. 已撤销
[$tkRev, $idRev] = $mkLink(['status' => 'revoked']);
$r = direct_resolve($tkRev, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '已撤销 → 410');

// 4. 已过期
[$tkExp] = $mkLink(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);
$r = direct_resolve($tkExp, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '已过期 → 410');

// 5. IP 不匹配
[$tkIp] = $mkLink(['ip_allow' => '198.51.100.0/24']);
$r = direct_resolve($tkIp, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 403, 'IP 不匹配 → 403');

// 6. 全部通过
[$tkOk] = $mkLink();
$r = direct_resolve($tkOk, '203.0.113.1');
check($r['ok'] === true && $r['code'] === 200, '全部通过 → ok');
check((int) ($r['row']['id'] ?? 0) > 0, '通过时 row 应已填好');
check((int) ($r['node']['id'] ?? 0) === $nodeT6Id, '通过时 node 应已填好');

// IP 匹配时同样通过
[$tkIpOk] = $mkLink(['ip_allow' => '203.0.113.0/24']);
$r = direct_resolve($tkIpOk, '203.0.113.55');
check($r['ok'] === true, 'IP 匹配 → ok');

// 7. 节点进回收站 → 410
$tmpT6b = sys_get_temp_dir() . '/t15-t6b.txt';
file_put_contents($tmpT6b, 'trash me');
$blobT6b = blob_ingest_file($tmpT6b, null, 'text/plain');
$nodeT6b = node_create_file($uidT6, user_root_id($uidT6), 't15-trash.txt', $blobT6b, 'text/plain');
[$tkTrash] = $mkLink(['node_id' => (int) $nodeT6b['id']]);
q_update('nodes', ['deleted_at' => now()], 'id = ?', [(int) $nodeT6b['id']]);
$r = direct_resolve($tkTrash, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 410, '节点在回收站 → 410');

// 8. 恢复后原令牌复活（令牌未变）
q_update('nodes', ['deleted_at' => null], 'id = ?', [(int) $nodeT6b['id']]);
$r = direct_resolve($tkTrash, '203.0.113.1');
check($r['ok'] === true, '恢复后原令牌应复活');

// 9. 文件夹 → 404
$folderT6 = node_create_folder($uidT6, user_root_id($uidT6), 't15-folder');
[$tkFolder] = $mkLink(['node_id' => (int) $folderT6['id']]);
$r = direct_resolve($tkFolder, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 404, '节点是文件夹 → 404');

// 10. 私密空间 → 403
$tmpT6c = sys_get_temp_dir() . '/t15-t6c.txt';
file_put_contents($tmpT6c, 'private');
$blobT6c = blob_ingest_file($tmpT6c, null, 'text/plain');
// 私密空间是按路径判定的——node_in_private() 看 path 是否落在私密根下，
// is_private 列只是这个判定的派生缓存。所以节点必须建在私密根里；
// 直接翻转 is_private 列会造出「列说是、路径说不是」的矛盾状态，
// 而 direct_resolve() 走的是路径判定，测试会假挂。
$nodeT6c = node_create_file($uidT6, user_private_id($uidT6), 't15-private.txt', $blobT6c, 'text/plain');
[$tkPriv] = $mkLink(['node_id' => (int) $nodeT6c['id']]);
$r = direct_resolve($tkPriv, '203.0.113.1');
check($r['ok'] === false && $r['code'] === 403, '私密空间 → 403');

// 11. 跨用户隔离：别人的令牌取不到我的文件
[$tkCross] = $mkLink();
q_update('direct_links', ['user_id' => $otherT6], 'token = ?', [$tkCross]);
$r = direct_resolve($tkCross, '203.0.113.1');
check($r['ok'] === false, 'user_id 被改到别人名下后不应再放行');

// 12. 同一文件多条直链互不影响
//     上面 $mkLink() 默认都建在 $nodeT6Id 上，此刻该节点已有若干条
$linkCountT6 = (int) q_val('SELECT COUNT(*) FROM direct_links WHERE node_id = ?', [$nodeT6Id]);
check($linkCountT6 >= 3, '同一文件应能挂多条直链');
// 其中 $tkRev 已撤销，$tkOk 仍生效——撤销一条不影响另一条
check(direct_resolve($tkRev, '203.0.113.1')['ok'] === false, '被撤销的那条仍应拒绝');
check(direct_resolve($tkOk, '203.0.113.1')['ok'] === true, '未被撤销的那条不受影响');

// ------------------------------------------------------------- 路由与装载

$router = (string) file_get_contents(BASE_PATH . '/app/router.php');
check(str_contains($router, "'direct.serve'"), 'direct.serve 应登记进 PUBLIC_ROUTES');

$boot = (string) file_get_contents(BASE_PATH . '/app/bootstrap.php');
check(str_contains($boot, "lib/directs.php"), 'bootstrap 应装载 lib/directs.php');

$mod = (string) file_get_contents(BASE_PATH . '/app/modules/direct.php');
check(str_contains($mod, 'function direct_serve'), 'direct.php 应有 direct_serve');
check(str_contains($mod, 'function direct_create'), 'direct.php 应有 direct_create');
check(str_contains($mod, 'function direct_revoke'), 'direct.php 应有 direct_revoke');
check(str_contains($mod, "require_method('POST')"), '写接口应限制 POST');
check(str_contains($mod, 'require_csrf()'), '写接口应校验 CSRF');
check(str_contains($mod, "'Allow: GET, HEAD'"), 'serve 应声明 Allow 头');
check(str_contains($mod, 'mime_inline_ok($mime)'), 'serve 应用内联白名单决定 disposition');

// 直链绝不写操作日志（视频拖动进度条会高频命中）。
// 不能整文件扫 activity_log——direct_create / direct_revoke 是正当要写的，
// 只有 direct_serve 不能写。所以切出 direct_serve 的函数体再查。
$serveStart = strpos($mod, 'function direct_serve');
$serveEnd = strpos($mod, 'function direct_create');
check($serveStart !== false && $serveEnd !== false && $serveEnd > $serveStart,
    'direct.php 应含 direct_serve 且其后的 direct_create');
$serveBody = substr($mod, (int) $serveStart, (int) $serveEnd - (int) $serveStart);
check(!str_contains($serveBody, 'activity_log'), 'direct_serve 不得写 activity_log');

// activity_label 覆盖新动作
$act = (string) file_get_contents(BASE_PATH . '/app/lib/activity.php');
check(str_contains($act, "'direct.create'"), 'activity_label 应覆盖 direct.create');
check(str_contains($act, "'direct.revoke'"), 'activity_label 应覆盖 direct.revoke');

// ------------------------------------------------------------ 节点角标计数

$uidT8 = (int) q_val('SELECT id FROM users ORDER BY id LIMIT 1');
$tmpT8 = sys_get_temp_dir() . '/t15-t8.txt';
file_put_contents($tmpT8, 'badge');
$blobT8 = blob_ingest_file($tmpT8, null, 'text/plain');
$nodeT8 = node_create_file($uidT8, user_root_id($uidT8), 't15-badge.txt', $blobT8, 'text/plain');
$nodeT8Id = (int) $nodeT8['id'];

$pub = node_public(node_get($uidT8, $nodeT8Id));
check(array_key_exists('directCount', $pub), 'node_public 应含 directCount');
check($pub['directCount'] === 0, '无直链时 directCount 应为 0');

q_insert('direct_links', [
    'user_id' => $uidT8, 'node_id' => $nodeT8Id, 'token' => direct_token(),
    'ip_allow' => null, 'expires_at' => null, 'status' => 'active',
    'hit_count' => 0, 'created_at' => now(),
]);
q_insert('direct_links', [
    'user_id' => $uidT8, 'node_id' => $nodeT8Id, 'token' => direct_token(),
    'ip_allow' => null, 'expires_at' => null, 'status' => 'revoked',
    'hit_count' => 0, 'created_at' => now(),
]);

$children = node_children($uidT8, user_root_id($uidT8), ['sort' => 'name', 'dir' => 'asc', 'showHidden' => true]);
$badgeCount = null;
foreach ($children as $c) {
    if ((int) $c['id'] === $nodeT8Id) {
        $badgeCount = (int) $c['direct_count'];
    }
}
check($badgeCount === 1, '只有 active 的直链计入角标（revoked 不算）');

$js = (string) file_get_contents(BASE_PATH . '/assets/js/files.js');
check(str_contains($js, 'directCount'), 'files.js 应读取 directCount');
check(str_contains($js, "icon('zap'"), 'files.js 应用 zap 图标做直链角标');

finish();
