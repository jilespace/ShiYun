<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   WebDAV：把网盘挂载成网络驱动器。
   入口 index.php?r=webdav.handle，子路径直接跟在后面（如 …/webdav.handle/设计稿）。
   认证用网盘账号密码，或把 API 令牌当密码用；账号开启两步验证后只接受 API 令牌。
   ========================================================================= */

function webdav_handle(): void
{
    $user = webdav_auth();
    if ($user === null) {
        header('WWW-Authenticate: Basic realm="拾云 WebDAV"');
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        exit("需要认证：请使用网盘账号（邮箱或用户名 + 密码），或把 API 令牌当密码。\n"
            . "若该账号已开启两步验证，登录密码会被拒绝——请改用 API 令牌（设置 → API 令牌）。\n");
    }

    $userId = (int) $user['id'];
    $path = webdav_path();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    header('DAV: 1, 2, 3');
    header('MS-Author-Via: DAV');
    header('Accept-Ranges: bytes');

    switch ($method) {
        case 'OPTIONS':
            header('Allow: OPTIONS, GET, HEAD, PUT, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK');
            http_response_code(200);
            exit;

        case 'PROPFIND':
            webdav_propfind($userId, $path);
            break;

        case 'PROPPATCH':
            webdav_multistatus([webdav_propstat($path, 200)]);
            break;

        case 'GET':
        case 'HEAD':
            webdav_get($userId, $path, $method === 'HEAD');
            break;

        case 'PUT':
            webdav_put($userId, $path);
            break;

        case 'MKCOL':
            webdav_mkcol($userId, $path);
            break;

        case 'DELETE':
            webdav_delete($userId, $path);
            break;

        case 'MOVE':
            webdav_move($userId, $path, false);
            break;

        case 'COPY':
            webdav_move($userId, $path, true);
            break;

        case 'LOCK':
            webdav_lock();
            break;

        case 'UNLOCK':
            http_response_code(204);
            exit;

        default:
            http_response_code(405);
            exit;
    }
}

/** 认证：Basic（账号密码）或 Bearer / 密码位放 API 令牌 */
function webdav_auth(): ?array
{
    $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

    if (preg_match('/Bearer\s+(\S+)/i', $header, $m) === 1) {
        $user = api_token_user_from_string($m[1]);
        if ($user !== null) {
            return $user;
        }
    }

    if (preg_match('/Basic\s+(\S+)/i', $header, $m) !== 1) {
        // 有些客户端不带 Authorization，用 PHP_AUTH_* 兜底
        $login = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $secret = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        if ($login === '') {
            return null;
        }
    } else {
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$login, $secret] = explode(':', $decoded, 2);
    }

    $login = trim((string) $login);
    $secret = (string) $secret;

    // 令牌当密码用（很多客户端不方便发 Bearer）
    if (str_starts_with($secret, 'sy_')) {
        $byToken = api_token_user_from_string($secret);
        if ($byToken !== null) {
            return $byToken;
        }
    }

    // 与 Web 登录共用失败计数/锁定，避免 Basic Auth 被无限暴力破解
    if (login_locked($login, client_ip())) {
        header('WWW-Authenticate: Basic realm="拾云 WebDAV"');
        http_response_code(429);
        header('Content-Type: text/plain; charset=utf-8');
        exit("尝试次数过多，请稍后再试。\n");
    }

    $user = find_user_by_login($login);
    if ($user === null || $user['status'] !== 'active') {
        log_login(null, $login, 'fail', 'WebDAV 账号不存在或已停用');
        return null;
    }
    // 账号开启两步验证后，登录密码不再被 WebDAV 接受。
    // 否则「邮箱 + 密码 + 任意 WebDAV 客户端」这条路径就完整绕过了第二因子。
    // 客户端不方便发 Bearer，但可以把 API 令牌填在密码位（上面的 sy_ 分支已处理）。
    if ((int) $user['totp_enabled'] === 1) {
        log_login((int) $user['id'], $login, 'fail', 'WebDAV 拒绝：已开启两步验证，需改用 API 令牌');
        return null;
    }

    if (!password_verify($secret, (string) $user['password_hash'])) {
        log_login((int) $user['id'], $login, 'fail', 'WebDAV 密码错误');
        return null;
    }

    log_login((int) $user['id'], $login, 'success', 'WebDAV');

    return $user;
}

