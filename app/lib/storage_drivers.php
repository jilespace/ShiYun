<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   多存储源驱动。
   local 是内置的内容寻址仓；ftp / s3 / sftp 用于把副本推到外部存储。
   凭据以 JSON 存在 storage_sources.config 里，入库前用 open_basedir 约束路径。
   ========================================================================= */

function storage_driver_info(): array
{
    return [
        'local' => ['label' => '本地磁盘', 'available' => true,
            'fields' => [['key' => 'root', 'label' => '存储目录', 'required' => false, 'secret' => false]],
            'note' => '默认仓库，按内容哈希去重存放。'],
        's3' => ['label' => 'S3 兼容', 'available' => function_exists('curl_init'),
            'fields' => [
                ['key' => 'endpoint', 'label' => 'Endpoint', 'required' => true, 'secret' => false],
                ['key' => 'region', 'label' => 'Region', 'required' => true, 'secret' => false],
                ['key' => 'bucket', 'label' => 'Bucket', 'required' => true, 'secret' => false],
                ['key' => 'access_key', 'label' => 'Access Key', 'required' => true, 'secret' => false],
                ['key' => 'secret_key', 'label' => 'Secret Key', 'required' => true, 'secret' => true],
                ['key' => 'prefix', 'label' => '路径前缀', 'required' => false, 'secret' => false],
            ],
            'note' => '兼容 MinIO / 阿里云 OSS / 腾讯云 COS 等 S3 协议实现。'],
        'ftp' => ['label' => 'FTP', 'available' => function_exists('ftp_connect'),
            'fields' => [
                ['key' => 'host', 'label' => '主机', 'required' => true, 'secret' => false],
                ['key' => 'port', 'label' => '端口', 'required' => false, 'secret' => false],
                ['key' => 'user', 'label' => '用户名', 'required' => true, 'secret' => false],
                ['key' => 'password', 'label' => '密码', 'required' => true, 'secret' => true],
                ['key' => 'passive', 'label' => '被动模式(1/0)', 'required' => false, 'secret' => false],
                ['key' => 'dir', 'label' => '远程目录', 'required' => false, 'secret' => false],
            ],
            'note' => '传输使用 PHP 内置 FTP 扩展。'],
        'sftp' => ['label' => 'SFTP', 'available' => false,
            'fields' => [
                ['key' => 'host', 'label' => '主机', 'required' => true, 'secret' => false],
                ['key' => 'port', 'label' => '端口', 'required' => false, 'secret' => false],
                ['key' => 'user', 'label' => '用户名', 'required' => true, 'secret' => false],
                ['key' => 'keyfile', 'label' => '私钥路径', 'required' => true, 'secret' => false],
                ['key' => 'dir', 'label' => '远程目录', 'required' => false, 'secret' => false],
            ],
            'note' => '服务器未装 ssh2 扩展，且禁用了进程执行函数，无法调用 sftp 客户端，因此当前不可用。'],
    ];
}

function storage_driver_available(string $driver): bool
{
    $info = storage_driver_info();
    return isset($info[$driver]) && $info[$driver]['available'];
}

/** IP → 无符号整型（用于私网区间比较） */
function ip_to_uint(string $ip): int
{
    $long = ip2long($ip);
    return $long === false ? -1 : (int) sprintf('%u', $long);
}

/** IP 是否落在回环 / 私网 / 链路本地 / CGNAT / 云元数据区间 */
function ip_is_private(string $ip): bool
{
    $ip = trim(trim($ip), '[]');
    if ($ip === '') {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $u = ip_to_uint($ip);
        if ($u < 0) {
            return false;
        }
        foreach ([
            ['127.0.0.0', '127.255.255.255'],    // 回环
            ['10.0.0.0', '10.255.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['169.254.0.0', '169.254.255.255'],  // 链路本地 / 云元数据
            ['100.64.0.0', '100.127.255.255'],   // CGNAT
            ['0.0.0.0', '0.255.255.255'],
        ] as [$from, $to]) {
            if ($u >= ip_to_uint($from) && $u <= ip_to_uint($to)) {
                return true;
            }
        }
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return $ip === '::1'
            || str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')
            || str_starts_with($ip, 'fe80');
    }

    return false;
}

/** 主机名是否指向本机 / 内网。只做字符串与 IP 判断，不做 DNS 解析。 */
function host_is_private(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    $host = trim($host, '[]');
    // 仅对非 IPv6 的 "主机:端口" 去端口；否则会把 ::1 / fe80::1 这类地址截断为空
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false && str_contains($host, ':')) {
        $host = explode(':', $host)[0];
    }
    if ($host === '') {
        return false;
    }

    if ($host === 'localhost' || $host === 'metadata.google.internal'
        || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
        return true;
    }

    return ip_is_private($host);
}

/**
 * 高危地址告警（非阻塞）：命中回环/私网/链路本地/云元数据时返回警示文案，否则 null。
 * 判断逻辑在 host_is_private()，这里只是换成给人看的说法。
 */
function host_risk_note(string $host): ?string
{
    return host_is_private($host) ? '该地址指向内网 / 本机，若作为公网服务请确认安全风险。' : null;
}

/**
 * 把主机名解析成 IP 列表。
 *
 * 离线下载光看主机名挡不住「域名解析到 127.0.0.1」这类走法，必须再解析一次。
 */
function host_resolve_ips(string $host): array
{
    $host = trim(trim($host), '[]');
    if ($host === '') {
        return [];
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }

    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = $v4;
    }
    $records = @dns_get_record($host, DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
    }
    return $ips;
}

/**
 * 存储源风险告警（非阻塞）。无风险返回 null。
 * s3 取 endpoint 的 host；ftp/sftp 取 host；local 检查 root 是否落在站点目录之外。
 */
function source_risk_note(array $source): ?string
{
    $driver = (string) ($source['driver'] ?? '');
    $config = is_string($source['config'] ?? null)
        ? (json_decode((string) $source['config'], true) ?: [])
        : (array) ($source['config'] ?? []);

    if ($driver === 's3') {
        return host_risk_note((string) (parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST) ?: ''));
    }
    if (in_array($driver, ['ftp', 'sftp'], true)) {
        return host_risk_note((string) ($config['host'] ?? ''));
    }
    if ($driver === 'local') {
        $root = (string) ($config['root'] ?? '');
        if ($root === '') {
            return null;
        }
        $real = realpath($root);
        $base = realpath(BASE_PATH);
        if ($real !== false && $base !== false && !str_starts_with($real, $base)) {
            return '本地存储目录位于站点目录之外，请确认已正确限制其访问权限。';
        }
    }

    return null;
}

