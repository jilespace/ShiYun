<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   传输任务：离线下载（HTTP/FTP，走 curl）、BT/磁力（需外部引擎）、
   以及压缩/解压这类耗时作业的统一队列。

   这里没有常驻守护进程，所以用「分段推进」模型：每次页面加载时推进一小段
   （默认 12 秒），任务状态与断点都落库，关掉浏览器也不会丢进度。
   ========================================================================= */

const TASK_TICK_SECONDS = 12;

/** 计划任务单次推进预算（秒）。配合每分钟一次的调用，占用约 45/60 的份额 */
const TASK_CRON_BUDGET_SECONDS = 45;

/** 上传会话保留期（小时）。超过此时间没有新分片的会话连同分片一并回收 */
const UPLOAD_SESSION_TTL_HOURS = 24;

function tasks_list(): void
{
    $userId = Auth::id();
    $user = Auth::user();

    tasks_tick($userId);
    tasks_sweep_uploads();

    $tasks = array_map('task_public', q_all(
        'SELECT * FROM transfer_tasks WHERE user_id = ? ORDER BY id DESC LIMIT 100',
        [$userId]
    ));

    $engine = task_engine_status();

    render('tasks', [
        'title'     => '传输任务 · 拾云',
        'bodyClass' => 'is-app',
        'styles'    => ['/assets/css/files.css', '/assets/css/console.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/shell.js', '/assets/js/console.js'],
        'user'      => $user,
        'section'   => 'tasks',
        'quota'     => ['used' => (int) $user['used_bytes'], 'total' => (int) $user['quota_bytes']],
        'page'      => 'tasks',
        'tasks'     => $tasks,
        'engine'    => $engine,
        'folders'   => task_folder_options($userId),
    ]);
}

function tasks_data(): void
{
    $userId = require_auth_api();
    tasks_tick($userId);

    json_ok([
        'tasks'  => array_map('task_public', q_all(
            'SELECT * FROM transfer_tasks WHERE user_id = ? ORDER BY id DESC LIMIT 100',
            [$userId]
        )),
        'engine' => task_engine_status(),
    ]);
}

function task_public(array $task): array
{
    $payload = json_decode((string) $task['payload'], true) ?: [];

    return [
        'id'        => (int) $task['id'],
        'kind'      => (string) $task['kind'],
        'kindLabel' => task_kind_label((string) $task['kind']),
        'title'     => (string) $task['title'],
        'status'    => (string) $task['status'],
        'progress'  => (int) $task['progress'],
        'error'     => (string) ($task['error'] ?? ''),
        'retries'   => (int) $task['retries'],
        'url'       => (string) ($payload['url'] ?? $payload['magnet'] ?? ''),
        'folder'    => (int) ($payload['folder'] ?? 0),
        'folderName' => (string) ($payload['folderName'] ?? ''),
        'received'  => (int) ($payload['received'] ?? 0),
        'receivedText' => format_bytes((float) ($payload['received'] ?? 0)),
        'total'     => (int) ($payload['total'] ?? 0),
        'totalText' => ($payload['total'] ?? 0) > 0 ? format_bytes((float) $payload['total']) : '未知',
        'speed'     => (int) ($payload['speed'] ?? 0),
        'createdText' => time_ago((string) $task['created_at']),
        'finishedText' => $task['finished_at'] !== null ? time_ago((string) $task['finished_at']) : null,
    ];
}

function task_kind_label(string $kind): string
{
    return match ($kind) {
        'offline'  => '离线下载',
        'bt'       => 'BT / 磁力',
        'archive'  => '压缩解压',
        'copy'     => '复制',
        'move'     => '移动',
        'download' => '打包下载',
        'push'     => '推送副本',
        'purge'    => '清理副本',
        default    => $kind,
    };
}

/** BT 引擎是否就绪（本机没有 aria2 时应明确告知，而不是假装成功） */
function task_engine_status(): array
{
    $rpc = (string) config('aria2_rpc', '');
    if ($rpc === '') {
        return [
            'btReady' => false,
            'label'   => '未配置',
            'message' => '本机未安装 BT 下载引擎。磁力/种子任务需要 aria2（或同类）的 JSON-RPC 地址，'
                . '在 app/config.php 里填 aria2_rpc 即可启用；HTTP/FTP 离线下载不受影响。',
        ];
    }
    return ['btReady' => true, 'label' => '已配置', 'message' => 'BT 引擎：' . $rpc];
}

