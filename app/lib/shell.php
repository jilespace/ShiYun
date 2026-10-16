<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   外部命令探测。
   本机 open_basedir 只放开了站点目录与 /tmp，所以 is_executable('/usr/bin/x')
   会被直接拒绝——但 exec 本身不受 open_basedir 限制。因此统一改用
   `command -v` 来问 shell：这样既绕开限制，也不用写死路径。
   ========================================================================= */

/** 命令名白名单校验，杜绝把用户输入拼进 shell */
function safe_command(string $command): ?string
{
    return preg_match('/^[A-Za-z0-9._-]+$/', $command) === 1 ? $command : null;
}

/**
 * 本机是否允许执行外部进程。
 * 这台机器的 php.ini 把 exec / proc_open / shell_exec / popen / system 全部禁用，
 * 所以凡是依赖外部程序的路径都必须回退或明确报错，而不是假装能用。
 */
function shell_available(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('exec') && function_exists('escapeshellarg');
    }
    return $ok;
}

/** 该命令是否存在（进程执行被禁用时恒为 false） */
function has_command(string $command): bool
{
    static $cache = [];

    $command = safe_command($command);
    if ($command === null || !shell_available()) {
        return false;
    }
    if (array_key_exists($command, $cache)) {
        return $cache[$command];
    }

    $out = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);

    return $cache[$command] = ($code === 0 && $out !== []);
}

/** 取命令的绝对路径（不可用则 null） */
function command_path(string $command): ?string
{
    $command = safe_command($command);
    if ($command === null || !has_command($command)) {
        return null;
    }

    $out = [];
    $code = 1;
    @exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $out, $code);

    $path = trim((string) ($out[0] ?? ''));

    // open_basedir 之外的路径不能直接读，但可以直接执行，所以照样返回
    return $path !== '' ? $path : $command;
}

/**
 * 运行一条命令，返回 [退出码, 合并输出]。
 * $args 里的每一项都会被 escapeshellarg，调用方不需要自己转义。
 */
function run_command(string $command, array $args = [], ?string $stdin = null, int $timeout = 0): array
{
    if (!shell_available()) {
        return [127, '服务器禁用了进程执行函数，无法调用外部命令。'];
    }

    $path = command_path($command);
    if ($path === null) {
        return [127, '命令不可用：' . $command];
    }

    $parts = [escapeshellarg($path)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg((string) $arg);
    }

    $cmd = implode(' ', $parts) . ' 2>&1';

    if ($stdin !== null) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [127, '无法启动：' . $command];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out . $err];
    }

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    return [$code, implode("\n", $out)];
}