/**
 * 本地存储源 root 的硬校验。返回错误文案，通过返回 null。
 *
 * 允许挂到站点目录之外的任意位置（挂 NAS、挂数据盘都是正常用法），
 * 但不允许落在站点目录里——那些目录由 Web 服务器直接对外，写进去的
 * 哈希命名文件会被当静态资源发出去。runtime/ 是例外：nginx 已 deny。
 */
function local_root_error(string $root): ?string
{
    $root = trim($root);
    if ($root === '') {
        return null;   // 留空即用内置内容仓
    }

    $real = realpath($root);
    if ($real === false || !is_dir($real)) {
        return '目录不存在或不可访问：' . $root;
    }

    $base = realpath(BASE_PATH);
    $storage = realpath((string) config('storage_path'));
    if ($base !== false && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        $runtime = $storage !== false ? dirname($storage) : $base . '/runtime';
        if (!str_starts_with($real, $runtime)) {
            return '本地存储目录不能放在站点目录内（runtime/ 除外），否则用户文件会暴露在 Web 根下。';
        }
    }

    return null;
}

/* ------------------------------------------------------------ 连通性测试 */

/** @return array{ok:bool,message:string} */
function storage_source_test(array $source): array
{
    $driver = (string) $source['driver'];
    $config = is_string($source['config'] ?? null)
        ? (json_decode((string) $source['config'], true) ?: [])
        : (array) ($source['config'] ?? []);

    try {
        return match ($driver) {
            'local' => storage_test_local($config),
            'ftp'   => storage_test_ftp($config),
            's3'    => storage_test_s3($config),
            'sftp'  => storage_test_sftp($config),
            default => ['ok' => false, 'message' => '未知驱动：' . $driver],
        };
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function storage_test_local(array $c): array
{
    $root = (string) ($c['root'] ?? '');
    if ($root === '') {
        $root = (string) config('storage_path');
    }
    if (!is_dir($root)) {
        @mkdir($root, 0700, true);
    }
    if (!is_dir($root)) {
        return ['ok' => false, 'message' => '目录不存在且无法创建：' . $root];
    }
    if (!is_writable($root)) {
        return ['ok' => false, 'message' => '目录不可写：' . $root];
    }

    $probe = rtrim($root, '/') . '/.probe-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'ok') === false) {
        return ['ok' => false, 'message' => '写入探测失败'];
    }
    @unlink($probe);

    $free = @disk_free_space($root);

    return ['ok' => true, 'message' => '可读写' . ($free !== false ? '，剩余 ' . format_bytes((float) $free) : '')];
}

function storage_test_ftp(array $c): array
{
    if (!function_exists('ftp_connect')) {
        return ['ok' => false, 'message' => 'PHP 未启用 FTP 扩展'];
    }
    $host = (string) ($c['host'] ?? '');
    if ($host === '') {
        return ['ok' => false, 'message' => '缺少主机地址'];
    }

    $conn = @ftp_connect($host, (int) ($c['port'] ?? 21), 8);
    if ($conn === false) {
        return ['ok' => false, 'message' => '无法连接 ' . $host];
    }
    if (!@ftp_login($conn, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''))) {
        ftp_close($conn);
        return ['ok' => false, 'message' => '认证失败'];
    }
    ftp_pasv($conn, (bool) ($c['passive'] ?? true));

    $dir = (string) ($c['dir'] ?? '');
    if ($dir !== '' && !@ftp_chdir($conn, $dir)) {
        ftp_close($conn);
        return ['ok' => false, 'message' => '无法进入目录 ' . $dir];
    }

    ftp_close($conn);
    return ['ok' => true, 'message' => '连接与认证正常'];
}

function storage_test_s3(array $c): array
{
    $need = ['endpoint', 'bucket', 'access_key', 'secret_key'];
    foreach ($need as $key) {
        if (empty($c[$key])) {
            return ['ok' => false, 'message' => '缺少字段：' . $key];
        }
    }

    // 探桶根，而不是前缀路径：带 prefix 时 HEAD 会打到一个不存在的对象上，
    // 失败原因与「桶能不能访问」无关，会误导用户。
    $probe = $c;
    $probe['prefix'] = '';
    $res = s3_request($probe, 'HEAD', '');
    if ($res['status'] >= 200 && $res['status'] < 400) {
        return ['ok' => true, 'message' => 'Bucket 可访问（HTTP ' . $res['status'] . '）'];
    }

    return ['ok' => false, 'message' => 'HTTP ' . $res['status'] . ' ' . mb_substr($res['body'], 0, 160)];
}

/**
 * 把目录路径塞进 sftp 批处理文件之前先清洗。
 *
 * sftp 的 -b 批处理按行解析，路径里只要有一个换行就能再塞进一条命令
 * （'cd x' + "\n" + 'rm -r /'）。sftp 批处理没有转义机制，只能把控制字符去掉。
 */
function sftp_batch_dir(string $dir): string
{
    return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $dir);
}

function storage_test_sftp(array $c): array
{
    $binary = command_path('sftp');
    if ($binary === null) {
        return ['ok' => false, 'message' => '服务器没有 sftp 客户端'];
    }

    $keyfile = (string) ($c['keyfile'] ?? '');
    if ($keyfile === '' || !is_file($keyfile)) {
        return ['ok' => false, 'message' => '私钥文件不存在：' . $keyfile];
    }

    $port = (int) ($c['port'] ?? 22);
    $dir = (string) ($c['dir'] ?? '.');
    $batch = implode("\n", ['cd ' . sftp_batch_dir($dir), 'pwd', 'bye']);

    $cmd = sprintf(
        '%s -b - -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=8 -P %d -i %s %s@%s 2>&1',
        escapeshellcmd($binary),
        $port,
        escapeshellarg($keyfile),
        escapeshellarg((string) ($c['user'] ?? '')),
        escapeshellarg((string) ($c['host'] ?? ''))
    );

    $out = storage_run_stdin($cmd, $batch);
    if (str_contains($out, 'Permission denied') || str_contains($out, 'Connection refused')
        || str_contains($out, 'Could not resolve') || str_contains($out, 'not found')) {
        return ['ok' => false, 'message' => mb_substr(trim($out), 0, 200)];
    }

    return ['ok' => true, 'message' => 'SFTP 通道可用'];
}

function storage_run_stdin(string $cmd, string $stdin): string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return '无法启动进程';
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return $out . $err;
}

/* ---------------------------------------------------------------- S3 签名 */

/**
 * 由配置推导虚拟主机风格的 base URL 与 host 头。
 * 两套签名（请求头 / 查询串）都必须走这里，否则会出现「签名与发送的 host 不一致」。
 *
 * @param bool $withBucket false 用于服务级调用（列桶）：桶名不参与寻址，打到端点根
 * @return array{base:string,host:string}
 */
