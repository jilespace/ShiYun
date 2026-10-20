<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string $tab 当前子页：profile | password | private | logs | activity | tokens */
$tab = $tab ?? 'profile';

$tabs = [
    ['id' => 'profile',  'label' => '个人资料', 'href' => url('settings.show')],
    ['id' => 'password', 'label' => '修改密码', 'href' => url('settings.password')],
    ['id' => 'private',  'label' => '私密空间', 'href' => url('security.private')],
    ['id' => 'totp',     'label' => '两步验证', 'href' => url('security.twofactor')],
    ['id' => 'logs',     'label' => '登录日志', 'href' => url('security.logs')],
    ['id' => 'activity', 'label' => '操作日志', 'href' => url('security.activity')],
    ['id' => 'rules',    'label' => '自动化',   'href' => url('rules.list')],
    ['id' => 'tokens',   'label' => 'API 令牌', 'href' => url('api.tokens')],
];
?>
<nav class="seg2" aria-label="设置子页">
    <?php foreach ($tabs as $item): ?>
        <a href="<?= e($item['href']) ?>" class="<?= $tab === $item['id'] ? 'is-on' : '' ?>"
           <?= $tab === $item['id'] ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
    <?php endforeach; ?>
</nav>
