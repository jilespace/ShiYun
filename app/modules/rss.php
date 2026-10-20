<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   RSS 订阅：用 API 令牌换一个只读的更新流，适合放进阅读器。
   ========================================================================= */

function rss_feed(): void
{
    $token = (string) ($_GET['token'] ?? $_SERVER['PHP_AUTH_PW'] ?? '');
    $user = rss_authenticate($token);

    if ($user === null) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="拾云 RSS"');
        header('Content-Type: text/plain; charset=utf-8');
        exit('需要一个有效的 API 令牌：index.php?r=rss.feed&token=YOUR_TOKEN');
    }

    $userId = (int) $user['id'];
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 40)));
    $kind = (string) ($_GET['kind'] ?? 'files');

    $site = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $self = $site . '/' . url('rss.feed', ['token' => $token] + ($kind !== 'files' ? ['kind' => $kind] : []));

    if ($kind === 'activity') {
        $items = rss_activity_items($userId, $limit);
        $channelTitle = '拾云 · 操作动态';
        $channelDesc = '我的网盘最近的操作记录';
    } else {
        $items = rss_file_items($userId, $limit, $site, $token);
        $channelTitle = '拾云 · 最近文件';
        $channelDesc = '我的网盘里最近变动的文件';
    }

    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: private, max-age=300');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    echo '<channel>' . "\n";
    echo '  <title>' . rss_esc($channelTitle) . '</title>' . "\n";
    echo '  <link>' . rss_esc($site . '/' . url('files.list')) . '</link>' . "\n";
    echo '  <description>' . rss_esc($channelDesc) . '</description>' . "\n";
    echo '  <language>zh-CN</language>' . "\n";
    echo '  <lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n";
    echo '  <atom:link href="' . rss_esc($self) . '" rel="self" type="application/rss+xml"/>' . "\n";

    foreach ($items as $item) {
        echo '  <item>' . "\n";
        echo '    <title>' . rss_esc($item['title']) . '</title>' . "\n";
        echo '    <link>' . rss_esc($item['link']) . '</link>' . "\n";
        echo '    <guid isPermaLink="false">' . rss_esc($item['guid']) . '</guid>' . "\n";
        echo '    <pubDate>' . date(DATE_RSS, (int) $item['ts']) . '</pubDate>' . "\n";
        echo '    <description>' . rss_esc($item['description']) . '</description>' . "\n";
        echo '  </item>' . "\n";
    }

    echo '</channel>' . "\n";
    echo '</rss>' . "\n";
    exit;
}

/** RSS 用 Basic/Bearer 里的令牌换用户 */
function rss_authenticate(string $token): ?array
{
    $token = trim($token);
    if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m) === 1) {
            $token = trim($m[1]);
        }
    }
    if ($token === '') {
        return null;
    }

    $row = q_one(
        'SELECT t.user_id, t.expires_at FROM api_tokens t WHERE t.token_hash = ?',
        [hash('sha256', $token)]
    );
    if ($row === null) {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return null;
    }

    q_exec('UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?', [now(), hash('sha256', $token)]);

    // 同 WebDAV：令牌有效不代表账号还能用
    $user = find_user_by_id((int) $row['user_id']);
    if ($user === null || $user['status'] !== 'active') {
        return null;
    }

    return $user;
}

/** @return list<array{title:string,link:string,guid:string,ts:int,description:string}> */
function rss_file_items(int $userId, int $limit, string $site, string $token): array
{
    $rows = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC LIMIT ' . $limit,
        [$userId, 'file']
    );

    $items = [];
    foreach ($rows as $row) {
        $meta = node_attach_meta($userId, [$row])[0];
        $items[] = [
            'title'       => (string) $row['name'],
            'link'        => $site . '/' . url('preview.open', ['id' => (int) $row['id']]),
            'guid'        => 'shiyun:file:' . $row['id'] . ':' . strtotime((string) $row['updated_at']),
            'ts'          => (int) strtotime((string) $row['updated_at']),
            'description' => sprintf(
                '大小 %s · 类型 %s · 更新于 %s',
                format_bytes((float) $row['size']),
                kind_of((string) $row['mime'], (string) $row['ext']),
                (string) $row['updated_at']
            ),
        ];
    }

    return $items;
}

function rss_activity_items(int $userId, int $limit): array
{
    $rows = activity_list($userId, $limit);

    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'title'       => activity_label((string) $row['action']) . ($row['detail'] !== null ? '：' . $row['detail'] : ''),
            'link'        => '',
            'guid'        => 'shiyun:act:' . $row['id'],
            'ts'          => (int) strtotime((string) $row['created_at']),
            'description' => (string) $row['created_at'],
        ];
    }

    return $items;
}

function rss_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