function s3_addressed(array $c, bool $withBucket = true): array
{
    $endpoint = rtrim((string) $c['endpoint'], '/');
    $bucket = (string) ($c['bucket'] ?? '');

    $scheme = (string) (parse_url($endpoint, PHP_URL_SCHEME) ?: 'https');
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);

    // 桶名进 host；已经带过就不再拼
    if ($withBucket && $bucket !== '' && !str_starts_with($host, $bucket . '.')) {
        $host = $bucket . '.' . $host;
    }

    $authority = $host . ($port !== null ? ':' . $port : '');

    return ['base' => $scheme . '://' . $authority, 'host' => $authority];
}

/**
 * SigV4 的 CanonicalQueryString：键按编码后字典序、值一律 RFC3986 编码。
 * 桶级操作与预签名（另一套算法）各自独立实现，这里只服务 s3_request。
 */
function s3_canonical_query(array $query): string
{
    if ($query === []) {
        return '';
    }
    $pairs = [];
    foreach ($query as $k => $v) {
        $pairs[rawurlencode((string) $k)] = rawurlencode((string) $v);
    }
    ksort($pairs);

    $out = [];
    foreach ($pairs as $k => $v) {
        $out[] = $k . '=' . $v;
    }
    return implode('&', $out);
}

/**
 * 极简 S3 请求（AWS SigV4）。
 * 使用虚拟主机风格寻址：桶名进 host（COS 拒绝 path-style，报 PathStyleDomainForbidden）。
 *
 * @return array{status:int,body:string,headers:string}
 */
