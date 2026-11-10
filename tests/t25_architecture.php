<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   架构回归：
   1) Shiyun\Lib\* 能被自动加载（不依赖 bootstrap 里的显式 require）。
   2) NodeView 是行/卡片标记的唯一来源，且对文件名做 HTML 转义。
   ========================================================================= */

check(class_exists(\Shiyun\Lib\Locations::class), '自动加载 Shiyun\\Lib\\Locations');
check(class_exists(\Shiyun\Lib\QrCode::class), '自动加载 Shiyun\\Lib\\QrCode');
check(class_exists(\Shiyun\Lib\NodeView::class), '自动加载 Shiyun\\Lib\\NodeView');

$node = [
    'id'          => 42,
    'kind'        => 'file',
    'name'        => '<b>危险</b>.txt',
    'type'        => 'text',
    'size'        => 1,
    'sizeText'    => '1 B',
    'mime'        => 'text/plain',
    'ext'         => 'txt',
    'icon'        => 'file',
    'favorite'    => false,
    'hidden'      => false,
    'locked'      => false,
    'private'     => false,
    'color'       => null,
    'note'        => '',
    'tags'        => [],
    'childCount'  => 0,
    'shareCount'  => 0,
    'directCount' => 1,
    'memberCount' => 0,
    'hasThumb'    => false,
    'cosStatus'   => '',
    'updated'     => '2026-01-01 00:00:00',
    'updatedText' => '刚刚',
    'created'     => '2026-01-01 00:00:00',
    'deleted'     => null,
];

$row = \Shiyun\Lib\NodeView::row($node, 0, false);
check(str_contains($row, '&lt;b&gt;危险&lt;/b&gt;.txt'), 'NodeView 转义文件名');
check(!str_contains($row, '<b>危险</b>'), 'NodeView 不输出原始 HTML');
check(str_contains($row, 'style="--i:0"'), 'NodeView 写入行序号');
check(str_contains($row, '有直链'), 'NodeView 保留直链角标');

$grid = \Shiyun\Lib\NodeView::fragment([$node, $node], 'grid', 5, false);
check(substr_count($grid, 'class="card"') === 2, 'fragment 按视图输出卡片');
check(str_contains($grid, 'style="--i:6"'), 'fragment 从指定 offset 继续编号');

finish();
