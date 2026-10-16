<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   纯 PHP 二维码编码器。
   本机 php.ini 把所有进程执行函数都禁用了（exec / proc_open / shell_exec），
   系统里虽然有 qrencode 也用不上，所以这里自己实现一份。

   范围：字节模式、纠错等级 M、版本 1–10（最多 214 字节，够放分享链接）。
   ========================================================================= */

final class QrCode
{
    /** 各版本在纠错等级 M 下的分块结构：[数据码字数, [块数, 每块数据码字数]…] */
    private const BLOCKS_M = [
        1  => [16, [[1, 16]]],
        2  => [28, [[1, 28]]],
        3  => [44, [[1, 44]]],
        4  => [64, [[2, 32]]],
        5  => [86, [[2, 43]]],
        6  => [108, [[4, 27]]],
        7  => [124, [[4, 31]]],
        8  => [154, [[2, 38], [2, 39]]],
        9  => [182, [[3, 36], [2, 37]]],
        10 => [216, [[4, 43], [1, 44]]],
    ];

    /** 纠错码字数（等级 M，版本 1–10） */
    private const ECC_PER_BLOCK_M = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26];

    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    private array $exp = [];
    private array $log = [];

    private int $version;
    private int $size;
    /** @var list<list<int|null>> */
    private array $matrix = [];
    /** @var list<list<bool>> 哪些位置属于功能图形（不参与掩码） */
    private array $reserved = [];

    public function __construct(private string $data, private int $eccLevelM = 0)
    {
        $this->initGalois();
        $this->version = $this->pickVersion(strlen($data));
        $this->size = 17 + 4 * $this->version;
    }

    /** 编码后返回 SVG 字符串 */
    public function svg(int $scale = 6, int $margin = 2): string
    {
        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->build($mask);
            $score = $this->penalty();
            if ($score < $bestPenalty) {
                $bestPenalty = $score;
                $best = $this->matrix;
            }
        }

        $matrix = $best ?? $this->matrix;
        $dim = ($this->size + $margin * 2) * $scale;

        $rects = '';
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if (($matrix[$y][$x] ?? 0) !== 1) {
                    continue;
                }
                $rects .= '<rect x="' . (($x + $margin) * $scale) . '" y="' . (($y + $margin) * $scale)
                    . '" width="' . $scale . '" height="' . $scale . '"/>';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '"'
            . ' width="' . $dim . '" height="' . $dim . '" shape-rendering="crispEdges">'
            . '<rect width="' . $dim . '" height="' . $dim . '" fill="#ffffff"/>'
            . '<g fill="#08090b">' . $rects . '</g></svg>';
    }

    /* ------------------------------------------------------------ 伽罗华域 */

    private function initGalois(): void
    {
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->exp[$i] = $x;
            $this->log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $this->exp[$i] = $this->exp[$i - 255];
        }
    }

    private function gmul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $this->exp[$this->log[$a] + $this->log[$b]];
    }

    /* -------------------------------------------------------------- 数据段 */

    private function pickVersion(int $length): int
    {
        for ($v = 1; $v <= 10; $v++) {
            $dataCodewords = self::BLOCKS_M[$v][0];
            $countBits = $v < 10 ? 8 : 16;
            $capacityBits = $dataCodewords * 8 - 4 - $countBits;
            if ($length * 8 <= $capacityBits) {
                return $v;
            }
        }
        throw new RuntimeException('二维码内容过长，超出 10 个版本的处理范围。');
    }

    /** @return list<int> 位数组 */
    private function bitstream(): array
    {
        $bits = [];
        $push = static function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $push(0b0100, 4);                                   // 字节模式
        $push(strlen($this->data), $this->version < 10 ? 8 : 16);

        foreach (str_split($this->data) as $ch) {
            $push(ord($ch), 8);
        }

        $dataCodewords = self::BLOCKS_M[$this->version][0];
        $capacityBits = $dataCodewords * 8;

        // 终止符（最多 4 个 0）
        for ($i = 0; $i < 4 && count($bits) < $capacityBits; $i++) {
            $bits[] = 0;
        }
        // 补齐到字节
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        // 转成码字
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        // 填充码字 0xEC / 0x11 交替
        $pad = [0xEC, 0x11];
        $k = 0;
        while (count($codewords) < $dataCodewords) {
            $codewords[] = $pad[$k % 2];
            $k++;
        }

        return $codewords;
    }

    /**
     * 生成 n 个纠错码字的生成多项式 g(x) = Π(x - α^i)。
     * 系数按降幂排列，g[0] 必须为 1，后续多项式除法才能靠它消掉首项。
     */
    private function generator(int $n): array
    {
        $poly = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;                                   // 乘 x
                $next[$j + 1] ^= $this->gmul($coef, $this->exp[$i]);  // 乘 α^i
            }
            $poly = $next;
        }
        return $poly;
    }

    /** 计算纠错码字 */
    private function ecc(array $data, int $count): array
    {
        $gen = $this->generator($count);
        $res = array_merge($data, array_fill(0, $count, 0));
        $len = count($data);

        for ($i = 0; $i < $len; $i++) {
            $coef = $res[$i];
            if ($coef === 0) {
                continue;
            }
            foreach ($gen as $j => $g) {
                $res[$i + $j] ^= $this->gmul($g, $coef);
            }
        }

        return array_slice($res, $len, $count);
    }

    /** 交错排列成最终码字序列 */
    private function interleave(): array
    {
        $data = $this->bitstream();
        [$totalData, $groups] = self::BLOCKS_M[$this->version];
        $eccCount = self::ECC_PER_BLOCK_M[$this->version];

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        foreach ($groups as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $eccBlocks[] = $this->ecc($block, $eccCount);
            }
        }

        $result = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccCount; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------ 矩阵构建 */

    private function build(int $mask): void
    {
        $size = $this->size;
        $this->matrix = array_fill(0, $size, array_fill(0, $size, null));
        $this->reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->placeFinders();
        $this->placeTiming();
        $this->placeAlignment();
        $this->reserveFormat();
        if ($this->version >= 7) {
            $this->placeVersion();
        }

        $this->placeData($this->interleave(), $mask);
        $this->placeFormat($mask);
        $this->placeDarkModule();

        // 剩余的 null 一律当 0
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($this->matrix[$y][$x] === null) {
                    $this->matrix[$y][$x] = 0;
                }
            }
        }
    }

    private function set(int $x, int $y, int $value, bool $reserve = true): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) {
            return;
        }
        $this->matrix[$y][$x] = $value;
        if ($reserve) {
            $this->reserved[$y][$x] = true;
        }
    }

    private function placeFinders(): void
    {
        foreach ([[0, 0], [$this->size - 7, 0], [0, $this->size - 7]] as [$ox, $oy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $ox + $x;
                    $py = $oy + $y;
                    if ($px < 0 || $py < 0 || $px >= $this->size || $py >= $this->size) {
                        continue;
                    }
                    $inRing = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                        || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6));
                    $inCore = ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4);
                    $this->set($px, $py, ($inRing || $inCore) ? 1 : 0);
                }
            }
        }
    }

    private function placeTiming(): void
    {
        for ($i = 8; $i < $this->size - 8; $i++) {
            $v = $i % 2 === 0 ? 1 : 0;
            $this->set($i, 6, $v);
            $this->set(6, $i, $v);
        }
    }

    private function placeAlignment(): void
    {
        $pos = self::ALIGN[$this->version];
        foreach ($pos as $cy) {
            foreach ($pos as $cx) {
                // 跳过与定位图形重叠的三个角
                if (($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $this->size - 7)
                    || ($cx === $this->size - 7 && $cy === 6)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $isEdge = max(abs($x), abs($y)) === 2;
                        $isCenter = $x === 0 && $y === 0;
                        $this->set($cx + $x, $cy + $y, ($isEdge || $isCenter) ? 1 : 0);
                    }
                }
            }
        }
    }

    /** 先占位格式信息区，避免数据写进去 */
    private function reserveFormat(): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $this->set($i, 8, 0);
                $this->set(8, $i, 0);
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($this->size - 1 - $i, 8, 0);
            $this->set(8, $this->size - 1 - $i, 0);
        }
        $this->set(8, 6, 1);
    }

    private function versionBits(): int
    {
        $v = $this->version;
        $rem = $v << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x1F25 << ($i - 12);
            }
        }
        return (($v << 12) | $rem) & 0x3FFFF;
    }

    private function placeVersion(): void
    {
        $bits = $this->versionBits();
        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $x = intdiv($i, 3);
            $y = $i % 3;
            $this->set($this->size - 11 + $y, $x, $bit);
            $this->set($x, $this->size - 11 + $y, $bit);
        }
    }

    /** 数据按之字形从右下角往上填 */
    private function placeData(array $codewords, int $mask): void
    {
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($cw >> $i) & 1;
            }
        }

        $idx = 0;
        $total = count($bits);
        $upward = true;

        for ($x = $this->size - 1; $x > 0; $x -= 2) {
            if ($x === 6) {
                $x = 5;   // 跳过竖向定时图形所在列
            }
            for ($step = 0; $step < $this->size; $step++) {
                $y = $upward ? $this->size - 1 - $step : $step;
                foreach ([$x, $x - 1] as $px) {
                    if ($this->reserved[$y][$px]) {
                        continue;
                    }
                    $bit = $idx < $total ? $bits[$idx] : 0;
                    $idx++;
                    if ($this->maskApplies($mask, $px, $y)) {
                        $bit ^= 1;
                    }
                    $this->matrix[$y][$px] = $bit;
                }
            }
            $upward = !$upward;
        }
    }

    private function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            7 => ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0,
            default => false,
        };
    }

    private function placeFormat(int $mask): void
    {
        // 等级 M 的两位编码是 00
        $data = ($mask & 0x7);
        $rem = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($data << 10) | ($rem & 0x3FF)) ^ 0x5412;

        // 标准要求高位在前：位置 i 放的是格式串的第 (14-i) 位
        for ($i = 0; $i < 15; $i++) {
            $bit = ($bits >> (14 - $i)) & 1;

            // 左上
            if ($i < 6) {
                $this->matrix[8][$i] = $bit;
            } elseif ($i === 6) {
                $this->matrix[8][7] = $bit;
            } elseif ($i === 7) {
                $this->matrix[8][8] = $bit;
            } elseif ($i === 8) {
                $this->matrix[7][8] = $bit;
            } else {
                $this->matrix[14 - $i][8] = $bit;
            }

            // 第二份：bit 0–6 沿列 8 自下而上，bit 7–14 沿行 8 自右向左。
            // 注意 bit 7 起于 x=size-8，暗模块 (x=8, y=size-8) 是独立的一格，不参与这里。
            if ($i < 7) {
                $this->matrix[$this->size - 1 - $i][8] = $bit;
            } else {
                $this->matrix[8][$this->size - 15 + $i] = $bit;
            }
        }
    }

    private function placeDarkModule(): void
    {
        $this->matrix[$this->size - 8][8] = 1;
    }

    /* -------------------------------------------------------------- 掩码罚分 */

    private function penalty(): int
    {
        $size = $this->size;
        $score = 0;

        // 规则 1：同色连续 ≥5
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($this->matrix[$y][$x] === $this->matrix[$y][$x - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($this->matrix[$y][$x] === $this->matrix[$y - 1][$x]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }
        }

        // 规则 2：2×2 同色块
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $v = $this->matrix[$y][$x];
                if ($v === $this->matrix[$y][$x + 1]
                    && $v === $this->matrix[$y + 1][$x]
                    && $v === $this->matrix[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // 规则 4：黑白比例失衡
        $dark = 0;
        foreach ($this->matrix as $row) {
            $dark += array_sum($row);
        }
        $ratio = $dark * 100 / ($size * $size);
        $score += (int) (abs($ratio - 50) / 5) * 10 * 2;

        return $score;
    }
}
