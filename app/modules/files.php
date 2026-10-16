<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* --------------------------------------------------------------- 页面 */

function files_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();
    $settings = user_settings($userId);

    $section = (string) ($_GET['section'] ?? 'files');
    if (!in_array($section, ['files', 'favorites', 'trash'], true)) {
        $section = 'files';
    }

    $opts = [
        'sort'       => (string) ($_GET['sort'] ?? $settings['sort_key'] ?? 'name'),
        'dir'        => (string) ($_GET['dir'] ?? $settings['sort_dir'] ?? 'asc'),
        'showHidden' => (bool) ($settings['show_hidden'] ?? false),
        'type'       => (string) ($_GET['type'] ?? 'all'),
    ];

    // 三个分区共用同一套渲染路径，只是数据源不同
    if ($section === 'trash') {
        $folder = node_require($userId, user_trash_id($userId));
        $children = array_map('node_public', node_trash_list($userId));
        $crumbs = [['id' => (int) $folder['id'], 'name' => '回收站']];
        $retention = (int) user_setting($userId, 'trash_retention_days', 30);
        // 仅在进入回收站时按上限分批清理，避免每次浏览目录都触发大量删除
        trash_purge_expired($userId, $retention, 200);
    } elseif ($section === 'favorites') {
        $folder = node_require($userId, user_root_id($userId));
        $children = array_map('node_public', node_favorites($userId));
        $crumbs = [['id' => (int) $folder['id'], 'name' => '收藏']];
        $retention = 0;
    } else {
        $folderId = input_int('folder', user_root_id($userId));
        $folder = node_get($userId, $folderId);
        if ($folder === null || $folder['deleted_at'] !== null) {
            $folder = node_require($userId, user_root_id($userId));
        }

        // 私密空间需要先解锁
        if (node_in_private($userId, $folder) && !Auth::privateUnlocked()) {
            redirect(url('files.list', ['folder' => user_root_id($userId)]));
        }

        $children = array_map('node_public', node_children($userId, (int) $folder['id'], $opts));
        $crumbs = node_breadcrumb($userId, (int) $folder['id']);
        $retention = 0;
    }

    $tags = q_all('SELECT * FROM tags WHERE user_id = ? ORDER BY name ASC', [$userId]);

    // 上传目标存储源：仅 s3（COS）源可作为推送目标；0 表示仅本地
    $uploadSources = array_map(
        static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
        q_all(
            "SELECT id, name FROM storage_sources
             WHERE user_id = ? AND driver = 's3' ORDER BY priority DESC, id ASC",
            [$userId]
        )
    );
    $uploadSourceId = (int) user_setting($userId, 'upload_source_id', 0);

    render('files', [
        'title'     => match ($section) {
            'trash'     => '回收站 · 拾云',
            'favorites' => '收藏 · 拾云',
            default     => $folder['name'] . ' · 拾云',
        },
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/files.js', '/assets/js/upload.js'],
        'user'      => $user,
        'settings'  => $settings,
        'folder'    => $folder,
        'children'  => $children,
        'crumbs'    => $crumbs,
        'tags'      => $tags,
        'section'   => $section,
        'opts'      => $opts,
        'retention' => $retention,
        'uploadSources'  => $uploadSources,
        'uploadSourceId' => $uploadSourceId,
        'quota'     => [
            'used'  => (int) $user['used_bytes'],
            'total' => (int) $user['quota_bytes'],
        ],
    ]);
}

/* --------------------------------------------------------------- 数据 */

