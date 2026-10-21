<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   对象存储管理页的回归测试。
   本文件按任务增量扩展：账号 → 协议 → 设置 → 上传/下载/复制/删除。
   ========================================================================= */

$email = 't23-objects@example.test';
$otherEmail = 't23-other@example.test';
foreach ([$email, $otherEmail] as $e) {
    $u = find_user_by_email($e);
    if ($u !== null) {
        $oid = (int) $u['id'];
        q_exec('DELETE FROM object_accounts WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM nodes WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM user_settings WHERE user_id = ?', [$oid]);
        q_exec('DELETE FROM users WHERE id = ?', [$oid]);
    }
}
$uid = create_user($email, 'fixt-pass-123', '对象存储');
$otherId = create_user($otherEmail, 'fixt-pass-123', '旁观者');

echo "\n· 账号\n";
$id = object_account_save($uid, [
    'name' => '腾讯云主账号',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETEXAMPLE',
]);
check($id > 0, '新建账号返回 id');

$list = object_account_list($uid);
check(count($list) === 1, '账号列表返回 1 条');
check($list[0]['secret_key'] === '••••••', '账号列表掩码 Secret Key');
check($list[0]['session_token'] === '', '没有临时令牌时不回显掩码');
check(object_account_get($otherId, $id) === null, '别的用户读不到该账号');

object_account_save($uid, [
    'name' => '改名',
    'provider' => 'cos',
    'endpoint' => 'https://cos.ap-guangzhou.myqcloud.com',
    'region' => 'ap-guangzhou',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => '••••••',
], $id);
$row = object_account_get($uid, $id);
check($row !== null && $row['name'] === '改名', '更新账号名称');
check($row !== null && $row['secret_key'] === 'SECRETEXAMPLE', '掩码原样回传时不覆盖已存密钥');

$origins = object_account_origins($uid);
check(in_array('https://cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含精确 endpoint');
check(in_array('https://*.cos.ap-guangzhou.myqcloud.com', $origins, true), 'CSP 来源包含桶名子域通配');

$bad = false;
try {
    object_account_save($uid, [
        'name' => '非法', 'provider' => 'cos', 'endpoint' => 'file:///etc/passwd',
        'region' => 'ap-guangzhou', 'access_key' => 'a', 'secret_key' => 'b',
    ]);
} catch (InvalidArgumentException $e) {
    $bad = true;
}
check($bad, '非 http/https endpoint 被拒');

echo "\n· 键名与桶名\n";
check(object_key_valid('photos/2026/a b+c.jpg'), '普通对象键合法');
check(!object_key_valid(''), '空键非法');
check(!object_key_valid("a\0b"), '含 NUL 的键非法');
check(!object_key_valid(str_repeat('a', 1025)), '超过 1024 字节的键非法');
check(object_key_join('photos/', '/2026/a.jpg') === 'photos/2026/a.jpg', '键拼接去掉多余斜杠');
check(object_cos_bucket_name_valid('photos-1250000000'), 'COS 桶名带 APPID 合法');
check(!object_cos_bucket_name_valid('photos'), 'COS 桶名缺 APPID 非法');
check(!object_cos_bucket_name_valid('Photos-1250000000'), 'COS 桶名大写非法');
check(object_storage_class_or_fail('standard_ia') === 'STANDARD_IA', '存储类型归一化为大写');
$badClass = false;
try { object_storage_class_or_fail('GLACIER'); } catch (InvalidArgumentException $e) { $badClass = true; }
check($badClass, '未知存储类型被拒');

echo "\n· 绑定守卫\n";
q_insert('storage_sources', [
    'user_id' => $uid, 'name' => '照片桶', 'driver' => 's3',
    'config' => json_encode(['bucket' => 'photos-1250000000']),
    'is_default' => 0, 'priority' => 1, 'status' => 'ok', 'created_at' => now(),
]);
check(object_bound_by($uid, 'photos-1250000000') === '照片桶', '已绑定桶能查到存储源名称');
check(object_bound_by($uid, 'other-1250000000') === null, '未绑定桶返回 null');
check(object_bound_by($otherId, 'photos-1250000000') === null, '别的用户看不到该绑定');

echo "\n· CSP\n";
$csp = csp_content(['https://cos.ap-guangzhou.myqcloud.com']);
check(str_contains($csp, "connect-src 'self' https://cos.ap-guangzhou.myqcloud.com;"), 'CSP 追加对象存储来源');
check(str_contains($csp, "script-src 'self' 'nonce-"), 'CSP 仍带脚本 nonce');
check(str_contains(csp_content(), "connect-src 'self';"), '无额外来源时 connect-src 仍只有 self');

object_account_delete($uid, $id);
check(object_account_get($uid, $id) === null, '删除账号');

/* ------------------------------------------------------------ 启动存根 */

$logFile = (string) tempnam(sys_get_temp_dir(), 'stub-s3-');
@unlink($logFile);

// 让内核分配一个空闲端口，再立刻释放给 php -S 用
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($probe === false) {
    check(false, '无法分配测试端口：' . $errstr);
    finish();
}
$port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
fclose($probe);

// 子进程的 stdout/stderr 必须重定向到文件而不是管道：
// php -S 每个请求都会往 stderr 写一行访问日志，用管道又没人读，
// 缓冲区写满后服务进程会阻塞在 write 上，后续请求全部拿不到响应。
$serverLog = (string) tempnam(sys_get_temp_dir(), 'stub-s3-srv-');
$descriptors = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', $serverLog, 'a'],
    2 => ['file', $serverLog, 'a'],
];
$proc = proc_open(
    sprintf('%s -S 127.0.0.1:%d %s', PHP_BINARY, $port, escapeshellarg(__DIR__ . '/stub_s3.php')),
    $descriptors,
    $pipes,
    null,
    ['STUB_S3_LOG' => $logFile, 'STUB_S3_SERVICE_HOST' => 'localhost'] + $_ENV
);
if (!is_resource($proc)) {
    check(false, '无法启动 S3 存根服务');
    finish();
}

