<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** HTML 转义输出 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['config'][$key] ?? $default;
}

/** 拼接内部路由 URL */
function url(string $route = '', array $params = []): string
{
    if ($route === '') {
        return 'index.php';
    }
    $query = ['r' => $route] + $params;
    return 'index.php?' . http_build_query($query);
}

/**
 * 静态资源 URL：按文件修改时间附带版本号。
 *
 * 不能拿固定的 app 版本号当 ?v= —— 文件改了而版本号没改，浏览器会按
 * Cache-Control 的强缓存继续用旧文件，改动等于根本没发出去。
 */
function asset_url(string $path): string
{
    $file = BASE_PATH . $path;
    $version = is_file($file) ? (string) filemtime($file) : (string) config('version');

    return $path . '?v=' . $version;
}

function redirect(string $target): never
{
    header('Location: ' . $target, true, 302);
    exit;
}

/** 渲染视图，$data 中的键会作为变量注入 */
function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require BASE_PATH . '/app/views/' . $name . '.php';
}

/** 用布局包裹内容并输出 */
function render(string $viewName, array $data = []): void
{
    ob_start();
    view($viewName, $data);
    $content = ob_get_clean();
    view('layout', array_merge($data, ['content' => $content]));
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
}

/** 从 UA 粗略识别设备，用于登录日志 */
function detect_device(string $ua): string
{
    $os = '未知系统';
    foreach ([
        'Windows NT 10' => 'Windows',
        'Windows'       => 'Windows',
        'iPhone'        => 'iPhone',
        'iPad'          => 'iPad',
        'Android'       => 'Android',
        'Mac OS X'      => 'macOS',
        'Linux'         => 'Linux',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            $os = $label;
            break;
        }
    }

    $browser = '未知浏览器';
    foreach ([
        'Edg'      => 'Edge',
        'OPR'      => 'Opera',
        'Chrome'   => 'Chrome',
        'Safari'   => 'Safari',
        'Firefox'  => 'Firefox',
        'curl'     => 'curl',
    ] as $needle => $label) {
        if (stripos($ua, $needle) !== false) {
            $browser = $label;
            break;
        }
    }

    return $os . ' · ' . $browser;
}

/** 人类可读的字节数，保留 1 位小数 */
function format_bytes(float $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $value = $i === 0 ? (string) (int) $bytes : number_format($bytes, $precision);
    return $value . ' ' . $units[$i];
}

