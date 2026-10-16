<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$cols = array_column(q_all('SHOW COLUMNS FROM upload_sessions'), 'Field');
check(in_array('source_id', $cols, true), 'upload_sessions 应新增 source_id 列');

$src = (string) file_get_contents(BASE_PATH . '/app/modules/upload.php');
// 两处：秒传分支（内容本地已有，前端不会走到合并）+ 合并完成分支
check(substr_count($src, 'tasks_enqueue_push(') === 2,
    'upload.php 应在秒传与合并两处都入队 push 任务');

$api = (string) file_get_contents(BASE_PATH . '/app/modules/api.php');
check(str_contains($api, "input('source'") || str_contains($api, "\$body['source']"),
    'api.php 上传应接收 source 参数');

finish();
