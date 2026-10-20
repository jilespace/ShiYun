<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   存储桶管理：寻址、查询串、名字校验、错误翻译、响应解析，
   以及 s3_request 重构后对象路径行为未变（回归）。

   起一个本地 S3 存根（php -S）承接真实 HTTP 请求，因此这里验的是
   「发出去的请求长什么样、拿到响应后怎么判断」，而不是判空逻辑自说自话。
   签名本身仍需真实端点验证——存根不校验 Authorization。
   ========================================================================= */

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

// 端点点名用 localhost：bucket 进 host 后会变成 <bucket>.localhost，
// 而 IP 端点做不到这一点（exists-bucket.127.0.0.1 无法解析）。
$cfg = [
    'endpoint'   => 'http://localhost:' . $port,
    'region'     => 'ap-guangzhou',
    'bucket'     => 'exists-bucket',
    'access_key' => 'AKIDSTUB',
    'secret_key' => 'SECRETSTUB',
    'prefix'     => '',
];

/* --------------------------------------------------- 纯函数：寻址与查询串 */

$addr = s3_addressed($cfg);
check($addr['host'] === 'exists-bucket.localhost:' . $port, '桶级寻址把桶名拼进 host');
check(s3_addressed($cfg, false)['host'] === 'localhost:' . $port, '服务级寻址不含桶名');
check(s3_addressed(['endpoint' => 'https://cos.ap-guangzhou.myqcloud.com', 'bucket' => 'demo-1250000000'])['host']
    === 'demo-1250000000.cos.ap-guangzhou.myqcloud.com', 'COS 虚拟主机风格 host 推导正确');

check(s3_canonical_query(['max-keys' => '1', 'list-type' => '2']) === 'list-type=2&max-keys=1', '查询串按键排序');
check(s3_canonical_query([]) === '', '空查询串返回空');
check(s3_canonical_query(['prefix' => 'a/b c']) === 'prefix=a%2Fb%20c', '查询串按 RFC3986 编码');

/* ------------------------------------------------------ 纯函数：桶名校验 */

check(s3_bucket_name_valid('photos-1250000000'), '合法桶名（含 appid）通过');
check(s3_bucket_name_valid('a.b-c'), '合法桶名（含点与短横）通过');
check(!s3_bucket_name_valid('ab'), '过短桶名被拒');
check(!s3_bucket_name_valid(str_repeat('a', 64)), '超长桶名被拒');
check(!s3_bucket_name_valid('Bucket'), '大写桶名被拒');
check(!s3_bucket_name_valid('-bad'), '短横开头被拒');
check(!s3_bucket_name_valid('a..b'), '连续点被拒');
check(!s3_bucket_name_valid('192.168.1.1'), 'IP 形式桶名被拒');

/* ------------------------------------------------ 桶级操作必须清掉 prefix */

$scoped = $cfg;
$scoped['prefix'] = 'my/prefix';
$cleaned = s3_bucket_config($scoped, 'other-bucket');
check($cleaned['bucket'] === 'other-bucket', 's3_bucket_config 换掉桶名');
check($cleaned['prefix'] === '', 's3_bucket_config 清掉前缀（否则会打到目录而非桶根）');

/* ------------------------------------------------------------ 列桶（服务级） */

$buckets = s3_list_buckets($scoped);   // 故意带 prefix：服务级调用必须忽略它
check(count($buckets) === 2, '列桶解析出 2 个桶');
check($buckets[0]['name'] === 'photos-1250000000', '解析桶名');
check($buckets[0]['region'] === 'ap-guangzhou', '解析地域');
check($buckets[0]['created'] === '2026-01-02T03:04:05Z', '解析创建时间');

$req = $requests(0);
check($req !== null && $req['method'] === 'GET', '列桶用的是 GET');
check($req !== null && $req['uri'] === '/', '列桶落在端点根，不带前缀（uri=' . ($req['uri'] ?? '?') . '）');
check($req !== null && $req['host'] === 'localhost:' . $port, '列桶的 host 不含桶名');
check($req !== null && $req['auth'] === 'present', '列桶带上了 Authorization 头');

/* ------------------------------------------------------------ 探桶 */

check(s3_bucket_probe($cfg, 'exists-bucket')['state'] === 'ok', '存在的桶判定为可访问');
check(s3_bucket_probe($cfg, 'missing-bucket')['state'] === 'missing', '404 判定为不存在');
check(s3_bucket_probe($cfg, 'denied-bucket')['state'] === 'denied', '403 判定为无权限');
check(s3_bucket_probe($cfg, 'ab')['state'] === 'invalid', '非法桶名不发请求，直接判非法');

$req = $requests(0);
check($req !== null && $req['method'] === 'HEAD', '探桶用的是 HEAD');
check($req !== null && $req['host'] === 'denied-bucket.localhost:' . $port, '探桶 host 带目标桶名');

// 带着 prefix 探桶，路径仍必须是桶根
s3_bucket_probe($scoped, 'exists-bucket');
$req = $requests(0);
check($req !== null && $req['uri'] === '/', '带前缀的配置探桶仍打到桶根（uri=' . ($req['uri'] ?? '?') . '）');

/* ------------------------------------------------------------ 判空 */

check(s3_bucket_empty($cfg, 'empty-bucket')['empty'] === true, '无对象的桶判为空');
check(s3_bucket_empty($cfg, 'full-bucket')['empty'] === false, '有对象的桶判为非空');

$req = $requests(0);
check($req !== null && $req['uri'] === '/?list-type=2&max-keys=1', '判空请求带规范化查询串（uri=' . ($req['uri'] ?? '?') . '）');

/* ------------------------------------------------------------ 建桶 */

