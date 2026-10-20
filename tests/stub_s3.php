<?php

declare(strict_types=1);

/* =========================================================================
   本地 S3 存根，供 t16_bucket.php 用 `php -S` 拉起。
   只回放协议层面的形状（寻址 / 请求行 / 查询串 / 响应体），不校验签名——
   签名正确性由真实端点验证，这里保证的是「发出去的请求长什么样」。

   每个请求追加一行 JSON 到 $STUB_S3_LOG 指向的文件，测试据此断言寻址是否落对。
   ========================================================================= */

$logFile = (string) (getenv('STUB_S3_LOG') ?: sys_get_temp_dir() . '/stub-s3.log');

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');

file_put_contents($logFile, json_encode([
    'method'  => $method,
    'uri'     => $uri,
    'host'    => $host,
    'auth'    => isset($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : '',
    'amzDate' => (string) ($_SERVER['HTTP_X_AMZ_DATE'] ?? ''),
    'sha256'  => (string) ($_SERVER['HTTP_X_AMZ_CONTENT_SHA256'] ?? ''),
    'contentMd5' => (string) ($_SERVER['HTTP_CONTENT_MD5'] ?? ''),
    'body'    => (string) (file_get_contents('php://input') ?: ''),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

// 虚拟主机风格：桶名是 host 最前面那一段。服务级调用（列桶）host 里没有桶名。
// 服务端点主机名由测试注入——用 IP 时桶名子域没法解析，所以测试走 localhost。
$serviceHost = (string) (getenv('STUB_S3_SERVICE_HOST') ?: '127.0.0.1');
$hostOnly = explode(':', $host)[0];
$bucket = '';
$suffix = '.' . $serviceHost;
if ($hostOnly !== $serviceHost && str_ends_with($hostOnly, $suffix)) {
    $bucket = substr($hostOnly, 0, -strlen($suffix));
}

$path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
$query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');

header('Content-Type: application/xml; charset=utf-8');

/* ---------------------------------------------------------- 服务级：列桶 */
if ($method === 'GET' && $bucket === '') {
    echo '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ListAllMyBucketsResult><Owner><ID>stub-owner</ID></Owner><Buckets>'
        . '<Bucket><Name>photos-1250000000</Name><Location>ap-guangzhou</Location>'
        . '<CreationDate>2026-01-02T03:04:05Z</CreationDate></Bucket>'
        . '<Bucket><Name>backup-bucket</Name><Location>ap-guangzhou</Location>'
        . '<CreationDate>2025-12-31T00:00:00Z</CreationDate></Bucket>'
        . '</Buckets></ListAllMyBucketsResult>';
    exit;
}

/* ------------------------------------------------------------ 桶级：探测 */
if ($method === 'HEAD') {
    if ($bucket === 'missing-bucket') {
        http_response_code(404);
    } elseif ($bucket === 'denied-bucket') {
        http_response_code(403);
    } else {
        http_response_code(200);
    }
    exit;
}

/* ------------------------------------------------------------ 桶级：创建 */
if ($method === 'PUT') {
    if ($bucket === 'taken-bucket') {
        http_response_code(409);
        echo '<Error><Code>BucketAlreadyOwnedByYou</Code>'
            . '<Message>Your previous request to create the named bucket succeeded.</Message></Error>';
        exit;
    }
    http_response_code(200);
    exit;
}

/* ------------------------------------------------------------ 桶级：删除 */
if ($method === 'DELETE') {
    if ($bucket === 'missing-bucket') {
        http_response_code(404);
        echo '<Error><Code>NoSuchBucket</Code><Message>The specified bucket does not exist.</Message></Error>';
        exit;
    }
    if ($bucket === 'notempty-bucket') {
        http_response_code(409);
        echo '<Error><Code>BucketNotEmpty</Code><Message>The bucket you tried to delete is not empty.</Message></Error>';
        exit;
    }
    http_response_code(204);
    exit;
}

/* -------------------------------------------------- 桶级：列对象（判空） */
if ($method === 'GET' && str_contains($query, 'list-type=2')) {
    echo '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><IsTruncated>false</IsTruncated>';
    if ($bucket === 'full-bucket') {
        echo '<Contents><Key>0a/abcdef</Key><Size>12</Size></Contents>';
    }
    // 状态化：同一个桶发生过批量删除后，后续列表一律返回空。
    // 清空桶的流程最后会再探一眼「是否还有剩余」，不做状态就没法验这一步。
    $deletedBefore = false;
    if (is_file($logFile)) {
        foreach ((array) file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)
                && $entry['method'] === 'POST'
                && str_contains((string) $entry['uri'], 'delete')
                && str_contains((string) $entry['host'], $bucket . '.')) {
                $deletedBefore = true;
                break;
            }
        }
    }

    if (($bucket === 'objbucket' || $bucket === 'purgebucket') && !$deletedBefore) {
        echo '<Contents><Key>0a/aaa111</Key><Size>1024</Size><LastModified>2026-01-02T03:04:05Z</LastModified></Contents>';
        echo '<Contents><Key>0b/bbb222</Key><Size>2048</Size><LastModified>2026-01-03T03:04:05Z</LastModified></Contents>';
        echo '<Contents><Key>note.txt</Key><Size>15</Size><LastModified>2026-01-04T03:04:05Z</LastModified></Contents>';
    }
    echo '</ListBucketResult>';
    exit;
}

/* ------------------------------------------------ 桶级：批量删除对象 */
if ($method === 'POST' && str_contains($query, 'delete')) {
    $body = (string) (file_get_contents('php://input') ?: '');
    $count = substr_count($body, '<Object>');
    echo '<?xml version="1.0" encoding="UTF-8"?><DeleteResult><Deleted>'
        . str_repeat('<Key>stub</Key>', $count) . '</Deleted></DeleteResult>';
    exit;
}

http_response_code(400);
echo '<Error><Code>UnexpectedRequest</Code><Message>' . htmlspecialchars($method . ' ' . $uri, ENT_XML1) . '</Message></Error>';
