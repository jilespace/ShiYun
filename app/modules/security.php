<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* --------------------------------------------------------------- 私密空间 */

/**
 * 私密空间只负责「上锁 / 解锁 / 设置密码」。
 * 一旦解锁就直接进文件管理器，复用完整的文件操作能力，不再维护第二套列表。
 */
function security_private(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        security_private_action();
        return;
    }

    if (private_space_ready($userId) && Auth::privateUnlocked()) {
        redirect(url('files.list', ['folder' => user_private_id($userId)]));
    }

    security_render_private($user, []);
}

function security_render_private(array $user, array $errors): never
{
    $userId = (int) $user['id'];

    render('security', [
        'title'     => '私密空间 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'private',
        'hasPassword' => private_space_ready($userId),
        'unlocked'  => Auth::privateUnlocked(),
        'itemCount' => (int) q_val(
            'SELECT COUNT(*) FROM nodes WHERE user_id = ? AND is_private = 1 AND deleted_at IS NULL',
            [$userId]
        ),
        'errors'    => $errors,
    ]);
    exit;
}

function security_private_action(): void
{
    require_csrf_post();
    $userId = Auth::id();
    $user = find_user_by_id($userId);
    $action = (string) ($_POST['action'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $errors = [];

    $hasPassword = $user !== null && $user['private_hash'] !== null;

    if ($action === 'set') {
        if ($hasPassword) {
            // 改密码需要先验证旧的
            if ($user === null || !password_verify($password, (string) $user['private_hash'])) {
                $errors['form'] = '当前密码不正确。';
            }
        }
        $new = (string) ($_POST['new_password'] ?? '');
        if ($new === '') {
            // 首次设置必须提供新密码；已设置时留空表示沿用当前密码
            if (!$hasPassword) {
                $errors['form'] = '请设置私密空间密码。';
            }
        } elseif (mb_strlen($new) < 4) {
            $errors['form'] = '密码至少 4 位。';
        } elseif (mb_strlen($new) > 72) {
            $errors['form'] = '密码最多 72 位。';
        }
        if ($errors === []) {
            $plain = $new !== '' ? $new : $password;   // 已设置且留空则沿用当前密码
            q_update('users', [
                'private_hash' => password_hash($plain, PASSWORD_DEFAULT),
            ], 'id = ?', [$userId]);
            Auth::unlockPrivate();
            activity_log($userId, 'private.unlock', null, '设置密码');
            redirect(url('security.private'));
        }
    } elseif ($action === 'unlock') {
        if ($user !== null && $user['private_hash'] !== null && password_verify($password, (string) $user['private_hash'])) {
            Auth::unlockPrivate();
            activity_log($userId, 'private.unlock');
            redirect(url('security.private'));
        }
        $errors['form'] = '密码不正确。';
    } elseif ($action === 'lock') {
        Auth::lockPrivate();
        activity_log($userId, 'private.lock');
        redirect(url('files.list'));
    } elseif ($action === 'remove') {
        if ($user !== null && $user['private_hash'] === null) {
            $errors['form'] = '尚未设置密码。';
        } elseif ($user === null || !password_verify($password, (string) $user['private_hash'])) {
            $errors['form'] = '密码不正确。';
        } else {
            q_update('users', ['private_hash' => null], 'id = ?', [$userId]);
            Auth::unlockPrivate();
            activity_log($userId, 'private.unlock', null, '移除密码');
            redirect(url('security.private'));
        }
    }

    if ($errors !== []) {
        $current = Auth::user();
        if ($current === null) {
            redirect(url('auth.login'));
        }
        security_render_private($current, $errors);
    }

    redirect(url('security.private'));
}

/* --------------------------------------------------------------- 两步验证 */

/**
 * TOTP 管理页。三种状态：
 *   off     —— 未设置，可点「开始设置」生成密钥
 *   pending —— 密钥已生成但未确认：扫码 + 输验证码确认后才生效
 *   on      —— 已启用：可输入验证码 + 登录密码关闭
 */
function security_twofactor(): void
{
    $user = Auth::user();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        security_twofactor_action();
        return;
    }

    security_render_twofactor($user, []);
}

/** 两步验证页渲染（首屏与失败回显共用，避免两处各写一份） */
function security_render_twofactor(array $user, array $errors): never
{
    $enabled = (int) $user['totp_enabled'] === 1;
    $secret = (string) ($user['totp_secret'] ?? '');
    $pending = !$enabled && $secret !== '';

    // 明文恢复码只在生成后展示一次：取出来即从会话里清掉
    $codesToShow = (array) ($_SESSION['totp_recovery_show'] ?? []);
    unset($_SESSION['totp_recovery_show']);

    render('totp', [
        'title'     => '两步验证 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'totp',
        'tab'       => 'totp',
        'state'     => $enabled ? 'on' : ($pending ? 'pending' : 'off'),
        'secret'    => $secret,
        // 长邮箱会让 otpauth URI 超出编码容量，此时返回空串，页面退化为只给手动密钥
        'qrSvg'     => $pending
            ? totp_qr_svg($secret, (string) $user['email'], (string) config('app_name') . ' SHIYUN')
            : '',
        'recovery'  => totp_recovery_stats((int) $user['id']),
        'recoveryCodes' => $codesToShow,
        'errors'    => $errors,
    ]);
    exit;
}

function security_twofactor_action(): void
{
    require_csrf_post();
    $userId = Auth::id();
    $user = find_user_by_id($userId);
    if ($user === null) {
        redirect(url('auth.login'));
    }

    $action = (string) ($_POST['action'] ?? '');
    $code = (string) ($_POST['code'] ?? '');
    $secret = (string) ($user['totp_secret'] ?? '');
    $enabled = (int) $user['totp_enabled'] === 1;

    $fail = static function (string $message) use ($user): never {
        security_render_twofactor($user, ['form' => $message]);
    };

    if ($action === 'begin') {
        if ($enabled) {
            redirect(url('security.twofactor'));
        }
        // 每次开始都换新密钥，旧的一次性作废
        q_update('users', ['totp_secret' => totp_secret_generate()], 'id = ?', [$userId]);
        activity_log($userId, 'totp.begin');
        redirect(url('security.twofactor'));
    }

    if ($action === 'cancel') {
        if (!$enabled) {
            q_update('users', ['totp_secret' => null], 'id = ?', [$userId]);
        }
        redirect(url('security.twofactor'));
    }

    if ($action === 'confirm') {
        if ($enabled || $secret === '') {
            redirect(url('security.twofactor'));
        }
        if (!totp_verify($secret, $code)) {
            $fail('验证码不正确，请确认认证器时间与手机时间一致后重试。');
        }
        q_update('users', ['totp_enabled' => 1], 'id = ?', [$userId]);
        // 第二因子的状态变了，其它设备上的旧会话不能继续免验证使用
        revoke_other_sessions($userId);
        // 开启的同时发一组一次性恢复码，避免认证器丢失后彻底进不来
        $_SESSION['totp_recovery_show'] = totp_recovery_generate($userId);
        activity_log($userId, 'totp.enable');
        redirect(url('security.twofactor'));
    }

    if ($action === 'regen') {
        if (!$enabled) {
            redirect(url('security.twofactor'));
        }
        // 重发等于作废旧的，必须先用动态码证明本人持有认证器
        if (!totp_verify($secret, $code)) {
            $fail('验证码不正确，无法重新生成恢复码。');
        }
        $_SESSION['totp_recovery_show'] = totp_recovery_generate($userId);
        activity_log($userId, 'totp.recovery_regen');
        redirect(url('security.twofactor'));
    }

    if ($action === 'disable') {
        if (!$enabled) {
            redirect(url('security.twofactor'));
        }
        if (!totp_verify($secret, $code)) {
            $fail('验证码不正确。');
        }
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            $fail('登录密码不正确。');
        }
        q_update('users', ['totp_secret' => null, 'totp_enabled' => 0], 'id = ?', [$userId]);
        totp_recovery_clear($userId);
        revoke_other_sessions($userId);
        activity_log($userId, 'totp.disable');
        redirect(url('security.twofactor'));
    }

    redirect(url('security.twofactor'));
}

