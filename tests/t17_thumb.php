<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   缩略图方向：EXIF Orientation 解析 + 按方向摆正。

   本机没有 exif 扩展，解析是按 JPEG 段结构手写的，所以这里合成带 APP1 段的
   JPEG 来验：既验解析（大小端都要对），也验真的把像素转对了（不只看宽高）。
   ========================================================================= */

/**
 * 造一张带 EXIF Orientation 的 JPEG：左半红、右半蓝，40x20。
 * 内容做成整块纯色——JPEG 有损，单像素标记会被压缩糊掉，采样就不准了。
 */
function jpeg_with_orientation(int $orientation, bool $little = true): string
{
    $im = imagecreatetruecolor(40, 20);
    imagefilledrectangle($im, 0, 0, 19, 19, (int) imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, 20, 0, 39, 19, (int) imagecolorallocate($im, 0, 0, 255));
    ob_start();
    imagejpeg($im, null, 95);
    $jpeg = (string) ob_get_clean();
    imagedestroy($im);

    $pack16 = $little ? 'v' : 'n';
    $pack32 = $little ? 'V' : 'N';

    $tiff = ($little ? 'II' : 'MM')
        . pack($pack16, 0x002A)
        . pack($pack32, 8)
        . pack($pack16, 1)
        . pack($pack16, 0x0112)
        . pack($pack16, 3)
        . pack($pack32, 1)
        . pack($pack16, $orientation) . "\x00\x00"
        . pack($pack32, 0);

    $payload = "Exif\x00\x00" . $tiff;
    $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
}

/** 采样缩略图四边中点，判断哪一侧是红的 */
function thumb_sides(string $path): array
{
    $img = imagecreatefromjpeg($path);
    $w = imagesx($img);
    $h = imagesy($img);
    $isRed = static function (int $c): bool {
        return (($c >> 16) & 255) > (($c) & 255);
    };
    $out = [
        'w'      => $w,
        'h'      => $h,
        'top'    => $isRed((int) imagecolorat($img, intdiv($w, 2), max(0, intdiv($h, 4)))),
        'bottom' => $isRed((int) imagecolorat($img, intdiv($w, 2), min($h - 1, intdiv($h * 3, 4)))),
        'left'   => $isRed((int) imagecolorat($img, max(0, intdiv($w, 4)), intdiv($h, 2))),
        'right'  => $isRed((int) imagecolorat($img, min($w - 1, intdiv($w * 3, 4)), intdiv($h, 2))),
    ];
    imagedestroy($img);

    return $out;
}

$tempFiles = [];
$thumbFiles = [];
register_shutdown_function(static function () use (&$tempFiles, &$thumbFiles): void {
    foreach ($tempFiles as $f) {
        @unlink($f);
    }
    foreach ($thumbFiles as $f) {
        @unlink($f);
    }
});

$write = static function (string $bytes) use (&$tempFiles): string {
    $path = (string) tempnam(sys_get_temp_dir(), 'thumb-src-');
    file_put_contents($path, $bytes);
    $tempFiles[] = $path;
    return $path;
};

/* ---------------------------------------------- 解析：1-8，大小端都要对 */

$parseOk = true;
$parseFail = '';
foreach ([true, false] as $little) {
    for ($o = 1; $o <= 8; $o++) {
        $path = $write(jpeg_with_orientation($o, $little));
        if (jpeg_exif_orientation($path) !== $o) {
            $parseOk = false;
            $parseFail .= sprintf(' [%d/%s]', $o, $little ? 'LE' : 'BE');
        }
    }
}
check($parseOk, 'Orientation 1-8 全部解析正确（小端 + 大端）' . $parseFail);

// 无 EXIF 的普通 JPEG 必须回落成 1，不能乱转
$im = imagecreatetruecolor(40, 20);
imagefilledrectangle($im, 0, 0, 39, 19, (int) imagecolorallocate($im, 10, 10, 10));
ob_start();
imagejpeg($im, null, 95);
$plainJpeg = (string) ob_get_clean();
imagedestroy($im);
check(jpeg_exif_orientation($write($plainJpeg)) === 1, '无 EXIF 的 JPEG 返回 1（不旋转）');
check(jpeg_exif_orientation($write('not a jpeg at all')) === 1, '非 JPEG 输入安全回落为 1');

// 畸形 TIFF：魔数不对、指针越界、字节序非法，都不能抛异常
check(jpeg_orientation_from_tiff("II\x00") === 1, '过短的 TIFF 块安全回落');
check(jpeg_orientation_from_tiff('II' . pack('v', 0x9999) . pack('V', 8) . "\x00\x00") === 1, 'TIFF 魔数错误回落为 1');
check(jpeg_orientation_from_tiff('II' . pack('v', 0x002A) . pack('V', 9999) . "\x00\x00") === 1, 'IFD 指针越界回落为 1');
check(jpeg_orientation_from_tiff('XX' . pack('v', 0x002A) . pack('V', 8) . "\x00\x00") === 1, '字节序非法回落为 1');

/* ------------------------------------------------- 生成：方向要真的转对 */

