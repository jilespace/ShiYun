<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* --------------------------------------------------------------- 页面 */

function auth_login(): void
{
    if (Auth::check()) {
        redirect(url('files.list'));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        auth_do_login();
        return;
    }

    auth_render('login');
}

/**
 * 注册开放状态：
 *   open      —— config.allow_registration 显式开启
 *   bootstrap —— 全新部署且一个账号都没有。此时放行，否则关掉注册后就再也
 *                建不出第一个账号；建出第一个账号后自动回到 closed。
 *   closed    —— 默认，注册接口与页面入口一并关闭
 */
function registration_state(): string
{
    if ((bool) config('allow_registration', false)) {
        return 'open';
    }
    return (int) q_val('SELECT COUNT(*) FROM users') === 0 ? 'bootstrap' : 'closed';
}

function registration_open(): bool
{
    return registration_state() !== 'closed';
}

function auth_register(): void
{
    if (Auth::check()) {
        redirect(url('files.list'));
    }

    // 关闭时不只是藏起入口：直接提交到这个地址同样会被挡下。
    if (!registration_open()) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            http_response_code(403);
        }
        auth_render('register');
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        auth_do_register();
        return;
    }

    auth_render('register');
}

function auth_logout(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_csrf_post();
    }
    $userId = Auth::id();
    if ($userId !== null) {
        activity_log($userId, 'logout');
    }
    Auth::logout();
    redirect(url('home.index'));
}

/* --------------------------------------------------------------- 两步验证 */

/** 该用户是否启用了 TOTP（启用标记 + 密钥都在才算） */
function auth_totp_required(int $userId): bool
{
    $row = q_one('SELECT totp_secret, totp_enabled FROM users WHERE id = ?', [$userId]);
    return $row !== null
        && (int) $row['totp_enabled'] === 1
        && (string) ($row['totp_secret'] ?? '') !== '';
}

/** 验证码通过后的公共收尾：会话、日志、统计 */
function auth_finish_login(int $userId, string $login, string $via = ''): void
{
    log_login($userId, $login, 'success', $via !== '' ? $via : null);
    q_update('users', ['last_login_at' => now()], 'id = ?', [$userId]);
    Auth::login($userId);
    activity_log($userId, 'login', null, detect_device(user_agent()));
    snapshot_today($userId);
}

/** 登录二步：输入认证器验证码 */
function auth_totp(): void
{
    $pending = (int) ($_SESSION['pending_2fa'] ?? 0);
    $pendingAt = (int) ($_SESSION['pending_2fa_at'] ?? 0);

    // 没有挂起态或挂起超时（5 分钟），一律回登录页重新来
    if ($pending <= 0 || $pendingAt <= 0 || time() - $pendingAt > 300 || find_user_by_id($pending) === null) {
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at']);
        redirect(url('auth.login'));
    }

    $user = find_user_by_id($pending);
    $login = (string) $user['email'];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        auth_do_totp($user, $login);
        return;
    }

    auth_render('totp', ['values' => ['account' => $login]]);
}

function auth_do_totp(array $user, string $login): void
{
    $errors = [];
    $viaRecovery = false;

    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = '会话已过期，请重新提交。';
    } elseif ((string) ($_POST['cancel'] ?? '') === '1') {
        // 放弃本次登录
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at']);
        redirect(url('auth.login'));
    } elseif (login_locked($login, client_ip())) {
        // 密码已通过、但验证码连续错误：同样计入限流。
        // 少了这一步，拿到密码的人就能在 5 分钟挂起窗口里反复穷举 6 位码。
        log_login((int) $user['id'], $login, 'locked', '两步验证触发限流');
        unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at']);
        $minutes = (int) round(((int) config('lockout_seconds')) / 60);
        auth_render('login', [
            'errors' => ['form' => '验证码尝试次数过多，请 ' . $minutes . ' 分钟后重新登录。'],
            'values' => ['email' => $login],
        ]);
        return;
    } else {
        $code = (string) ($_POST['code'] ?? '');
        $ok = totp_verify((string) $user['totp_secret'], $code);

        // 12 位的输入按一次性恢复码处理：认证器丢了还能进来
        if (!$ok && totp_recovery_looks_like($code)) {
            $viaRecovery = totp_recovery_consume((int) $user['id'], $code);
            $ok = $viaRecovery;
        }

        if (!$ok) {
            log_login((int) $user['id'], $login, 'fail', '两步验证码错误');
            $errors['form'] = '验证码不正确或已过期，请重试。';
        }
    }

    if ($errors !== []) {
        auth_render('totp', ['errors' => $errors, 'values' => ['account' => $login]]);
        return;
    }

    unset($_SESSION['pending_2fa'], $_SESSION['pending_2fa_at']);

    if ($viaRecovery) {
        activity_log((int) $user['id'], 'totp.recovery_used');
        auth_finish_login((int) $user['id'], $login, '使用一次性恢复码登录');
    } else {
        auth_finish_login((int) $user['id'], $login, '两步验证通过');
    }

    redirect(url('files.list'));
}