/* --------------------------------------------------------------- 登录日志 */

function security_logs(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $page = max(1, input_int('page', 1));
    $per = 40;
    $offset = ($page - 1) * $per;

    $rows = q_all(
        'SELECT * FROM login_logs WHERE user_id = ? OR email = ?
         ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . $offset,
        [$userId, (string) $user['email']]
    );
    $total = (int) q_val(
        'SELECT COUNT(*) FROM login_logs WHERE user_id = ? OR email = ?',
        [$userId, (string) $user['email']]
    );

    // 异常登录提醒：最近 30 天内出现过的、不同于常用设备的成功登录
    $known = q_all(
        'SELECT device, MAX(created_at) AS last_at, COUNT(*) AS times
         FROM login_logs WHERE user_id = ? AND status = ?
         GROUP BY device ORDER BY times DESC',
        [$userId, 'success']
    );

    render('loginlog', [
        'title'     => '登录日志 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'loginlog',
        'rows'      => $rows,
        'devices'   => $known,
        'total'     => $total,
        'pageNo'    => $page,
        'per'       => $per,
    ]);
}

/* --------------------------------------------------------------- 操作日志 */

function security_activity(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $page = max(1, input_int('page', 1));
    $per = 50;
    $action = (string) ($_GET['action'] ?? '');

    $rows = activity_list($userId, $per, ($page - 1) * $per, $action !== '' ? $action : null);
    $total = (int) q_val(
        'SELECT COUNT(*) FROM activities WHERE user_id = ?' . ($action !== '' ? ' AND action = ?' : ''),
        $action !== '' ? [$userId, $action] : [$userId]
    );

    $actions = q_all(
        'SELECT action, COUNT(*) AS times FROM activities WHERE user_id = ?
         GROUP BY action ORDER BY times DESC LIMIT 40',
        [$userId]
    );

    render('activity', [
        'title'     => '操作日志 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'activity',
        'rows'      => $rows,
        'actions'   => $actions,
        'total'     => $total,
        'pageNo'    => $page,
        'per'       => $per,
        'filter'    => $action,
    ]);
}