register_shutdown_function(static function () use ($proc, $serverLog): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    @unlink($serverLog);
});

// 等端口就绪
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $conn = @stream_socket_client('tcp://127.0.0.1:' . $port, $e1, $e2, 0.3);
    if ($conn !== false) {
        fclose($conn);
        $ready = true;
        break;
    }
    usleep(100000);
}
check($ready, 'S3 存根服务已就绪（端口 ' . $port . '）');
if (!$ready) {
    finish();
}

/** 读取存根记录的请求；$index 为倒数第几个（0 = 最近一条） */
$requests = static function (int $index = 0) use ($logFile): ?array {
    $lines = is_file($logFile) ? (array) file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    if ($lines === []) {
        return null;
    }
    $line = $lines[count($lines) - 1 - $index] ?? null;
    return $line === null ? null : (json_decode($line, true) ?: null);
};

/* ------------------------------------------------------------ 协议层 */

$base = 'http://localhost:' . $port;
$cfg = [
    'endpoint' => $base, 'region' => 'ap-guangzhou', 'bucket' => 'photos-1250000000',
    'access_key' => 'AKIDEXAMPLE', 'secret_key' => 'SECRETEXAMPLE', 'prefix' => '',
];

echo "\n· 签名头\n";
s3_request($cfg, 'PUT', 'target.txt', [
    'headers' => ['x-amz-copy-source' => '/photos-1250000000/a b+c.jpg'],
    'payload_hash' => hash('sha256', ''),
]);
$req = $requests(0);
$headers = array_change_key_case((array) ($req['headers'] ?? []), CASE_LOWER);
check(isset($headers['x-amz-copy-source']), '额外头出现在请求里');
check(str_contains((string) ($req['authHeader'] ?? ''), 'x-amz-copy-source'), '额外头参与签名');

echo "\n· STS 预签名\n";
$url = s3_presign($cfg + ['session_token' => 'TOKEN+WITH/SLASH'], 'GET', 'a.txt', 300);
check(str_contains($url, 'X-Amz-Security-Token=TOKEN%2BWITH%2FSLASH'), '临时令牌进入预签名查询串');

$signed = s3_presign($cfg, 'PUT', 'a.txt', 300, [], [
    'content-type' => 'text/plain',
    'x-amz-storage-class' => 'STANDARD_IA',
]);
check(str_contains($signed, 'X-Amz-SignedHeaders=content-type%3Bhost%3Bx-amz-storage-class'),
    '预签名把额外头纳入签名');

