<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string $tab */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */
/** @var string|null $notice */
/** @var string|null $resetLink */
/** @var string|null $resetToken */

$appName = (string) config('app_name');
$appNameEn = (string) config('app_name_en');

$headline = ['把散落的文件，', '收进同一片云。'];
$charIndex = 0;

/** 逐字包裹，用于遮罩升起 */
$chars = static function (string $text) use (&$charIndex): string {
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $out .= '<span class="ch" style="--i:' . $charIndex . '"><i>' . e($ch) . '</i></span>';
        $charIndex++;
    }
    return $out;
};

$alert = static function (?string $message): string {
    if ($message === null || $message === '') {
        return '';
    }
    return '<div class="note" role="alert">' . lucide('circle-alert', 'note__ico')
        . '<span>' . e($message) . '</span></div>';
};

$info = static function (?string $message): string {
    if ($message === null || $message === '') {
        return '';
    }
    return '<div class="note note--info" role="status">' . lucide('info', 'note__ico')
        . '<span>' . e($message) . '</span></div>';
};

$reveal = static function (string $id, string $name, string $autocomplete, string $placeholder): string {
    return '<span class="fld__box">'
        . '<span class="fld__ico">' . lucide('lock') . '</span>'
        . '<input class="fld__in" id="' . e($id) . '" name="' . e($name) . '" type="password"'
        . ' autocomplete="' . e($autocomplete) . '" required placeholder="' . e($placeholder) . '">'
        . '<button class="fld__reveal" type="button" data-reveal aria-label="显示密码" aria-pressed="false">'
        . '<span class="i-eye">' . lucide('eye') . '</span>'
        . '<span class="i-eye-off">' . lucide('eye-off') . '</span>'
        . '</button></span>';
};

$isRecovery = in_array($tab, ['forgot', 'reset'], true);
?>
<div class="rail" aria-hidden="true"><span>云端归档 · ARCHIVE <?= date('Y') ?></span></div>

