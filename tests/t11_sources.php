<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$src = (string) file_get_contents(BASE_PATH . '/app/modules/sources.php');
// 注意：早期写的 `!str_contains($src, 'n.source_id')` 是个近乎空转的断言——
// `n.source_id` 并不是 `nodes.source_id` 的子串（点号前一个是 s 不是 n），
// 所以 sources.php 里留着 `nodes.source_id` 它照样通过。两种写法都要查。
check(!str_contains($src, 'nodes.source_id'), 'sources.php 不得再引用 nodes.source_id');
check(!preg_match('/\bn\.source_id\b/', $src), 'sources.php 不得再以别名引用 source_id');
check(!str_contains($src, 'UPDATE nodes SET source_id'), 'sources.php 不得再写 nodes.source_id');
check(str_contains($src, 'blob_locations'), 'sources.php 应改用 blob_locations');
check(str_contains($src, 'blob_push_to_source('), 'sources_push 应复用 blob_push_to_source');

check(
    !q_val("SHOW COLUMNS FROM nodes LIKE 'source_id'"),
    'nodes.source_id 应已在本任务被删除（裁定 R2）'
);

finish();
