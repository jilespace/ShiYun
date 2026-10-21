<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var bool $hasPassword */
/** @var bool $unlocked */
/** @var int $itemCount */
/** @var array<string,string> $errors */
/** @var array{used:int,total:int} $quota */
?>
<div class="app" data-app data-section="settings" data-page="private">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php
            $tab = 'private';
            require BASE_PATH . '/app/views/partials/settings_tabs.php';
            ?>

            <div class="lockscreen">
                <span class="lockscreen__glyph"><?= lucide($hasPassword ? 'lock-keyhole' : 'shield', 'lockscreen__ico') ?></span>

                <?php if (!$hasPassword): ?>
                    <h1 class="lockscreen__title">给私密空间上把锁</h1>
                    <p class="lockscreen__hint">
                        设置一个独立密码后，这个空间的内容就不会出现在普通文件列表、
                        搜索结果和 WebDAV 里，只有输入密码解锁后才看得到。
                    </p>

                    <?php if (isset($errors['form'])): ?>
                        <div class="note" role="alert"><?= lucide('circle-alert', 'note__ico') ?><span><?= e($errors['form']) ?></span></div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('security.private')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set">
                        <span class="fld__box" style="flex:1 1 220px;min-width:0">
                            <span class="fld__ico"><?= lucide('key-round') ?></span>
                            <input class="fld__in" name="password" type="password" required
                                   autocomplete="new-password" placeholder="设置密码（至少 4 位）">
                        </span>
                        <button class="mbtn mbtn--primary" type="submit">启用私密空间</button>
                    </form>
                <?php else: ?>
                    <h1 class="lockscreen__title">私密空间已锁定</h1>
                    <p class="lockscreen__hint">
                        里面目前有 <?= (int) $itemCount ?> 个条目。输入密码解锁后会在文件管理器里打开，
                        和普通目录一样可以上传、整理、分享。
                    </p>

                    <?php if (isset($errors['form'])): ?>
                        <div class="note" role="alert"><?= lucide('circle-alert', 'note__ico') ?><span><?= e($errors['form']) ?></span></div>
                    <?php endif; ?>

                    <form method="post" action="<?= e(url('security.private')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unlock">
                        <span class="fld__box" style="flex:1 1 220px;min-width:0">
                            <span class="fld__ico"><?= lucide('lock') ?></span>
                            <input class="fld__in" name="password" type="password" required
                                   autocomplete="current-password" placeholder="输入密码">
                        </span>
                        <button class="mbtn mbtn--primary" type="submit">解锁</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ($hasPassword): ?>
                <div class="panel" style="margin-top:22px">
                    <div class="panel__head">
                        <h2 class="panel__title">管理密码</h2>
                        <span class="panel__note">需要验证当前密码</span>
                    </div>
                    <div class="panel__body">
                        <div class="grid grid--2">
                            <form method="post" action="<?= e(url('security.private')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set">
                                <label class="mfield">
                                    <span class="mfield__label">当前密码</span>
                                    <input class="minput" name="password" type="password" required autocomplete="current-password">
                                </label>
                                <label class="mfield">
                                    <span class="mfield__label">新密码</span>
                                    <input class="minput" name="new_password" type="password" required autocomplete="new-password">
                                </label>
                                <div class="formactions">
                                    <button class="mbtn" type="submit">更换密码</button>
                                </div>
                            </form>

                            <div>
                                <p class="hint">
                                    移除密码后，私密空间里的内容会重新出现在普通列表与搜索结果中。
                                    文件本身不会被删除或移动。
                                </p>
                                <form method="post" action="<?= e(url('security.private')) ?>" style="margin-top:16px"
                                      data-confirm="移除密码后私密空间将不再受保护，确定继续？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <label class="mfield">
                                        <span class="mfield__label">确认密码</span>
                                        <input class="minput" name="password" type="password" required autocomplete="current-password">
                                    </label>
                                    <div class="formactions">
                                        <button class="mbtn mbtn--danger" type="submit">移除密码并公开</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="hint hint--warn" style="margin-top:20px">
                            <?= lucide('alert-triangle') ?>
                            <span>这个密码与登录密码相互独立。忘记后无法通过邮箱找回——请自行保管好。</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