function s3_request(array $c, string $method, string $key, array $opts = []): array
{
    $region = (string) ($c['region'] ?? 'us-east-1');
    $prefix = trim((string) ($c['prefix'] ?? ''), '/');
    $service = !empty($opts['service']);

    // 虚拟主机风格：桶名进 host（COS 拒绝 path-style，报 PathStyleDomainForbidden）。
    // 列桶是服务级操作，桶名不参与寻址，打到端点根。
    $addr = s3_addressed($c, !$service);
    $hostHeader = $addr['host'];

    if ($service) {
        $uriPath = '/' . ltrim($key, '/');
    } else {
        $objectKey = $prefix !== '' ? $prefix . '/' . ltrim($key, '/') : ltrim($key, '/');
        $uriPath = '/' . ($objectKey !== '' ? str_replace('%2F', '/', rawurlencode($objectKey)) : '');
    }

    $canonicalQuery = s3_canonical_query((array) ($opts['query'] ?? []));
    $url = $addr['base'] . $uriPath . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = $opts['payload_hash'] ?? hash('sha256', (string) ($opts['body'] ?? ''));

    $headers = [];
    foreach ((array) ($opts['headers'] ?? []) as $hk => $hv) {
        $hk = strtolower(trim((string) $hk));
        if ($hk === '' || in_array($hk, [
            'host', 'authorization', 'content-length', 'expect',
            'x-amz-date', 'x-amz-content-sha256', 'x-amz-security-token',
        ], true)) {
            continue;
        }
        $headers[$hk] = trim((string) $hv);
    }
    $headers['host']                 = $hostHeader;
    $headers['x-amz-content-sha256'] = $payloadHash;
    $headers['x-amz-date']           = $amzDate;
    if (!empty($c['session_token'])) {
        $headers['x-amz-security-token'] = (string) $c['session_token'];
    }
    if (isset($opts['content_type'])) {
        $headers['content-type'] = (string) $opts['content_type'];
    }
    // DeleteObjects 需要 Content-MD5（AWS 强制，COS 兼容）；它必须进签名头，故在此加入
    if (isset($opts['content_md5'])) {
        $headers['content-md5'] = (string) $opts['content_md5'];
    }

    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim((string) $v) . "\n";
    }
    $signedHeaders = implode(';', array_keys($headers));

    $canonicalRequest = implode("\n", [
        $method,
        $uriPath,
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $scope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . (string) $c['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $auth = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key'] . '/' . $scope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

    $send = [];
    foreach ($headers as $k => $v) {
        $send[] = $k . ': ' . $v;
    }
    $send[] = 'Authorization: ' . $auth;

    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $send,
        CURLOPT_TIMEOUT        => (int) ($opts['timeout'] ?? 20),
        CURLOPT_CONNECTTIMEOUT => 8,
    ];
    if (strtoupper($method) === 'HEAD') {
        // 必须用 NOBODY，不能用 CUSTOMREQUEST='HEAD'：
        // 后者只改方法名，curl 仍会按响应的 Content-Length 去等一个
        // HEAD 永远不会发来的响应体，一直等到超时（实测 5 秒后 status=0）。
        $curlOpts[CURLOPT_NOBODY] = true;
    } else {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = $method;
    }
    curl_setopt_array($ch, $curlOpts);
    if (isset($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $opts['body']);
    }
    $inFh = null;
    $outFh = null;
    if (isset($opts['file'])) {
        $inFh = fopen((string) $opts['file'], 'rb');
        if ($inFh === false) {
            curl_close($ch);
            return ['status' => 0, 'body' => '无法读取本地文件', 'headers' => ''];
        }
        curl_setopt($ch, CURLOPT_INFILE, $inFh);
        curl_setopt($ch, CURLOPT_INFILESIZE, (int) filesize((string) $opts['file']));
        curl_setopt($ch, CURLOPT_UPLOAD, true);
    }
    if (!empty($opts['download'])) {
        $outFh = fopen((string) $opts['download'], 'wb');
        if ($outFh === false) {
            if (is_resource($inFh)) {
                fclose($inFh);
            }
            curl_close($ch);
            return ['status' => 0, 'body' => '无法写入本地文件', 'headers' => ''];
        }
        curl_setopt($ch, CURLOPT_FILE, $outFh);
        curl_setopt($ch, CURLOPT_HEADER, false);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    // cURL 不会关闭通过 CURLOPT_INFILE / CURLOPT_FILE 传入的流，这里显式释放，避免句柄泄漏
    if (is_resource($inFh)) {
        fclose($inFh);
    }
    if (is_resource($outFh)) {
        fclose($outFh);
    }

    if ($response === false) {
        return ['status' => 0, 'body' => $error, 'headers' => ''];
    }

    if (!empty($opts['download'])) {
        return ['status' => $status, 'body' => '', 'headers' => ''];
    }

    return [
        'status'  => $status,
        'headers' => substr((string) $response, 0, $headerSize),
        'body'    => substr((string) $response, $headerSize),
    ];
}

/* ------------------------------------------------------ S3 预签名（查询串） */

/**
 * 生成预签名 URL（SigV4 查询串签名，与 s3_request 的请求头签名是两套算法）。
 * 使用虚拟主机风格寻址：桶名进 host。
 */
function s3_presign(array $c, string $method, string $key, int $ttl, array $query = [], array $extraHeaders = []): string
{
    $region = (string) ($c['region'] ?? 'us-east-1');
    $prefix = trim((string) ($c['prefix'] ?? ''), '/');

    // 虚拟主机风格：桶名进 host（COS 拒绝 path-style，报 PathStyleDomainForbidden）
    $addr = s3_addressed($c);
    $hostHeader = $addr['host'];

    $objectKey = $prefix !== '' ? $prefix . '/' . ltrim($key, '/') : ltrim($key, '/');
    $url = $addr['base'] . '/' . str_replace('%2F', '/', rawurlencode($objectKey));

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $scope = $dateStamp . '/' . $region . '/s3/aws4_request';

    // 参与签名的请求头：host 必选；额外头用于 content-type / x-amz-storage-class
    $signedMap = ['host' => $hostHeader];
    foreach ($extraHeaders as $hk => $hv) {
        $hk = strtolower(trim((string) $hk));
        if ($hk === '' || $hk === 'host') {
            continue;
        }
        $signedMap[$hk] = trim((string) $hv);
    }
    ksort($signedMap);
    $signedHeaders = implode(';', array_keys($signedMap));
    $canonicalHeaders = '';
    foreach ($signedMap as $hk => $hv) {
        $canonicalHeaders .= $hk . ':' . $hv . "\n";
    }

    $params = $query + [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => (string) $c['access_key'] . '/' . $scope,
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string) $ttl,
        'X-Amz-SignedHeaders' => $signedHeaders,
    ];
    if (!empty($c['session_token'])) {
        $params['X-Amz-Security-Token'] = (string) $c['session_token'];
    }
    ksort($params);

    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    $canonicalQuery = implode('&', $pairs);

    $canonicalRequest = implode("\n", [
        $method,
        parse_url($url, PHP_URL_PATH) ?: '/',
        $canonicalQuery,
        $canonicalHeaders,
        $signedHeaders,
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . (string) $c['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    return $url . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}

function s3_presign_url(
    array $config,
    string $method,
    string $key,
    int $ttl,
    array $query = [],
    array $extraHeaders = []
): string {
    return s3_presign($config, $method, $key, max(60, min(3600, $ttl)), $query, $extraHeaders);
}

/** 对象键 → URL 路径段；保留 / 作为分隔符 */
function s3_encode_key(string $key): string
{
    return str_replace('%2F', '/', rawurlencode($key));
}

/** CopyObject 的 x-amz-copy-source 值 */
function s3_copy_source(string $bucket, string $key): string
{
    return '/' . $bucket . '/' . s3_encode_key($key);
}

/* ------------------------------------------------------------ 推送到外部源 */

/**
 * 把一份 blob 推到指定存储源。返回外部键名。
 */
function storage_source_put(array $source, string $contentHash, string $localPath): string
{
    $driver = (string) $source['driver'];
    $config = json_decode((string) $source['config'], true) ?: [];
    $key = substr($contentHash, 0, 2) . '/' . $contentHash;

    switch ($driver) {
        case 'local':
            $root = rtrim((string) ($config['root'] ?? config('storage_path')), '/');
            $dest = $root . '/' . $key;
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0700, true);
            }
            if (!@copy($localPath, $dest)) {
                throw new RuntimeException('写入本地源失败');
            }
            return $key;

        case 's3':
            $result = s3_request($config, 'PUT', $key, [
                'file'         => $localPath,
                'payload_hash' => hash_file('sha256', $localPath) ?: 'UNSIGNED-PAYLOAD',
                'content_type' => 'application/octet-stream',
            ]);
            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException('S3 上传失败：HTTP ' . $result['status']);
            }
            return $key;

        case 'ftp':
            if (!function_exists('ftp_connect')) {
                throw new RuntimeException('FTP 扩展不可用');
            }
            $conn = @ftp_connect((string) $config['host'], (int) ($config['port'] ?? 21), 10);
            if ($conn === false || !@ftp_login($conn, (string) $config['user'], (string) $config['password'])) {
                throw new RuntimeException('FTP 连接失败');
            }
            ftp_pasv($conn, (bool) ($config['passive'] ?? true));
            $dir = trim((string) ($config['dir'] ?? ''), '/');
            if ($dir !== '') {
                @ftp_mkdir($conn, $dir);
                @ftp_chdir($conn, $dir);
            }
            @ftp_mkdir($conn, substr($key, 0, 2));
            $ok = @ftp_put($conn, $key, $localPath, FTP_BINARY);
            ftp_close($conn);
            if (!$ok) {
                throw new RuntimeException('FTP 上传失败');
            }
            return $key;

        case 'sftp':
            $binary = command_path('sftp');
            if ($binary === null) {
                throw new RuntimeException('sftp 客户端不可用');
            }
            $dir = rtrim((string) ($config['dir'] ?? '.'), '/');
            $batch = implode("\n", [
                'cd ' . sftp_batch_dir($dir),
                '-mkdir ' . substr($key, 0, 2),
                'put ' . escapeshellarg($localPath) . ' ' . $key,
                'bye',
            ]);
            $cmd = sprintf(
                '%s -b - -o BatchMode=yes -o StrictHostKeyChecking=no -P %d -i %s %s@%s 2>&1',
                escapeshellcmd($binary),
                (int) ($config['port'] ?? 22),
                escapeshellarg((string) $config['keyfile']),
                escapeshellarg((string) $config['user']),
                escapeshellarg((string) $config['host'])
            );
            $out = storage_run_stdin($cmd, $batch);
            if (stripos($out, 'denied') !== false || stripos($out, 'not found') !== false) {
                throw new RuntimeException('SFTP 上传失败：' . mb_substr(trim($out), 0, 160));
            }
            return $key;

        default:
            throw new RuntimeException('未知驱动：' . $driver);
    }
}

/**
 * 删除存储源上的一个对象。失败抛 RuntimeException。
 */
function storage_source_delete(array $source, string $objectKey): void
{
    $driver = (string) $source['driver'];
    $config = json_decode((string) $source['config'], true) ?: [];
    $prefix = trim((string) ($config['prefix'] ?? ''), '/');
    $key = $prefix !== '' ? $prefix . '/' . ltrim($objectKey, '/') : ltrim($objectKey, '/');

    switch ($driver) {
        case 'local':
            $root = rtrim((string) ($config['root'] ?? config('storage_path')), '/');
            $path = $root . '/' . $key;
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('删除本地源对象失败');
            }
            return;

        case 's3':
            $result = s3_request($config, 'DELETE', $objectKey, [
                'payload_hash' => hash('sha256', ''),
            ]);
            // 404 视为已不存在，幂等成功
            if ($result['status'] !== 404 && ($result['status'] < 200 || $result['status'] >= 300)) {
                throw new RuntimeException('S3 删除失败：HTTP ' . $result['status']);
            }
            return;

        default:
            throw new RuntimeException('该存储类型不支持远程删除：' . $driver);
    }
}

