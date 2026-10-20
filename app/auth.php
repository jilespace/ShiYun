<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

final class Auth
{
    private static ?array $cache = null;

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function user(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        if (self::$cache !== null && (int) self::$cache['id'] === $id) {
            return self::$cache;
        }
        $user = find_user_by_id($id);
        if ($user === null || $user['status'] !== 'active') {
            self::logout();
            return null;
        }
        self::$cache = $user;
        return $user;
    }

    /** 建立登录态：重生成会话 ID，防止会话固定 */
    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['auth_epoch'] = time();
        unset($_SESSION['csrf_token']);
        self::$cache = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$cache = null;
    }

    /** 是否已解锁私密空间 */
    public static function privateUnlocked(): bool
    {
        return !empty($_SESSION['private_ok']);
    }

    public static function unlockPrivate(): void
    {
        $_SESSION['private_ok'] = true;
    }

    public static function lockPrivate(): void
    {
        unset($_SESSION['private_ok']);
    }
}

function require_auth(): void
{
    if (!Auth::check() || Auth::user() === null) {
        // 必须打到 auth.login 这个真实存在的路由；写成 url('login') 会因为没有
        // 点号被 route_parse 判成空路由，最终把人送回官网首页而不是登录页。
        redirect(url('auth.login'));
    }
}

function current_user(): array
{
    $user = Auth::user();
    if ($user === null) {
        redirect(url('auth.login'));
    }
    return $user;
}

/* ------------------------------------------------------------ 登录限流 */

/** 记一条登录日志 */
function log_login(?int $userId, string $email, string $status, ?string $reason = null): void
{
    q_insert('login_logs', [
        'user_id'    => $userId,
        'email'      => mb_substr($email, 0, 190),
        'ip'         => client_ip(),
        'ua'         => user_agent(),
        'device'     => detect_device(user_agent()),
        'status'     => $status,
        'reason'     => $reason !== null ? mb_substr($reason, 0, 120) : null,
        'created_at' => now(),
    ]);
}

/** 是否因连续失败被临时锁定 */
function login_locked(string $email, string $ip): bool
{
    $since = date('Y-m-d H:i:s', time() - (int) config('lockout_seconds'));
    $max = (int) config('max_login_attempts');

    $byEmail = (int) q_val(
        'SELECT COUNT(*) FROM login_logs WHERE status = ? AND created_at > ? AND email = ?',
        ['fail', $since, $email]
    );
    if ($byEmail >= $max) {
        return true;
    }

    $byIp = (int) q_val(
        'SELECT COUNT(*) FROM login_logs WHERE status = ? AND created_at > ? AND ip = ?',
        ['fail', $since, $ip]
    );
    return $byIp >= $max * 4;
}

/* ------------------------------------------------------------ 注册校验 */

/**
 * 校验注册输入，返回 [字段 => 错误信息]。
 */
function validate_registration(array $input): array
{
    $errors = [];

    $email = trim((string) ($input['email'] ?? ''));
    if ($email === '') {
        $errors['email'] = '请填写邮箱。';
    } elseif (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = '邮箱格式不正确。';
    } elseif (find_user_by_email($email) !== null) {
        $errors['email'] = '该邮箱已被注册。';
    }

    $password = (string) ($input['password'] ?? '');
    if ($password === '') {
        $errors['password'] = '请设置密码。';
    } elseif (mb_strlen($password) < 8) {
        $errors['password'] = '密码至少 8 位。';
    } elseif (mb_strlen($password) > 72) {
        $errors['password'] = '密码最多 72 位。';
    }

    $confirm = (string) ($input['password_confirm'] ?? '');
    if ($confirm !== $password) {
        $errors['password_confirm'] = '两次输入的密码不一致。';
    }

    if (empty($input['agree'])) {
        $errors['agree'] = '请先阅读并同意服务条款。';
    }

    return $errors;
}

/** 校验密码强度（用于修改密码） */
function validate_password_change(array $input, int $userId): array
{
    $errors = [];
    $user = find_user_by_id($userId);
    $current = (string) ($input['current_password'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $confirm = (string) ($input['password_confirm'] ?? '');

    if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
        $errors['current_password'] = '当前密码不正确。';
    }
    if (mb_strlen($password) < 8) {
        $errors['password'] = '新密码至少 8 位。';
    } elseif (mb_strlen($password) > 72) {
        $errors['password'] = '新密码最多 72 位。';
    } elseif ($password === $current) {
        $errors['password'] = '新密码不能与当前密码相同。';
    }
    if ($confirm !== $password) {
        $errors['password_confirm'] = '两次输入的密码不一致。';
    }

    return $errors;
}
