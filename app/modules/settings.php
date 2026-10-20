<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function settings_show(): void
{
    $userId = Auth::id();
    $user = Auth::user();
    $settings = user_settings($userId);

    // 存储源与令牌的概要，便于在设置页一眼看到接入情况
    $sourceCount = (int) q_val('SELECT COUNT(*) FROM storage_sources WHERE user_id = ?', [$userId]);
    $tokenCount = (int) q_val('SELECT COUNT(*) FROM api_tokens WHERE user_id = ?', [$userId]);
    $shareCount = (int) q_val("SELECT COUNT(*) FROM shares WHERE user_id = ? AND status = 'active'", [$userId]);

    render('settings', [
        'title'     => '设置 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'settings'  => $settings,
        'page'      => 'settings',
        'counters'  => ['sources' => $sourceCount, 'tokens' => $tokenCount, 'shares' => $shareCount],
        'tab'       => (string) ($_GET['tab'] ?? 'profile'),
    ]);
}

function settings_password(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    render('password', [
        'title'     => '修改密码 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'password',
    ]);
}

/** 保存个人资料 */
function settings_profile(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $errors = [];
    $patch = [];

    if (input('display_name') !== null) {
        $name = trim((string) input('display_name'));
        if ($name === '' || mb_strlen($name) > 64) {
            $errors['display_name'] = '昵称需在 1–64 字之间。';
        } else {
            $patch['display_name'] = $name;
        }
    }

    if (input('username') !== null) {
        $username = trim((string) input('username'));
        if (preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username) !== 1) {
            $errors['username'] = '用户名只能包含字母、数字、下划线、点与短横，长度 3–40。';
        } elseif (q_val('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $userId]) !== null) {
            $errors['username'] = '该用户名已被占用。';
        } else {
            $patch['username'] = $username;
        }
    }

    if (input('bio') !== null) {
        $patch['bio'] = mb_substr(trim((string) input('bio')), 0, 255);
    }

    if ($errors !== []) {
        json_fail(implode(' ', $errors), 422, 'validation', ['errors' => $errors]);
    }
    if ($patch === []) {
        json_fail('没有需要更新的字段。');
    }

    $patch['updated_at'] = now();
    q_update('users', $patch, 'id = ?', [$userId]);
    activity_log($userId, 'profile.update');

    json_ok(['user' => array_intersect_key(find_user_by_id($userId) ?? [], array_flip(['display_name', 'username', 'bio']))]);
}

/** 修改密码 */
function settings_change_password(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $errors = validate_password_change(request_input(), $userId);
    if ($errors !== []) {
        foreach ($errors as $message) {
            json_fail($message, 422, 'validation', ['errors' => $errors]);
        }
    }

    $email = (string) Auth::user()['email'];
    change_password($userId, (string) input('password'));
    // 先把其它设备上的会话踢掉，再重开当前会话；顺序反了会把当前会话也踢下线
    revoke_other_sessions($userId);
    activity_log($userId, 'password.change');
    log_login($userId, $email, 'success', '修改密码');

    Auth::login($userId);

    json_ok(['changed' => true]);
}

/** 偏好设置 */
function settings_preferences(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $patch = [];

    if (input('view') !== null && in_array((string) input('view'), ['list', 'grid'], true)) {
        $patch['view_mode'] = (string) input('view');
    }
    if (input('showHidden') !== null) {
        $patch['show_hidden'] = input_bool('showHidden') ? 1 : 0;
    }
    if (input('trashRetention') !== null) {
        $days = input_int('trashRetention', 30);
        $patch['trash_retention_days'] = max(1, min(365, $days));
    }
    if (input('uploadLimit') !== null) {
        $patch['upload_speed_limit'] = max(0, min(1024 * 1024, input_int('uploadLimit')));
    }
    // 记住上次选择的上传目标存储源：0 = 仅本地，非零必须是本人的 s3（COS）源
    if (input('upload_source_id') !== null) {
        $sid = input_int('upload_source_id');
        if ($sid === 0) {
            $patch['upload_source_id'] = 0;
        } elseif (q_one(
            "SELECT id FROM storage_sources WHERE id = ? AND user_id = ? AND driver = 's3'",
            [$sid, $userId]
        ) !== null) {
            $patch['upload_source_id'] = $sid;
        } else {
            json_fail('存储源不存在或不可用。');
        }
    }
    if (input('downloadLimit') !== null) {
        $patch['download_speed_limit'] = max(0, min(1024 * 1024, input_int('downloadLimit')));
    }
    if (input('notifyLogin') !== null) {
        $patch['notify_login'] = input_bool('notifyLogin') ? 1 : 0;
    }
    if (input('defaultSharePerm') !== null) {
        $perm = (string) input('defaultSharePerm');
        if (in_array($perm, ['preview', 'download', 'upload'], true)) {
            $patch['default_share_perm'] = $perm;
        }
    }

    if ($patch === []) {
        json_fail('没有需要更新的字段。');
    }

    save_user_settings($userId, $patch);
    json_ok(['settings' => user_settings($userId)]);
}

/** 当前用量（供前端刷新容量仪表） */
function settings_quota(): void
{
    $userId = require_auth_api();
    $user = find_user_by_id($userId);
    if ($user === null) {
        json_fail('用户不存在。', 404);
    }

    $used = user_recompute_usage($userId);

    json_ok([
        'used'    => $used,
        'total'   => (int) $user['quota_bytes'],
        'usedText'  => format_bytes((float) $used),
        'totalText' => format_bytes((float) $user['quota_bytes']),
        'percent' => (int) $user['quota_bytes'] > 0
            ? round($used / (int) $user['quota_bytes'] * 100, 2)
            : 0,
    ]);
}

/** 管理员：调整配额（个人网盘里给自己用） */
function settings_quota_update(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $gib = (float) input('quotaGb', 0);
    if ($gib < 1 || $gib > 1024 * 1024) {
        json_fail('配额需在 1 GB 到 1 PB 之间。');
    }

    q_update('users', ['quota_bytes' => (int) ($gib * 1024 ** 3)], 'id = ?', [$userId]);
    activity_log($userId, 'quota.update', null, $gib . ' GB');

    $user = find_user_by_id($userId);
    json_ok(['quota' => (int) ($user['quota_bytes'] ?? 0)]);
}
