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

q_exec('DELETE FROM storage_sources WHERE user_id = ?', [$uid]);
q_exec('DELETE FROM nodes WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM user_settings WHERE user_id IN (?, ?)', [$uid, $otherId]);
q_exec('DELETE FROM users WHERE id IN (?, ?)', [$uid, $otherId]);

finish();
