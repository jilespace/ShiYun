<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* ------------------------------------------------------------ 操作日志 */

function activity_log(int $userId, string $action, ?int $nodeId = null, ?string $detail = null): void
{
    q_insert('activities', [
        'user_id'    => $userId,
        'node_id'    => $nodeId,
        'action'     => mb_substr($action, 0, 48),
        'detail'     => $detail !== null ? mb_substr($detail, 0, 500) : null,
        'ip'         => client_ip(),
        'created_at' => now(),
    ]);
}

function activity_list(int $userId, int $limit = 50, int $offset = 0, ?string $action = null): array
{
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);

    if ($action !== null && $action !== '') {
        return q_all(
            'SELECT * FROM activities WHERE user_id = ? AND action = ? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$userId, $action]
        );
    }

    return q_all(
        'SELECT * FROM activities WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        [$userId]
    );
}

/** 某个文件的动态记录 */
function activity_for_node(int $userId, int $nodeId, int $limit = 50): array
{
    return q_all(
        'SELECT * FROM activities WHERE user_id = ? AND node_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
        [$userId, $nodeId]
    );
}

/** 动作码 → 中文描述 */
function activity_label(string $action): string
{
    static $map = [
        'login'          => '登录',
        'logout'         => '退出登录',
        'register'       => '注册账号',
        'password.change' => '修改密码',
        'password.reset' => '重置密码',
        'profile.update' => '更新资料',
        'file.upload'    => '上传文件',
        'file.create'    => '新建',
        'file.rename'    => '重命名',
        'file.move'      => '移动',
        'file.copy'      => '复制',
        'file.delete'    => '移入回收站',
        'file.restore'   => '恢复',
        'file.purge'     => '彻底删除',
        'file.download'  => '下载',
        'file.preview'   => '预览',
        'file.lock'      => '锁定',
        'file.unlock'    => '解锁',
        'file.hide'      => '隐藏',
        'file.show'      => '取消隐藏',
        'file.favorite'  => '收藏',
        'file.unfavorite' => '取消收藏',
        'file.note'      => '编辑备注',
        'tag.attach'     => '添加标签',
        'tag.detach'     => '移除标签',
        'share.create'   => '创建分享',
        'share.revoke'   => '取消分享',
        'share.download' => '分享下载',
        'archive.extract' => '在线解压',
        'archive.compress' => '压缩',
        'task.offline'   => '离线下载',
        'task.bt'        => 'BT 下载',
        'private.unlock' => '解锁私密空间',
        'private.lock'   => '锁定私密空间',
        'trash.empty'    => '清空回收站',
        'source.add'     => '添加存储源',
        'source.remove'  => '移除存储源',
        'token.create'   => '创建 API 令牌',
        'token.revoke'   => '吊销 API 令牌',
    ];

    return $map[$action] ?? $action;
}

/* ------------------------------------------------------------ 流量统计 */

function traffic_add(int $userId, string $direction, int $bytes, int $count = 1): void
{
    if ($bytes <= 0 && $count <= 0) {
        return;
    }
    $day = date('Y-m-d');
    $up = $direction === 'up' ? max(0, $bytes) : 0;
    $down = $direction === 'down' ? max(0, $bytes) : 0;
    $upCount = $direction === 'up' ? $count : 0;
    $downCount = $direction === 'down' ? $count : 0;

    q_exec(
        'INSERT INTO traffic_stats (user_id, day, uploaded_bytes, downloaded_bytes, upload_count, download_count)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            uploaded_bytes   = uploaded_bytes + VALUES(uploaded_bytes),
            downloaded_bytes = downloaded_bytes + VALUES(downloaded_bytes),
            upload_count     = upload_count + VALUES(upload_count),
            download_count   = download_count + VALUES(download_count)',
        [$userId, $day, $up, $down, $upCount, $downCount]
    );
}

/** 最近 N 天的流量序列（缺失日期补零） */
function traffic_series(int $userId, int $days = 30): array
{
    $days = max(1, min(365, $days));
    $rows = q_all(
        'SELECT day, uploaded_bytes, downloaded_bytes, upload_count, download_count
         FROM traffic_stats WHERE user_id = ? AND day >= ? ORDER BY day ASC',
        [$userId, date('Y-m-d', time() - $days * 86400)]
    );

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(string) $row['day']] = $row;
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', time() - $i * 86400);
        $row = $byDay[$day] ?? null;
        $series[] = [
            'day'        => $day,
            'up'         => (int) ($row['uploaded_bytes'] ?? 0),
            'down'       => (int) ($row['downloaded_bytes'] ?? 0),
            'up_count'   => (int) ($row['upload_count'] ?? 0),
            'down_count' => (int) ($row['download_count'] ?? 0),
        ];
    }

    return $series;
}