<main class="shell">
    <header class="topbar">
        <a class="brand" href="<?= e(url('')) ?>">
            <?= lucide('archive', 'brand__mark') ?>
            <span class="brand__name"><?= e($appName) ?></span>
            <span class="brand__en"><?= e($appNameEn) ?></span>
        </a>

        <div class="status" aria-hidden="true">
            <span class="status__dot"></span>
            <span class="status__text">在线</span>
            <span class="status__rule"></span>
            <span class="status__clock" data-clock>--:--:--</span>
        </div>
    </header>

    <section class="hero">
        <div class="hero__type">
            <h1 class="display" aria-label="<?= e($headline[0] . $headline[1]) ?>">
                <span class="ln ln--1" aria-hidden="true"><?= $chars($headline[0]) ?></span>
                <span class="ln ln--2" aria-hidden="true"><?= $chars($headline[1]) ?></span>
            </h1>

            <p class="lede">一块属于你的云端空间。上传、整理，随处取用。</p>

            <div class="meter">
                <div class="meter__row">
                    <span class="meter__label">起步容量</span>
                    <span class="meter__val"><b>1</b> TB</span>
                </div>
                <span class="meter__track"><span class="meter__fill"></span></span>
            </div>
        </div>

        <aside class="dock" data-dock>
            <?php if ($isRecovery): ?>
                <div class="tabs tabs--plain">
                    <a class="tab" href="<?= e(url('auth.login')) ?>"><?= lucide('arrow-left', 'tab__ico') ?>返回登录</a>
                </div>
            <?php else: ?>
                <div class="tabs" role="tablist" data-tabs data-active="<?= e($tab) ?>">
                    <a class="tab" role="tab" href="<?= e(url('auth.login')) ?>" data-goto="login"
                       aria-selected="<?= $tab === 'login' ? 'true' : 'false' ?>">登录</a>
                    <a class="tab" role="tab" href="<?= e(url('auth.register')) ?>" data-goto="register"
                       aria-selected="<?= $tab === 'register' ? 'true' : 'false' ?>">注册</a>
                    <span class="tabs__ink" aria-hidden="true"></span>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'login'): ?>
            <div class="pane" data-pane="login">
                <h2 class="pane__title">欢迎回来</h2>
                <p class="pane__sub">登录以继续访问你的空间。</p>

                <?= $alert($errors['form'] ?? null) ?>

                <form class="form" method="post" action="<?= e(url('auth.login')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <label class="fld<?= isset($errors['email']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">邮箱或用户名</span>
                        <span class="fld__box">
                            <span class="fld__ico"><?= lucide('mail') ?></span>
                            <input class="fld__in" id="login-email" name="email" type="text"
                                   autocomplete="username" required
                                   value="<?= e($values['email'] ?? '') ?>" placeholder="you@example.com">
                        </span>
                        <?php if (isset($errors['email'])): ?>
                            <span class="fld__msg"><?= e($errors['email']) ?></span>
                        <?php endif; ?>
                    </label>

                    <label class="fld<?= isset($errors['password']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">密码</span>
                        <?= $reveal('login-password', 'password', 'current-password', '请输入密码') ?>
                        <?php if (isset($errors['password'])): ?>
                            <span class="fld__msg"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </label>

                    <div class="form__aux">
                        <a class="link-quiet" href="<?= e(url('auth.forgot')) ?>">忘记密码？</a>
                    </div>

                    <button class="cta" data-magnetic type="submit">
                        <span class="cta__label">登录</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </button>
                </form>

                <p class="pane__foot">还没有账号？<a href="<?= e(url('auth.register')) ?>" data-goto="register">去注册</a></p>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'register'): ?>
            <div class="pane" data-pane="register">
                <h2 class="pane__title">创建账号</h2>
                <p class="pane__sub">几秒钟，开启你的云端空间。</p>

                <?= $alert($errors['form'] ?? null) ?>

                <form class="form" method="post" action="<?= e(url('auth.register')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <label class="fld<?= isset($errors['email']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">邮箱</span>
                        <span class="fld__box">
                            <span class="fld__ico"><?= lucide('mail') ?></span>
                            <input class="fld__in" id="reg-email" name="email" type="email"
                                   inputmode="email" autocomplete="username" required
                                   value="<?= e($values['email'] ?? '') ?>" placeholder="you@example.com">
                        </span>
                        <?php if (isset($errors['email'])): ?>
                            <span class="fld__msg"><?= e($errors['email']) ?></span>
                        <?php endif; ?>
                    </label>

                    <label class="fld<?= isset($errors['password']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">密码</span>
                        <span class="fld__box">
                            <span class="fld__ico"><?= lucide('lock') ?></span>
                            <input class="fld__in" id="reg-password" name="password" type="password"
                                   autocomplete="new-password" required placeholder="至少 8 位"
                                   data-strength-input aria-describedby="reg-strength">
                            <button class="fld__reveal" type="button" data-reveal
                                    aria-label="显示密码" aria-pressed="false">
                                <span class="i-eye"><?= lucide('eye') ?></span>
                                <span class="i-eye-off"><?= lucide('eye-off') ?></span>
                            </button>
                        </span>
                        <span class="strength" id="reg-strength" data-strength data-level="0" aria-live="polite">
                            <span class="strength__label" data-strength-text>密码强度</span>
                            <span class="strength__bar"></span>
                            <span class="strength__bar"></span>
                            <span class="strength__bar"></span>
                            <span class="strength__bar"></span>
                        </span>
                        <?php if (isset($errors['password'])): ?>
                            <span class="fld__msg"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </label>

                    <label class="fld<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">确认密码</span>
                        <?= $reveal('reg-password-confirm', 'password_confirm', 'new-password', '再输入一次') ?>
                        <?php if (isset($errors['password_confirm'])): ?>
                            <span class="fld__msg"><?= e($errors['password_confirm']) ?></span>
                        <?php endif; ?>
                    </label>

                    <label class="agree<?= isset($errors['agree']) ? ' is-invalid' : '' ?>">
                        <input type="checkbox" name="agree" value="1" required>
                        <span class="agree__box"><?= lucide('check', 'agree__tick', 2.5) ?></span>
                        <span class="agree__text">我已阅读并同意<a href="#">服务条款</a>与<a href="#">隐私政策</a></span>
                    </label>
                    <?php if (isset($errors['agree'])): ?>
                        <span class="fld__msg fld__msg--loose"><?= e($errors['agree']) ?></span>
                    <?php endif; ?>

                    <button class="cta" data-magnetic type="submit">
                        <span class="cta__label">创建账号</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </button>
                </form>

                <p class="pane__foot">已有账号？<a href="<?= e(url('auth.login')) ?>" data-goto="login">去登录</a></p>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'forgot'): ?>
            <div class="pane" data-pane="forgot">
                <h2 class="pane__title">找回密码</h2>
                <p class="pane__sub">输入注册邮箱，我们会生成一条重设链接。</p>

                <?= $alert($errors['form'] ?? null) ?>
                <?= $info($notice) ?>

                <?php if ($resetLink !== null): ?>
                    <div class="linkbox">
                        <span class="linkbox__label"><?= lucide('key-round', 'linkbox__ico') ?>重设链接（30 分钟内有效）</span>
                        <a class="linkbox__url" href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= e(url('auth.forgot')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <label class="fld">
                        <span class="fld__label">邮箱</span>
                        <span class="fld__box">
                            <span class="fld__ico"><?= lucide('mail') ?></span>
                            <input class="fld__in" name="email" type="email" inputmode="email"
                                   autocomplete="username" required
                                   value="<?= e($values['email'] ?? '') ?>" placeholder="you@example.com">
                        </span>
                    </label>

                    <button class="cta" data-magnetic type="submit">
                        <span class="cta__label">生成重设链接</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </button>
                </form>

                <p class="pane__foot">想起来了？<a href="<?= e(url('auth.login')) ?>">返回登录</a></p>
            </div>
            <?php endif; ?>

            <?php if ($tab === 'reset'): ?>
            <div class="pane" data-pane="reset">
                <h2 class="pane__title">重设密码</h2>
                <p class="pane__sub">设置一个新密码，旧密码将立即失效。</p>

                <?= $alert($errors['form'] ?? null) ?>

                <?php if ($resetToken !== null): ?>
                <form class="form" method="post" action="<?= e(url('auth.reset')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($resetToken) ?>">

                    <label class="fld<?= isset($errors['password']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">新密码</span>
                        <?= $reveal('reset-password', 'password', 'new-password', '至少 8 位') ?>
                        <?php if (isset($errors['password'])): ?>
                            <span class="fld__msg"><?= e($errors['password']) ?></span>
                        <?php endif; ?>
                    </label>

                    <label class="fld<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>">
                        <span class="fld__label">确认新密码</span>
                        <?= $reveal('reset-confirm', 'password_confirm', 'new-password', '再输入一次') ?>
                        <?php if (isset($errors['password_confirm'])): ?>
                            <span class="fld__msg"><?= e($errors['password_confirm']) ?></span>
                        <?php endif; ?>
                    </label>

                    <button class="cta" data-magnetic type="submit">
                        <span class="cta__label">保存并登录</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </button>
                </form>
                <?php else: ?>
                    <p class="pane__foot"><a href="<?= e(url('auth.forgot')) ?>">重新申请一条重设链接</a></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </aside>
    </section>

    <footer class="foot">
        <span class="foot__c">© <?= date('Y') ?> <?= e($appName) ?></span>
        <nav class="foot__nav">
            <a href="#">隐私政策</a>
            <a href="#">服务条款</a>
        </nav>
    </footer>
</main>