/** 建任务 */
function tasks_create(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $kind = (string) input('kind', 'offline');
    $url = trim((string) input('url', ''));
    $folderId = input_int('folder') ?: (int) user_root_id($userId);

    if (!in_array($kind, ['offline', 'bt'], true)) {
        json_fail('任务类型不合法。');
    }
    if ($url === '') {
        json_fail('请填写下载地址' . ($kind === 'bt' ? '或磁力链接' : '') . '。');
    }

    $folder = node_require($userId, $folderId);
    if ($folder['kind'] !== 'folder') {
        json_fail('目标不是文件夹。');
    }

    $title = $url;
    if ($kind === 'offline') {
        if (preg_match('#^(https?|ftps?)://#i', $url) !== 1) {
            json_fail('只支持 http / https / ftp 直链。');
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);
        if ($base !== '' && $base !== '/') {
            $title = rawurldecode($base);
        }
    } else {
        if (preg_match('/^(magnet:\?xt=|https?:\/\/.*\.torrent)/i', $url) !== 1) {
            json_fail('请填入磁力链接（magnet:?xt=…）或种子文件的直链。');
        }
        $title = '磁力任务 ' . mb_substr($url, 0, 60);
    }

    // 离线下载是服务器主动发起请求，必须挡住内网 / 本机 / 云元数据地址——
    // 否则「填个 URL」就等于把服务器当成内网探针。主机名和解析结果都要查：
    // 光看主机名挡不住「域名解析到 127.0.0.1」。
    $fetchHost = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    if ($fetchHost !== '') {
        if (host_is_private($fetchHost)) {
            json_fail('出于安全考虑，不允许下载内网 / 本机地址。');
        }
        foreach (host_resolve_ips($fetchHost) as $ip) {
            if (ip_is_private($ip)) {
                json_fail('该地址解析到内网 / 本机，已拒绝。');
            }
        }
    }

    $id = q_insert('transfer_tasks', [
        'user_id'    => $userId,
        'kind'       => $kind,
        'title'      => mb_substr($title, 0, 255),
        'payload'    => json_encode([
            'url'        => $url,
            'folder'     => (int) $folder['id'],
            'folderName' => (string) $folder['name'],
            'received'   => 0,
            'total'      => 0,
            'speed'      => 0,
        ], JSON_UNESCAPED_UNICODE),
        'progress'   => 0,
        'status'     => 'queued',
        'priority'   => 0,
        'created_at' => now(),
    ]);

    activity_log($userId, $kind === 'bt' ? 'task.bt' : 'task.offline', null, $title);

    $warnUrl = $kind === 'offline' ? $url : (preg_match('#^https?://#i', $url) === 1 ? $url : '');
    $warnHost = $warnUrl !== '' ? (string) (parse_url($warnUrl, PHP_URL_HOST) ?: '') : '';

    json_ok([
        'task'    => task_public(q_one('SELECT * FROM transfer_tasks WHERE id = ?', [$id])),
        'warning' => host_risk_note($warnHost),
    ]);
}

/** 入队一个推送任务 */
function tasks_enqueue_push(int $userId, int $blobId, int $sourceId, string $title): int
{
    return q_insert('transfer_tasks', [
        'user_id'  => $userId,
        'kind'     => 'push',
        'title'    => mb_substr($title, 0, 255),
        'payload'  => json_encode([
            'blob_id'   => $blobId,
            'source_id' => $sourceId,
        ], JSON_UNESCAPED_UNICODE),
        'progress' => 0,
        'status'   => 'queued',
        'priority' => 0,
        'created_at' => now(),
    ]);
}

