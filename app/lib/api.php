<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   API 令牌：对外提供 Bearer 认证的 REST 接口。
   令牌只存哈希，明文仅在创建时返回一次。
   ========================================================================= */

function api_token_create(int $userId, string $name, ?int $ttlDays = null): array
{
    $plain = 'sy_' . random_token(24);

    q_insert('api_tokens', [
        'user_id'    => $userId,
        'name'       => mb_substr($name, 0, 64),
        'token_hash' => hash('sha256', $plain),
        'scopes'     => '*',
        'expires_at' => $ttlDays !== null && $ttlDays > 0
            ? date('Y-m-d H:i:s', time() + $ttlDays * 86400)
            : null,
        'created_at' => now(),
    ]);

    return ['token' => $plain];
}

/** 从 Authorization 头或 token 参数取令牌并换出用户 */
function api_token_user(): ?array
{
    $token = '';

    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $header, $m) === 1) {
        $token = $m[1];
    } elseif (isset($_SERVER['PHP_AUTH_PW'])) {
        $token = (string) $_SERVER['PHP_AUTH_PW'];
    } elseif (isset($_GET['token'])) {
        $token = (string) $_GET['token'];
    }

    $token = trim($token);
    if ($token === '') {
        return null;
    }

    $hash = hash('sha256', $token);
    $row = q_one('SELECT * FROM api_tokens WHERE token_hash = ?', [$hash]);
    if ($row === null) {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    q_exec('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now(), (int) $row['id']]);

    $user = find_user_by_id((int) $row['user_id']);
    if ($user === null || $user['status'] !== 'active') {
        return null;
    }

    return $user;
}

/** 要求令牌有效，返回用户 id */
function api_require_user(): int
{
    $user = api_token_user();
    if ($user === null) {
        header('WWW-Authenticate: Bearer realm="拾云 API"');
        json_fail('缺少或无效的 API 令牌。', 401, 'unauthenticated');
    }
    return (int) $user['id'];
}
