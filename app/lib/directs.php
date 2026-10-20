<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   直链：免登录、可撤销、可选绑定来源 IP 的单文件字节流入口。
   准入判定全部收在 direct_resolve()，路由里不留分支。
   ========================================================================= */

function direct_token(): string
{
    return random_token(16);   // 32 位十六进制 = 128 bit
}

/**
 * 校验并规范化 ip_allow 输入。非法返回 null。
 *
 * 支持：IPv4 单地址、IPv4 CIDR、IPv6 单地址。
 * 不支持 IPv6 CIDR——写错一位就整段失效，且实际几乎用不到。
 */
function direct_normalize_ip(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (str_contains($input, '/')) {
        $parts = explode('/', $input, 2);
        $addr = trim($parts[0]);
        $bits = trim($parts[1]);

        if (str_contains($addr, ':') || preg_match('/^\d+$/', $bits) !== 1) {
            return null;
        }
        $packed = @inet_pton($addr);
        if ($packed === false || strlen($packed) !== 4) {
            return null;
        }
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return null;
        }
        return inet_ntop($packed) . '/' . $bits;
    }

    $packed = @inet_pton($input);
    if ($packed === false) {
        return null;
    }
    return inet_ntop($packed);
}

/** ip_allow 为空即不限来源；否则按单地址或 IPv4 网段匹配 */
function direct_ip_ok(array $row, string $ip): bool
{
    $allow = $row['ip_allow'] ?? null;
    if ($allow === null || $allow === '') {
        return true;
    }
    $allow = (string) $allow;

    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (!str_contains($allow, '/')) {
        $want = @inet_pton($allow);
        return $want !== false && strlen($want) === strlen($packed) && hash_equals($want, $packed);
    }

    $parts = explode('/', $allow, 2);
    $net = @inet_pton($parts[0]);
    // 只支持 IPv4 网段：两侧都必须恰好 4 字节
    if ($net === false || strlen($net) !== 4 || strlen($packed) !== 4) {
        return false;
    }

    $bits = (int) $parts[1];
    if ($bits <= 0) {
        return true;
    }
    if ($bits > 32) {
        return false;
    }

    // 逐位比较：字符串前缀比较会让 203.0.113.7 匹配上 203.0.113.70
    $mask = str_repeat("\xff", intdiv($bits, 8));
    $rem = $bits % 8;
    if ($rem > 0) {
        $mask .= chr((0xFF << (8 - $rem)) & 0xFF);
    }
    $mask = str_pad($mask, 4, "\x00");

    return ($net & $mask) === ($packed & $mask);
}

/** 状态为 active 且未过期 */
function direct_is_live(array $row): bool
{
    if (($row['status'] ?? '') !== 'active') {
        return false;
    }
    $expires = $row['expires_at'] ?? null;
    return $expires === null || strtotime((string) $expires) >= time();
}

/** 按令牌查表；格式不合法或未命中返回 null */
function direct_find(string $token): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
        return null;
    }
    return q_one('SELECT * FROM direct_links WHERE token = ? LIMIT 1', [$token]);
}

/** 直链信息的对外形状 */
function direct_public(array $row): array
{
    $expires = $row['expires_at'] ?? null;
    $token = (string) ($row['token'] ?? '');

    return [
        'id'          => (int) ($row['id'] ?? 0),
        'token'       => $token,
        'url'         => url('direct.serve', ['t' => $token]),
        'urlDownload' => url('direct.serve', ['t' => $token, 'dl' => '1']),
        'ipAllow'     => $row['ip_allow'] !== null ? (string) $row['ip_allow'] : '',
        'expiresAt'   => $expires !== null ? (string) $expires : null,
        'expiresText' => $expires === null
            ? '永久有效'
            : (strtotime((string) $expires) < time()
                ? '已过期'
                : '至 ' . date('Y-m-d H:i', strtotime((string) $expires))),
        'hits'        => (int) ($row['hit_count'] ?? 0),
        'lastAccess'  => ($row['last_access_at'] ?? null) !== null
            ? time_ago((string) $row['last_access_at'])
            : '从未',
        'createdAt'   => (string) ($row['created_at'] ?? ''),
        'createdText' => ($row['created_at'] ?? null) !== null ? time_ago((string) $row['created_at']) : '',
        'active'      => direct_is_live($row),
    ];
}