/** 暂停 / 继续 / 取消 / 重试 / 删除 */
function tasks_control(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();

    $action = (string) input('action', '');
    $ids = array_map('intval', (array) input('ids', []));
    if ($ids === []) {
        $single = input_int('id');
        if ($single > 0) {
            $ids = [$single];
        }
    }
    if ($ids === []) {
        json_fail('请选择任务。');
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $tasks = q_all("SELECT * FROM transfer_tasks WHERE user_id = ? AND id IN ({$in})", [$userId, ...$ids]);
    if ($tasks === []) {
        json_fail('任务不存在。', 404);
    }

    $count = 0;
    foreach ($tasks as $task) {
        switch ($action) {
            case 'pause':
                if (in_array($task['status'], ['queued', 'running'], true)) {
                    q_update('transfer_tasks', ['status' => 'paused'], 'id = ?', [(int) $task['id']]);
                    $count++;
                }
                break;

            case 'resume':
                if (in_array($task['status'], ['paused', 'failed'], true)) {
                    q_update('transfer_tasks', ['status' => 'queued', 'error' => null, 'finished_at' => null], 'id = ?', [(int) $task['id']]);
                    $count++;
                }
                break;

            case 'cancel':
                q_update('transfer_tasks', [
                    'status' => 'canceled',
                    'finished_at' => now(),
                ], 'id = ?', [(int) $task['id']]);
                task_cleanup_temp($task);
                $count++;
                break;

            case 'retry':
                q_update('transfer_tasks', [
                    'status' => 'queued',
                    'error' => null,
                    'retries' => 0,
                    'finished_at' => null,
                ], 'id = ?', [(int) $task['id']]);
                $count++;
                break;

            case 'delete':
                task_cleanup_temp($task);
                q_exec('DELETE FROM transfer_tasks WHERE id = ?', [(int) $task['id']]);
                $count++;
                break;

            default:
                json_fail('不支持的操作。');
        }
    }

    json_ok(['count' => $count]);
}

function tasks_tick_now(): void
{
    require_method('POST');
    require_csrf();
    $userId = require_auth_api();
    $processed = tasks_tick($userId, TASK_TICK_SECONDS * 3);

    json_ok(['processed' => $processed]);
}

/**
 * 计划任务入口：index.php?r=tasks.cron&key=<cron_key>
 *
 * 无需登录（密钥校验），供宝塔「计划任务」每分钟调用一次。没有它，队列只在有人
 * 打开页面时推进；有了它，离线下载等后台任务在浏览器关掉后也会继续跑。
 * 顺带回收废弃的上传会话与分片。
 */
function tasks_cron(): void
{
    $expected = (string) config('cron_key', '');
    $given = (string) ($_GET['key'] ?? '');

    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        return;
    }

    @ignore_user_abort(true);
    @set_time_limit(0);

    $deadline = microtime(true) + TASK_CRON_BUDGET_SECONDS;

    // 所有有待办任务的用户依次推进，预算在用户之间共享
    $userIds = array_map('intval', array_column(
        q_all("SELECT DISTINCT user_id FROM transfer_tasks WHERE status = 'queued'"),
        'user_id'
    ));

    $processed = 0;
    foreach ($userIds as $uid) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            break;
        }
        $processed += tasks_tick($uid, max(1, (int) $left));
    }

    json_ok([
        'processed' => $processed,
        'users'     => count($userIds),
        'swept'     => tasks_sweep_uploads(),
        'trash'     => tasks_sweep_trash(),
        'logs'      => tasks_sweep_logs(),
    ]);
}

/**
 * 清理过期日志。
 *
 * activities 每打开一次文件就写一行、login_logs 每次登录尝试也写一行，
 * 而此前没有任何清理逻辑——只增不减。日志是排查用的，留够窗口就够，
 * 没必要永久保存每一行（设置页的日志标签也会越翻越慢）。
 *
 * 删除按 created_at 走索引分批进行，避免每分钟的定时任务做一次全表扫描。
 *
 * @return array{activities:int,logins:int}
 */
function tasks_sweep_logs(): array
{
    $activityDays = max(7, (int) config('activity_retention_days', 90));
    $loginDays = max(30, (int) config('login_log_retention_days', 365));

    $activities = q_exec(
        'DELETE FROM activities WHERE created_at < ? LIMIT 2000',
        [date('Y-m-d H:i:s', time() - $activityDays * 86400)]
    );
    $logins = q_exec(
        'DELETE FROM login_logs WHERE created_at < ? LIMIT 2000',
        [date('Y-m-d H:i:s', time() - $loginDays * 86400)]
    );

    return ['activities' => $activities, 'logins' => $logins];
}