echo "\n· ListObjectsV2\n";
$page = s3_list_objects_page($cfg, 'photos-1250000000', ['prefix' => 'photos/', 'delimiter' => '/']);
check(count($page['folders']) === 1 && $page['folders'][0]['name'] === '2026', '解析 CommonPrefixes');
check(count($page['objects']) === 2, '解析两个对象');
check($page['objects'][0]['name'] === 'a b+c.jpg', '对象名去掉前缀并保留空格与加号');
check($page['objects'][0]['etag'] === '"etag-a"', '解析 ETag');
check($page['objects'][1]['storage_class'] === 'STANDARD_IA', '解析存储类型');
check($page['truncated'] === true && $page['next_token'] === 'page-2', '解析分页游标');

$req = $requests(0);
check(str_contains((string) ($req['uri'] ?? ''), 'prefix=photos%2F'), '请求带 prefix');
check(str_contains((string) ($req['uri'] ?? ''), 'delimiter=%2F'), '请求带 delimiter');

$page2 = s3_list_objects_page($cfg, 'photos-1250000000', ['prefix' => 'photos/', 'continuation_token' => 'page-2']);
check($page2['truncated'] === false && $page2['objects'] === [], '第二页返回空且未截断');

echo "\n· 搜索前缀的显示名\n";
// Task 8 的搜索前缀可能不以 / 结尾（photos/abc），此时展示名只应去掉到最后一个 / 为止。
$searchXml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>'
    . '<CommonPrefixes><Prefix>photos/abc2026/</Prefix></CommonPrefixes>'
    . '<Contents><Key>photos/abcdef.txt</Key><Size>1</Size></Contents>'
    . '<Contents><Key>photos/abc</Key><Size>2</Size></Contents>'
    . '<Contents><Key>photos/a b+c.jpg</Key><Size>3</Size></Contents>'
    . '<IsTruncated>false</IsTruncated></ListBucketResult>';

$search = s3_parse_object_page($searchXml, 'photos/abc');
check($search['objects'][0]['name'] === 'abcdef.txt', '非边界前缀不吞掉键名前段');
check($search['objects'][1]['name'] === 'abc', '键恰好等于前缀时显示名不为空');
check($search['objects'][0]['key'] === 'photos/abcdef.txt', '原始 key 字段不受显示名影响');
check($search['folders'][0]['name'] === 'abc2026', 'CommonPrefixes 同样按路径边界去前缀');

$dirParse = s3_parse_object_page($searchXml, 'photos/');
check($dirParse['objects'][2]['name'] === 'a b+c.jpg', '目录浏览（前缀以 / 结尾）显示名保持不变');

echo "\n· 对象级协议\n";
$head = s3_head_object($cfg, 'photos-1250000000', 'a.txt');
check($head !== null && $head['size'] === 1024, 'HEAD 解析大小');
check($head !== null && $head['storage_class'] === 'STANDARD_IA', 'HEAD 解析存储类型');
check(s3_head_object($cfg, 'photos-1250000000', 'missing.txt') === null, 'HEAD 404 返回 null');

$copy = s3_copy_object($cfg, 'photos-1250000000', 'a b+c.jpg', 'copied/中文.txt');
check($copy['ok'] === true, '复制成功');
$req = $requests(0);
$headers = array_change_key_case((array) ($req['headers'] ?? []), CASE_LOWER);
check(($headers['x-amz-copy-source'] ?? '') === '/photos-1250000000/a%20b%2Bc.jpg', '复制源键按路径段编码');

$restore = s3_restore_object($cfg + ['provider' => 'cos'], 'photos-1250000000', 'cold.bin', 2);
check($restore['ok'] === true, '解冻返回成功');
$req = $requests(0);
$restoreBody = (string) ($req['body'] ?? '');
check(str_contains($restoreBody, '<Days>2</Days>'), '解冻请求体带天数');
check(str_contains($restoreBody, '<CASJobParameters><Tier>Standard</Tier></CASJobParameters>'), 'COS 解冻层级包在 CASJobParameters');
check(!str_contains($restoreBody, 'GlacierJobParameters'), 'COS 解冻不带 GlacierJobParameters');

$restoreS3 = s3_restore_object($cfg + ['provider' => 's3'], 'photos-1250000000', 'cold.bin', 2);
check($restoreS3['ok'] === true, 'S3 解冻返回成功');
$req = $requests(0);
$restoreBody = (string) ($req['body'] ?? '');
check(str_contains($restoreBody, '<GlacierJobParameters><Tier>Standard</Tier></GlacierJobParameters>'), 'S3 解冻层级包在 GlacierJobParameters');

