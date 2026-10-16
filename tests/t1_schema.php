<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 触发迁移
db();

check(
    (bool) q_val("SHOW TABLES LIKE 'blob_locations'"),
    'blob_locations 表应已创建'
);

$cols = array_column(q_all("SHOW COLUMNS FROM blob_locations"), 'Field');
foreach (['blob_id', 'source_id', 'object_key', 'size', 'status', 'error', 'updated_at'] as $c) {
    check(in_array($c, $cols, true), "blob_locations 应有列 {$c}");
}

$statusType = '';
foreach (q_all('SHOW COLUMNS FROM blob_locations') as $row) {
    if ($row['Field'] === 'status') {
        $statusType = (string) $row['Type'];
    }
}
check(str_contains($statusType, 'stale'), 'status 枚举应含 stale');

check(
    !q_val("SHOW COLUMNS FROM nodes LIKE 'source_id'"),
    'nodes.source_id 应在 Task 11 被删除'
);

finish();
