<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   自动化规则：文件上传后按条件自动打标签、上色、移动或锁定。
   规则是"看得见的"——每条都记命中次数，并在操作日志里留痕。
   ========================================================================= */

const RULE_ACTIONS = ['tag', 'color', 'move', 'hide', 'lock', 'note'];

function rule_conditions_schema(): array
{
    return [
        'ext'      => '扩展名包含（逗号分隔，如 pdf,jpg）',
        'nameHas'  => '文件名包含',
        'kind'     => '类型（image / video / audio / document / archive / code / other）',
        'minSize'  => '不小于（KB）',
        'maxSize'  => '不大于（KB）',
    ];
}

/** 规则对外的形状 */
function rule_public(array $rule): array
{
    $conditions = json_decode((string) $rule['conditions'], true) ?: [];
    $actions = json_decode((string) $rule['actions'], true) ?: [];

    return [
        'id'          => (int) $rule['id'],
        'name'        => (string) $rule['name'],
        'trigger'     => (string) $rule['trigger_type'],
        'conditions'  => $conditions,
        'actions'     => $actions,
        'enabled'     => (int) $rule['enabled'] === 1,
        'runCount'    => (int) $rule['run_count'],
        'lastRunText' => $rule['last_run_at'] !== null ? time_ago((string) $rule['last_run_at']) : '从未运行',
        'createdText' => time_ago((string) $rule['created_at']),
    ];
}

/** 条件描述文本 */
function rule_condition_text(array $conditions): string
{
    $parts = [];
    if (!empty($conditions['ext'])) {
        $parts[] = '扩展名 ' . (string) $conditions['ext'];
    }
    if (!empty($conditions['nameHas'])) {
        $parts[] = '名称含「' . (string) $conditions['nameHas'] . '」';
    }
    if (!empty($conditions['kind'])) {
        $parts[] = '类型为 ' . (string) $conditions['kind'];
    }
    if (!empty($conditions['minSize'])) {
        $parts[] = '≥ ' . format_bytes((float) $conditions['minSize'] * 1024);
    }
    if (!empty($conditions['maxSize'])) {
        $parts[] = '≤ ' . format_bytes((float) $conditions['maxSize'] * 1024);
    }
    return $parts === [] ? '不限制（匹配全部）' : implode(' · ', $parts);
}

function rule_action_text(array $actions): string
{
    $labels = [
        'tag'   => '打标签',
        'color' => '标记颜色',
        'move'  => '移动到指定目录',
        'hide'  => '设为隐藏',
        'lock'  => '锁定',
        'note'  => '写入备注',
    ];

    $parts = [];
    foreach ($actions as $action) {
        $type = (string) ($action['type'] ?? '');
        if (!isset($labels[$type])) {
            continue;
        }
        $value = (string) ($action['value'] ?? '');
        $parts[] = $labels[$type] . ($value !== '' ? '：' . $value : '');
    }
    return $parts === [] ? '无动作' : implode('，', $parts);
}

/** 单个节点是否满足条件 */
function rule_matches(array $conditions, array $node): bool
{
    $ext = (string) ($node['ext'] ?? '');
    $name = (string) $node['name'];
    $bytes = (int) $node['size'];

    if (!empty($conditions['ext'])) {
        $wanted = array_filter(array_map(
            static fn (string $e): string => strtolower(trim($e, " \t\n.,")),
            explode(',', (string) $conditions['ext'])
        ));
        if ($wanted !== [] && !in_array(strtolower($ext), $wanted, true)) {
            return false;
        }
    }

    if (!empty($conditions['nameHas']) && mb_stripos($name, (string) $conditions['nameHas']) === false) {
        return false;
    }

    if (!empty($conditions['kind'])) {
        $kind = kind_of((string) ($node['mime'] ?? ''), $ext);
        if ($kind !== (string) $conditions['kind']) {
            return false;
        }
    }

    if (!empty($conditions['minSize']) && $bytes < (int) $conditions['minSize'] * 1024) {
        return false;
    }
    if (!empty($conditions['maxSize']) && $bytes > (int) $conditions['maxSize'] * 1024) {
        return false;
    }

    return true;
}

/**
 * 对单个节点执行所有启用的上传规则。
 * 返回执行的动作条数。
 */
function rules_apply_to_node(int $userId, int $nodeId): int
{
    $node = node_get($userId, $nodeId);
    if ($node === null || $node['kind'] !== 'file') {
        return 0;
    }

    $rules = q_all(
        "SELECT * FROM automation_rules WHERE user_id = ? AND enabled = 1 AND trigger_type = 'upload' ORDER BY id ASC",
        [$userId]
    );

    $applied = 0;
    foreach ($rules as $rule) {
        $conditions = json_decode((string) $rule['conditions'], true) ?: [];
        if (!rule_matches($conditions, $node)) {
            continue;
        }

        $done = rule_execute($userId, $rule, $node);
        if ($done > 0) {
            q_exec(
                'UPDATE automation_rules SET run_count = run_count + 1, last_run_at = ? WHERE id = ?',
                [now(), (int) $rule['id']]
            );
            $applied += $done;
        }
    }

    return $applied;
}

