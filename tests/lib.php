<?php
declare(strict_types=1);

// 测试脚本只能从命令行运行。站点根目录包含 tests/，Web 直接请求
// 这些文件会真的执行建库/删用户等测试逻辑，必须挡掉。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

// 未捕获异常必须以非零码退出。
// app/bootstrap.php 的处理器末尾是裸 `exit;`（HTTP 场景下是对的），
// CLI 下会让失败的测试假绿，所以这里覆盖掉。
set_exception_handler(static function (Throwable $e): void {
    $message = "\n未捕获异常：" . get_class($e) . ': ' . $e->getMessage() . "\n"
        . $e->getFile() . ':' . $e->getLine() . "\n";
    // 正常只会走 CLI；万一被其他 SAPI 引入，STDERR 未定义也不能再抛致命错误。
    if (defined('STDERR')) {
        fwrite(STDERR, $message);
    } else {
        error_log(trim($message));
    }
    exit(1);
});

$GLOBALS['__failures'] = 0;

function check(bool $cond, string $message): void
{
    if ($cond) {
        echo "  ok   {$message}\n";
        return;
    }
    $GLOBALS['__failures']++;
    echo "  FAIL {$message}\n";
}

function finish(): never
{
    $n = (int) $GLOBALS['__failures'];
    echo $n === 0 ? "\n全部通过\n" : "\n{$n} 项失败\n";
    exit($n === 0 ? 0 : 1);
}
