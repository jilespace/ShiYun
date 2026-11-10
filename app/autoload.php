<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   极简自动加载。
   约定：命名空间 Shiyun\Lib\Foo → app/lib/Foo.php。
   历史全局函数仍由 bootstrap 显式 require；新代码优先写成命名空间类，
   避免继续把函数堆进全局作用域。
   ========================================================================= */

spl_autoload_register(static function (string $class): void {
    // 目录名是 app/lib（小写），命名空间按惯例写成 Shiyun\Lib，这里显式映射。
    $prefixes = [
        'Shiyun\\Lib\\' => BASE_PATH . '/app/lib/',
    ];

    foreach ($prefixes as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        // 类名只可能由字母/数字/下划线/命名空间分隔符组成；拒绝异常输入拼出路径。
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
            return;
        }
        $file = $dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});