/* ============================================================ 存储桶管理
   用一组 S3 凭据对桶本身做增删查。全部走桶级调用，因此必须清掉 prefix：
   s3_request 会把 prefix 拼进对象路径，带上它就打到「目录」而不是桶根，
   storage_test_s3 里也是同一个原因才把 prefix 置空。
   ========================================================================= */

/** 桶名合法性（S3 / COS 通用规则：3–63 位，小写字母数字以及 - 和 .） */
function s3_bucket_name_valid(string $name): bool
{
    if (preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $name) !== 1) {
        return false;
    }
    if (str_contains($name, '..') || str_contains($name, '.-') || str_contains($name, '-.')) {
        return false;
    }
    // 形如 192.168.1.1 的名字不允许
    return preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $name) !== 1;
}

/** 把目标桶装进配置：换掉 bucket、清掉 prefix，其余凭据照旧 */
function s3_bucket_config(array $config, string $bucket): array
{
    $config['bucket'] = $bucket;
    $config['prefix'] = '';
    return $config;
}

/** 解析 S3 / COS 的 XML 错误体，取出错误码与描述 */
function s3_error_detail(string $body): array
{
    $code = '';
    $message = '';
    if ($body !== '') {
        if (preg_match('#<Code>([^<]*)</Code>#', $body, $m) === 1) {
            $code = trim($m[1]);
        }
        if (preg_match('#<Message>([^<]*)</Message>#', $body, $m) === 1) {
            $message = trim($m[1]);
        }
    }
    return ['code' => $code, 'message' => $message];
}

/**
 * 把 S3 错误翻译成看得懂的话。
 * 桶级操作最容易撞上的两类失败：权限不够、桶名冲突——两者原文都很难读。
 */
function s3_error_message(array $res, string $fallback): string
{
    $detail = s3_error_detail((string) ($res['body'] ?? ''));
    $code = $detail['code'];

    $known = [
        'BucketAlreadyOwnedByYou'  => '这个桶已经在你名下，不用重复创建。',
        'BucketAlreadyExists'      => '桶名已被占用——桶名全局唯一，换一个再试。',
        'BucketNotEmpty'           => '桶里还有对象，清空之后才能删除。',
        'NoSuchBucket'             => '桶不存在，可能已被删除。',
        'InvalidBucketName'        => '桶名不合法。',
        'AccessDenied'             => '权限不足：这组密钥没有桶级权限（需要 ListBuckets / PutBucket / DeleteBucket）。',
        'InvalidAccessKeyId'       => 'Access Key 无效，请核对密钥。',
        'SignatureDoesNotMatch'    => '签名不匹配，请核对 Secret Key。',
        'PathStyleDomainForbidden' => '该端点不接受 path-style 寻址，请确认 endpoint 填的是域名形式。',
        'RequestTimeTooSkewed'     => '本机时间与服务器偏差过大，请校准系统时间。',
        'EntityTooLarge'             => '对象超过单次上传上限，请改用分片上传。',
        'InvalidStorageClass'        => '该存储类型在此服务商不可用。',
        'CORSNotAllowed'             => '桶的 CORS 未放行本站，请先配置 CORS。',
        'NoSuchCORSConfiguration'    => '桶未配置 CORS。',
        'NoSuchLifecycleConfiguration' => '桶未配置生命周期。',
        'NoSuchBucketPolicy'         => '桶未设置策略。',
        'InvalidPart'                => '分片无效，请重新上传该分片。',
        'InvalidPartOrder'           => '分片顺序不正确，请重新完成上传。',
        'NoSuchUpload'               => '分片上传不存在或已过期，请重新开始。',
    ];

    if ($code !== '' && isset($known[$code])) {
        return $known[$code] . '（' . $code . '）';
    }

    $tail = 'HTTP ' . (int) ($res['status'] ?? 0);
    if ($code !== '') {
        $tail .= ' ' . $code;
    }
    if ($detail['message'] !== '') {
        $tail .= ' · ' . mb_substr($detail['message'], 0, 160);
    }

    return $fallback . '：' . $tail;
}

/** 解析 ListBuckets 返回的桶清单 */
function s3_parse_bucket_list(string $xml): array
{
    if ($xml === '' || !class_exists('DOMDocument')) {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) {
        return [];
    }

    $out = [];
    foreach ($doc->getElementsByTagName('Bucket') as $node) {
        $name = '';
        $region = '';
        $created = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->nodeName === 'Name') {
                $name = trim($child->textContent);
            } elseif ($child->nodeName === 'Location' || $child->nodeName === 'LocationConstraint') {
                $region = trim($child->textContent);
            } elseif ($child->nodeName === 'CreationDate') {
                $created = trim($child->textContent);
            }
        }
        if ($name !== '') {
            $out[] = ['name' => $name, 'region' => $region, 'created' => $created];
        }
    }

    return $out;
}

/**
 * 列出账号下全部桶。凭据权限不足时抛 RuntimeException（文案已翻译）。
 *
 * @return list<array{name:string,region:string,created:string}>
 */
function s3_list_buckets(array $config): array
{
    $cfg = $config;
    $cfg['prefix'] = '';
    // 服务级调用：桶名不进 host，路径为端点根
    $res = s3_request($cfg, 'GET', '', [
        'service'      => true,
        'payload_hash' => hash('sha256', ''),
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '列桶失败'));
    }

    return s3_parse_bucket_list((string) $res['body']);
}

/**
 * 探桶：状态码决定存在性。
 * 200/3xx 可访问；404 不存在；403 多为「存在但密钥无权访问」，也可能被他人占用。
 *
 * @return array{status:int,state:string,message:string}
 */
function s3_bucket_probe(array $config, string $bucket): array
{
    if (!s3_bucket_name_valid($bucket)) {
        return ['status' => 0, 'state' => 'invalid', 'message' => '桶名不合法。'];
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'HEAD', '', [
        'payload_hash' => hash('sha256', ''),
    ]);
    $status = (int) $res['status'];

    if ($status >= 200 && $status < 400) {
        return ['status' => $status, 'state' => 'ok', 'message' => '桶存在且可访问。'];
    }
    if ($status === 404) {
        return ['status' => $status, 'state' => 'missing', 'message' => '桶不存在。'];
    }
    if ($status === 403) {
        return ['status' => $status, 'state' => 'denied', 'message' => '桶存在但当前密钥无访问权限，或该桶名属于他人。'];
    }

    return ['status' => $status, 'state' => 'error', 'message' => s3_error_message($res, '探测桶失败')];
}