function api_token_user_from_string(string $token): ?array
{
    $row = q_one('SELECT * FROM api_tokens WHERE token_hash = ?', [hash('sha256', trim($token))]);
    if ($row === null) {
        return null;
    }
    if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
        return null;
    }
    q_exec('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now(), (int) $row['id']]);

    // 停用的账号必须在这里就挡掉。Basic 分支查了 status，令牌分支不查的话，
    // 一个被停用的账号只要手里还有令牌，就照样能经 WebDAV 读、写、删。
    $user = find_user_by_id((int) $row['user_id']);
    if ($user === null || $user['status'] !== 'active') {
        return null;
    }

    return $user;
}

/** 取出 WebDAV 子路径 */
function webdav_path(): string
{
    $raw = (string) ($_GET['r'] ?? '');
    $sub = '';
    if (str_starts_with($raw, 'webdav.handle')) {
        $sub = substr($raw, strlen('webdav.handle'));
    }
    if ($sub === '' && !empty($_SERVER['PATH_INFO'])) {
        $sub = (string) $_SERVER['PATH_INFO'];
    }

    $sub = rawurldecode($sub);
    $sub = str_replace('\\', '/', $sub);
    $sub = preg_replace('#/+#', '/', $sub) ?? '';

    return trim($sub, '/');
}

/** 路径 → 节点；不存在返回 null */
function webdav_node(int $userId, string $path): ?array
{
    $rootId = user_root_id($userId);
    if ($path === '') {
        return node_get($userId, $rootId);
    }

    $parentId = $rootId;
    $parts = array_filter(explode('/', $path), static fn (string $p): bool => $p !== '');
    $current = null;

    foreach ($parts as $part) {
        $current = q_one(
            'SELECT * FROM nodes WHERE user_id = ? AND parent_id = ? AND name_key = ? AND deleted_at IS NULL LIMIT 1',
            [$userId, $parentId, mb_strtolower($part)]
        );
        if ($current === null) {
            return null;
        }
        // 私密空间不在 WebDAV 暴露
        if ((int) $current['is_private'] === 1) {
            return null;
        }
        $parentId = (int) $current['id'];
    }

    return $current;
}

/** 父目录 id（用于 PUT/MKCOL 创建） */
function webdav_parent_id(int $userId, string $path): int
{
    $parts = array_filter(explode('/', $path), static fn (string $p): bool => $p !== '');
    array_pop($parts);

    if ($parts === []) {
        return user_root_id($userId);
    }

    $parent = webdav_node($userId, implode('/', $parts));

    return $parent !== null ? (int) $parent['id'] : 0;
}

/* ------------------------------------------------------------- PROPFIND */

function webdav_propfind(int $userId, string $path): void
{
    $node = webdav_node($userId, $path);
    if ($node === null) {
        http_response_code(404);
        exit;
    }

    $depth = (string) ($_SERVER['HTTP_DEPTH'] ?? '1');
    $entries = [[$node, $path]];

    if ($node['kind'] === 'folder' && $depth !== '0') {
        foreach (node_children($userId, (int) $node['id'], ['sort' => 'name', 'dir' => 'asc', 'showHidden' => false]) as $child) {
            $childPath = $path === '' ? (string) $child['name'] : $path . '/' . (string) $child['name'];
            $entries[] = [$child, $childPath];
        }
    }

    $responses = [];
    foreach ($entries as [$entry, $entryPath]) {
        $responses[] = webdav_response($entry, $entryPath);
    }

    webdav_multistatus($responses);
}

