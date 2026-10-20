<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 工作台侧栏导航（各页面共用一份定义） */
function app_nav(): array
{
    return [
        ['id' => 'files',     'label' => '我的文件', 'icon' => 'folder',        'href' => url('files.list')],
        ['id' => 'album',     'label' => '相册',     'icon' => 'image',         'href' => url('files.album')],
        ['id' => 'favorites', 'label' => '收藏',     'icon' => 'star',          'href' => url('files.list', ['section' => 'favorites'])],
        ['id' => 'shares',    'label' => '分享',     'icon' => 'share-2',       'href' => url('shares.list')],
        ['id' => 'trash',     'label' => '回收站',   'icon' => 'trash-2',       'href' => url('files.list', ['section' => 'trash'])],
        ['id' => 'tags',      'label' => '标签',     'icon' => 'tag',           'href' => url('tags.list')],
        ['id' => 'tasks',     'label' => '传输',     'icon' => 'arrow-up-down', 'href' => url('tasks.list')],
        ['id' => 'stats',     'label' => '统计',     'icon' => 'bar-chart-3',   'href' => url('stats.show')],
        ['id' => 'sources',   'label' => '存储源',   'icon' => 'server',        'href' => url('sources.list')],
        ['id' => 'settings',  'label' => '设置',     'icon' => 'settings',      'href' => url('settings.show')],
    ];
}

/** 当前用户是否设置了私密空间密码 */
function private_space_ready(int $userId): bool
{
    return q_val('SELECT private_hash FROM users WHERE id = ?', [$userId]) !== null;
}