$version = s3_get_subresource($cfg, 'photos-1250000000', 'versioning');
check(in_array($version['status'], [200, 404], true), '子资源 GET 返回状态码');
$put = s3_put_subresource($cfg, 'photos-1250000000', 'versioning', '<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>');
check($put['ok'] === true, '子资源 PUT 成功');

$err = s3_error_message(['status' => 400, 'body' => '<Error><Code>EntityTooLarge</Code></Error>', 'headers' => ''], '上传失败');
check(str_contains($err, '单次上传上限'), '翻译 EntityTooLarge');
$err2 = s3_error_message(['status' => 404, 'body' => '<Error><Code>NoSuchCORSConfiguration</Code></Error>', 'headers' => ''], '读取失败');
check(str_contains($err2, '未配置 CORS'), '翻译 NoSuchCORSConfiguration');

echo "\n· 页面骨架\n";
$nav = (string) file_get_contents(BASE_PATH . '/app/lib/nav.php');
check(str_contains($nav, "'objects'") && str_contains($nav, 'objects.index'), '导航新增对象存储入口');
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_index'), '模块提供页面入口');
check(str_contains($module, 'csp_content(object_account_origins'), '页面渲染前扩展 CSP');
$view = (string) file_get_contents(BASE_PATH . '/app/views/objects.php');
check(str_contains($view, 'data-page="objects"'), '视图标记页面');
check(str_contains($view, 'data-config'), '视图注入前端配置');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, "objects.account_save"), '前端提交账号保存');
check(str_contains($js, 'state.accounts.some'), '前端校正已删除账号的失效 id');
$css = (string) file_get_contents(BASE_PATH . '/assets/css/console.css');
check(!str_contains($css, 'var(--accent)'), '对象存储样式使用已定义的主题变量');

echo "\n· 桶接口\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_buckets'), '桶列表接口存在');
check(str_contains($module, 'function objects_bucket_bind'), '绑定接口存在');
check(str_contains($module, 'function objects_assert_mutable'), '写操作有绑定守卫');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, '__objectsLoadBuckets'), '前端加载桶列表');
check(str_contains($js, 'objects.bucket_create'), '前端可建桶');
check(str_contains($js, 'objects.bucket_bind'), '前端可绑定桶');
check(str_contains($js, 'objects.bucket_delete'), '前端可删桶');
check(str_contains($js, 'objects.bucket_purge'), '前端可清空桶');

echo "\n· 桶设置 XML\n";
check(object_versioning_parse('<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>') === 'Enabled', '解析版本控制');
check(str_contains(object_versioning_xml('Suspended'), '<Status>Suspended</Status>'), '生成版本控制');

$site = object_website_parse('<WebsiteConfiguration><IndexDocument><Suffix>index.html</Suffix></IndexDocument></WebsiteConfiguration>');
check($site['enabled'] === true && $site['index'] === 'index.html', '解析静态网站');
check(str_contains(object_website_xml('index.html', '404.html'), '<Key>404.html</Key>'), '生成静态网站');

$corsXml = object_cors_xml([[
    'id' => 'site', 'origins' => ['http://localhost:15244'], 'methods' => ['GET', 'PUT'],
    'headers' => ['*'], 'expose' => ['ETag'], 'max_age' => 600,
]]);
$rules = object_cors_parse($corsXml);
check(count($rules) === 1 && $rules[0]['origins'][0] === 'http://localhost:15244', 'CORS 往返');
check(object_cors_allows($rules, 'http://localhost:15244', ['GET', 'PUT']) === [true, []], 'CORS 放行判断');
[$ok, $issues] = object_cors_allows($rules, 'https://evil.example', ['GET']);
check($ok === false && $issues !== [], 'CORS 拒绝陌生来源');

$merged = object_cors_merge_origin($rules, 'https://shiyun.example');
[$ok2] = object_cors_allows($merged, 'https://shiyun.example', ['GET', 'PUT', 'HEAD']);
check($ok2 === true && count($merged) === 2, '一键允许本站会追加规则并保留原规则');

