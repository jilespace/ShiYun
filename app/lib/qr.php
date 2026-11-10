<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* 兼容垫片：历史代码仍可使用全局 QrCode。
   新代码请直接依赖 Shiyun\Lib\QrCode（由 app/autoload.php 自动加载）。 */
require_once __DIR__ . '/QrCode.php';

if (!class_exists('QrCode', false)) {
    class_alias(\Shiyun\Lib\QrCode::class, 'QrCode');
}