/**
 * 桶内是否为空。只探一条对象——判断空/非空足够，不用把对象全列一遍。
 *
 * @return array{empty:bool,message:string}
 */
function s3_bucket_empty(array $config, string $bucket): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => ['list-type' => '2', 'max-keys' => '1'],
        'payload_hash' => hash('sha256', ''),
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取桶内容失败'));
    }

    $body = (string) $res['body'];
    $hasObject = str_contains($body, '<Contents>') || str_contains($body, '<Key>');

    return [
        'empty'   => !$hasObject,
        'message' => $hasObject ? '桶内还有对象。' : '桶是空的。',
    ];
}

/**
 * 创建桶。region 非 us-east-1 时按 S3 约定带上 LocationConstraint
 * （COS 通常忽略该字段，创建区域由 endpoint 决定）。
 */
function s3_create_bucket(array $config, string $bucket): array
{
    if (!s3_bucket_name_valid($bucket)) {
        return ['ok' => false, 'message' => '桶名不合法：3–63 位，只能用小写字母、数字、- 和 .，且首尾必须是字母或数字。'];
    }

    $region = (string) ($config['region'] ?? '');
    $body = '';
    if ($region !== '' && $region !== 'us-east-1') {
        $body = '<CreateBucketConfiguration><LocationConstraint>'
            . htmlspecialchars($region, ENT_QUOTES | ENT_XML1, 'UTF-8')
            . '</LocationConstraint></CreateBucketConfiguration>';
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'PUT', '', [
        'body'         => $body,
        'payload_hash' => hash('sha256', $body),
        'content_type' => 'application/xml',
    ]);

    if ($res['status'] >= 200 && $res['status'] < 300) {
        return ['ok' => true, 'message' => '桶已创建。'];
    }

    return ['ok' => false, 'message' => s3_error_message($res, '创建桶失败')];
}

/**
 * 删除桶。调用方必须先用 s3_bucket_empty() 确认空桶——
 * 这里只负责把 BucketNotEmpty 翻译清楚，不做静默清空。
 */
function s3_delete_bucket(array $config, string $bucket): array
{
    if (!s3_bucket_name_valid($bucket)) {
        return ['ok' => false, 'message' => '桶名不合法。'];
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'DELETE', '', [
        'payload_hash' => hash('sha256', ''),
    ]);

    // 404 视为已不存在，幂等成功
    if ($res['status'] === 404) {
        return ['ok' => true, 'message' => '桶已不存在。'];
    }
    if ($res['status'] >= 200 && $res['status'] < 300) {
        return ['ok' => true, 'message' => '桶已删除。'];
    }

    return ['ok' => false, 'message' => s3_error_message($res, '删除桶失败')];
}

/* ---------------------------------------------------- 桶内对象（查看 / 清空）
   删桶要求桶为空，此前界面只提示「先清空」却没给手段——这几件事补上这条路。
   ========================================================================= */

/** 解析 ListObjectsV2 的对象清单 */
function s3_parse_object_list(string $xml): array
{
    if ($xml === '' || !class_exists('DOMDocument')) {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) {
        return [];
    }

    $out = [];
    foreach ($doc->getElementsByTagName('Contents') as $node) {
        $key = '';
        $size = 0;
        $modified = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->nodeName === 'Key') {
                $key = $child->textContent;
            } elseif ($child->nodeName === 'Size') {
                $size = (int) $child->textContent;
            } elseif ($child->nodeName === 'LastModified') {
                $modified = trim($child->textContent);
            }
        }
        if ($key !== '') {
            $out[] = ['key' => $key, 'size' => $size, 'modified' => $modified];
        }
    }

    return $out;
}

/**
 * 列出桶内对象。
 * @return array{objects:list<array>,count:int,truncated:bool}
 */
function s3_list_objects(array $config, string $bucket, int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));

    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => ['list-type' => '2', 'max-keys' => (string) $limit],
        'payload_hash' => hash('sha256', ''),
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取桶内对象失败'));
    }

    $objects = s3_parse_object_list((string) $res['body']);

    return [
        'objects'   => $objects,
        'count'     => count($objects),
        'truncated' => str_contains((string) $res['body'], '<IsTruncated>true</IsTruncated>'),
    ];
}

/** 解析 ListObjectsV2（含 CommonPrefixes / 分页 / ETag / 存储类型） */
function s3_parse_object_page(string $xml, string $prefix = ''): array
{
    $out = ['folders' => [], 'objects' => [], 'truncated' => false, 'next_token' => '', 'key_count' => 0];
    if ($xml === '' || !class_exists('DOMDocument')) {
        return $out;
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || $doc->documentElement === null) {
        return $out;
    }

    // 展示名只去掉到路径边界为止：目录浏览前缀以 / 结尾可直接去；
    // 搜索前缀（如 photos/abc）只能去掉最后一个 / 及之前的部分，否则会把
    // photos/abcdef.txt 误显示成 def.txt，键恰好等于前缀时还会变成空名。
    $displayBase = $prefix;
    if ($prefix !== '' && !str_ends_with($prefix, '/')) {
        $slash = strrpos($prefix, '/');
        $displayBase = $slash === false ? '' : substr($prefix, 0, $slash + 1);
    }

    foreach ($doc->documentElement->childNodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        if ($node->nodeName === 'IsTruncated') {
            $out['truncated'] = strtolower(trim($node->textContent)) === 'true';
        } elseif ($node->nodeName === 'NextContinuationToken') {
            $out['next_token'] = trim($node->textContent);
        } elseif ($node->nodeName === 'KeyCount') {
            $out['key_count'] = (int) $node->textContent;
        } elseif ($node->nodeName === 'CommonPrefixes') {
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'Prefix') {
                    $full = $child->textContent;
                    $name = ($displayBase !== '' && str_starts_with($full, $displayBase))
                        ? substr($full, strlen($displayBase)) : $full;
                    $out['folders'][] = ['prefix' => $full, 'name' => rtrim($name, '/')];
                }
            }
        } elseif ($node->nodeName === 'Contents') {
            $row = ['key' => '', 'name' => '', 'size' => 0, 'etag' => '', 'modified' => '', 'storage_class' => ''];
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                switch ($child->nodeName) {
                    case 'Key': $row['key'] = $child->textContent; break;
                    case 'Size': $row['size'] = (int) $child->textContent; break;
                    case 'ETag': $row['etag'] = trim($child->textContent); break;
                    case 'LastModified': $row['modified'] = trim($child->textContent); break;
                    case 'StorageClass': $row['storage_class'] = trim($child->textContent); break;
                }
            }
            if ($row['key'] !== '') {
                $row['name'] = ($displayBase !== '' && str_starts_with($row['key'], $displayBase))
                    ? substr($row['key'], strlen($displayBase)) : $row['key'];
                // 键正好等于当前前缀（0 字节目录标记）不渲染成空名行
                if ($row['name'] !== '') {
                    $out['objects'][] = $row;
                }
            }
        }
    }
    return $out;
}

