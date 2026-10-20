<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/**
 * 路由：`index.php?r=模块.动作` → app/modules/模块.php 中的 `模块_动作()`。
 * 亦兼容 PATH_INFO：/index.php/files/list
 *
 * 未登录可访问的路由列在 PUBLIC_ROUTES 中，其余一律要求登录。
 */
const PUBLIC_ROUTES = [
    'home.index',
    'auth.login',
    'auth.totp',
    'auth.register',
    'auth.forgot',
    'auth.reset',
    'auth.logout',
    'shares.open',
    'shares.unlock',
    'shares.download',
    'shares.upload',
    'shares.browse',
    'shares.raw',
    'rss.feed',
    'webdav.handle',
    'api.handle',
    'tasks.cron',
];

/** 解析出 [模块, 动作] */
function route_parse(): array
{
    $route = (string) ($_GET['r'] ?? '');

    // 兼容 /index.php/files/list 形式的 PATH_INFO
    if ($route === '' && !empty($_SERVER['PATH_INFO'])) {
        $route = trim((string) $_SERVER['PATH_INFO'], '/');
        $route = str_replace('/', '.', $route);
    }

    if ($route === '') {
        return ['', ''];
    }

    $route = preg_replace('/[^A-Za-z0-9_.]/', '', $route) ?? '';
    if ($route === '' || !str_contains($route, '.')) {
        return ['', ''];
    }

    // WebDAV 客户端会把子路径直接缀在入口后面（…?r=webdav.handle/设计稿），
    // 这里统一收敛到 webdav.handle，具体路径交给模块自己解析。
    if (str_starts_with($route, 'webdav')) {
        return ['webdav', 'handle'];
    }

    [$module, $action] = explode('.', $route, 2);
    return [$module, $action];
}

function route_dispatch(): void
{
    [$module, $action] = route_parse();

    if ($module === '') {
        // 默认入口：官网首页；已登录用户直接进网盘
        redirect(url(Auth::check() ? 'files.list' : 'home.index'));
    }

    $name = $module . '.' . $action;

    $file = BASE_PATH . '/app/modules/' . $module . '.php';
    if (!is_file($file)) {
        route_not_found();
    }

    require_once $file;

    $fn = $module . '_' . $action;
    if (!function_exists($fn)) {
        route_not_found();
    }

    if (!in_array($name, PUBLIC_ROUTES, true)) {
        require_auth();
    }

    $fn();
}

function route_not_found(): never
{
    if (wants_json()) {
        json_fail('接口不存在', 404, 'not_found');
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>404</title>';
    echo '<body style="background:#08090b;color:#f4f2ec;font:16px/1.6 system-ui;padding:12vh 8vw">';
    echo '<h1 style="font-size:1.6rem;letter-spacing:-.02em">找不到这个页面</h1>';
    echo '<p style="color:rgba(244,242,236,.6)">链接可能已失效，或地址输入有误。</p>';
    echo '<p><a href="' . e(url('')) . '" style="color:#8a88ff">返回我的文件</a></p>';
    exit;
}