/** 执行一条规则的动作 */
function rule_execute(int $userId, array $rule, array $node): int
{
    $actions = json_decode((string) $rule['actions'], true) ?: [];
    $done = 0;
    $nodeId = (int) $node['id'];

    foreach ($actions as $action) {
        $type = (string) ($action['type'] ?? '');
        $value = trim((string) ($action['value'] ?? ''));

        switch ($type) {
            case 'tag':
                if ($value === '') {
                    break;
                }
                $tagId = rule_ensure_tag($userId, $value);
                q_exec('INSERT IGNORE INTO node_tags (node_id, tag_id) VALUES (?, ?)', [$nodeId, $tagId]);
                $done++;
                break;

            case 'color':
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                    break;
                }
                q_update('nodes', ['color' => $value], 'id = ? AND user_id = ?', [$nodeId, $userId]);
                $done++;
                break;

            case 'hide':
                q_update('nodes', ['is_hidden' => 1], 'id = ? AND user_id = ?', [$nodeId, $userId]);
                $done++;
                break;

            case 'lock':
                q_update('nodes', ['is_locked' => 1], 'id = ? AND user_id = ?', [$nodeId, $userId]);
                $done++;
                break;

            case 'note':
                if ($value === '') {
                    break;
                }
                q_update('nodes', ['note' => mb_substr($value, 0, 2000)], 'id = ? AND user_id = ?', [$nodeId, $userId]);
                $done++;
                break;

            case 'move':
                // 目标用目录 id 或目录名（按名找根目录下的同名文件夹）
                $targetId = 0;
                if (ctype_digit($value)) {
                    $targetId = (int) $value;
                } elseif ($value !== '') {
                    $found = q_one(
                        'SELECT id FROM nodes WHERE user_id = ? AND kind = ? AND name_key = ? AND deleted_at IS NULL LIMIT 1',
                        [$userId, 'folder', mb_strtolower($value)]
                    );
                    $targetId = $found !== null ? (int) $found['id'] : 0;
                }
                if ($targetId <= 0 || $targetId === (int) $node['parent_id']) {
                    break;
                }
                try {
                    node_move($userId, node_require($userId, $nodeId), $targetId);
                    $done++;
                } catch (RuntimeException $e) {
                    // 目标非法时静默跳过，不影响其它动作
                }
                break;

            default:
                break;
        }
    }

    if ($done > 0) {
        activity_log($userId, 'rule.apply', $nodeId, (string) $rule['name']);
    }

    return $done;
}

/** 按名字取标签，不存在就建一个 */
function rule_ensure_tag(int $userId, string $name): int
{
    $name = mb_substr(trim($name), 0, 48);
    $existing = q_one('SELECT id FROM tags WHERE user_id = ? AND name = ?', [$userId, $name]);
    if ($existing !== null) {
        return (int) $existing['id'];
    }

    $palette = tag_palette();
    $count = (int) q_val('SELECT COUNT(*) FROM tags WHERE user_id = ?', [$userId]);

    return q_insert('tags', [
        'user_id'    => $userId,
        'name'       => $name,
        'color'      => $palette[$count % count($palette)],
        'created_at' => now(),
    ]);
}

/**
 * 把规则手动套用到现有文件上（建规则时的"回填"）。
 */
function rules_run_backfill(int $userId, int $ruleId, int $limit = 500): array
{
    $rule = q_one('SELECT * FROM automation_rules WHERE id = ? AND user_id = ?', [$ruleId, $userId]);
    if ($rule === null) {
        throw new RuntimeException('规则不存在。');
    }

    $conditions = json_decode((string) $rule['conditions'], true) ?: [];
    $rows = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC LIMIT ' . max(1, min(2000, $limit)),
        [$userId, 'file']
    );

    $matched = 0;
    $actions = 0;
    foreach ($rows as $row) {
        if (!rule_matches($conditions, $row)) {
            continue;
        }
        $matched++;
        $actions += rule_execute($userId, $rule, $row);
    }

    if ($matched > 0) {
        q_exec(
            'UPDATE automation_rules SET run_count = run_count + ?, last_run_at = ? WHERE id = ?',
            [$matched, now(), $ruleId]
        );
    }

    return ['matched' => $matched, 'actions' => $actions];
}
