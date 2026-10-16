<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

function rules_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    $rows = q_all('SELECT * FROM automation_rules WHERE user_id = ? ORDER BY id DESC', [$userId]);

    render('rules', [
        'title'     => '自动化规则 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'settings',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'rules',
        'rules'     => array_map('rule_public', $rows),
        'folders'   => array_map(
            static fn (array $n): array => ['id' => (int) $n['id'], 'name' => (string) $n['name']],
            q_all(
                'SELECT id, name FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL ORDER BY name_key ASC',
                [$userId, 'folder']
            )
        ),
        'kinds'     => ['image' => '图片', 'video' => '视频', 'audio' => '音频',
            'document' => '文档', 'archive' => '压缩包', 'code' => '代码', 'other' => '其他'],
        'palette'   => tag_palette(),
    ]);
}

function rules_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $name = trim((string) input('name', ''));
    if ($name === '' || mb_strlen($name) > 64) {
        json_fail('规则名称需在 1–64 字之间。');
    }

    $incoming = (array) input('conditions', []);
    $conditions = [];
    foreach (array_keys(rule_conditions_schema()) as $key) {
        $value = trim((string) ($incoming[$key] ?? ''));
        if ($value !== '') {
            $conditions[$key] = $value;
        }
    }

    $incomingActions = (array) input('actions', []);
    $actions = [];
    foreach ($incomingActions as $action) {
        $type = (string) ($action['type'] ?? '');
        if (!in_array($type, RULE_ACTIONS, true)) {
            continue;
        }
        $actions[] = ['type' => $type, 'value' => trim((string) ($action['value'] ?? ''))];
    }
    if ($actions === []) {
        json_fail('至少需要一个动作。');
    }

    $id = q_insert('automation_rules', [
        'user_id'      => $userId,
        'name'         => $name,
        'trigger_type' => 'upload',
        'conditions'   => json_encode($conditions, JSON_UNESCAPED_UNICODE),
        'actions'      => json_encode($actions, JSON_UNESCAPED_UNICODE),
        'enabled'      => 1,
        'created_at'   => now(),
    ]);

    $rule = q_one('SELECT * FROM automation_rules WHERE id = ?', [$id]);
    activity_log($userId, 'rule.create', null, $name);

    json_ok(['rule' => rule_public($rule ?? [])]);
}

function rules_toggle(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $rule = q_one('SELECT * FROM automation_rules WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($rule === null) {
        json_fail('规则不存在。', 404);
    }

    q_update('automation_rules', ['enabled' => (int) $rule['enabled'] === 1 ? 0 : 1], 'id = ?', [(int) $rule['id']]);

    json_ok(['enabled' => (int) $rule['enabled'] !== 1]);
}

function rules_delete(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $rule = q_one('SELECT * FROM automation_rules WHERE id = ? AND user_id = ?', [input_int('id'), $userId]);
    if ($rule === null) {
        json_fail('规则不存在。', 404);
    }

    q_exec('DELETE FROM automation_rules WHERE id = ?', [(int) $rule['id']]);
    activity_log($userId, 'rule.delete', null, (string) $rule['name']);

    json_ok(['deleted' => true]);
}

/** 手动回填：把规则套到已有文件上 */
function rules_backfill(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    try {
        $result = rules_run_backfill($userId, input_int('id'));
    } catch (RuntimeException $e) {
        json_fail($e->getMessage(), 404);
    }

    json_ok($result);
}
