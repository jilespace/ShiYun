<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function search_results(): void
{
    $userId = Auth::id();
    $user = Auth::user();
    $settings = user_settings($userId);

    $filters = [
        'q'     => trim((string) ($_GET['q'] ?? '')),
        'scope' => (string) ($_GET['scope'] ?? 'name'),
        'type'  => (string) ($_GET['type'] ?? 'all'),
        'tag'   => (int) ($_GET['tag'] ?? 0),
        'from'  => (string) ($_GET['from'] ?? ''),
        'to'    => (string) ($_GET['to'] ?? ''),
        'min'   => (int) ($_GET['min'] ?? 0),
        'max'   => (int) ($_GET['max'] ?? 0),
        'sort'  => (string) ($_GET['sort'] ?? 'time'),
        'dir'   => (string) ($_GET['dir'] ?? 'desc'),
    ];

    $hasQuery = $filters['q'] !== '' || $filters['type'] !== 'all' || $filters['tag'] > 0
        || $filters['from'] !== '' || $filters['to'] !== '' || $filters['min'] > 0 || $filters['max'] > 0;

    $result = $hasQuery
        ? search_nodes($userId, $filters + ['limit' => 100, 'offset' => 0])
        : ['items' => [], 'total' => 0, 'facets' => search_facets($userId)];

    $activeTag = $filters['tag'] > 0
        ? q_one('SELECT * FROM tags WHERE id = ? AND user_id = ?', [$filters['tag'], $userId])
        : null;

    render('search', [
        'title'     => ($filters['q'] !== '' ? $filters['q'] . ' · 搜索' : '搜索') . ' · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'search',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'search',
        'filters'   => $filters,
        'result'    => $result,
        'hasQuery'  => $hasQuery,
        'tags'      => q_all('SELECT * FROM tags WHERE user_id = ? ORDER BY name ASC', [$userId]),
        'activeTag' => $activeTag,
    ]);
}

function search_query(): void
{
    $userId = require_auth_api();

    $data = search_nodes($userId, [
        'q'      => trim((string) input('q', '')),
        'scope'  => (string) input('scope', 'name'),
        'type'   => (string) input('type', 'all'),
        'tag'    => input_int('tag'),
        'from'   => (string) input('from', ''),
        'to'     => (string) input('to', ''),
        'min'    => input_int('min'),
        'max'    => input_int('max'),
        'sort'   => (string) input('sort', 'time'),
        'dir'    => (string) input('dir', 'desc'),
        'limit'  => input_int('limit', 60),
        'offset' => input_int('offset'),
    ]);

    json_ok($data);
}

/** 为缺失索引的文件补建索引 */
function search_reindex(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $result = search_rebuild($userId);
    activity_log($userId, 'search.reindex', null, $result['indexed'] . ' 个文件');
    json_ok($result);
}
