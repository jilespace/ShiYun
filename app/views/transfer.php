<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var bool $enabled */
/** @var bool $localOnly */
$appName = (string) config('app_name');
?>
<div class="tp" data-page="transfer">
    <header class="tp__head">
        <a class="tp__brand" href="<?= e(url('')) ?>">
            <?= lucide('archive', 'tp__mark') ?>
            <span><?= e($appName) ?> · 临时传输</span>
        </a>
        <a class="tp__login" href="<?= e(url('auth.login')) ?>">登录</a>
    </header>

    <main class="tp__main">
        <section class="tp__intro">
            <h1>两台电脑，<br>直接传文件。</h1>
            <p>不用登录。创建一个房间，把链接或 6 位取件码给对方；双方都能上传和下载，24 小时后自动清空。</p>
            <?php if ($localOnly): ?>
                <div class="mwarn">
                    <?= lucide('alert-triangle') ?>
                    <span>你正在用 localhost 访问。这个地址只在本机有效；局域网互传请用服务器的局域网 IP 打开本站再创建房间。</span>
                </div>
            <?php endif; ?>
            <?php if (!$enabled): ?>
                <div class="mwarn"><?= lucide('alert-triangle') ?><span>临时传输当前已关闭。</span></div>
            <?php endif; ?>
        </section>

        <div class="tp__grid">
            <section class="tp__card">
                <h2>创建房间</h2>
                <p class="tp__hint">创建一个双方都能上传/下载的临时空间。</p>
                <label class="mfield">
                    <span class="mfield__label">提取密码（可选）</span>
                    <input class="minput" type="password" data-create-pass maxlength="32" autocomplete="new-password">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-create<?= $enabled ? '' : ' disabled' ?>>创建房间</button>
            </section>

            <section class="tp__card">
                <h2>输入取件码</h2>
                <p class="tp__hint">输入对方给你的 6 位数字。</p>
                <label class="mfield">
                    <span class="mfield__label">取件码</span>
                    <input class="minput tp__code-input" inputmode="numeric" maxlength="6" data-join-code placeholder="000000">
                </label>
                <label class="mfield">
                    <span class="mfield__label">提取密码（如有）</span>
                    <input class="minput" type="password" data-join-pass maxlength="32" autocomplete="off">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-join>进入房间</button>
            </section>
        </div>

        <section class="tp__result" data-create-result hidden>
            <div class="tp__result-main">
                <span class="tp__label">取件码</span>
                <b class="tp__bigcode" data-result-code></b>
                <span class="tp__label">房间链接</span>
                <input class="minput" readonly data-result-link>
                <div class="tp__row">
                    <button class="chipbtn" type="button" data-result-copy><?= lucide('copy', 'chipbtn__ico') ?>复制链接</button>
                    <a class="chipbtn" data-result-enter href="#">进入房间</a>
                </div>
                <p class="tp__hint">链接使用你当前访问的地址；局域网互传请用服务器局域网 IP 打开本站再创建。</p>
            </div>
            <img class="tp__qr" data-result-qr alt="房间二维码">
        </section>
    </main>

    <footer class="tp__foot">
        <span>文件临时保存在本服务器，到期或关闭后删除；管理员理论上可读取内容。</span>
        <span>公网使用请确保站点启用 HTTPS。</span>
    </footer>
</div>

<?php view('partials/modal') ?>