$life = object_lifecycle_xml([[
    'id' => 'cold', 'prefix' => 'cold/', 'expire_days' => 365,
    'transition_days' => 30, 'transition_class' => 'ARCHIVE', 'abort_days' => 7,
]]);
object_lifecycle_validate($life);
$parsed = object_lifecycle_rules_parse($life);
check($parsed[0]['expire_days'] === 365 && $parsed[0]['transition_class'] === 'ARCHIVE', '生命周期往返');

$badXml = false;
try { object_lifecycle_validate('<NotLifecycle/>'); } catch (InvalidArgumentException $e) { $badXml = true; }
check($badXml, '非法生命周期根节点被拒');

$tags = object_tagging_parse(object_tagging_xml([['key' => 'env', 'value' => 'prod']]));
check($tags[0]['key'] === 'env' && $tags[0]['value'] === 'prod', '标签往返');

$acl = object_acl_parse(
    '<AccessControlPolicy><AccessControlList><Grant><Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="Group">'
    . '<URI>http://acs.amazonaws.com/groups/global/AllUsers</URI></Grantee><Permission>READ</Permission></Grant></AccessControlList></AccessControlPolicy>'
);
check($acl['canned'] === 'public-read', '从 ACL 推断 public-read');

$badRule = false;
try { object_lifecycle_xml([['id' => 'empty', 'prefix' => '']]); } catch (InvalidArgumentException $e) { $badRule = true; }
check($badRule, '生命周期规则缺少过期/转存储/清理分片被拒');

$badClass2 = false;
try {
    object_lifecycle_xml([['id' => 'x', 'transition_days' => 5, 'transition_class' => 'NOPE']]);
} catch (InvalidArgumentException $e) { $badClass2 = true; }
check($badClass2, '生命周期转存储类型不合法被拒');

$badCors = false;
try { object_cors_xml([['origins' => [], 'methods' => ['GET']]]); } catch (InvalidArgumentException $e) { $badCors = true; }
check($badCors, 'CORS 空来源被拒');

$badCors2 = false;
try { object_cors_xml([['origins' => ['https://a.example'], 'methods' => ['TRACE']]]); } catch (InvalidArgumentException $e) { $badCors2 = true; }
check($badCors2, 'CORS 非白名单方法被拒');

echo "\n· 对象列表\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_list'), '对象列表接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.list'), '前端请求对象列表');
check(str_contains($js, 'data-obj-check'), '对象行有复选框');
// 回归：进入桶时必须清掉上一个桶遗留的搜索词，否则新桶会带着旧 q 进入递归搜索。
$enterPos = strpos($js, 'window.__objectsEnterBucket = function () {');
check(
    $enterPos !== false && str_contains(substr($js, $enterPos, 240), "state.query = ''"),
    '进入桶时重置搜索状态'
);

echo "\n· 预签名与分片\n";
$sign = s3_presign_url($cfg, 'PUT', 'photos/a b.jpg', 99999);
check(str_contains($sign, 'X-Amz-Expires=3600'), 'TTL 上限被钳制到 3600');
check(str_contains($sign, 'photos/a%20b.jpg'), '预签名路径正确编码');
$short = s3_presign_url($cfg, 'GET', 'a.txt', 5);
check(str_contains($short, 'X-Amz-Expires=60'), 'TTL 下限被抬到 60');

$multi = s3_multipart_part_url($cfg, 'photos-1250000000', 'big.bin', 'uid+/=', 7, 600);
check(str_contains($multi, 'partNumber=7'), '分片 URL 带 partNumber');
check(str_contains($multi, 'uploadId=uid%2B%2F%3D'), '分片 URL 编码 uploadId');

$uploadId = s3_multipart_init($cfg, 'photos-1250000000', 'big.bin', 'STANDARD_IA', 'application/octet-stream');
check($uploadId === 'stub-upload-1', '初始化分片解析 UploadId');
$initReq = $requests(0);
$initHeaders = array_change_key_case((array) ($initReq['headers'] ?? []), CASE_LOWER);
check(($initHeaders['content-type'] ?? '') === 'application/octet-stream', '初始化分片带 Content-Type');
check(($initHeaders['x-amz-storage-class'] ?? '') === 'STANDARD_IA', '初始化分片带存储类型');
check(str_contains((string) ($initReq['authHeader'] ?? ''), 'content-type')
    && str_contains((string) ($initReq['authHeader'] ?? ''), 'x-amz-storage-class'),
    '初始化分片的 Content-Type 与存储类型参与签名');
