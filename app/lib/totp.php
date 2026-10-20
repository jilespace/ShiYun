<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   TOTP 两步验证（RFC 6238 / RFC 4648 Base32），纯 PHP，无外部依赖。
   ========================================================================= */

const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** 生成 160 位随机密钥的 Base32 表示（32 字符，兼容各类认证器） */
function totp_secret_generate(): string
{
    $raw = random_bytes(20);
    $out = '';
    $bits = 0;
    $acc = 0;
    for ($i = 0, $n = strlen($raw); $i < $n; $i++) {
        $acc = ($acc << 8) | ord($raw[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out .= B32_ALPHABET[($acc >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= B32_ALPHABET[($acc << (5 - $bits)) & 31];
    }
    return $out;
}

/** Base32 解码（忽略空格与大小写）；非法输入返回 null */
function totp_base32_decode(string $b32): ?string
{
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
    if ($b32 === '') {
        return null;
    }

    $out = '';
    $bits = 0;
    $acc = 0;
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos(B32_ALPHABET, $b32[$i]);
        if ($pos === false) {
            return null;
        }
        $acc = ($acc << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($acc >> $bits) & 255);
        }
    }
    return $out;
}

/** 计算某个时间片（默认当前）的 6 位验证码 */
function totp_code(string $b32Secret, ?int $counter = null): string
{
    $key = totp_base32_decode($b32Secret);
    if ($key === null || $key === '') {
        return '';
    }

    $counter ??= (int) floor(time() / 30);
    $binCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);

    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $value = (
        ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF)
    );

    return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
}

/** 校验验证码；允许 ±1 个时间片（30s）的时钟偏移 */
function totp_verify(string $b32Secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }

    $counter = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($b32Secret, $counter + $i), $code)) {
            return true;
        }
    }
    return false;
}

/** 认证器扫码用的 otpauth:// URI */
function totp_uri(string $b32Secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
        . '?secret=' . $b32Secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/**
 * 认证器绑定的二维码 SVG。
 *
 * 内置编码器只到版本 10（约 213 字节），而邮箱一长，完整 otpauth URI 就会超容量。
 * 这里逐级退化：完整账号 → 截断账号 → 返回空串（由页面退化为只给手动密钥）。
 * 绝不能让它抛异常——那会把整个绑定页打成 400。
 */
function totp_qr_svg(string $b32Secret, string $account, string $issuer, int $scale = 5, int $margin = 1): string
{
    $candidates = [$account];
    $short = mb_substr($account, 0, 32);
    if ($short !== $account && $short !== '') {
        $candidates[] = $short;
    }

    foreach ($candidates as $label) {
        try {
            return (new QrCode(totp_uri($b32Secret, $label, $issuer)))->svg($scale, $margin);
        } catch (RuntimeException $e) {
            // 内容仍超容量：换更短的账号标签再试
        }
    }

    return '';
}

/* ------------------------------------------------------------ 一次性恢复码

   认证器丢了就进不去，这是 TOTP 的经典死结。这里给一组一次性码兜底：
   只存 sha256 哈希、用掉即标记，生成后只完整展示一次。
   ========================================================================= */

const TOTP_RECOVERY_COUNT = 8;

/** 恢复码归一化：去掉分隔符并转大写（用户抄写时常带空格/短横） */
function totp_recovery_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
}

/** 输入是否像恢复码（12 位）而不是 6 位动态码 */
function totp_recovery_looks_like(string $code): bool
{
    return strlen(totp_recovery_normalize($code)) === 12;
}

/**
 * 为用户重新生成一组恢复码；旧的一次性全部作废。
 * @return list<string> 明文码（形如 K7F2-9QXM-3TB8），调用方负责只展示一次
 */
function totp_recovery_generate(int $userId, int $count = TOTP_RECOVERY_COUNT): array
{
    q_exec('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);

    $plain = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = '';
        // 每 4 位一组，共 12 位（60 bit 熵），只取 Base32 字母表里的字符
        for ($j = 0; $j < 12; $j++) {
            $raw .= B32_ALPHABET[random_int(0, 31)];
        }
        $plain[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);

        q_insert('totp_recovery_codes', [
            'user_id'    => $userId,
            'code_hash'  => hash('sha256', $raw),
            'used_at'    => null,
            'created_at' => now(),
        ]);
    }

    return $plain;
}

/** 核销一个恢复码；命中则标记已用并返回 true */
function totp_recovery_consume(int $userId, string $code): bool
{
    $normalized = totp_recovery_normalize($code);
    if (strlen($normalized) !== 12) {
        return false;
    }

    $row = q_one(
        'SELECT id FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1',
        [$userId, hash('sha256', $normalized)]
    );
    if ($row === null) {
        return false;
    }

    // 原子核销：带上 used_at IS NULL 条件，避免同一码被并发用两次
    $affected = q_exec(
        'UPDATE totp_recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL',
        [now(), (int) $row['id']]
    );

    return $affected === 1;
}

/** @return array{total:int,unused:int} */
function totp_recovery_stats(int $userId): array
{
    $total = (int) q_val('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
    $unused = (int) q_val('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);

    return ['total' => $total, 'unused' => $unused];
}

/** 关掉两步验证时一并清掉恢复码 */
function totp_recovery_clear(int $userId): void
{
    q_exec('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
}
