<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var int $days */
/** @var list<array> $storage */
/** @var list<array> $traffic */
/** @var array<string,array{label:string,bytes:int,count:int}> $dist */
/** @var array $totals */
/** @var int $sumUp */
/** @var int $sumDown */
/** @var array $suggestions */
/** @var array{used:int,total:int} $quota */

$usedPct = $quota['total'] > 0 ? min(100, $quota['used'] / $quota['total'] * 100) : 0;
$peak = max(1, max(array_map(static fn (array $r): int => $r['used'], $storage) ?: [1]));

/** 生成折线/面积图路径 */
$areaPath = static function (array $series, string $key, float $w, float $h): array {
    $n = count($series);
    if ($n === 0) {
        return ['line' => '', 'area' => ''];
    }
    $max = max(1, max(array_map(static fn (array $r): int => (int) $r[$key], $series)));
    $step = $n > 1 ? $w / ($n - 1) : $w;

    $pts = [];
    foreach ($series as $i => $row) {
        $x = $i * $step;
        $y = $h - ((int) $row[$key] / $max) * ($h - 8) - 4;
        $pts[] = [$x, $y];
    }

    $line = 'M' . implode(' L', array_map(static fn (array $p): string => round($p[0], 1) . ' ' . round($p[1], 1), $pts));
    $area = $line . ' L' . round($w, 1) . ' ' . $h . ' L0 ' . $h . ' Z';

    return ['line' => $line, 'area' => $area];
};

$storageChart = $areaPath($storage, 'used', 720, 150);

// 流量柱子
$maxTraffic = max(1, max(array_map(static fn (array $r): int => max($r['up'], $r['down']), $traffic) ?: [1]));
$bars = [];
$barCount = max(1, count($traffic));
$barW = 720 / $barCount;

$distTotal = max(1, array_sum(array_map(static fn (array $d): int => $d['bytes'], $dist)));