/**
 * 回收废弃的上传会话。
 *
 * 用户传一半关掉页面时，upload_sessions 行和 runtime/chunks 里的分片会一直留着，
 * 而分片不计入配额，等于可以无限占用磁盘。超过保留期未更新的会话连同分片一起删除。
 */
function tasks_sweep_uploads(int $ttlHours = UPLOAD_SESSION_TTL_HOURS): int
{
    $cutoff = date('Y-m-d H:i:s', time() - $ttlHours * 3600);

    $rows = q_all(
        'SELECT id, upload_id FROM upload_sessions WHERE updated_at < ?',
        [$cutoff]
    );

    foreach ($rows as $row) {
        chunk_remove_dir((string) $row['upload_id']);
        q_exec('DELETE FROM upload_sessions WHERE id = ?', [(int) $row['id']]);
    }

    return count($rows);
}

/** 计划任务里分批清理各用户过期的回收站条目（每用户每次有上限） */
function tasks_sweep_trash(int $perUser = 100): int
{
    $cutoff = date('Y-m-d H:i:s', time() - (int) config('trash_retention') * 86400);
    $uids = array_map('intval', array_column(
        q_all('SELECT DISTINCT user_id FROM nodes WHERE deleted_at IS NOT NULL AND deleted_at < ?', [$cutoff]),
        'user_id'
    ));

    $n = 0;
    foreach ($uids as $uid) {
        $days = (int) user_setting($uid, 'trash_retention_days', (int) config('trash_retention'));
        $n += trash_purge_expired($uid, $days, $perUser);
    }
    return $n;
}

/* ============================================================ 任务推进器 */

/** 任务停在 running 多久算卡死 */
const TASK_STUCK_MINUTES = 30;

/**
 * 回收卡死的任务：超过 TASK_STUCK_MINUTES 仍停在 running 的，重新排队；
 * 已经反复卡死的直接判失败，免得在队列里空转。
 */
function tasks_reap_stuck(int $userId): void
{
    $cutoff = date('Y-m-d H:i:s', time() - TASK_STUCK_MINUTES * 60);

    q_exec(
        "UPDATE transfer_tasks
         SET status = 'failed', error = '任务反复中断，已放弃', finished_at = ?
         WHERE user_id = ? AND status = 'running' AND started_at IS NOT NULL
           AND started_at < ? AND retries >= 3",
        [now(), $userId, $cutoff]
    );

    // started_at 一并清空：抢占时用的是 COALESCE(started_at, ?)，
    // 留着旧值会让重排后的任务立刻又被判成卡死。
    q_exec(
        "UPDATE transfer_tasks
         SET status = 'queued', started_at = NULL, retries = retries + 1,
             error = '上一次执行中断，已重新排队'
         WHERE user_id = ? AND status = 'running' AND started_at IS NOT NULL AND started_at < ?",
        [$userId, $cutoff]
    );
}

/**
 * 推进队列。返回本次处理的任务数。
 */
function tasks_tick(int $userId, int $budgetSeconds = TASK_TICK_SECONDS): int
{
    // 先把卡死的任务捞回来：worker 中途崩溃（OOM、被 kill）会让任务永远停在
    // running，而下面只挑 queued，不重置的话它就再也不会被拾取。
    tasks_reap_stuck($userId);

    $deadline = microtime(true) + $budgetSeconds;
    $queued = q_all(
        "SELECT * FROM transfer_tasks WHERE user_id = ? AND status = 'queued'
         ORDER BY priority DESC, id ASC LIMIT 5",
        [$userId]
    );

    $processed = 0;
    foreach ($queued as $task) {
        if (microtime(true) >= $deadline) {
            break;
        }
        // 原子抢占：仅当仍为 queued 时置为 running，成功者才执行，
        // 避免与 tasks_cron / 页面加载并发时同一任务被处理两次
        $claimed = q_exec(
            "UPDATE transfer_tasks SET status = 'running', started_at = COALESCE(started_at, ?)
             WHERE id = ? AND status = 'queued'",
            [now(), (int) $task['id']]
        );
        if ($claimed === 0) {
            continue;
        }

        // 用户可能已经关掉页面，让下载继续跑完当前这一段
        @ignore_user_abort(true);
        @set_time_limit(0);

        if ($task['kind'] === 'offline') {
            task_run_offline($task, $deadline);
        } elseif ($task['kind'] === 'bt') {
            task_run_bt($task);
        } elseif ($task['kind'] === 'push') {
            task_run_push($task);
        } elseif ($task['kind'] === 'purge') {
            task_run_purge($task);
        } else {
            q_update('transfer_tasks', ['status' => 'done', 'progress' => 100, 'finished_at' => now()], 'id = ?', [(int) $task['id']]);
        }
        $processed++;
    }

    return $processed;
}

