<?php

declare(strict_types=1);
require __DIR__ . '/lib.php';

/* =========================================================================
   WebDAV 路径解析回归。

   不少客户端（含 cc-switch）把基址写成 index.php?r=webdav.handle，
   但子路径拼在 index.php 之后，集合请求还会在查询串里留下尾斜杠：

       MKCOL   /index.php/cc-switch-sync?r=webdav.handle/
       PROPFIND/index.php/cc-switch-sync/v2?r=webdav.handle/
       PUT     /index.php/cc-switch-sync/v2/default/db.sql?r=webdav.handle

   此时 r 只有入口名，真正的子路径在 PATH_INFO。若 webdav_path() 不回落，
   MKCOL 会全部作用到根目录（405），目录永远建不出来，随后的 PUT 因父目录
   不存在返回 409。
   ========================================================================= */

require_once BASE_PATH . '/app/modules/webdav.php';

function wd_path_with(array $get, array $server): string
{
    $savedGet = $_GET;
    $savedServer = $_SERVER;
    $_GET = $get;
    $_SERVER = $server;
    $path = webdav_path();
    $_GET = $savedGet;
    $_SERVER = $savedServer;
    return $path;
}

check(
    wd_path_with(['r' => 'webdav.handle/'], ['PATH_INFO' => '/cc-switch-sync/v2/db-v6/default']) === 'cc-switch-sync/v2/db-v6/default',
    'r 只有入口名且带尾斜杠时，回落到 PATH_INFO'
);
check(
    wd_path_with(['r' => 'webdav.handle'], ['PATH_INFO' => '/cc-switch-sync/v2/db-v6/default/db.sql']) === 'cc-switch-sync/v2/db-v6/default/db.sql',
    'r 只有入口名时，回落到 PATH_INFO'
);
check(
    wd_path_with(['r' => 'webdav.handle/设计稿'], []) === '设计稿',
    '子路径直接跟在 r 后面时仍然有效'
);
check(
    wd_path_with(['r' => 'webdav.handle'], []) === '',
    '没有任何子路径时解析为根目录'
);

finish();
