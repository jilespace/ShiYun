<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* --------------------------------------------------------------- 官网首页 */

/**
 * 单页官网：项目的默认入口。
 * 未登录访客在这里了解产品，再经由页面上的入口进入登录 / 注册；
 * 已登录用户直接进入网盘（见 router.php 的默认跳转）。
 *
 * registration_open() 定义在 auth 模块中，这里仅取其函数定义。
 */
require_once __DIR__ . '/auth.php';

function home_index(): void
{
    render('home', [
        'title'     => (string) config('app_name') . ' · 自托管的个人网盘',
        'loggedIn'  => Auth::check(),
        'allowRegistration' => registration_open(),
        'firstRun'          => registration_state() === 'bootstrap',
        'bodyClass' => 'is-home',
        'styles'    => ['/assets/css/home.css'],
        'scripts'   => ['/assets/js/home.js'],
    ]);
}
