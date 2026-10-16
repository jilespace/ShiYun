<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// 未捕获异常必须以非零码退出。
// app/bootstrap.php 的处理器末尾是裸 `exit;`（HTTP 场景下是对的），
// CLI 下会让失败的测试假绿，所以这里覆盖掉。
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, "\n未捕获异常：" . get_class($e) . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
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