$ok = s3_create_bucket($cfg, 'new-bucket');
check($ok['ok'] === true, '建桶成功');
$req = $requests(0);
check($req !== null && $req['method'] === 'PUT', '建桶用的是 PUT');
check($req !== null && $req['uri'] === '/', '建桶落在桶根');
check($req !== null && str_contains($req['body'], '<LocationConstraint>ap-guangzhou</LocationConstraint>'),
    '非 us-east-1 区域带上了 LocationConstraint');

$dup = s3_create_bucket($cfg, 'taken-bucket');
check($dup['ok'] === false, '重复建桶返回失败');
check(str_contains($dup['message'], '已经在你名下'), 'BucketAlreadyOwnedByYou 被翻译成中文');

$bad = s3_create_bucket($cfg, 'BadName');
check($bad['ok'] === false && str_contains($bad['message'], '不合法'), '非法桶名在本地就被拒，不发请求');

/* ------------------------------------------------------------ 删桶 */

$del = s3_delete_bucket($cfg, 'empty-bucket');
check($del['ok'] === true, '删空桶成功（204 视为成功）');

$req = $requests(0);
check($req !== null && $req['method'] === 'DELETE', '删除用的是 DELETE');
check($req !== null && $req['host'] === 'empty-bucket.localhost:' . $port, '删除的 host 带目标桶名');

$busy = s3_delete_bucket($cfg, 'notempty-bucket');
check($busy['ok'] === false, '非空桶拒绝删除');
check(str_contains($busy['message'], '还有对象'), 'BucketNotEmpty 被翻译成中文');

$missing = s3_delete_bucket($cfg, 'missing-bucket');
check($missing['ok'] === true, '对已不存在的桶删除视为幂等成功');

/* -------------------------------------- 回归：对象路径行为与改造前一致 */

s3_request($cfg, 'PUT', 'ab/cdef', ['body' => 'x', 'payload_hash' => hash('sha256', 'x')]);
$req = $requests(0);
check($req !== null && $req['uri'] === '/ab/cdef', '无前缀时对象键路径不变（uri=' . ($req['uri'] ?? '?') . '）');

s3_request($scoped, 'PUT', 'ab/cdef', ['body' => 'x', 'payload_hash' => hash('sha256', 'x')]);
$req = $requests(0);
check($req !== null && $req['uri'] === '/my/prefix/ab/cdef', '有前缀时对象键仍拼上前缀（uri=' . ($req['uri'] ?? '?') . '）');

/* ------------------------------------------------ 错误翻译（合成响应） */

$synthetic = [
    'status' => 403,
    'body'   => '<Error><Code>AccessDenied</Code><Message>Access Denied.</Message></Error>',
    'headers' => '',
];
check(str_contains(s3_error_message($synthetic, '列桶失败'), '权限不足'), 'AccessDenied 翻译为权限不足提示');
check(str_contains(s3_error_message(['status' => 500, 'body' => '', 'headers' => ''], '列桶失败'), 'HTTP 500'),
    '未知错误回落到状态码描述');

/* -------------------------------------------------- 桶内对象：查看 / 删 / 清空 */

$listed = s3_list_objects($cfg, 'objbucket');
check($listed['count'] === 3, '列出桶内 3 个对象（实际 ' . $listed['count'] . '）');
check($listed['objects'][0]['key'] === '0a/aaa111', '解析对象键');
check($listed['objects'][0]['size'] === 1024, '解析对象大小');
check($listed['objects'][0]['modified'] === '2026-01-02T03:04:05Z', '解析对象修改时间');
check($listed['truncated'] === false, '未截断标记正确');

$req = $requests(0);
check($req !== null && $req['uri'] === '/?list-type=2&max-keys=200', '列对象带规范化查询串（uri=' . ($req['uri'] ?? '?') . '）');
check($req !== null && $req['host'] === 'objbucket.localhost:' . $port, '列对象的 host 带目标桶名');

check(s3_list_objects($cfg, 'empty-bucket')['count'] === 0, '空桶列不出对象');

$one = s3_delete_object($cfg, 'objbucket', 'note.txt');
check($one['ok'] === true, '删除单个对象成功');
$req = $requests(0);
check($req !== null && $req['method'] === 'DELETE' && $req['uri'] === '/note.txt', '删对象打的是 DELETE /note.txt');

$batch = s3_delete_objects($cfg, 'objbucket', ['0a/aaa111', '0b/bbb222']);
check($batch['ok'] === true && $batch['deleted'] === 2, '批量删除返回删除数量');
$req = $requests(0);
check($req !== null && $req['method'] === 'POST', '批量删除用的是 POST');
check($req !== null && $req['uri'] === '/?delete=', '批量删除带 delete 查询参数（uri=' . ($req['uri'] ?? '?') . '）');
check($req !== null && str_contains($req['body'], '<Key>0a/aaa111</Key>'), '批量删除请求体包含对象键');
check($req !== null && str_contains($req['body'], '<Key>0b/bbb222</Key>'), '批量删除请求体包含第二个键');
check($req !== null && $req['contentMd5'] !== '', '批量删除带上了 Content-MD5（AWS 强制要求）');

check(s3_delete_objects($cfg, 'objbucket', [])['deleted'] === 0, '空键列表不发请求，直接返回 0');

// purge：先列（3 个）→ 批量删 → 再探（存根已置空）→ remaining 应为 false
$purge = s3_purge_objects($cfg, 'purgebucket');
check($purge['ok'] === true && $purge['deleted'] === 3, '清空桶删掉 3 个对象（实际 ' . $purge['deleted'] . '）');
check($purge['remaining'] === false, '清空后再探已无剩余');

check(s3_purge_objects($cfg, 'empty-bucket')['deleted'] === 0, '已空的桶清空返回 0');

@unlink($logFile);
finish();
