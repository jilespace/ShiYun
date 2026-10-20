<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/runtime/php-error.log');

$GLOBALS['config'] = require BASE_PATH . '/app/config.php';

require BASE_PATH . '/app/helpers.php';

// 统一时区（PHP 侧；MySQL 会话时区在 db() 中按同一配置设置）
date_default_timezone_set((string) config('timezone', 'Asia/Shanghai'));

// 基础安全响应头（在输出之前下发；因存在内联脚本/样式，暂不启用 CSP）
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (($_SERVER['HTTPS'] ?? '') === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
require BASE_PATH . '/app/icons.php';
require BASE_PATH . '/app/schema.php';
require BASE_PATH . '/app/db.php';
require BASE_PATH . '/app/csrf.php';
require BASE_PATH . '/app/auth.php';
require BASE_PATH . '/app/response.php';

require BASE_PATH . '/app/lib/shell.php';
require BASE_PATH . '/app/lib/users.php';
require BASE_PATH . '/app/lib/nav.php';
require BASE_PATH . '/app/lib/nodes.php';
require BASE_PATH . '/app/lib/locations.php';
require BASE_PATH . '/app/lib/storage.php';
require BASE_PATH . '/app/lib/storage_drivers.php';
require BASE_PATH . '/app/lib/activity.php';
require BASE_PATH . '/app/lib/ooxml.php';
require BASE_PATH . '/app/lib/archive.php';
require BASE_PATH . '/app/lib/search.php';
require BASE_PATH . '/app/lib/qr.php';
require BASE_PATH . '/app/lib/totp.php';
require BASE_PATH . '/app/lib/shares.php';
require BASE_PATH . '/app/lib/directs.php';
require BASE_PATH . '/app/lib/versions.php';
require BASE_PATH . '/app/lib/api.php';
require BASE_PATH . '/app/lib/rules.php';

// 建立连接并确保表结构就位（首次请求会自动建表）
db();

// 运行时目录（需 Web 服务器拒绝直接访问：nginx 配 location ^~ /runtime/ { deny all; return 404; }）
foreach ([
    (string) config('session_path'),
    (string) config('storage_path'),
    (string) config('chunk_path'),
    (string) config('thumb_path'),
    (string) config('trash_path'),
] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

// 会话安全
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (($_SERVER['HTTPS'] ?? '') === 'on') {
    ini_set('session.cookie_secure', '1');
}
$sessionPath = (string) config('session_path');
if (is_dir($sessionPath) && is_writable($sessionPath)) {
    session_save_path($sessionPath);
}
session_name('shiyun_sid');
session_start();

/** 当前请求是否期望 JSON 响应 */
function wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    return str_contains($accept, 'application/json')
        || $xhr === 'XMLHttpRequest'
        || str_contains($ctype, 'application/json');
}

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    if (headers_sent()) {
        return;
    }

    // safe_error_message() 会把 PDOException 挡在外面——它也继承 RuntimeException，
    // 但 message 里带着 SQL，直接回显等于把表结构送给对方。
    $isDomain = ($e instanceof RuntimeException || $e instanceof InvalidArgumentException)
        && !$e instanceof PDOException;
    $message = safe_error_message($e, '请稍后重试，或联系管理员查看日志。');

    if (wants_json()) {
        json_out([
            'ok'    => false,
            'error' => $isDomain ? $message : '服务器内部错误',
            'code'  => $isDomain ? 'rejected' : 'internal',
        ], $isDomain ? 400 : 500);
    }

    http_response_code($isDomain ? 400 : 500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>出错了</title>';
    echo '<body style="background:#08090b;color:#f4f2ec;font:16px/1.6 system-ui;padding:12vh 8vw">';
    echo '<h1 style="font-size:1.6rem;letter-spacing:-.02em">' . ($isDomain ? '请求未被接受' : '服务器内部错误') . '</h1>';
    echo '<p style="color:rgba(244,242,236,.6)">' . e($message) . '</p>';
    echo '<p><a href="' . e(url('')) . '" style="color:#8a88ff">返回首页</a></p>';
    exit;
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // 弃用提示不影响功能（例如 PHP 8.5 里 imagedestroy 已无作用），只记录不抛出
    if (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
        return true;
    }
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
