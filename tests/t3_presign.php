<?php
declare(strict_types=1);

// 注意：本脚本只做结构校验，无法证明 COS 会接受该签名。
// 拿到真实 AK/SK 后必须补一次对真实 COS 的端到端验证（见 Task 15）。

require __DIR__ . '/lib.php';

$cfg = [
    'endpoint'   => 'https://cos.ap-guangzhou.myqcloud.com',
    'region'     => 'ap-guangzhou',
    'bucket'     => 'examplebucket-1250000000',
    'access_key' => 'AKIDEXAMPLE',
    'secret_key' => 'SECRETEXAMPLE',
    'prefix'     => '',
];

$url = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 7200);

check(str_starts_with($url, 'https://examplebucket-1250000000.cos.ap-guangzhou.myqcloud.com/ab/'),
    'URL 应为虚拟主机风格（桶名进 host），COS 拒绝 path-style');
check(str_contains($url, 'X-Amz-Algorithm=AWS4-HMAC-SHA256'), '应含 X-Amz-Algorithm');
check(str_contains($url, 'X-Amz-Expires=7200'), '应含 X-Amz-Expires=7200');
check(str_contains($url, 'X-Amz-SignedHeaders=host'), '应含 X-Amz-SignedHeaders=host');
check(str_contains($url, 'ap-guangzhou%2Fs3%2Faws4_request'), '凭据范围应含 region 与 s3');
check(preg_match('/X-Amz-Signature=[0-9a-f]{64}$/', $url) === 1, '应以 64 位十六进制签名结尾');

// 同样输入两次必须得到同样结果（签名可复现）
$again = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 7200);
check(substr($url, 0, strrpos($url, 'X-Amz-Signature=')) ===
      substr($again, 0, strrpos($again, 'X-Amz-Signature=')), '相同输入应产生相同待签串');

// 额外查询参数必须进入签名（response-content-disposition 用于保住文件名）
$withName = s3_presign($cfg, 'GET', 'ab/x', 60, ['response-content-disposition' => 'attachment; filename="a.txt"']);
check(str_contains($withName, 'response-content-disposition='), '应含自定义查询参数');
check(substr($url, -64) !== substr($withName, -64), '查询参数不同则签名必须不同');

// 有效期不同则签名不同
$shortTtl = s3_presign($cfg, 'GET', 'ab/' . str_repeat('c', 64), 60);
check(substr($url, -64) !== substr($shortTtl, -64), '有效期不同则签名必须不同');

finish();