$makeThumb = static function (string $sourcePath) use (&$thumbFiles): ?string {
    $out = make_thumbnail($sourcePath, random_token(32));
    if ($out !== null) {
        $thumbFiles[] = $out;
    }
    return $out;
};

// 基准：无方向标签 -> 左红右蓝，尺寸不变
$base = $makeThumb($write(jpeg_with_orientation(1)));
check($base !== null, '基准缩略图生成成功');
$s1 = $base === null ? [] : thumb_sides($base);
check(($s1['w'] ?? 0) === 40 && ($s1['h'] ?? 0) === 20, 'Orientation=1 尺寸保持 40x20');
check(($s1['left'] ?? false) === true && ($s1['right'] ?? true) === false, 'Orientation=1 左红右蓝');

// Orientation=6：顺时针 90 度，尺寸应变成 20x40，源图左半（红）转到上半
$o6 = $makeThumb($write(jpeg_with_orientation(6)));
$s6 = $o6 === null ? [] : thumb_sides((string) $o6);
check(($s6['w'] ?? 0) === 20 && ($s6['h'] ?? 0) === 40,
    'Orientation=6 宽高对调为 20x40（实际 ' . ($s6['w'] ?? 0) . 'x' . ($s6['h'] ?? 0) . '）');
check(($s6['top'] ?? false) === true && ($s6['bottom'] ?? true) === false,
    'Orientation=6 顺时针 90 度：左侧转到上方（红在上）');

// Orientation=8：逆时针 90 度，尺寸对调，源图左半（红）转到下方
$o8 = $makeThumb($write(jpeg_with_orientation(8)));
$s8 = $o8 === null ? [] : thumb_sides((string) $o8);
check(($s8['w'] ?? 0) === 20 && ($s8['h'] ?? 0) === 40, 'Orientation=8 宽高对调为 20x40');
check(($s8['bottom'] ?? false) === true && ($s8['top'] ?? true) === false,
    'Orientation=8 逆时针 90 度：左侧转到下方（红在下）');

// Orientation=3：180 度，尺寸不变，左右互换
$o3 = $makeThumb($write(jpeg_with_orientation(3)));
$s3 = $o3 === null ? [] : thumb_sides((string) $o3);
check(($s3['w'] ?? 0) === 40 && ($s3['h'] ?? 0) === 20, 'Orientation=3 尺寸保持 40x20');
check(($s3['right'] ?? false) === true && ($s3['left'] ?? true) === false, 'Orientation=3 旋转 180 度：左右互换');

// Orientation=2：水平镜像，尺寸不变，左右互换
$o2 = $makeThumb($write(jpeg_with_orientation(2)));
$s2 = $o2 === null ? [] : thumb_sides((string) $o2);
check(($s2['right'] ?? false) === true && ($s2['left'] ?? true) === false, 'Orientation=2 水平镜像：左右互换');

/* ------------------------------------------------------------ 缓存版本 */

check(str_contains(thumb_path(str_repeat('a', 64)), '-r' . THUMB_REVISION . '.jpg'),
    '缩略图缓存键带版本号——改生成逻辑后旧缓存不会被误用');

/* ------------------------------------------------- 解码内存闸门

   GD 解码会一次铺开整张真彩位图。128M 的默认上限连 48MP 手机照片都放不下，
   硬解会内存耗尽、把整个上传请求带崩 —— 这里验估算与放行判定。 */

check(memory_limit_bytes() > 0, '能解析 memory_limit');
check(memory_limit_bytes() === PHP_INT_MAX || memory_limit_bytes() >= 32 * 1024 * 1024,
    'memory_limit 解析出合理量级（' . format_bytes((float) memory_limit_bytes()) . '）');

// 48MP 手机照片：8000×6000×4 ≈ 183MB
$need48 = thumb_memory_estimate(8000, 6000, false);
check($need48 > 150 * 1024 * 1024 && $need48 < 250 * 1024 * 1024,
    '48MP 原图估算约 190MB（实际 ' . format_bytes((float) $need48) . '）');

// 旋转会多开一张同尺寸的图，估算必须翻倍——否则闸门会漏
$needRot = thumb_memory_estimate(8000, 6000, true);
check($needRot > $need48 * 1.9, '旋转场景的估算约为不旋转的两倍（峰值确实翻倍）');

// 小图不该被拦
check(thumb_memory_gate(1200, 900, false)['ok'], '普通照片放行');

// 超上限必须拦下，并给出可读原因
$huge = thumb_memory_gate(20000, 15000, false);
check(!$huge['ok'], '超大原图被拦下（估算 ' . format_bytes((float) $huge['need']) . '）');
check(str_contains($huge['reason'], 'thumb_max_mb'), '拦截原因里点明了可调的配置项');

// 闸门必须排在真正解码之前，否则拦不住就等于没拦
$thumbSrc = (string) file_get_contents(BASE_PATH . '/app/lib/storage.php');
$gatePos = strpos($thumbSrc, 'thumb_memory_gate(');
$decodePos = strpos($thumbSrc, '@imagecreatefromjpeg($sourcePath)');
check($gatePos !== false && $decodePos !== false && $gatePos < $decodePos,
    '内存闸门必须排在 imagecreatefromjpeg 之前');

finish();