function webdav_response(array $node, string $hrefPath): string
{
    $isFolder = $node['kind'] === 'folder';
    $name = (string) $node['name'];

    // 逐段编码，保留路径分隔符
    $encoded = implode('/', array_map(
        static fn (string $segment): string => rawurlencode($segment),
        array_filter(explode('/', $hrefPath), static fn (string $s): bool => $s !== '')
    ));
    $href = '/' . url('webdav.handle') . ($encoded !== '' ? '/' . $encoded : '');

    $modified = gmdate('D, d M Y H:i:s \G\M\T', (int) strtotime((string) $node['updated_at']));
    $length = $isFolder ? 0 : (int) $node['size'];
    $mime = $isFolder ? 'httpd/unix-directory' : (string) ($node['mime'] ?? 'application/octet-stream');

    $body = '<D:response>'
        . '<D:href>' . e($href) . '</D:href>'
        . '<D:propstat><D:prop>'
        . '<D:displayname>' . e($name) . '</D:displayname>'
        . '<D:getlastmodified>' . $modified . '</D:getlastmodified>'
        . '<D:getcontentlength>' . $length . '</D:getcontentlength>'
        . '<D:getcontenttype>' . e($mime) . '</D:getcontenttype>'
        . '<D:creationdate>' . gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $node['created_at'])) . '</D:creationdate>'
        . '<D:resourcetype>' . ($isFolder ? '<D:collection/>' : '') . '</D:resourcetype>'
        . '<D:supportedlock><D:lockentry><D:lockscope><D:exclusive/></D:lockscope>'
        . '<D:locktype><D:write/></D:locktype></D:lockentry></D:supportedlock>'
        . '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat>'
        . '</D:response>';

    return $body;
}

function webdav_propstat(string $path, int $code): string
{
    return '<D:response><D:href>/' . e(url('webdav.handle') . '/' . $path) . '</D:href>'
        . '<D:propstat><D:prop/><D:status>HTTP/1.1 ' . $code . ' OK</D:status></D:propstat></D:response>';
}

function webdav_multistatus(array $responses): void
{
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    echo '<D:multistatus xmlns:D="DAV:">' . implode('', $responses) . '</D:multistatus>';
    exit;
}

/* ------------------------------------------------------------ GET / PUT */

