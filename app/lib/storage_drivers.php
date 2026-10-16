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
    $batch = implode("\n", ['cd ' . $dir, 'pwd', 'bye']);

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
 * @return array{base:string,host:string}
 */
function s3_addressed(array $c): array
{
    $endpoint = rtrim((string) $c['endpoint'], '/');
    $bucket = (string) $c['bucket'];

    $scheme = (string) (parse_url($endpoint, PHP_URL_SCHEME) ?: 'https');
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    $port = parse_url($endpoint, PHP_URL_PORT);

    // 桶名进 host；已经带过就不再拼
    if (!str_starts_with($host, $bucket . '.')) {
        $host = $bucket . '.' . $host;
    }

    $authority = $host . ($port !== null ? ':' . $port : '');

    return ['base' => $scheme . '://' . $authority, 'host' => $authority];
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

    // 虚拟主机风格：桶名进 host（COS 拒绝 path-style，报 PathStyleDomainForbidden）
    $addr = s3_addressed($c);
    $hostHeader = $addr['host'];

    $objectKey = $prefix !== '' ? $prefix . '/' . ltrim($key, '/') : ltrim($key, '/');
    $url = $addr['base'] . '/' . ($objectKey !== '' ? str_replace('%2F', '/', rawurlencode($objectKey)) : '');

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = $opts['payload_hash'] ?? hash('sha256', (string) ($opts['body'] ?? ''));

    $headers = [
        'host'                 => $hostHeader,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date'           => $amzDate,
    ];
    if (!empty($c['session_token'])) {
        $headers['x-amz-security-token'] = (string) $c['session_token'];
    }
    if (isset($opts['content_type'])) {
        $headers['content-type'] = (string) $opts['content_type'];
    }

    ksort($headers);
    $canonicalHeaders = '';
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= $k . ':' . trim((string) $v) . "\n";
    }
    $signedHeaders = implode(';', array_keys($headers));

    $uriPath = parse_url($url, PHP_URL_PATH) ?: '/';
    $canonicalRequest = implode("\n", [
        $method,
        $uriPath,
        '',
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
    if (isset($opts['file'])) {
        curl_setopt($ch, CURLOPT_INFILE, fopen((string) $opts['file'], 'rb'));
        curl_setopt($ch, CURLOPT_INFILESIZE, (int) filesize((string) $opts['file']));
        curl_setopt($ch, CURLOPT_UPLOAD, true);
    }
    if (!empty($opts['download'])) {
        $fh = fopen((string) $opts['download'], 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_HEADER, false);
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

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
function s3_presign(array $c, string $method, string $key, int $ttl, array $query = []): string
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

    $params = $query + [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => (string) $c['access_key'] . '/' . $scope,
        'X-Amz-Date'          => $amzDate,
        'X-Amz-Expires'       => (string) $ttl,
        'X-Amz-SignedHeaders' => 'host',
    ];
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
        'host:' . $hostHeader . "\n",
        'host',
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
                'cd ' . $dir,
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