/** HTTP / FTP 离线下载 */
function task_run_offline(array $task, float $deadline): void
{
    $userId = (int) $task['user_id'];
    $payload = json_decode((string) $task['payload'], true) ?: [];
    $url = (string) ($payload['url'] ?? '');

    q_update('transfer_tasks', [
        'status'     => 'running',
        'started_at' => $task['started_at'] ?? now(),
    ], 'id = ?', [(int) $task['id']]);

    $target = node_get($userId, (int) ($payload['folder'] ?? 0));
    if ($target === null || $target['kind'] !== 'folder') {
        task_fail($task, '目标目录已不存在。');
        return;
    }

    $tmp = task_temp_path((int) $task['id']);
    $offset = is_file($tmp) ? (int) filesize($tmp) : 0;

    $fh = fopen($tmp, $offset > 0 ? 'ab' : 'wb');
    if ($fh === false) {
        task_fail($task, '无法创建临时文件。');
        return;
    }

    $lastWrite = microtime(true);
    $speedBytes = 0;
    $speedAt = microtime(true);
    $blocked = false;

    $ch = curl_init($url);
    $options = [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        // 只允许这几种协议。默认值还包含 dict/gopher/file 等，file:// 能读本机文件。
        CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS | CURLPROTO_FTP,
        CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTP | CURLPROTO_HTTPS | CURLPROTO_FTP,
        // 入队时挡的是最初的主机名，重定向的落点必须再挡一次
        CURLOPT_HEADERFUNCTION   => static function ($ch, string $line) use (&$blocked): int {
            if (preg_match('#^location:\s*(\S+)#i', trim($line), $m) === 1) {
                $h = (string) (parse_url($m[1], PHP_URL_HOST) ?: '');
                if ($h !== '' && host_is_private($h)) {
                    $blocked = true;
                    return 0;   // 返回 0 中断传输
                }
            }
            return strlen($line);
        },
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_LOW_SPEED_LIMIT => 128,
        CURLOPT_LOW_SPEED_TIME => 45,
        CURLOPT_USERAGENT      => 'ShiyunOffline/1.0',
        CURLOPT_FAILONERROR    => true,
        CURLOPT_BUFFERSIZE     => 65536,
        CURLOPT_NOPROGRESS     => false,
        CURLOPT_RESUME_FROM    => $offset,
        CURLOPT_PROGRESSFUNCTION => static function ($res, $dlTotal, $dlNow, $ulTotal, $ulNow) use ($task, $offset, $deadline, &$lastWrite, &$speedBytes, &$speedAt): int {
            // 让出一小段预算后主动中断，把断点留给下一次 tick
            if (microtime(true) >= $deadline) {
                return 1;
            }
            $elapsed = microtime(true) - $speedAt;
            if ($elapsed >= 0.8) {
                $speedBytes = (int) ($dlNow / max(0.001, $elapsed));
                $speedAt = microtime(true);

                $payload = json_decode((string) $task['payload'], true) ?: [];
                $payload['received'] = (int) $offset + (int) $dlNow;
                $payload['total'] = (int) $dlTotal;
                $payload['speed'] = $speedBytes;

                $progress = (int) $dlTotal > 0
                    ? min(99, (int) round(($offset + $dlNow) / ($offset + $dlTotal) * 100))
                    : 0;

                q_update('transfer_tasks', [
                    'payload'  => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'progress' => $progress,
                ], 'id = ?', [(int) $task['id']]);
                $lastWrite = microtime(true);
            }
            return 0;
        },
    ];
    curl_setopt_array($ch, $options);

    $ok = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fh);

    // 重定向跳到了内网：直接判失败，不要按普通网络错误重试三次
    if ($blocked) {
        @unlink($tmp);
        task_fail($task, '重定向指向内网 / 本机地址，已拒绝。');
        return;
    }

    // 预算耗尽：保留断点，下次继续
    if (microtime(true) >= $deadline && $ok === false) {
        q_update('transfer_tasks', ['status' => 'queued'], 'id = ?', [(int) $task['id']]);
        return;
    }

    if ($ok === false || $httpStatus >= 400) {
        $retries = (int) $task['retries'] + 1;
        if ($retries <= 3) {
            q_update('transfer_tasks', [
                'status'  => 'queued',
                'retries' => $retries,
                'error'   => '第 ' . $retries . ' 次重试：' . ($error !== '' ? $error : 'HTTP ' . $httpStatus),
            ], 'id = ?', [(int) $task['id']]);
            return;
        }
        task_fail($task, $error !== '' ? $error : ('HTTP ' . $httpStatus));
        return;
    }

    $size = (int) filesize($tmp);
    if ($size === 0) {
        task_fail($task, '下载到的文件是空的。');
        return;
    }

    try {
        assert_quota($userId, $size);
    } catch (RuntimeException $e) {
        task_fail($task, '空间不足，无法保存。');
        @unlink($tmp);
        return;
    }

    $name = safe_filename($task['title']) ?? ('下载-' . $task['id']);
    $mime = mime_of($name, (string) @file_get_contents($tmp, false, null, 0, 12));
    $blob = blob_ingest_file($tmp, null, $mime);
    $node = node_create_file($userId, (int) $target['id'], $name, $blob, $mime);

    if (str_starts_with($mime, 'image/')) {
        make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
    }
    if (function_exists('search_index_node')) {
        search_index_node($userId, $node);
    }

    q_update('transfer_tasks', [
        'status'      => 'done',
        'progress'    => 100,
        'finished_at' => now(),
        'error'       => null,
    ], 'id = ?', [(int) $task['id']]);

    activity_log($userId, 'task.offline', (int) $node['id'], (string) $node['name']);
    traffic_add($userId, 'down', $size, 1);
}