/** 相对时间：刚刚 / 12 分钟前 / 3 天前 */
function time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return (int) ($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return (int) ($diff / 3600) . ' 小时前';
    }
    if ($diff < 86400 * 30) {
        return (int) ($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

/** 生成 URL 安全随机串 */
function random_token(int $bytes = 16): string
{
    return bin2hex(random_bytes($bytes));
}

function display_name_from_email(string $email): string
{
    $prefix = strstr($email, '@', true);
    return $prefix !== false && $prefix !== '' ? $prefix : $email;
}

/** 文件名扩展名（小写，不含点） */
function ext_of(string $filename): string
{
    $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    return mb_substr($ext, 0, 32);
}

/**
 * 由扩展名推断 MIME。fileinfo 未编译进本机 PHP，故用扩展名表 + 魔数嗅探。
 */
function mime_of(string $filename, ?string $head = null): string
{
    static $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'avif' => 'image/avif',
        'heic' => 'image/heic', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',

        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo', 'flv' => 'video/x-flv',
        'm4v' => 'video/x-m4v', 'ts' => 'video/mp2t', 'wmv' => 'video/x-ms-wmv',

        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
        'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'm4a' => 'audio/mp4', 'wma' => 'audio/x-ms-wma', 'opus' => 'audio/opus',

        'pdf' => 'application/pdf',
        'txt' => 'text/plain', 'log' => 'text/plain', 'ini' => 'text/plain',
        'md' => 'text/markdown', 'markdown' => 'text/markdown',
        'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values',
        'html' => 'text/html', 'htm' => 'text/html', 'xml' => 'text/xml',
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
        'json' => 'application/json', 'yml' => 'text/yaml', 'yaml' => 'text/yaml',
        'sql' => 'text/plain', 'sh' => 'text/x-sh', 'py' => 'text/x-python',
        'php' => 'text/x-php', 'java' => 'text/x-java', 'c' => 'text/x-c',
        'h' => 'text/x-c', 'cpp' => 'text/x-c++', 'go' => 'text/x-go',
        'rs' => 'text/x-rust', 'rb' => 'text/x-ruby', 'ts' => 'text/typescript',

        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',

        'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar',
        'gz' => 'application/gzip', 'bz2' => 'application/x-bzip2',
        'xz' => 'application/x-xz', 'tgz' => 'application/gzip',

        'epub' => 'application/epub+zip', 'torrent' => 'application/x-bittorrent',
        'apk' => 'application/vnd.android.package-archive',
        'exe' => 'application/vnd.microsoft.portable-executable',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];

    $ext = ext_of($filename);
    if (isset($map[$ext])) {
        return $map[$ext];
    }

    if ($head !== null) {
        $sig = substr($head, 0, 12);
        if (str_starts_with($sig, "\xFF\xD8\xFF")) return 'image/jpeg';
        if (str_starts_with($sig, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($sig, 'GIF8')) return 'image/gif';
        if (str_starts_with($sig, 'RIFF') && substr($sig, 8, 4) === 'WEBP') return 'image/webp';
        if (str_starts_with($sig, '%PDF')) return 'application/pdf';
        if (str_starts_with($sig, 'PK')) return 'application/zip';
        if (str_starts_with($sig, "\x1f\x8b")) return 'application/gzip';
        if (str_starts_with($sig, 'Rar!')) return 'application/vnd.rar';
    }

    return 'application/octet-stream';
}

/** 归类：image / video / audio / document / archive / code / other */
function kind_of(string $mime, string $ext = ''): string
{
    if (str_starts_with($mime, 'image/')) return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    if (str_starts_with($mime, 'audio/')) return 'audio';
    if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'], true)) return 'archive';
    if (in_array($ext, ['js', 'mjs', 'ts', 'php', 'py', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'rb', 'sh', 'sql', 'css', 'html', 'htm', 'json', 'yml', 'yaml', 'xml'], true)) return 'code';
    if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'txt', 'md', 'markdown', 'csv', 'tsv', 'odt', 'epub'], true)) return 'document';
    return 'other';
}

/** 该 MIME 是否可作为文本在浏览器内预览 */
function is_textual(string $mime, string $ext): bool
{
    if (str_starts_with($mime, 'text/')) {
        return true;
    }
    return in_array($mime, ['application/json', 'application/xml', 'application/javascript'], true)
        || in_array($ext, ['md', 'markdown', 'log', 'ini', 'conf', 'env', 'sql', 'sh', 'bat', 'ps1', 'toml', 'lock'], true);
}

/**
 * 该 MIME 是否可以作为内联内容发给浏览器。
 *
 * 白名单而非黑名单——黑名单永远漏。能进来的必须是浏览器会当作「数据」
 * 渲染的类型；text/html、image/svg+xml 这类可以执行脚本的一律排除，
 * 否则在站点同源下就是存储型 XSS。
 */
function mime_inline_ok(string $mime): bool
{
    $mime = strtolower(trim(explode(';', $mime)[0]));

    if (in_array($mime, [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/avif', 'image/bmp', 'application/pdf', 'text/plain',
    ], true)) {
        return true;
    }

    return str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/');
}

/**
 * 校验并规范化用户输入的文件名。
 * 拒绝路径穿越、控制字符与保留名。
 */
function safe_filename(string $name): ?string
{
    $name = trim($name);
    $name = str_replace(["\0", '/', '\\'], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);

    if ($name === '' || $name === '.' || $name === '..') {
        return null;
    }
    if (mb_strlen($name) > 200) {
        return null;
    }
    return $name;
}

/** 同名冲突时追加 " (2)"、" (3)"… */
function unique_name(string $name, callable $exists, bool $isFolder = false): string
{
    if (!$exists($name)) {
        return $name;
    }
    if ($isFolder) {
        $base = $name;
        $ext = '';
    } else {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? '.' . $ext : '';
        $base = mb_substr($name, 0, mb_strlen($name) - mb_strlen($ext));
    }

    for ($i = 2; $i < 1000; $i++) {
        $candidate = $base . ' (' . $i . ')' . $ext;
        if (!$exists($candidate)) {
            return $candidate;
        }
    }
    return $base . ' (' . bin2hex(random_bytes(3)) . ')' . $ext;
}
