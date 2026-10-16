<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 统一 JSON 信封 */
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(mixed $data = null, array $extra = []): never
{
    json_out(['ok' => true, 'data' => $data] + $extra);
}

function json_fail(string $message, int $status = 400, string $code = 'error', array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message, 'code' => $code] + $extra, $status);
}

/** 校验请求方法 */
function require_method(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== strtoupper($method)) {
        json_fail('方法不被允许', 405, 'method_not_allowed');
    }
}

/**
 * 读取请求体：支持 JSON 与表单。
 */
function request_input(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ctype, 'application/json')) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $cache = is_array($decoded) ? $decoded : [];
    } else {
        $cache = $_POST;
    }

    return $cache;
}

function input(string $key, mixed $default = null): mixed
{
    $data = request_input();
    return $data[$key] ?? $_GET[$key] ?? $default;
}

/** 取整数参数 */
function input_int(string $key, int $default = 0): int
{
    $v = input($key, $default);
    return is_numeric($v) ? (int) $v : $default;
}

/** 取布尔参数（"1"/"true"/"on" 均为真） */
function input_bool(string $key, bool $default = false): bool
{
    $v = input($key, null);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
}

/**
 * 对写操作强制 CSRF 校验（JSON 请求通过 X-CSRF-Token 头传递）。
 */
function require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('csrf_token');
    if (!csrf_verify(is_string($token) ? $token : null)) {
        json_fail('会话已过期，请刷新页面后重试。', 419, 'csrf');
    }
}

/** 要求已登录，否则 401 */
function require_auth_api(): int
{
    $id = Auth::id();
    if ($id === null) {
        json_fail('请先登录。', 401, 'unauthenticated');
    }
    return $id;
}