/* ------------------------------------------------------------ 容量快照 */

function snapshot_today(int $userId): void
{
    $row = q_one(
        'SELECT
            COALESCE(SUM(CASE WHEN kind = ? THEN size ELSE 0 END), 0) AS bytes,
            COALESCE(SUM(kind = ?), 0) AS files,
            COALESCE(SUM(kind = ?), 0) AS folders
         FROM nodes WHERE user_id = ?',
        ['file', 'file', 'folder', $userId]
    );

    q_exec(
        'INSERT INTO storage_snapshots (user_id, day, used_bytes, file_count, folder_count)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE used_bytes = VALUES(used_bytes), file_count = VALUES(file_count), folder_count = VALUES(folder_count)',
        [$userId, date('Y-m-d'), (int) ($row['bytes'] ?? 0), (int) ($row['files'] ?? 0), (int) ($row['folders'] ?? 0)]
    );
}

/** 最近 N 天的容量趋势 */
function storage_series(int $userId, int $days = 30): array
{
    $days = max(1, min(365, $days));
    $rows = q_all(
        'SELECT day, used_bytes, file_count, folder_count FROM storage_snapshots
         WHERE user_id = ? AND day >= ? ORDER BY day ASC',
        [$userId, date('Y-m-d', time() - $days * 86400)]
    );

    $byDay = [];
    foreach ($rows as $row) {
        $byDay[(string) $row['day']] = $row;
    }

    $series = [];
    $last = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', time() - $i * 86400);
        $row = $byDay[$day] ?? null;
        $used = $row !== null ? (int) $row['used_bytes'] : $last;
        $last = $used;
        $series[] = [
            'day'     => $day,
            'used'    => $used,
            'files'   => (int) ($row['file_count'] ?? 0),
            'folders' => (int) ($row['folder_count'] ?? 0),
        ];
    }

    return $series;
}

/** 按类型分布统计（单条 SQL 聚合，避免把全部节点载入内存） */
function type_distribution(int $userId): array
{
    $buckets = [
        'image' => ['label' => '图片', 'bytes' => 0, 'count' => 0],
        'video' => ['label' => '视频', 'bytes' => 0, 'count' => 0],
        'audio' => ['label' => '音频', 'bytes' => 0, 'count' => 0],
        'document' => ['label' => '文档', 'bytes' => 0, 'count' => 0],
        'archive' => ['label' => '压缩包', 'bytes' => 0, 'count' => 0],
        'code' => ['label' => '代码', 'bytes' => 0, 'count' => 0],
        'other' => ['label' => '其他', 'bytes' => 0, 'count' => 0],
    ];

    // CASE 分支顺序与取值必须与 kind_of() 保持一致
    $rows = q_all(
        "SELECT CASE
                   WHEN mime LIKE 'image/%' THEN 'image'
                   WHEN mime LIKE 'video/%' THEN 'video'
                   WHEN mime LIKE 'audio/%' THEN 'audio'
                   WHEN ext IN ('zip','rar','7z','tar','gz','bz2','xz','tgz') THEN 'archive'
                   WHEN ext IN ('js','mjs','ts','php','py','java','c','h','cpp','go','rs','rb','sh','sql','css','html','htm','json','yml','yaml','xml') THEN 'code'
                   WHEN ext IN ('doc','docx','xls','xlsx','ppt','pptx','pdf','txt','md','markdown','csv','tsv','odt','epub') THEN 'document'
                   ELSE 'other'
                 END AS bucket,
                 COUNT(*) AS cnt,
                 COALESCE(SUM(size), 0) AS bytes
         FROM nodes WHERE user_id = ? AND kind = 'file'
         GROUP BY bucket",
        [$userId]
    );

    foreach ($rows as $row) {
        $b = (string) $row['bucket'];
        if (isset($buckets[$b])) {
            $buckets[$b]['count'] = (int) $row['cnt'];
            $buckets[$b]['bytes'] = (int) $row['bytes'];
        }
    }

    return $buckets;
}
