<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

check(function_exists('storage_source_delete'), 'storage_source_delete 应存在');

// 未配置 aria2 之类的无关依赖不应影响；这里只验证对不可达 endpoint 会抛错
$source = [
    'id' => 999, 'driver' => 's3',
    'config' => json_encode([
        'endpoint' => 'https://127.0.0.1:1', 'region' => 'ap-guangzhou',
        'bucket' => 'b-1', 'access_key' => 'AK', 'secret_key' => 'SK',
    ]),
];

$threw = false;
try {
    storage_source_delete($source, 'aa/bb');
} catch (RuntimeException $e) {
    $threw = true;
}
check($threw, 'endpoint 不可达时应抛 RuntimeException');

// local 驱动：删不存在的文件不应抛错（幂等）
$localSource = ['id' => 998, 'driver' => 'local',
    'config' => json_encode(['root' => sys_get_temp_dir() . '/t4-none'])];
$ok = true;
try {
    storage_source_delete($localSource, 'does/not/exist');
} catch (Throwable $e) {
    $ok = false;
}
check($ok, 'local 源删除不存在的键应幂等不抛错');

finish();
