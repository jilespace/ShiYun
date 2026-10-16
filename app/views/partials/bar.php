<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string|null $barTools 页面专有的工具按钮 HTML */
/** @var array $user */
/** @var string|null $query 搜索框当前值 */

$barTools = $barTools ?? '';
$query = $query ?? '';
?>
<header class="bar">
    <button class="iconbtn bar__burger" type="button" data-burger aria-label="展开导航">
        <?= lucide('panel-left') ?>
    </button>

    <form class="omni" role="search" data-omni action="<?= e(url('search.results')) ?>" method="get">
        <input type="hidden" name="r" value="search.results">
        <?= lucide('search', 'omni__ico') ?>
        <input class="omni__in" name="q" type="search" placeholder="搜索文件名，或输入内容关键词"
               autocomplete="off" value="<?= e($query) ?>">
        <kbd class="omni__kbd">/</kbd>
    </form>

    <div class="bar__tools">
        <?= $barTools ?>

        <button class="iconbtn" type="button" data-task-toggle aria-label="传输队列" title="传输队列">
            <?= lucide('arrow-up-down') ?><span class="dot" data-task-dot hidden></span>
        </button>
        <a class="iconbtn" href="<?= e(url('settings.show')) ?>" aria-label="设置"><?= lucide('settings') ?></a>

        <div class="who" data-who>
            <button class="who__btn" type="button" data-who-btn aria-haspopup="menu" aria-expanded="false" aria-label="账户菜单">
                <span class="who__avatar"><?= e(mb_substr((string) $user['display_name'], 0, 1)) ?></span>
            </button>
            <div class="who__menu" data-who-menu hidden role="menu">
                <div class="who__head">
                    <b><?= e((string) $user['display_name']) ?></b>
                    <span><?= e((string) $user['email']) ?></span>
                </div>
                <a href="<?= e(url('settings.show')) ?>" role="menuitem"><?= lucide('user', 'who__ico') ?>个人资料</a>
                <a href="<?= e(url('settings.password')) ?>" role="menuitem"><?= lucide('key-round', 'who__ico') ?>修改密码</a>
                <a href="<?= e(url('security.logs')) ?>" role="menuitem"><?= lucide('history', 'who__ico') ?>登录日志</a>
                <a href="<?= e(url('security.activity')) ?>" role="menuitem"><?= lucide('activity', 'who__ico') ?>操作日志</a>
                <form method="post" action="<?= e(url('auth.logout')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" role="menuitem"><?= lucide('log-out', 'who__ico') ?>退出登录</button>
                </form>
            </div>
        </div>
    </div>
</header>