function webdav_get(int $userId, string $path, bool $head): void
{
    $node = webdav_node($userId, $path);
    if ($node === null) {
        http_response_code(404);
        exit;
    }
    if ($node['kind'] === 'folder') {
        http_response_code(405);
        exit;
    }
    if ($node['blob_id'] === null) {
        http_response_code(404);
        exit;
    }

    $hash = (string) q_val('SELECT hash FROM blobs WHERE id = ?', [(int) $node['blob_id']]);
    $file = blob_path($hash);
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }

    traffic_add($userId, 'down', (int) $node['size'], 1);
    activity_log($userId, 'file.download', (int) $node['id'], (string) $node['name'] . '（WebDAV）');

    if ($head) {
        header('Content-Type: ' . (string) ($node['mime'] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', (int) strtotime((string) $node['updated_at'])));
        http_response_code(200);
        exit;
    }

    blob_stream(
        (int) $node['blob_id'],
        (string) ($node['mime'] ?? 'application/octet-stream'),
        (string) $node['name'],
        false
    );
}

function webdav_put(int $userId, string $path): void
{
    if ($path === '') {
        http_response_code(405);
        exit;
    }

    $existing = webdav_node($userId, $path);
    $name = basename($path);
    $parentId = $existing !== null ? (int) $existing['parent_id'] : webdav_parent_id($userId, $path);

    if ($parentId <= 0) {
        http_response_code(409);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'dav');
    if ($tmp === false) {
        http_response_code(500);
        exit;
    }

    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    if ($in === false || $out === false) {
        http_response_code(500);
        exit;
    }
    $size = (int) stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    try {
        assert_quota($userId, $size);
    } catch (RuntimeException $e) {
        @unlink($tmp);
        http_response_code(507);
        exit;
    }

    $mime = mime_of($name, (string) @file_get_contents($tmp, false, null, 0, 12));
    $blob = blob_ingest_file($tmp, null, $mime);

    if ($existing !== null && $existing['kind'] === 'file') {
        $node = node_replace_content($userId, $existing, $blob, $mime);
        versions_prune($userId, (int) $node['id'], 10);
    } else {
        $node = node_create_file($userId, $parentId, $name, $blob, $mime);
        if (function_exists('search_index_node')) {
            search_index_node($userId, $node);
        }
    }

    user_recompute_usage($userId);
    traffic_add($userId, 'up', $size, 1);
    activity_log($userId, 'file.upload', (int) $node['id'], $name . '（WebDAV）');

    http_response_code($existing !== null ? 204 : 201);
    exit;
}

function webdav_mkcol(int $userId, string $path): void
{
    if ($path === '' || webdav_node($userId, $path) !== null) {
        http_response_code(405);
        exit;
    }

    $parentId = webdav_parent_id($userId, $path);
    if ($parentId <= 0) {
        http_response_code(409);
        exit;
    }

    try {
        node_create_folder($userId, $parentId, basename($path));
    } catch (RuntimeException $e) {
        http_response_code(409);
        exit;
    }

    http_response_code(201);
    exit;
}

function webdav_delete(int $userId, string $path): void
{
    $node = webdav_node($userId, $path);
    if ($node === null || $path === '') {
        http_response_code(404);
        exit;
    }

    try {
        node_trash($userId, $node);
    } catch (RuntimeException $e) {
        http_response_code(423);
        exit;
    }

    activity_log($userId, 'file.delete', (int) $node['id'], (string) $node['name'] . '（WebDAV）');
    http_response_code(204);
    exit;
}

function webdav_move(int $userId, string $path, bool $copy): void
{
    $node = webdav_node($userId, $path);
    if ($node === null || $path === '') {
        http_response_code(404);
        exit;
    }

    $destination = (string) ($_SERVER['HTTP_DESTINATION'] ?? '');
    if ($destination === '') {
        http_response_code(400);
        exit;
    }

    $destPath = webdav_destination_path($destination);
    if ($destPath === null || $destPath === '') {
        http_response_code(400);
        exit;
    }

    $overwrite = strtoupper((string) ($_SERVER['HTTP_OVERWRITE'] ?? 'T')) !== 'F';
    $target = webdav_node($userId, $destPath);
    if ($target !== null && !$overwrite) {
        http_response_code(412);
        exit;
    }

    $created = $target === null;

    try {
        if ($target !== null) {
            node_purge($userId, $target);
        }

        $targetParentId = webdav_parent_id($userId, $destPath);
        if ($targetParentId <= 0) {
            http_response_code(409);
            exit;
        }

        if ($copy) {
            node_copy($userId, $node, $targetParentId);
        } else {
            node_move($userId, $node, $targetParentId);
        }
    } catch (RuntimeException $e) {
        http_response_code(409);
        exit;
    }

    user_recompute_usage($userId);
    activity_log($userId, $copy ? 'file.copy' : 'file.move', (int) $node['id'], (string) $node['name'] . '（WebDAV）');

    http_response_code($created ? 201 : 204);
    exit;
}

/** 把 Destination 绝对地址折回站内路径 */
function webdav_destination_path(string $destination): ?string
{
    $path = (string) parse_url($destination, PHP_URL_PATH);
    $query = (string) parse_url($destination, PHP_URL_QUERY);

    if ($query !== '') {
        parse_str($query, $params);
        $raw = (string) ($params['r'] ?? '');
        if (str_starts_with($raw, 'webdav.handle')) {
            return trim(substr($raw, strlen('webdav.handle')), '/');
        }
    }

    if ($path === '') {
        return null;
    }

    $marker = '/webdav.handle';
    $pos = strpos($path, $marker);
    if ($pos === false) {
        return null;
    }

    return trim(rawurldecode(substr($path, $pos + strlen($marker))), '/');
}

/** 最小 LOCK 支持：给出一个伪锁令牌，客户端即可进入读写 */
function webdav_lock(): void
{
    $token = 'opaquelocktoken:' . bin2hex(random_bytes(16));

    http_response_code(200);
    header('Content-Type: application/xml; charset=utf-8');
    header('Lock-Token: <' . $token . '>');

    echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
    echo '<D:prop xmlns:D="DAV:"><D:lockdiscovery><D:activelock>'
        . '<D:locktype><D:write/></D:locktype>'
        . '<D:lockscope><D:exclusive/></D:lockscope>'
        . '<D:depth>infinity</D:depth>'
        . '<D:timeout>Second-3600</D:timeout>'
        . '<D:locktoken><D:href>' . e($token) . '</D:href></D:locktoken>'
        . '</activelock></D:lockdiscovery></D:prop>';
    exit;
}