/** ListObjectsV2 分页读取 */
function s3_list_objects_page(array $config, string $bucket, array $opts = []): array
{
    $prefix = (string) ($opts['prefix'] ?? '');
    $delimiter = (string) ($opts['delimiter'] ?? '/');
    $maxKeys = max(1, min(1000, (int) ($opts['max_keys'] ?? 100)));

    $query = ['list-type' => '2', 'max-keys' => (string) $maxKeys];
    if ($prefix !== '') {
        $query['prefix'] = $prefix;
    }
    if ($delimiter !== '') {
        $query['delimiter'] = $delimiter;
    }
    if (!empty($opts['continuation_token'])) {
        $query['continuation-token'] = (string) $opts['continuation_token'];
    }
    if (!empty($opts['start_after'])) {
        $query['start-after'] = (string) $opts['start_after'];
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => $query,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ($res['status'] < 200 || $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取桶内对象失败'));
    }
    return s3_parse_object_page((string) $res['body'], $prefix);
}

/** 删除桶内单个对象；404 视为已不存在 */
function s3_delete_object(array $config, string $bucket, string $key): array
{
    if ($key === '') {
        return ['ok' => false, 'message' => '对象键为空。'];
    }

    $res = s3_request(s3_bucket_config($config, $bucket), 'DELETE', $key, [
        'payload_hash' => hash('sha256', ''),
    ]);

    if ($res['status'] === 404) {
        return ['ok' => true, 'message' => '对象已不存在。'];
    }
    if ($res['status'] >= 200 && $res['status'] < 300) {
        return ['ok' => true, 'message' => '对象已删除。'];
    }

    return ['ok' => false, 'message' => s3_error_message($res, '删除对象失败')];
}

/**
 * 批量删除（DeleteObjects，一次最多 1000 个键）。
 * 逐个 DELETE 会打上百次请求，既慢又容易在浏览器侧超时，所以走批量接口。
 */
function s3_delete_objects(array $config, string $bucket, array $keys): array
{
    $keys = array_values(array_filter(
        array_map(static fn ($k): string => (string) $k, $keys),
        static fn (string $k): bool => $k !== ''
    ));
    $keys = array_slice($keys, 0, 1000);

    if ($keys === []) {
        return ['ok' => true, 'deleted' => 0, 'message' => '没有需要删除的对象。'];
    }

    $xml = '<Delete>';
    foreach ($keys as $key) {
        $xml .= '<Object><Key>' . htmlspecialchars($key, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Key></Object>';
    }
    $xml .= '</Delete>';

    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', '', [
        'query'        => ['delete' => ''],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_md5'  => base64_encode(md5($xml, true)),
        'content_type' => 'application/xml',
    ]);

    if ($res['status'] < 200 || $res['status'] >= 300) {
        return [
            'ok' => false, 'deleted' => 0, 'failed' => 0, 'errors' => [],
            'message' => s3_error_message($res, '批量删除失败'),
        ];
    }

    // DeleteObjects 即使在 200 里也会逐键返回 <Deleted>/<Error>，不能只看 HTTP 状态
    $deleted = 0;
    $errors = [];
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML((string) $res['body'], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($loaded && $doc->documentElement !== null) {
        foreach ($doc->documentElement->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->nodeName === 'Deleted') {
                $deleted++;
            } elseif ($node->nodeName === 'Error') {
                $key = '';
                $code = '';
                foreach ($node->childNodes as $child) {
                    if (!$child instanceof DOMElement) {
                        continue;
                    }
                    if ($child->nodeName === 'Key') {
                        $key = $child->textContent;
                    } elseif ($child->nodeName === 'Code') {
                        $code = $child->textContent;
                    }
                }
                $errors[] = ['key' => $key, 'code' => $code];
            }
        }
    }

    // 个别实现不返回可解析的 DeleteResult：退回按请求数计成功，避免误报失败
    if ($deleted === 0 && $errors === []) {
        $deleted = count($keys);
    }

    $failed = count($errors);
    return [
        'ok'      => $failed === 0,
        'deleted' => $deleted,
        'failed'  => $failed,
        'errors'  => $errors,
        'message' => '已删除 ' . $deleted . ' 个对象' . ($failed > 0 ? '，' . $failed . ' 个失败' : '') . '。',
    ];
}

/**
 * 清空桶：列一页（最多 1000）后批量删，再探一眼确认是否已空。
 * 调用方负责确认与守卫；这里只做动作，不猜意图。
 *
 * @return array{ok:bool,deleted:int,remaining:bool,message:string}
 */
function s3_purge_objects(array $config, string $bucket): array
{
    $listed = s3_list_objects($config, $bucket, 1000);
    if ($listed['count'] === 0) {
        return ['ok' => true, 'deleted' => 0, 'remaining' => false, 'message' => '桶本来就是空的。'];
    }

    $result = s3_delete_objects($config, $bucket, array_column($listed['objects'], 'key'));

    // 删完再探一眼：超过 1000 个对象时还有剩余，界面据此提示再点一次
    $after = s3_list_objects($config, $bucket, 1);

    return [
        'ok'        => (bool) $result['ok'],
        'deleted'   => (int) ($result['deleted'] ?? 0),
        'failed'    => (int) ($result['failed'] ?? 0),
        'errors'    => $result['errors'] ?? [],
        'remaining' => $after['count'] > 0,
        'message'   => (string) $result['message'],
    ];
}

/* -------------------------------------------------------- 对象级操作 */

/** 把 cURL 返回的原始响应头解析成小写键的数组 */
function s3_response_headers(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $out[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    return $out;
}

/** HEAD 对象；404 返回 null */
function s3_head_object(array $config, string $bucket, string $key): ?array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'HEAD', $key, [
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] === 404) {
        return null;
    }
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '读取对象信息失败'));
    }
    $h = s3_response_headers((string) $res['headers']);
    return [
        'size'          => (int) ($h['content-length'] ?? 0),
        'content_type'  => (string) ($h['content-type'] ?? ''),
        'etag'          => (string) ($h['etag'] ?? ''),
        'last_modified' => (string) ($h['last-modified'] ?? ''),
        'storage_class' => (string) ($h['x-amz-storage-class'] ?? 'STANDARD'),
        'restore'       => (string) ($h['x-amz-restore'] ?? ''),
    ];
}