$distColors = [
    'image'    => '#8a88ff',
    'video'    => '#3ad0d0',
    'audio'    => '#4ad07a',
    'document' => '#ffa23a',
    'archive'  => '#ff5a5a',
    'code'     => '#e8d44d',
    'other'    => 'rgba(244,242,236,.3)',
];
?>
<div class="app" data-app data-section="stats" data-page="stats">
    <?php view('partials/spine', ['section' => 'stats', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">统计</h1>
                    <p class="pagehead__sub">容量、构成、趋势与流量。数据每天打一次快照，长期曲线会逐渐完整。</p>
                </div>
                <div class="pagehead__acts">
                    <?php foreach ([7 => '7 天', 30 => '30 天', 90 => '90 天', 180 => '半年'] as $d => $label): ?>
                        <a class="pill<?= $days === $d ? ' pill--on' : '' ?>"
                           href="<?= e(url('stats.show', ['days' => $d])) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="grid grid--4" style="margin-bottom:22px">
                <div class="tile tile--signal">
                    <span class="tile__label">已用容量</span>
                    <span class="tile__value"><?= e(format_bytes((float) $quota['used'])) ?></span>
                    <span class="tile__hint">占总配额 <?= e(number_format($usedPct, 2)) ?>%</span>
                </div>
                <div class="tile">
                    <span class="tile__label">文件 / 文件夹</span>
                    <span class="tile__value"><?= (int) $totals['files'] ?><small>/ <?= (int) $totals['folders'] ?></small></span>
                </div>
                <div class="tile">
                    <span class="tile__label"><?= $days ?> 天上传</span>
                    <span class="tile__value"><?= e(format_bytes((float) $sumUp)) ?></span>
                </div>
                <div class="tile">
                    <span class="tile__label"><?= $days ?> 天下载</span>
                    <span class="tile__value"><?= e(format_bytes((float) $sumDown)) ?></span>
                </div>
            </div>

            <div class="panel" style="margin-bottom:22px">
                <div class="panel__head">
                    <h2 class="panel__title">容量趋势</h2>
                    <span class="panel__note">峰值 <?= e(format_bytes((float) $peak)) ?></span>
                </div>
                <div class="panel__body">
                    <svg class="chart" viewBox="0 0 720 150" preserveAspectRatio="none" height="150" role="img"
                         aria-label="容量趋势">
                        <defs>
                            <linearGradient id="gradArea" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#8a88ff" stop-opacity="0.32"/>
                                <stop offset="100%" stop-color="#8a88ff" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <?php for ($g = 0; $g <= 3; $g++): ?>
                            <line class="chart__grid" x1="0" x2="720" y1="<?= $g * 50 ?>" y2="<?= $g * 50 ?>"/>
                        <?php endfor; ?>
                        <path class="chart__area" d="<?= e($storageChart['area']) ?>"/>
                        <path class="chart__line" d="<?= e($storageChart['line']) ?>"/>
                    </svg>

                    <div class="legend" style="margin-top:12px">
                        <span class="legend__key"><span class="legend__swatch" style="--k:#8a88ff"></span>已用容量</span>
                        <span class="legend__key"><?= e((string) $storage[0]['day']) ?> → <?= e((string) end($storage)['day']) ?></span>
                    </div>
                </div>
            </div>

            <div class="grid grid--2" style="margin-bottom:22px">
                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">类型分布</h2>
                        <span class="panel__note">按占用空间</span>
                    </div>
                    <div class="panel__body">
                        <div class="dist">
                            <?php foreach ($dist as $key => $bucket): ?>
                                <?php if ($bucket['count'] === 0) continue; ?>
                                <div class="dist__row">
                                    <span class="muted"><?= e((string) $bucket['label']) ?></span>
                                    <span class="dist__track">
                                        <span class="dist__fill"
                                              style="--w:<?= e(number_format($bucket['bytes'] / $distTotal * 100, 2)) ?>%;--c:<?= e($distColors[$key] ?? '#8a88ff') ?>"></span>
                                    </span>
                                    <span class="dist__val"><?= e(format_bytes((float) $bucket['bytes'])) ?> · <?= (int) $bucket['count'] ?> 个</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">流量</h2>
                        <span class="panel__note">上传 / 下载</span>
                    </div>
                    <div class="panel__body">
                        <svg class="chart" viewBox="0 0 720 150" preserveAspectRatio="none" height="150" role="img"
                             aria-label="流量柱状图">
                            <?php for ($g = 0; $g <= 3; $g++): ?>
                                <line class="chart__grid" x1="0" x2="720" y1="<?= $g * 50 ?>" y2="<?= $g * 50 ?>"/>
                            <?php endfor; ?>
                            <?php foreach ($traffic as $i => $row): ?>
                                <?php
                                $x = $i * $barW;
                                $upH = ($row['up'] / $maxTraffic) * 130;
                                $downH = ($row['down'] / $maxTraffic) * 130;
                                $bw = max(1.2, $barW * 0.34);
                                ?>
                                <?php if ($downH > 0): ?>
                                    <rect x="<?= round($x + $barW * 0.12, 2) ?>" y="<?= round(140 - $downH, 2) ?>"
                                          width="<?= round($bw, 2) ?>" height="<?= round($downH, 2) ?>"
                                          fill="#8a88ff" opacity="0.85"/>
                                <?php endif; ?>
                                <?php if ($upH > 0): ?>
                                    <rect x="<?= round($x + $barW * 0.52, 2) ?>" y="<?= round(140 - $upH, 2) ?>"
                                          width="<?= round($bw, 2) ?>" height="<?= round($upH, 2) ?>"
                                          fill="#4ad07a" opacity="0.85"/>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </svg>

                        <div class="legend" style="margin-top:12px">
                            <span class="legend__key"><span class="legend__swatch" style="--k:#8a88ff"></span>下载 <?= e(format_bytes((float) $sumDown)) ?></span>
                            <span class="legend__key"><span class="legend__swatch" style="--k:#4ad07a"></span>上传 <?= e(format_bytes((float) $sumUp)) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">清理建议</h2>
                    <span class="panel__note">只提示，不会自动删</span>
                </div>
                <div class="panel__body">
                    <div class="suggest">
                        <?php if ($suggestions['trashCount'] > 0): ?>
                            <div class="suggest__row">
                                <?= lucide('trash-2') ?>
                                <span class="suggest__txt">
                                    回收站里还有 <?= (int) $suggestions['trashCount'] ?> 项，
                                    占着 <?= e(format_bytes((float) $suggestions['trashBytes'])) ?>。
                                </span>
                                <a class="chipbtn" href="<?= e(url('files.list', ['section' => 'trash'])) ?>">去处理</a>
                            </div>
                        <?php endif; ?>

                        <?php foreach (array_slice($suggestions['dupes'], 0, 3) as $dupe): ?>
                            <div class="suggest__row">
                                <?= lucide('layers') ?>
                                <span class="suggest__txt">
                                    「<?= e((string) $dupe['sample']) ?>」等 <?= (int) $dupe['copies'] ?> 个条目内容完全相同，
                                    冗余 <?= e(format_bytes((float) $dupe['wasted'])) ?>。物理文件已自动合并，删掉多余副本可释放配额。
                                </span>
                                <span class="suggest__num"><?= (int) $dupe['copies'] ?> 份</span>
                            </div>
                        <?php endforeach; ?>

                        <?php foreach (array_slice($suggestions['largest'], 0, 4) as $big): ?>
                            <div class="suggest__row">
                                <?= lucide((string) $big['icon']) ?>
                                <span class="suggest__txt">
                                    <a href="<?= e(url('preview.open', ['id' => (int) $big['id']])) ?>"
                                       style="color:var(--ink);text-decoration:none"><?= e((string) $big['name']) ?></a>
                                    占了 <?= e((string) $big['sizeText']) ?>。
                                </span>
                                <span class="suggest__num"><?= e((string) $big['sizeText']) ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($suggestions['stale'] !== []): ?>
                            <div class="suggest__row">
                                <?= lucide('clock') ?>
                                <span class="suggest__txt">
                                    <?= count($suggestions['stale']) ?> 个大文件超过一年没动过，
                                    合计 <?= e(format_bytes((float) array_sum(array_map(static fn (array $r): int => (int) $r['size'], $suggestions['stale'])))) ?>。
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($suggestions['trashCount'] === 0 && $suggestions['dupes'] === [] && $suggestions['stale'] === []): ?>
                            <div class="suggest__row">
                                <?= lucide('check-circle') ?>
                                <span class="suggest__txt">空间很干净，没什么可清理的。</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