$done = s3_multipart_complete($cfg, 'photos-1250000000', 'big.bin', $uploadId, [
    ['part_number' => 1, 'etag' => '"etag-1"'],
]);
check($done['ok'] === true, '完成分片上传');
s3_multipart_abort($cfg, 'photos-1250000000', 'big.bin', $uploadId);
check(true, '放弃分片不抛异常');

echo "\n· 下载与详情\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_download_sign'), '下载预签名接口存在');
check(str_contains($module, 'function objects_restore'), '解冻接口存在');
check(str_contains($module, 'restore_required'), '归档对象会要求先解冻');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.download_sign'), '前端请求下载链接');
check(str_contains($js, 'objects.restore'), '前端可触发解冻');

echo "\n· 复制与删除\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_copy'), '复制接口存在');
check(str_contains($module, 'function objects_delete'), '批量删除接口存在');
check(str_contains($module, 'function objects_mkdir'), '新建文件夹接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.copy'), '前端调用复制');
check(str_contains($js, 'objects.delete'), '前端调用批量删除');
check(str_contains($js, 'data-obj-delete-selected'), '前端有删除所选');
check(str_contains($module, 'strlen($name) > 255'), '文件夹名按字节数限制');

$delResult = s3_delete_objects($cfg, 'photos-1250000000', ['ok.txt', 'fail.txt']);
check($delResult['deleted'] === 1 && $delResult['failed'] === 1, '批量删除解析逐键结果');
check($delResult['errors'][0]['key'] === 'fail.txt', '批量删除保留失败键');
check($delResult['ok'] === false, '有失败键时 ok 为 false');

echo "\n· 设置与统计\n";
$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
check(str_contains($module, 'function objects_settings'), '设置读取接口存在');
check(str_contains($module, 'function objects_setting_save'), '设置保存接口存在');
check(str_contains($module, 'function objects_stat'), '统计接口存在');
$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'objects.settings'), '前端读取设置');
check(str_contains($js, "data-tab=\"settings\"") || str_contains($js, 'data-tab-panel'), '前端有设置页签');

echo "\n· 最终审查修复\n";
$copyFail = s3_copy_object($cfg, 'photos-1250000000', 'a.txt', 'copy-fail.txt');
check($copyFail['ok'] === false, 'CopyObject 200+<Error> 判为失败');
$completeFail = s3_multipart_complete($cfg, 'photos-1250000000', 'complete-fail.bin', 'uid', [
    ['part_number' => 1, 'etag' => '"e"'],
]);
check($completeFail['ok'] === false, 'CompleteMultipartUpload 200+<Error> 判为失败');
s3_delete_objects($cfg, 'photos-1250000000', ['a<b&c".txt']);
$req = $requests(0);
check(str_contains((string) ($req['body'] ?? ''), '<Key>a&lt;b&amp;c&quot;.txt</Key>'),
    '批量删除键名 XML 转义');

$module = (string) file_get_contents(BASE_PATH . '/app/modules/objects.php');
foreach ([
    'objects_upload_sign', 'objects_upload_init', 'objects_upload_part',
    'objects_upload_complete', 'objects_upload_abort', 'objects_copy',
    'objects_delete', 'objects_mkdir', 'objects_restore', 'objects_setting_save',
    'objects_cors_allow', 'objects_bucket_purge', 'objects_bucket_delete',
] as $fn) {
    $pos = strpos($module, 'function ' . $fn);
    $chunk = $pos === false ? '' : substr($module, $pos, 900);
    check(str_contains($chunk, 'objects_assert_mutable'), $fn . ' 有绑定守卫');
}

$js = (string) file_get_contents(BASE_PATH . '/assets/js/objects.js');
check(str_contains($js, 'state.reqToken'), '对象列表有请求序号防陈旧响应');
check(str_contains($js, '该桶已绑定为存储源，只读'), '上传对已绑定桶短路');
check(str_contains($js, 'for (let attempt = 1; attempt <= 3; attempt++)'), '单次上传有重试');
check(str_contains($js, "data-set=\"cors\" style=\"min-height:180px\""), 'CORS 使用 JSON 原样回显');

q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM user_settings WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM users WHERE id IN (?, ?)', [$uid, $otherId]);

finish();
