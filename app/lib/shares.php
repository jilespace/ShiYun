<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   分享：令牌访问 + 可选提取密码 + 权限（仅预览 / 可下载 / 可上传）。
   ========================================================================= */

const SHARE_PERMISSIONS = ['preview', 'download', 'upload'];

function share_token(): string
{
    // 只用无歧义字符，方便口述和手输
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function share_find(string $token): ?array
{
    if ($token === '' || preg_match('/^[a-z0-9]{8,32}$/', $token) !== 1) {
        return null;
    }
    return q_one('SELECT * FROM shares WHERE token = ? LIMIT 1', [$token]);
}

/** 分享是否仍然有效 */
function share_is_live(array $share): bool
{
    if ($share['status'] !== 'active') {
        return false;
    }
    if ($share['expires_at'] !== null && strtotime((string) $share['expires_at']) < time()) {
        return false;
    }
    if ($share['max_downloads'] !== null && (int) $share['download_count'] >= (int) $share['max_downloads']) {
        return false;
    }
    return true;
}

/** 是否需要并已通过密码校验 */
function share_unlocked(array $share): bool
{
    if ($share['password_hash'] === null) {
        return true;
    }
    $ok = $_SESSION['share_ok'] ?? [];
    $until = (int) ($ok[(string) $share['token']] ?? 0);
    return $until > time();
}

function share_mark_unlocked(string $token): void
{
    $_SESSION['share_ok'][$token] = time() + 3600;
}

/** 记一次访问 */
function share_visit(int $shareId, string $action, ?int $nodeId = null): void
{
    q_insert('share_visits', [
        'share_id'   => $shareId,
        'ip'         => client_ip(),
        'ua'         => user_agent(),
        'action'     => $action,
        'node_id'    => $nodeId,
        'created_at' => now(),
    ]);
}

/** 分享信息的对外形状 */
function share_public(array $share, ?array $node = null): array
{
    $expires = $share['expires_at'] !== null ? (string) $share['expires_at'] : null;

    return [
        'id'          => (int) $share['id'],
        'token'       => (string) $share['token'],
        'url'         => url('shares.open', ['t' => (string) $share['token']]),
        'permission'  => (string) $share['permission'],
        'hasPassword' => $share['password_hash'] !== null,
        'expiresAt'   => $expires,
        'expiresText' => $expires === null
            ? '永久有效'
            : (strtotime($expires) < time() ? '已过期' : '至 ' . date('Y-m-d H:i', strtotime($expires))),
        'maxDownloads' => $share['max_downloads'] !== null ? (int) $share['max_downloads'] : null,
        'downloads'   => (int) $share['download_count'],
        'views'       => (int) $share['view_count'],
        'createdAt'   => (string) $share['created_at'],
        'createdText' => time_ago((string) $share['created_at']),
        'status'      => share_is_live($share) ? 'active' : 'expired',
        'node'        => $node !== null ? node_public($node) : null,
    ];
}

/** 统计某次分享的访问明细 */
function share_visits_of(int $shareId, int $limit = 100): array
{
    return q_all(
        'SELECT * FROM share_visits WHERE share_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
        [$shareId]
    );
}

/**
 * 渲染二维码 SVG。
 * 本机 php.ini 禁用了所有进程执行函数，系统里的 qrencode 用不上，
 * 所以直接用内置的纯 PHP 编码器（见 app/lib/qr.php）。
 */
function qr_svg(string $text, int $scale = 6, int $margin = 2): ?string
{
    try {
        return (new QrCode($text))->svg(max(1, min(20, $scale)), max(0, min(10, $margin)));
    } catch (Throwable $e) {
        return null;
    }
}
