<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array<string,mixed> $user */

$appName = (string) config('app_name');
$used  = (int) $user['used_bytes'];
$quota = max(1, (int) $user['quota_bytes']);
$pct   = min(100, $used / $quota * 100);
?>
<div class="rail" aria-hidden="true"><span>我的空间 · MY SPACE</span></div>

<main class="shell">
    <header class="topbar">
        <a class="brand" href="<?= e(url('files.list')) ?>">
            <?= lucide('archive', 'brand__mark') ?>
            <span class="brand__name"><?= e($appName) ?></span>
        </a>

        <div class="status">
            <span class="status__text"><?= e((string) $user['display_name']) ?></span>
            <form method="post" action="<?= e(url('auth.logout')) ?>">
                <?= csrf_field() ?>
                <button class="ghost" data-magnetic type="submit">
                    <?= lucide('log-out', 'ghost__ico') ?>
                    <span>退出登录</span>
                </button>
            </form>
        </div>
    </header>

    <section class="board">
        <h1 class="board__title">你好，<?= e((string) $user['display_name']) ?></h1>

        <div class="meter meter--lg">
            <div class="meter__row">
                <span class="meter__label">已用空间</span>
                <span class="meter__val"><b><?= e(format_bytes($used)) ?></b> / <?= e(format_bytes((float) $quota, 0)) ?></span>
            </div>
            <span class="meter__track">
                <span class="meter__fill" style="--fill:<?= e((string) round($pct, 2)) ?>%"></span>
            </span>
        </div>

        <p class="board__empty">你的空间还空着。上传与文件管理将在下一步接入。</p>
    </section>
</main>
