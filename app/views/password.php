<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array{used:int,total:int} $quota */
?>
<div class="app" data-app data-section="settings" data-page="password">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php
            $tab = 'password';
            require BASE_PATH . '/app/views/partials/settings_tabs.php';
            ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">修改密码</h1>
                    <p class="pagehead__sub">
                        改完之后当前会话会重新建立，其他设备上仍保持登录的会话需要重新输入新密码才能继续操作。
                    </p>
                </div>
            </div>

            <div class="grid grid--2">
                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">设置新密码</h2></div>
                    <div class="panel__body">
                        <form data-api="settings.change_password" data-ok="密码已更新">
                            <label class="mfield">
                                <span class="mfield__label">当前密码</span>
                                <span class="fld__box">
                                    <span class="fld__ico"><?= lucide('lock') ?></span>
                                    <input class="fld__in" name="current_password" type="password"
                                           autocomplete="current-password" required>
                                    <button class="fld__reveal" type="button" data-reveal
                                            aria-label="显示密码" aria-pressed="false">
                                        <span class="i-eye"><?= lucide('eye') ?></span>
                                        <span class="i-eye-off"><?= lucide('eye-off') ?></span>
                                    </button>
                                </span>
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">新密码</span>
                                <span class="fld__box">
                                    <span class="fld__ico"><?= lucide('key-round') ?></span>
                                    <input class="fld__in" name="password" type="password"
                                           autocomplete="new-password" required placeholder="至少 8 位"
                                           data-strength-input aria-describedby="pw-strength">
                                    <button class="fld__reveal" type="button" data-reveal
                                            aria-label="显示密码" aria-pressed="false">
                                        <span class="i-eye"><?= lucide('eye') ?></span>
                                        <span class="i-eye-off"><?= lucide('eye-off') ?></span>
                                    </button>
                                </span>
                                <span class="strength" id="pw-strength" data-strength data-level="0" aria-live="polite">
                                    <span class="strength__label" data-strength-text>密码强度</span>
                                    <span class="strength__bar"></span>
                                    <span class="strength__bar"></span>
                                    <span class="strength__bar"></span>
                                    <span class="strength__bar"></span>
                                </span>
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">确认新密码</span>
                                <span class="fld__box">
                                    <span class="fld__ico"><?= lucide('key-round') ?></span>
                                    <input class="fld__in" name="password_confirm" type="password"
                                           autocomplete="new-password" required placeholder="再输入一次">
                                    <button class="fld__reveal" type="button" data-reveal
                                            aria-label="显示密码" aria-pressed="false">
                                        <span class="i-eye"><?= lucide('eye') ?></span>
                                        <span class="i-eye-off"><?= lucide('eye-off') ?></span>
                                    </button>
                                </span>
                            </label>

                            <div class="formactions">
                                <button class="mbtn mbtn--primary" type="submit">更新密码</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">忘了密码怎么办</h2></div>
                    <div class="panel__body">
                        <p class="hint">
                            在登录页点「忘记密码」，输入注册邮箱即可生成一条 30 分钟内有效的重设链接。
                            本机没有配置邮件服务时，链接会直接显示在页面上。
                        </p>

                        <div class="hint hint--warn" style="margin-top:18px">
                            <?= lucide('shield') ?>
                            <span>密码用 bcrypt 加盐存储，服务端无法还原出明文。若怀疑泄露，改密后建议一并吊销 API 令牌。</span>
                        </div>

                        <div class="formactions">
                            <a class="mbtn" href="<?= e(url('api.tokens')) ?>">管理 API 令牌</a>
                            <a class="mbtn" href="<?= e(url('security.logs')) ?>">查看登录日志</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