/** BT / 磁力：没有引擎时如实报错，而不是假装在下载 */
function task_run_bt(array $task): void
{
    $status = task_engine_status();
    if (!$status['btReady']) {
        task_fail($task, '本机未配置 BT 下载引擎，无法执行磁力/种子任务。');
        return;
    }

    $payload = json_decode((string) $task['payload'], true) ?: [];
    $rpc = (string) config('aria2_rpc');
    $secret = (string) config('aria2_secret', '');

    $params = [];
    if ($secret !== '') {
        $params[] = 'token:' . $secret;
    }
    $params[] = (string) ($payload['url'] ?? '');

    $body = json_encode([
        'jsonrpc' => '2.0',
        'id'      => (string) $task['id'],
        'method'  => 'aria2.addUri',
        'params'  => $params,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($rpc);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code >= 400) {
        task_fail($task, '下载引擎无响应：' . ($error !== '' ? $error : ('HTTP ' . $code)));
        return;
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded) || isset($decoded['error'])) {
        task_fail($task, '下载引擎拒绝了任务：' . mb_substr((string) json_encode($decoded['error'] ?? []), 0, 160));
        return;
    }

    $payload['gid'] = (string) ($decoded['result'] ?? '');
    q_update('transfer_tasks', [
        'status'  => 'running',
        'progress' => 1,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'started_at' => now(),
    ], 'id = ?', [(int) $task['id']]);
}

