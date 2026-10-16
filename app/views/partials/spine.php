<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string $section */
/** @var array $user */
/** @var array{used:int,total:int} $quota */

$appName = (string) config('app_name');
$appNameEn = (string) config('app_name_en');
$usedPct = $quota['total'] > 0 ? min(100, ($quota['used'] / $quota['total']) * 100) : 0;
$privateReady = private_space_ready((int) $user['id']);
$privateOpen = Auth::privateUnlocked();
?>
<aside class="spine" data-spine>
    <a class="spine__brand" href="<?= e(url('files.list')) ?>">
        <?= lucide('archive', 'spine__mark') ?>
        <span class="spine__word">
            <b><?= e($appName) ?></b>
            <i><?= e($appNameEn) ?></i>
        </span>
    </a>

    <nav class="spine__nav" aria-label="主导航">
        <?php foreach (app_nav() as $item): ?>
            <a class="spine__item<?= $section === $item['id'] ? ' is-active' : '' ?>"
               href="<?= e($item['href']) ?>" data-nav="<?= e($item['id']) ?>"<?= $section === $item['id'] ? ' aria-current="page"' : '' ?>>
                <?= lucide($item['icon'], 'spine__ico') ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="spine__foot">
        <a class="spine__private<?= $privateOpen ? ' is-open' : '' ?>" href="<?= e(url('security.private')) ?>">
            <?= lucide($privateReady ? ($privateOpen ? 'unlock' : 'lock-keyhole') : 'lock-keyhole', 'spine__ico') ?>
            <span><?= $privateReady
                ? ($privateOpen ? '私密空间 · 已解锁' : '私密空间 · 已锁定')
                : '私密空间' ?></span>
        </a>

        <div class="gauge" title="<?= e(format_bytes((float) $quota['used'])) ?> / <?= e(format_bytes((float) $quota['total'])) ?>">
            <div class="gauge__row">
                <span class="gauge__label">已用</span>
                <span class="gauge__val"><?= e(format_bytes((float) $quota['used'])) ?></span>
            </div>
            <span class="gauge__track">
                <span class="gauge__fill" style="--fill:<?= e(number_format($usedPct, 2)) ?>%"></span>
            </span>
            <span class="gauge__hint">共 <?= e(format_bytes((float) $quota['total'])) ?></span>
        </div>
    </div>
</aside>
