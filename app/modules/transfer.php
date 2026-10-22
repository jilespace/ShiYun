<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   免登录临时传输：公开路由。领域逻辑在 app/lib/transfer.php。
   ========================================================================= */

function transfer_is_local_host(): bool
{
    $host = strtolower((string) parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
}

function transfer_index(): void
{
    transfer_reap_rooms();
    render('transfer', [
        'title'     => '临时传输 · 拾云',
        'bodyClass' => 'is-transfer',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css', '/assets/css/transfer.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/transfer.js'],
        'enabled'   => transfer_enabled(),
        'localOnly' => transfer_is_local_host(),
    ]);
}

function transfer_create(): void
{
    require_method('POST');
    require_csrf();
    try {
        $created = transfer_room_create((string) input('password', ''));
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'rate_limited');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'invalid');
    } catch (RuntimeException $e) {
        json_fail(safe_error_message($e, '创建房间失败，请重试。'), 400, 'error');
    }

    $token = (string) $created['token'];
    json_ok([
        'room' => [
            'token'       => $token,
            'code'        => (string) $created['code'],
            'url'         => url('transfer.room', ['t' => $token]),
            'absoluteUrl' => absolute_url('transfer.room', ['t' => $token]),
            'expiresAt'   => (string) $created['expires_at'],
        ],
        'creator_key' => (string) $created['creator_key'],
    ]);
}

function transfer_join(): void
{
    require_method('POST');
    require_csrf();
    try {
        $room = transfer_join_by_code((string) input('code', ''), (string) input('password', ''));
    } catch (TransferRateLimitException $e) {
        json_fail($e->getMessage(), 429, 'rate_limited');
    } catch (InvalidArgumentException $e) {
        json_fail($e->getMessage(), 422, 'bad_password');
    }

    json_ok([
        'token' => (string) $room['token'],
        'url'   => url('transfer.room', ['t' => (string) $room['token']]),
    ]);
}

function transfer_qr(): void
{
    $token = (string) input('t', '');
    $room = transfer_room_by_token($token);
    if ($room === null) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    echo qr_svg(absolute_url('transfer.room', ['t' => $token]), 6, 2);
    exit;
}
