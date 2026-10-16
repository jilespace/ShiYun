<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$settings = (string) file_get_contents(BASE_PATH . '/app/views/settings.php');
check(str_contains($settings, 'COS'), '设置页限速项旁应说明对 COS 直链无效');

$api = (string) file_get_contents(BASE_PATH . '/app/modules/api.php');
check(str_contains($api, '302'), '接口说明应写明下载会 302');

finish();