function auth_render(string $tab, array $state = []): void
{
    render('auth', [
        'title'     => match ($tab) {
            'register' => registration_open() ? '创建账号 · 拾云' : '注册已关闭 · 拾云',
            'forgot'   => '找回密码 · 拾云',
            'reset'    => '重设密码 · 拾云',
            'totp'     => '两步验证 · 拾云',
            default    => '登录 · 拾云',
        },
        'tab'       => $tab,
        'errors'    => $state['errors'] ?? [],
        'values'    => $state['values'] ?? [],
        'notice'    => $state['notice'] ?? null,
        'resetLink' => $state['resetLink'] ?? null,
        'resetToken' => $state['resetToken'] ?? null,
        'allowRegistration' => registration_open(),
        'firstRun'          => registration_state() === 'bootstrap',
        'bodyClass' => 'is-auth',
    ]);
}

/* --------------------------------------------------------------- 登录 */

function auth_do_login(): void
{
    $login    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $errors   = [];

    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = '会话已过期，请重新提交。';
    } elseif ($login === '' || $password === '') {
        $errors['form'] = '请填写账号和密码。';
    } elseif (login_locked($login, client_ip())) {
        $minutes = (int) round(((int) config('lockout_seconds')) / 60);
        log_login(null, $login, 'locked', '触发限流');
        $errors['form'] = "尝试次数过多，请 {$minutes} 分钟后再试。";
    } else {
        $user = find_user_by_login($login);

        if ($user !== null && password_verify($password, (string) $user['password_hash'])) {
            if ($user['status'] !== 'active') {
                log_login((int) $user['id'], $login, 'fail', '账号已停用');
                $errors['form'] = '该账号已被停用。';
            } else {
                if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                    q_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [(int) $user['id']]);
                }

                if (auth_totp_required((int) $user['id'])) {
                    // 密码通过但开了两步验证：先挂起，验证码通过后才算登录
                    $_SESSION['pending_2fa'] = (int) $user['id'];
                    $_SESSION['pending_2fa_at'] = time();
                    redirect(url('auth.totp'));
                }

                log_login((int) $user['id'], $login, 'success');
                auth_finish_login((int) $user['id'], $login);
                redirect(url('files.list'));
            }
        } else {
            log_login($user !== null ? (int) $user['id'] : null, $login, 'fail', '密码错误或账号不存在');
            $errors['form'] = '账号或密码不正确。';
        }
    }

    auth_render('login', ['errors' => $errors, 'values' => ['email' => $login]]);
}

/* --------------------------------------------------------------- 注册 */

function auth_do_register(): void
{
    $errors = [];

    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = '会话已过期，请重新提交。';
    } else {
        $errors = validate_registration($_POST);
    }

    if ($errors === []) {
        $email = trim((string) $_POST['email']);
        $userId = create_user($email, (string) $_POST['password'], display_name_from_email($email));

        log_login($userId, $email, 'success', '注册');
        Auth::login($userId);
        activity_log($userId, 'register');
        snapshot_today($userId);

        redirect(url('files.list'));
    }

    auth_render('register', [
        'errors' => $errors,
        'values' => ['email' => trim((string) ($_POST['email'] ?? ''))],
    ]);
}

/* ------------------------------------------------------------ 找回密码 */