function files_data(): void
{
    $userId = require_auth_api();
    $settings = user_settings($userId);
    $section = (string) input('section', 'files');

    $opts = [
        'sort'       => (string) input('sort', $settings['sort_key'] ?? 'name'),
        'dir'        => (string) input('dir', $settings['sort_dir'] ?? 'asc'),
        'showHidden' => (bool) ($settings['show_hidden'] ?? false),
        'type'       => (string) input('type', 'all'),
    ];

    if ($section === 'trash') {
        $folder = node_require($userId, user_trash_id($userId));
        $items = node_trash_list($userId);
        json_ok([
            'folder'   => node_public($folder),
            'crumbs'   => [['id' => (int) $folder['id'], 'name' => '回收站']],
            'children' => array_map('node_public', $items),
            'stats'    => [
                'files'   => count(array_filter($items, static fn (array $n): bool => $n['kind'] === 'file')),
                'folders' => count(array_filter($items, static fn (array $n): bool => $n['kind'] === 'folder')),
                'bytes'   => array_sum(array_map(static fn (array $n): int => (int) $n['size'], $items)),
            ],
        ]);
    }

    if ($section === 'favorites') {
        $folder = node_require($userId, user_root_id($userId));
        json_ok([
            'folder'   => node_public($folder),
            'crumbs'   => [['id' => (int) $folder['id'], 'name' => '收藏']],
            'children' => array_map('node_public', node_favorites($userId)),
            'stats'    => ['files' => 0, 'bytes' => 0, 'folders' => 0],
        ]);
    }

    $folderId = input_int('folder', user_root_id($userId));
    $folder = node_require($userId, $folderId);

    if (node_in_private($userId, $folder) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    json_ok([
        'folder'   => node_public($folder),
        'crumbs'   => node_breadcrumb($userId, $folderId),
        'children' => array_map('node_public', node_children($userId, $folderId, $opts)),
        'stats'    => node_subtree_stats($userId, $folder),
    ]);
}

/** 目录树（移动/复制目标选择器用） */
function files_tree(): void
{
    $userId = require_auth_api();
    $rootId = user_root_id($userId);
    $exclude = input_int('exclude');

    $rows = q_all(
        'SELECT id, parent_id, name, is_private FROM nodes
         WHERE user_id = ? AND kind = ? AND deleted_at IS NULL ORDER BY name_key ASC',
        [$userId, 'folder']
    );

    $privateId = user_private_id($userId);
    $trashId = user_trash_id($userId);
    $excluded = [];

    // 被移动/复制的目录及其整棵子树不能作为目标
    if ($exclude > 0) {
        $node = node_get($userId, $exclude);
        if ($node !== null && $node['kind'] === 'folder') {
            $sub = q_all(
                'SELECT id FROM nodes WHERE user_id = ? AND (id = ? OR path LIKE ?)',
                [$userId, $exclude, (string) $node['path'] . '%']
            );
            $excluded = array_map(static fn (array $r): int => (int) $r['id'], $sub);
        }
    }

    $tree = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($id === $trashId || in_array($id, $excluded, true)) {
            continue;
        }
        if ((int) $row['is_private'] === 1 && !Auth::privateUnlocked()) {
            continue;
        }
        $tree[] = [
            'id'       => $id,
            'parent'   => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name'     => $id === $rootId ? '我的文件' : (string) $row['name'],
            'private'  => (int) $row['is_private'] === 1,
        ];
    }

    json_ok(['tree' => $tree, 'root' => $rootId, 'private' => $privateId]);
}

/* --------------------------------------------------------------- 新建 */

function files_create_folder(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $parentId = input_int('parent', user_root_id($userId));
    $name = trim((string) input('name', ''));

    $node = node_create_folder($userId, $parentId, $name);
    activity_log($userId, 'file.create', (int) $node['id'], $name);

    json_ok(['node' => node_public(node_attach_meta($userId, [$node])[0])]);
}

/* --------------------------------------------------------------- 改名 */

function files_rename(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $node = node_require($userId, input_int('id'));
    $name = trim((string) input('name', ''));
    $updated = node_rename($userId, $node, $name);

    activity_log($userId, 'file.rename', (int) $updated['id'], $node['name'] . ' → ' . $updated['name']);
    json_ok(['node' => node_public(node_attach_meta($userId, [$updated])[0])]);
}

/* --------------------------------------------------------------- 移动 */

function files_move(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $target = input_int('target', 0);
    $ids = array_map('intval', (array) input('ids', []));

    if ($target <= 0) {
        json_fail('请选择目标文件夹。');
    }
    if ($ids === []) {
        json_fail('请先选择要移动的条目。');
    }

    $moved = [];
    foreach (nodes_by_ids($userId, $ids) as $node) {
        $result = node_move($userId, $node, $target);
        $moved[] = node_public($result);
        activity_log($userId, 'file.move', (int) $node['id'], (string) $node['name']);
    }

    json_ok(['moved' => $moved, 'count' => count($moved)]);
}

/* --------------------------------------------------------------- 复制 */

function files_copy(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $target = input_int('target', 0);
    $ids = array_map('intval', (array) input('ids', []));

    if ($target <= 0) {
        json_fail('请选择目标文件夹。');
    }
    if ($ids === []) {
        json_fail('请先选择要复制的条目。');
    }

    $copied = [];
    foreach (nodes_by_ids($userId, $ids) as $node) {
        $result = node_copy($userId, $node, $target);
        $copied[] = node_public($result);
        activity_log($userId, 'file.copy', (int) $node['id'], (string) $node['name']);
    }

    json_ok(['copied' => $copied, 'count' => count($copied)]);
}

/* --------------------------------------------------------------- 删除 */

function files_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请先选择要删除的条目。');
    }

    $count = 0;
    foreach (nodes_by_ids($userId, $ids) as $node) {
        node_trash($userId, $node);
        activity_log($userId, 'file.delete', (int) $node['id'], (string) $node['name']);
        $count++;
    }

    json_ok(['count' => $count]);
}

function files_restore(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请先选择要恢复的条目。');
    }

    $count = 0;
    foreach (nodes_by_ids($userId, $ids) as $node) {
        if ($node['deleted_at'] === null) {
            continue;
        }
        node_restore($userId, $node);
        activity_log($userId, 'file.restore', (int) $node['id'], (string) $node['name']);
        $count++;
    }

    json_ok(['count' => $count]);
}