function s3_copy_object(array $config, string $bucket, string $source, string $target, ?string $storageClass = null): array
{
    $headers = [
        'x-amz-copy-source'        => s3_copy_source($bucket, $source),
        'x-amz-metadata-directive' => 'COPY',
    ];
    if ($storageClass !== null && $storageClass !== '') {
        $headers['x-amz-storage-class'] = $storageClass;
    }
    $res = s3_request(s3_bucket_config($config, $bucket), 'PUT', $target, [
        'headers'      => $headers,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '复制对象失败')];
    }

    // 部分 S3 实现会用 200 + <Error> 表示复制失败（如归档源的 InvalidObjectState），
    // 只看状态码会把「没复制成功」当成功，move 随后删源就是数据丢失。
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML((string) $res['body'], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $loaded ? $doc->documentElement : null;
    if ($root === null
        || $root->localName !== 'CopyObjectResult'
        || $doc->getElementsByTagName('Error')->length > 0) {
        return ['ok' => false, 'message' => s3_error_message($res, '复制对象失败')];
    }
    return ['ok' => true, 'message' => '已复制。'];
}

function s3_restore_object(array $config, string $bucket, string $key, int $days, string $tier = 'Standard'): array
{
    $days = max(1, min(30, $days));
    $tier = in_array($tier, ['Standard', 'Bulk', 'Expedited'], true) ? $tier : 'Standard';
    // 层级参数必须包在服务商对应的容器里：COS 用 CASJobParameters，
    // AWS 兼容实现用 GlacierJobParameters；顶层 <Tier> 两家都不接受。
    $params = (string) ($config['provider'] ?? 's3') === 'cos' ? 'CASJobParameters' : 'GlacierJobParameters';
    $xml = '<RestoreRequest><Days>' . $days . '</Days><' . $params . '><Tier>' . $tier
        . '</Tier></' . $params . '></RestoreRequest>';
    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['restore' => ''],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '解冻失败')];
    }
    return ['ok' => true, 'message' => '已提交解冻，归档对象通常需要数分钟到数小时。'];
}

/* ------------------------------------------------------------ 子资源 */

function s3_get_subresource(array $config, string $bucket, string $sub, array $query = []): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'GET', '', [
        'query'        => [$sub => ''] + $query,
        'payload_hash' => hash('sha256', ''),
    ]);
    return [
        'status'  => (int) $res['status'],
        'body'    => (string) $res['body'],
        'headers' => (string) $res['headers'],
    ];
}

function s3_put_subresource(array $config, string $bucket, string $sub, string $xml, array $headers = []): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'PUT', '', [
        'query'        => [$sub => ''],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
        'headers'      => $headers,
    ]);
    $ok = (int) $res['status'] >= 200 && (int) $res['status'] < 300;
    return ['ok' => $ok, 'status' => (int) $res['status'], 'message' => $ok ? '已保存。' : s3_error_message($res, '保存失败')];
}

function s3_delete_subresource(array $config, string $bucket, string $sub): array
{
    $res = s3_request(s3_bucket_config($config, $bucket), 'DELETE', '', [
        'query'        => [$sub => ''],
        'payload_hash' => hash('sha256', ''),
    ]);
    $status = (int) $res['status'];
    // 只有「该配置不存在」的 404 才算幂等成功；桶不存在的 404 必须报错
    $notFoundIsOk = $status === 404 && !str_contains((string) $res['body'], 'NoSuchBucket');
    $ok = $notFoundIsOk || ($status >= 200 && $status < 300);
    return ['ok' => $ok, 'status' => $status, 'message' => $ok ? '已删除。' : s3_error_message($res, '删除失败')];
}

/* ------------------------------------------------------------ Multipart */

function s3_multipart_init(
    array $config,
    string $bucket,
    string $key,
    ?string $storageClass = null,
    ?string $contentType = null
): string {
    $headers = [];
    if ($storageClass !== null && $storageClass !== '') {
        $headers['x-amz-storage-class'] = $storageClass;
    }
    if ($contentType !== null && $contentType !== '') {
        $headers['content-type'] = $contentType;
    }
    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['uploads' => ''],
        'headers'      => $headers,
        'payload_hash' => hash('sha256', ''),
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        throw new RuntimeException(s3_error_message($res, '初始化分片上传失败'));
    }
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $ok = $doc->loadXML((string) $res['body'], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) {
        throw new RuntimeException('无法解析分片上传响应。');
    }
    $nodes = $doc->getElementsByTagName('UploadId');
    if ($nodes->length === 0) {
        throw new RuntimeException('分片上传响应缺少 UploadId。');
    }
    return $nodes->item(0)->textContent;
}

function s3_multipart_part_url(array $config, string $bucket, string $key, string $uploadId, int $partNumber, int $ttl = 3600): string
{
    $partNumber = max(1, min(10000, $partNumber));
    return s3_presign_url(
        s3_bucket_config($config, $bucket),
        'PUT',
        $key,
        $ttl,
        ['partNumber' => (string) $partNumber, 'uploadId' => $uploadId]
    );
}

function s3_multipart_complete(array $config, string $bucket, string $key, string $uploadId, array $parts): array
{
    $xml = '<CompleteMultipartUpload>';
    foreach ($parts as $part) {
        $number = (int) ($part['part_number'] ?? 0);
        $etag = (string) ($part['etag'] ?? '');
        if ($number < 1 || $number > 10000 || $etag === '') {
            throw new InvalidArgumentException('分片编号或 ETag 不合法。');
        }
        $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>'
            . object_xml_escape($etag) . '</ETag></Part>';
    }
    $xml .= '</CompleteMultipartUpload>';

    $res = s3_request(s3_bucket_config($config, $bucket), 'POST', $key, [
        'query'        => ['uploadId' => $uploadId],
        'body'         => $xml,
        'payload_hash' => hash('sha256', $xml),
        'content_type' => 'application/xml',
        'timeout'      => 60,
    ]);
    if ((int) $res['status'] < 200 || (int) $res['status'] >= 300) {
        return ['ok' => false, 'message' => s3_error_message($res, '完成分片上传失败')];
    }

    // 与 CopyObject 同理：200 + <Error> 不能算完成
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML((string) $res['body'], LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $loaded ? $doc->documentElement : null;
    if ($root === null
        || $root->localName !== 'CompleteMultipartUploadResult'
        || $doc->getElementsByTagName('Error')->length > 0) {
        return ['ok' => false, 'message' => s3_error_message($res, '完成分片上传失败')];
    }
    return ['ok' => true, 'message' => '上传完成。'];
}

function s3_multipart_abort(array $config, string $bucket, string $key, string $uploadId): void
{
    s3_request(s3_bucket_config($config, $bucket), 'DELETE', $key, [
        'query'        => ['uploadId' => $uploadId],
        'payload_hash' => hash('sha256', ''),
    ]);
}