function auth_forgot(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        auth_do_forgot();
        return;
    }
    auth_render('forgot');
}

function auth_do_forgot(): void
{
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        auth_render('forgot', ['errors' => ['form' => '会话已过期，请重新提交。']]);
        return;
    }

    // 无论账号是否存在都给出相同回应，避免枚举用户
    $notice = '如果该邮箱已注册，我们已生成重置链接。';
    $user = find_user_by_email($email);
    $resetLink = null;

    $mailConfigured = function_exists('mail') && (bool) config('mail_enabled', false);
    // 仅当显式开启（本机/单人自部署）才允许把重置链接回显在页面；
    // 多用户环境下默认关闭，否则任何人凭邮箱即可接管他人账号。
    $linkAllowed = (bool) config('reset_link_in_response', false);

    if ($user !== null) {
        $token = random_token(24);
        q_insert('password_resets', [
            'user_id'    => (int) $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + (int) config('reset_ttl')),
            'created_at' => now(),
        ]);

        $resetLink = url('auth.reset', ['token' => $token]);

        if ($mailConfigured) {
            // 配置了邮件服务：发信，不在响应里回显链接
            @mail($email, '拾云 · 重设密码', "重设链接：{$resetLink}");
            $resetLink = null;
        } elseif (!$linkAllowed) {
            // 默认：不回显链接，仅写日志供管理员处置
            error_log('[auth.forgot] reset link for ' . $email . ': ' . $resetLink);
            $resetLink = null;
        }
        // $mailConfigured 为假且 $linkAllowed 为真时，$resetLink 保留回显
    }

    // 统一补充措辞：对"存在/不存在"的邮箱一视同仁，避免账号枚举
    if (!$mailConfigured && !$linkAllowed) {
        $notice .= '（当前未配置邮件服务，请联系管理员获取重置链接。）';
    }

    auth_render('forgot', [
        'notice'    => $notice,
        'resetLink' => $resetLink,
        'values'    => ['email' => $email],
    ]);
}

function auth_reset(): void
{
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        auth_do_reset($token);
        return;
    }

    $row = auth_reset_lookup($token);
    if ($row === null) {
        auth_render('reset', ['errors' => ['form' => '链接无效或已过期，请重新申请。']]);
        return;
    }

    auth_render('reset', ['resetToken' => $token]);
}

function auth_do_reset(string $token): void
{
    $row = auth_reset_lookup($token);
    if ($row === null) {
        auth_render('reset', ['errors' => ['form' => '链接无效或已过期，请重新申请。']]);
        return;
    }

    $errors = [];
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!csrf_verify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $errors['form'] = '会话已过期，请重新提交。';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = '密码至少 8 位。';
    } elseif (mb_strlen($password) > 72) {
        $errors['password'] = '密码最多 72 位。';
    }
    if ($confirm !== $password) {
        $errors['password_confirm'] = '两次输入的密码不一致。';
    }

    if ($errors !== []) {
        auth_render('reset', ['errors' => $errors, 'resetToken' => $token]);
        return;
    }

    $userId = (int) $row['user_id'];
    change_password($userId, $password);
    q_update('password_resets', ['used_at' => now()], 'id = ?', [(int) $row['id']]);
    revoke_other_sessions($userId);
    activity_log($userId, 'password.reset');

    // 重置密码不等于通过第二因子。开了两步验证的账号必须再走一次验证码，
    // 否则任何一个拿到重置链接的人（邮箱被攻破、或本机开了 reset_link_in_response）
    // 都能直接接管账号，TOTP 形同虚设。
    if (auth_totp_required($userId)) {
        $_SESSION['pending_2fa'] = $userId;
        $_SESSION['pending_2fa_at'] = time();
        redirect(url('auth.totp'));
    }

    log_login($userId, (string) q_val('SELECT email FROM users WHERE id = ?', [$userId]), 'success', '重置密码后登录');

    Auth::login($userId);
    redirect(url('files.list'));
}

function auth_reset_lookup(string $token): ?array
{
    if ($token === '' || strlen($token) < 16) {
        return null;
    }
    return q_one(
        'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1',
        [hash('sha256', $token), now()]
    );
}
