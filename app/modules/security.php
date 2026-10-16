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
        if ($new !== '' && mb_strlen($new) < 4) {
            $errors['form'] = '密码至少 4 位。';
        }
        if ($errors === []) {
            q_update('users', [
                'private_hash' => password_hash($new !== '' ? $new : $password, PASSWORD_DEFAULT),
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
