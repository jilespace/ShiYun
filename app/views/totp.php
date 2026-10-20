<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var string $state on | pending | off */
/** @var string $secret */
/** @var string $qrSvg */
/** @var array<string,string> $errors */
/** @var array{used:int,total:int} $quota */

$groups = $secret !== '' ? str_split($secret, 4) : [];
?>
<div class="app" data-app data-section="settings" data-page="totp">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php require BASE_PATH . '/app/views/partials/settings_tabs.php'; ?>

            <?php if (isset($errors['form'])): ?>
                <div class="note" role="alert" style="max-width:560px">
                    <?= lucide('circle-alert', 'note__ico') ?><span><?= e($errors['form']) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($state === 'off'): ?>
                <div class="lockscreen">
                    <span class="lockscreen__glyph"><?= lucide('shield', 'lockscreen__ico') ?></span>
                    <h1 class="lockscreen__title">给账号加上第二把锁</h1>
                    <p class="lockscreen__hint">
                        开启后，登录除了密码还需要一个动态验证码。
                        用手机上的认证器应用（Google Authenticator、Microsoft Authenticator、1Password 等）
                        扫一次码即可，之后每 30 秒生成一个 6 位数字。
                    </p>
                    <form method="post" action="<?= e(url('security.twofactor')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="begin">
                        <button class="mbtn mbtn--primary" type="submit">
                            <?= lucide('shield-check') ?> 开始设置
                        </button>
                    </form>
                </div>

            <?php elseif ($state === 'pending'): ?>
                <div class="panel" style="max-width:720px">
                    <div class="panel__head">
                        <h2 class="panel__title"><?= $qrSvg !== '' ? '扫码，然后输入验证码' : '输入密钥，然后验证' ?></h2>
                        <span class="panel__note">第 1 / 2 步 · 确认后才会生效</span>
                    </div>
                    <div class="panel__body">
                        <div class="totp-setup">
                            <?php if ($qrSvg !== ''): ?>
                                <div class="totp-qr"><?= $qrSvg ?></div>
                            <?php endif; ?>

                            <div class="totp-setup__side">
                                <p class="hint">
                                    <?php if ($qrSvg !== ''): ?>
                                        打开认证器应用，扫描左侧二维码；扫不了码就手动输入下面的密钥。
                                    <?php else: ?>
                                        在认证器应用里选择「手动输入密钥」，填入下面这串字符。
                                    <?php endif; ?>
                                </p>

                                <button class="totp-secret" type="button" data-copy-secret
                                        title="点击复制密钥"><?= e(implode(' ', $groups)) ?></button>
                                <p class="totp-secret__tip" data-copy-tip>点击即可复制</p>

                                <form method="post" action="<?= e(url('security.twofactor')) ?>" class="totp-confirm">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <label class="mfield">
                                        <span class="mfield__label">输入认证器里的 6 位验证码</span>
                                        <input class="minput totp-confirm__code" name="code" type="text"
                                               inputmode="numeric" pattern="[0-9]*" maxlength="6"
                                               autocomplete="one-time-code" required placeholder="000000">
                                    </label>
                                    <div class="formactions">
                                        <button class="mbtn" type="submit" name="action" value="cancel">取消设置</button>
                                        <button class="mbtn mbtn--primary" type="submit">确认开启</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="hint hint--warn" style="margin-top:18px">
                            <?= lucide('alert-triangle') ?>
                            <span>确认开启后会同时生成一组一次性恢复码，请离线保存。
                            认证器一旦丢失，那组码就是唯一的自助入口。</span>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="lockscreen">
                    <span class="lockscreen__glyph"><?= lucide('shield-check', 'lockscreen__ico') ?></span>
                    <h1 class="lockscreen__title">两步验证已开启</h1>
                    <p class="lockscreen__hint">
                        登录时除了密码，还需要认证器里的 6 位验证码。
                    </p>
                </div>

                <?php if ($recoveryCodes !== []): ?>
                    <div class="panel" style="margin-top:22px;max-width:720px">
                        <div class="panel__head">
                            <h2 class="panel__title">一次性恢复码</h2>
                            <span class="panel__note">只显示这一次 · 请立刻保存</span>
                        </div>
                        <div class="panel__body">
                            <div class="mwarn">
                                <?= lucide('alert-triangle') ?>
                                <span>这 <?= count($recoveryCodes) ?> 个码每个只能用一次，且离开本页后不再显示明文。
                                认证器丢失时，在登录页的验证码框里填其中一个就能进来。</span>
                            </div>
                            <ul class="reclist">
                                <?php foreach ($recoveryCodes as $index => $recoveryCode): ?>
                                    <li><span class="reclist__no"><?= $index + 1 ?></span><?= e($recoveryCode) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="panel" style="margin-top:22px;max-width:640px">
                    <div class="panel__head">
                        <h2 class="panel__title">恢复码</h2>
                        <span class="panel__note">剩余 <?= (int) $recovery['unused'] ?> / <?= (int) $recovery['total'] ?> 个可用</span>
                    </div>
                    <div class="panel__body">
                        <?php if ((int) $recovery['unused'] === 0): ?>
                            <div class="mwarn">
                                <?= lucide('alert-triangle') ?>
                                <span>恢复码已经用尽。建议重新生成一组——不然认证器一丢就没有自助入口了。</span>
                            </div>
                        <?php else: ?>
                            <p class="hint">重新生成会让旧的恢复码全部作废，所以需要先用认证器验证一次。</p>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('security.twofactor')) ?>" style="margin-top:14px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="regen">
                            <label class="mfield">
                                <span class="mfield__label">认证器验证码</span>
                                <input class="minput" name="code" type="text" inputmode="numeric"
                                       pattern="[0-9]*" maxlength="6" required
                                       autocomplete="one-time-code" placeholder="000000">
                            </label>
                            <div class="formactions">
                                <button class="mbtn" type="submit">重新生成恢复码</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel" style="margin-top:22px;max-width:560px">
                    <div class="panel__head">
                        <h2 class="panel__title">关闭两步验证</h2>
                        <span class="panel__note">需要验证码与登录密码</span>
                    </div>
                    <div class="panel__body">
                        <form method="post" action="<?= e(url('security.twofactor')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="disable">
                            <label class="mfield">
                                <span class="mfield__label">认证器验证码</span>
                                <input class="minput" name="code" type="text" inputmode="numeric"
                                       pattern="[0-9]*" maxlength="6" required
                                       autocomplete="one-time-code" placeholder="000000">
                            </label>
                            <label class="mfield">
                                <span class="mfield__label">登录密码</span>
                                <input class="minput" name="password" type="password" required
                                       autocomplete="current-password">
                            </label>
                            <div class="formactions">
                                <button class="mbtn mbtn--danger" type="submit">关闭两步验证</button>
                            </div>
                        </form>

                        <div class="hint hint--warn" style="margin-top:18px">
                            <?= lucide('alert-triangle') ?>
                            <span>关闭后登录只验证密码。公网部署不建议关闭。</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