function files_purge(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请先选择要彻底删除的条目。');
    }

    $count = 0;
    foreach (nodes_by_ids($userId, $ids) as $node) {
        if ($node['deleted_at'] === null) {
            continue;
        }
        node_purge($userId, $node);
        activity_log($userId, 'file.purge', null, (string) $node['name']);
        $count++;
    }

    user_recompute_usage($userId);
    json_ok(['count' => $count]);
}

function files_empty_trash(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $rows = node_trash_list($userId);
    $count = 0;
    foreach ($rows as $row) {
        node_purge($userId, $row);
        $count++;
    }

    user_recompute_usage($userId);
    activity_log($userId, 'trash.empty', null, "清理 {$count} 项");
    json_ok(['count' => $count]);
}

/* --------------------------------------------------------------- 标记 */

function files_flags(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        json_fail('请先选择条目。');
    }

    $flags = [];
    $action = 'file.favorite';

    if (input('favorite') !== null) {
        $flags['is_favorite'] = input_bool('favorite') ? 1 : 0;
        $action = $flags['is_favorite'] ? 'file.favorite' : 'file.unfavorite';
    }
    if (input('hidden') !== null) {
        $flags['is_hidden'] = input_bool('hidden') ? 1 : 0;
        $action = $flags['is_hidden'] ? 'file.hide' : 'file.show';
    }
    if (input('locked') !== null) {
        $flags['is_locked'] = input_bool('locked') ? 1 : 0;
        $action = $flags['is_locked'] ? 'file.lock' : 'file.unlock';
    }
    if (input('color') !== null) {
        $color = trim((string) input('color', ''));
        if ($color !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            json_fail('颜色值不合法。');
        }
        $flags['color'] = $color === '' ? null : $color;
    }
    if (input('note') !== null) {
        $flags['note'] = mb_substr(trim((string) input('note', '')), 0, 2000);
        $action = 'file.note';
    }

    if ($flags === []) {
        json_fail('没有需要更新的字段。');
    }

    $count = node_set_flags($userId, $ids, $flags);
    activity_log($userId, $action, $ids[0] ?? null, $count . ' 项');

    json_ok(['count' => $count]);
}

/* --------------------------------------------------------------- 详情 */

function files_info(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    $meta = node_attach_meta($userId, [$node])[0];
    $info = node_public($meta);
    // COS 副本状态（信息对话框数据源；缺失时安全回落到未同步）
    $info['cosStatus'] = $meta['cos']['status'] ?? '';
    $info['cosSourceId'] = $meta['cos']['sourceId'] ?? 0;
    $info['stats'] = $node['kind'] === 'folder'
        ? node_subtree_stats($userId, $node)
        : ['files' => 1, 'bytes' => (int) $node['size'], 'folders' => 0];
    $info['activity'] = array_map(
        static fn (array $a): array => [
            'action' => (string) $a['action'],
            'label'  => activity_label((string) $a['action']),
            'detail' => (string) ($a['detail'] ?? ''),
            'when'   => time_ago((string) $a['created_at']),
        ],
        activity_for_node($userId, (int) $node['id'], 12)
    );

    $share = q_one(
        'SELECT * FROM shares WHERE node_id = ? AND user_id = ? AND status = ? LIMIT 1',
        [(int) $node['id'], $userId, 'active']
    );
    $info['share'] = $share !== null ? [
        'token'      => (string) $share['token'],
        'url'        => url('shares.open', ['t' => (string) $share['token']]),
        'permission' => (string) $share['permission'],
        'views'      => (int) $share['view_count'],
        'downloads'  => (int) $share['download_count'],
    ] : null;

    json_ok(['info' => $info]);
}

/* --------------------------------------------------------------- 视图设置 */

function files_view(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $patch = [];
    if (input('view') !== null) {
        $view = (string) input('view');
        if (!in_array($view, ['list', 'grid'], true)) {
            json_fail('视图模式不合法。');
        }
        $patch['view_mode'] = $view;
    }
    if (input('sort') !== null) {
        $sort = (string) input('sort');
        if (!isset(NODE_SORTS[$sort])) {
            json_fail('排序字段不合法。');
        }
        $patch['sort_key'] = $sort;
    }
    if (input('dir') !== null) {
        $dir = strtolower((string) input('dir'));
        if (!in_array($dir, ['asc', 'desc'], true)) {
            json_fail('排序方向不合法。');
        }
        $patch['sort_dir'] = $dir;
    }
    if (input('showHidden') !== null) {
        $patch['show_hidden'] = input_bool('showHidden') ? 1 : 0;
    }

    save_user_settings($userId, $patch);
    json_ok(['settings' => user_settings($userId)]);
}
