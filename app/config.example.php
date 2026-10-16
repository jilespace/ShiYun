<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

// 拾云 · 配置模板
// 复制为 app/config.php 再填自己的值；该文件已被 .gitignore 排除，不会入库。
//
// 需要填的：
//   db.pass       MySQL 密码
//   cron_key      计划任务密钥，随便一串随机 hex 即可（越随机越好）
//   aria2_rpc     可选；填了才能下 BT/磁力，本机没装引擎就留空
return [
    'app_name'    => '拾云',
    'app_name_en' => 'SHIYUN',
    'version'     => '2.0.0',

    // ---------------------------------------------------------------- 数据库
    'db' => [
        'driver'  => 'mysql',
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'shiyun',
        'user'    => 'shiyun',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ------------------------------------------------------------ 运行时目录
    // runtime/ 必须被 Web 服务器拒绝直接访问（用户文件只能经 PHP 鉴权后读取）。
    // nginx 请配置：location ^~ /runtime/ { deny all; return 404; }
    'session_path' => BASE_PATH . '/runtime/sessions',
    'storage_path' => BASE_PATH . '/runtime/storage',   // 内容寻址文件仓
    'chunk_path'   => BASE_PATH . '/runtime/chunks',    // 分片上传暂存
    'thumb_path'   => BASE_PATH . '/runtime/thumbs',    // 缩略图
    'trash_path'   => BASE_PATH . '/runtime/trash',     // 离线归档暂存

    // ---------------------------------------------------------------- 配额
    'default_quota' => 1024 ** 4,   // 新用户默认 1 TiB

    // ---------------------------------------------------------------- 安全
    'max_login_attempts' => 5,
    'lockout_seconds'    => 900,
    'reset_ttl'          => 1800,   // 找回密码令牌有效期
    'trash_retention'    => 30,     // 回收站默认保留天数
    // 找回密码时是否把重置链接直接回显在页面。
    // 仅本机/单人自部署可开启；多用户环境务必保持 false，否则任何人凭邮箱即可接管账号。
    'reset_link_in_response' => false,
    // 是否开放自助注册。个人自用保持 false——登录页会显示「注册已关闭」，
    // 注册接口同时被服务端拒绝（不是只藏起入口）。多人共用才改成 true。
    'allow_registration'     => false,
    // PHP 与 MySQL 会话统一使用的时区
    'timezone'               => 'Asia/Shanghai',

    // ---------------------------------------------------------------- 上传
    'chunk_size'   => 5 * 1024 * 1024,   // 分片大小 5 MiB
    'max_upload'   => 200 * 1024 ** 3,   // 单文件上限 200 GiB
    'max_extract'  => 20 * 1024 ** 3,    // 在线解压上限
    'max_pack'     => 2 * 1024 ** 3,     // 打包下载总量上限

    // ---------------------------------------------------------------- 集成
    // 外部下载引擎（aria2 等）。未安装时离线下载仅支持 HTTP/FTP，BT/磁力会明确报错
    'aria2_rpc'    => '',   // 例如 http://127.0.0.1:6800/jsonrpc
    'aria2_secret' => '',

    // ---------------------------------------------------------- 存储源直链
    // 预签名 URL 有效期（秒）。媒体元素开始拉流后会用同一 URL 连续发 Range 请求，
    // 中途过期会断流，所以留足 2 小时。
    'presign_ttl'  => 7200,

    // ------------------------------------------------------------ 计划任务
    // 队列默认只在页面加载时推进；配上计划任务后即使没人开页面也会继续跑。
    // 在宝塔「计划任务」里建一条「访问 URL」，每分钟请求：
    //   https://你的域名/index.php?r=tasks.cron&key=<下面的密钥>
    'cron_key'     => '',
];