/** 把一份内容推送到存储源 */
function task_run_push(array $task): void
{
    $payload = json_decode((string) $task['payload'], true) ?: [];
    $blobId = (int) ($payload['blob_id'] ?? 0);
    $sourceId = (int) ($payload['source_id'] ?? 0);

    if ($blobId <= 0 || $sourceId <= 0) {
        task_fail($task, '推送任务缺少必要参数。');
        return;
    }

    q_update('transfer_tasks', ['status' => 'running', 'started_at' => now()], 'id = ?', [(int) $task['id']]);

    $result = blob_push_to_source($blobId, $sourceId);

    if ($result['ok']) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'error' => null, 'finished_at' => now(),
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    $retries = (int) $task['retries'] + 1;
    if ($retries <= 3) {
        q_update('transfer_tasks', [
            'status' => 'queued', 'retries' => $retries,
            'error' => '第 ' . $retries . ' 次重试：' . $result['error'],
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    task_fail($task, $result['error']);
}

/**
 * 清理存储源上的副本。
 *
 * 关键护栏：object_key 是内容寻址的，删除后重传同一份内容会落在同一个 key 上，
 * 队列里这个待执行任务会把新对象误删。所以删除前必须复查有没有 blob 仍引用它。
 */
function task_run_purge(array $task): void
{
    $payload = json_decode((string) $task['payload'], true) ?: [];
    $sourceId = (int) ($payload['source_id'] ?? 0);
    $objectKey = (string) ($payload['object_key'] ?? '');

    if ($sourceId <= 0 || $objectKey === '') {
        task_fail($task, '清理任务缺少必要参数。');
        return;
    }

    q_update('transfer_tasks', ['status' => 'running', 'started_at' => now()], 'id = ?', [(int) $task['id']]);

    $stillUsed = (int) q_val(
        'SELECT COUNT(*) FROM blob_locations WHERE source_id = ? AND object_key = ?',
        [$sourceId, $objectKey]
    );
    if ($stillUsed > 0) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'finished_at' => now(),
            'error' => '对象已被新记录引用，跳过删除',
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    $source = q_one('SELECT * FROM storage_sources WHERE id = ?', [$sourceId]);
    if ($source === null) {
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'finished_at' => now(),
            'error' => '存储源已不存在',
        ], 'id = ?', [(int) $task['id']]);
        return;
    }

    try {
        storage_source_delete($source, $objectKey);
        q_update('transfer_tasks', [
            'status' => 'done', 'progress' => 100, 'error' => null, 'finished_at' => now(),
        ], 'id = ?', [(int) $task['id']]);
    } catch (Throwable $e) {
        error_log('清理远程副本失败：' . $e->getMessage());
        $reason = safe_error_message($e, '远程删除失败');
        $retries = (int) $task['retries'] + 1;
        if ($retries <= 3) {
            q_update('transfer_tasks', [
                'status' => 'queued', 'retries' => $retries,
                'error' => '第 ' . $retries . ' 次重试：' . $reason,
            ], 'id = ?', [(int) $task['id']]);
            return;
        }
        task_fail($task, $reason);
    }
}

function task_fail(array $task, string $message): void
{
    q_update('transfer_tasks', [
        'status'      => 'failed',
        'error'       => mb_substr($message, 0, 480),
        'finished_at' => now(),
    ], 'id = ?', [(int) $task['id']]);
}

function task_temp_path(int $taskId): string
{
    $dir = (string) config('trash_path') . '/tasks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . $taskId . '.part';
}

function task_cleanup_temp(array $task): void
{
    $path = task_temp_path((int) $task['id']);
    if (is_file($path)) {
        @unlink($path);
    }
}

/** 目标目录候选（供前端选择） */
function task_folder_options(int $userId): array
{
    $rows = q_all(
        'SELECT id, parent_id, name, is_private, path FROM nodes
         WHERE user_id = ? AND kind = ? AND deleted_at IS NULL ORDER BY name_key ASC',
        [$userId, 'folder']
    );

    $privateId = user_private_id($userId);
    $trashId = user_trash_id($userId);
    $trashPrefix = node_trash_prefix($userId);
    $rootId = user_root_id($userId);

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($id === $trashId) {
            continue;
        }
        // 回收站里被删文件夹的子目录 deleted_at 仍是 NULL，靠路径前缀排除（同 files_tree）
        if ($trashPrefix !== '' && str_starts_with((string) $row['path'], $trashPrefix)) {
            continue;
        }
        if ((int) $row['is_private'] === 1 && !Auth::privateUnlocked()) {
            continue;
        }
        $out[] = [
            'id'     => $id,
            'parent' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name'   => $id === $rootId ? '我的文件' : (string) $row['name'],
            'private' => (int) $row['is_private'] === 1,
            'depth'  => 0,
        ];
    }

    return $out;
}
