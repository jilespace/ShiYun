<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function tags_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $tags = q_all(
        'SELECT t.*, COUNT(nt.node_id) AS use_count
         FROM tags t
         LEFT JOIN node_tags nt ON nt.tag_id = t.id
         LEFT JOIN nodes n ON n.id = nt.node_id AND n.deleted_at IS NULL
         WHERE t.user_id = ?
         GROUP BY t.id
         ORDER BY use_count DESC, t.name ASC',
        [$userId]
    );

    render('tags', [
        'title'     => '标签 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'tags',
        'tags'      => $tags,
        'palette'   => tag_palette(),
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'tags',
    ]);
}

function tags_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $name = trim((string) input('name', ''));
    if ($name === '' || mb_strlen($name) > 48) {
        json_fail('标签名需在 1–48 字之间。');
    }
    if (q_val('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$userId, $name]) !== null) {
        json_fail('已存在同名标签。');
    }

    $color = (string) input('color', tag_palette()[0]);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
        $color = tag_palette()[0];
    }

    $id = q_insert('tags', [
        'user_id'    => $userId,
        'name'       => $name,
        'color'      => $color,
        'created_at' => now(),
    ]);

    json_ok(['tag' => ['id' => $id, 'name' => $name, 'color' => $color, 'use_count' => 0]]);
}

function tags_rename(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $id = input_int('id');
    $tag = q_one('SELECT * FROM tags WHERE id = ? AND user_id = ?', [$id, $userId]);
    if ($tag === null) {
        json_fail('标签不存在。', 404);
    }

    $patch = [];
    if (input('name') !== null) {
        $name = trim((string) input('name'));
        if ($name === '' || mb_strlen($name) > 48) {
            json_fail('标签名需在 1–48 字之间。');
        }
        if (q_val('SELECT id FROM tags WHERE user_id = ? AND name = ? AND id <> ?', [$userId, $name, $id]) !== null) {
            json_fail('已存在同名标签。');
        }
        $patch['name'] = $name;
    }
    if (input('color') !== null) {
        $color = (string) input('color');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            json_fail('颜色值不合法。');
        }
        $patch['color'] = $color;
    }
    if ($patch === []) {
        json_fail('没有需要更新的字段。');
    }

    q_update('tags', $patch, 'id = ?', [$id]);
    json_ok(['tag' => array_merge($tag, $patch)]);
}

function tags_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $id = input_int('id');
    $tag = q_one('SELECT * FROM tags WHERE id = ? AND user_id = ?', [$id, $userId]);
    if ($tag === null) {
        json_fail('标签不存在。', 404);
    }

    db_tx(static function () use ($id): void {
        q_exec('DELETE FROM node_tags WHERE tag_id = ?', [$id]);
        q_exec('DELETE FROM tags WHERE id = ?', [$id]);
    });

    json_ok(['deleted' => true]);
}

/**
 * 给一批节点打标签 / 取消标签。
 * mode=add|remove|set
 */
function tags_apply(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $ids = array_map('intval', (array) input('ids', []));
    $tagIds = array_map('intval', (array) input('tags', []));
    $mode = (string) input('mode', 'add');

    if ($ids === []) {
        json_fail('请先选择条目。');
    }
    if ($tagIds === [] && $mode !== 'set') {
        json_fail('请先选择标签。');
    }

    // 只允许操作自己的标签
    $owned = array_map(
        static fn (array $r): int => (int) $r['id'],
        q_all('SELECT id FROM tags WHERE user_id = ?', [$userId])
    );
    $tagIds = array_values(array_intersect($tagIds, $owned));

    $nodes = nodes_by_ids($userId, $ids);
    if ($nodes === []) {
        json_fail('条目不存在。');
    }

    $changed = 0;
    db_tx(static function () use ($nodes, $tagIds, $mode, &$changed): void {
        foreach ($nodes as $node) {
            $nodeId = (int) $node['id'];

            if ($mode === 'set') {
                q_exec('DELETE FROM node_tags WHERE node_id = ?', [$nodeId]);
            }
            if ($mode === 'remove') {
                foreach ($tagIds as $tagId) {
                    $changed += q_exec('DELETE FROM node_tags WHERE node_id = ? AND tag_id = ?', [$nodeId, $tagId]);
                }
                continue;
            }
            foreach ($tagIds as $tagId) {
                q_exec('INSERT IGNORE INTO node_tags (node_id, tag_id) VALUES (?, ?)', [$nodeId, $tagId]);
                $changed++;
            }
        }
    });

    activity_log($userId, $mode === 'remove' ? 'tag.detach' : 'tag.attach', null, $changed . ' 处');
    json_ok(['changed' => $changed, 'count' => count($nodes)]);
}

/** 供其它页面拉取标签清单（含使用次数） */
function tags_index(): void
{
    $userId = require_auth_api();
    json_ok([
        'tags' => q_all(
            'SELECT t.id, t.name, t.color, COUNT(nt.node_id) AS use_count
             FROM tags t LEFT JOIN node_tags nt ON nt.tag_id = t.id
             WHERE t.user_id = ? GROUP BY t.id ORDER BY use_count DESC, t.name ASC',
            [$userId]
        ),
        'palette' => tag_palette(),
    ]);
}
