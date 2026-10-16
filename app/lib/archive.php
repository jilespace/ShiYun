<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   压缩包：打包下载（ZipArchive）与在线解压（7z / unzip CLI）。
   解压前一律校验每个条目的落点，杜绝 zip slip 路径穿越。
   ========================================================================= */

/** 压缩包条目名消毒 */
function safe_zip_name(string $name): string
{
    $name = str_replace(["\0", '\\', '/'], '', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return '未命名';
    }
    return mb_substr($name, 0, 180);
}

function unique_zip_entry(string $entry, array &$used): string
{
    if (!isset($used[$entry])) {
        $used[$entry] = true;
        return $entry;
    }
    $ext = pathinfo($entry, PATHINFO_EXTENSION);
    $ext = $ext !== '' ? '.' . $ext : '';
    $base = mb_substr($entry, 0, mb_strlen($entry) - mb_strlen($ext));

    for ($i = 2; $i < 1000; $i++) {
        $candidate = $base . ' (' . $i . ')' . $ext;
        if (!isset($used[$candidate])) {
            $used[$candidate] = true;
            return $candidate;
        }
    }
    return $base . '-' . bin2hex(random_bytes(3)) . $ext;
}

/** 把一组节点打包成 zip 并直接输出下载 */
function zip_stream_nodes(int $userId, array $nodes, string $downloadName): never
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('服务器未启用 zip 扩展');
    }

    $tmp = (string) config('trash_path') . '/pack-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('无法创建压缩包');
    }

    $used = [];
    $totalBytes = 0;
    $topLevel = count($nodes) > 1;
    $hashes = archive_prefetch_hashes($userId, $nodes);

    try {
        foreach ($nodes as $node) {
            if ($node['deleted_at'] !== null) {
                continue;
            }
            $prefix = $topLevel ? safe_zip_name((string) $node['name']) . '/' : '';
            if ($node['kind'] === 'folder') {
                zip_add_folder($zip, $userId, $node, $prefix, $used, $totalBytes, $hashes);
            } else {
                zip_add_file($zip, $node, $prefix . (string) $node['name'], $used, $totalBytes, $hashes);
            }
        }
    } catch (RuntimeException $e) {
        $zip->close();
        @unlink($tmp);
        http_response_code(413);
        exit($e->getMessage());
    }

    $zip->close();

    if ($totalBytes === 0) {
        @unlink($tmp);
        http_response_code(404);
        exit('没有可打包的内容');
    }

    traffic_add($userId, 'down', $totalBytes, 1);

    if (!headers_sent()) {
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($tmp));
        header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName)
            . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('Cache-Control: no-store');
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    @set_time_limit(0);
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** 预取所选子树内所有文件的 blob_id→hash，避免逐文件查询 */
function archive_prefetch_hashes(int $userId, array $nodes): array
{
    $blobIds = [];
    $folderPaths = [];
    foreach ($nodes as $node) {
        if ($node['kind'] === 'file' && $node['blob_id'] !== null) {
            $blobIds[(int) $node['blob_id']] = (int) $node['blob_id'];
        } elseif ($node['kind'] === 'folder') {
            $folderPaths[] = (string) $node['path'] . '%';
        }
    }
    foreach ($folderPaths as $p) {
        foreach (q_all(
            'SELECT blob_id FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL AND path LIKE ?',
            [$userId, 'file', $p]
        ) as $r) {
            if ($r['blob_id'] !== null) {
                $blobIds[(int) $r['blob_id']] = (int) $r['blob_id'];
            }
        }
    }
    if ($blobIds === []) {
        return [];
    }

    $ids = array_values($blobIds);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $map = [];
    foreach (q_all("SELECT id, hash FROM blobs WHERE id IN ({$in})", $ids) as $r) {
        $map[(int) $r['id']] = (string) $r['hash'];
    }
    return $map;
}

function zip_add_folder(ZipArchive $zip, int $userId, array $folder, string $prefix, array &$used, int &$totalBytes, array $hashes): void
{
    $children = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL
         ORDER BY (kind = ?) DESC, name_key ASC',
        [$userId, (int) $folder['id'], 'folder']
    );

    if ($children === []) {
        $zip->addEmptyDir(rtrim($prefix . safe_zip_name((string) $folder['name']), '/'));
        return;
    }

    foreach ($children as $child) {
        $path = $prefix . safe_zip_name((string) $folder['name']) . '/';
        if ($child['kind'] === 'folder') {
            zip_add_folder($zip, $userId, $child, $path, $used, $totalBytes, $hashes);
        } else {
            zip_add_file($zip, $child, $path . safe_zip_name((string) $child['name']), $used, $totalBytes, $hashes);
        }
    }
}

function zip_add_file(ZipArchive $zip, array $node, string $entry, array &$used, int &$totalBytes, array $hashes): void
{
    if ($node['blob_id'] === null) {
        return;
    }
    $hash = (string) ($hashes[(int) $node['blob_id']] ?? '');
    if ($hash === '') {
        return;
    }
    $path = blob_path($hash);
    if (!is_file($path)) {
        return;
    }

    $size = (int) filesize($path);
    $cap = (int) config('max_pack', 2 * 1024 ** 3);
    if ($cap > 0 && $totalBytes + $size > $cap) {
        throw new RuntimeException('打包体积超过上限 ' . format_bytes((float) $cap) . '，请分批下载。');
    }

    $entry = unique_zip_entry($entry, $used);
    $zip->addFile($path, $entry);
    $totalBytes += $size;
}

/* -------------------------------------------------------------- 在线解压 */

/**
 * 该格式能否在线解压。
 * 本机进程执行被禁用，所以只有 ZIP 能解（走 PHP 内置 ZipArchive，纯代码实现）。
 */
function extract_tool(string $ext): ?array
{
    if ($ext === 'zip' && class_exists('ZipArchive')) {
        return ['ziparchive', 'zip'];
    }
    return null;
}

/**
 * 在线解压到目标目录。
 *
 * @return array{created:int,folders:int,bytes:int,skipped:int,target:int}
 */
function extract_node(int $userId, array $node, ?int $targetParentId = null, ?string $zipPassword = null): array
{
    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        throw new RuntimeException('只能解压文件。');
    }

    $ext = strtolower((string) $node['ext']);
    if (extract_tool($ext) === null) {
        $hint = shell_available() ? '' : '当前服务器禁用了进程执行函数，因此只有 ZIP 可以在线解压。';
        throw new RuntimeException('暂不支持在线解压 .' . $ext . ' 格式。' . $hint);
    }

    $hash = (string) q_val('SELECT hash FROM blobs WHERE id = ?', [(int) $node['blob_id']]);
    $archive = blob_path($hash);
    if ($hash === '' || !is_file($archive)) {
        throw new RuntimeException('压缩包内容不可读。');
    }

    $maxExtract = (int) config('max_extract');
    if ((int) $node['size'] > $maxExtract) {
        throw new RuntimeException('压缩包超过解压上限 ' . format_bytes((float) $maxExtract) . '。');
    }

    $targetId = $targetParentId ?? (int) $node['parent_id'];
    node_require($userId, $targetId);

    // 先解到临时目录，再逐个收进内容仓——这样每个文件都能享受去重与配额校验
    $work = (string) config('trash_path') . '/x-' . bin2hex(random_bytes(8));
    if (!@mkdir($work, 0700, true) && !is_dir($work)) {
        throw new RuntimeException('无法创建解压工作目录。');
    }

    try {
        zip_extract_safely($archive, $work, $maxExtract, $zipPassword);
        $created = archive_import_tree($userId, $targetId, $work);
    } finally {
        archive_rmtree($work);
    }

    return $created;
}

/**
 * 安全的 ZIP 解压：逐条校验落点、限制总量，挡住 zip slip 与压缩炸弹。
 */
function zip_extract_safely(string $archive, string $work, int $maxBytes, ?string $password = null): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        throw new RuntimeException('压缩包无法打开，可能已损坏。');
    }

    if ($password !== null && $password !== '') {
        $zip->setPassword($password);
    }

    $total = 0;
    $count = 0;
    $root = realpath($work);

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) {
            continue;
        }

        $name = (string) $stat['name'];
        $count++;
        if ($count > 20000) {
            $zip->close();
            throw new RuntimeException('压缩包内条目过多，已中止。');
        }

        $safe = zip_safe_entry($name);
        if ($safe === null) {
            continue;   // 路径穿越等可疑条目直接跳过
        }

        $total += (int) $stat['size'];
        if ($total > $maxBytes) {
            $zip->close();
            throw new RuntimeException('解压后体积超过上限 ' . format_bytes((float) $maxBytes) . '，已中止。');
        }

        if (str_ends_with($name, '/')) {
            $dir = $work . '/' . rtrim($safe, '/');
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            continue;
        }

        $dest = $work . '/' . $safe;
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            continue;
        }

        // 二次确认：解析后的真实路径必须仍在工作目录内
        $realDir = realpath($dir);
        if ($realDir === false || $root === false || !str_starts_with($realDir, $root)) {
            continue;
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            continue;   // 加密但未提供密码等情况
        }
        $out = @fopen($dest, 'wb');
        if ($out === false) {
            fclose($stream);
            continue;
        }
        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);
        @chmod($dest, 0600);
    }

    $zip->close();
}

/** 条目名 → 安全相对路径；不安全返回 null */
function zip_safe_entry(string $name): ?string
{
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
        return null;
    }

    $parts = [];
    foreach (explode('/', $name) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $parts[] = $segment;
    }

    if ($parts === []) {
        return null;
    }
    if (count($parts) > 24) {
        return null;
    }

    $trailing = str_ends_with($name, '/') ? '/' : '';
    return implode('/', $parts) . $trailing;
}

/**
 * 把解压出来的目录树递归导入为用户文件。
 */
function archive_import_tree(int $userId, int $parentId, string $dir): array
{
    $created = ['created' => 0, 'folders' => 0, 'bytes' => 0, 'skipped' => 0, 'target' => $parentId];

    $items = @scandir($dir);
    if ($items === false) {
        return $created;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . '/' . $item;

        // 符号链接一律跳过，避免越出工作目录
        if (is_link($full)) {
            $created['skipped']++;
            continue;
        }
        if (is_dir($full)) {
            $name = safe_filename($item);
            if ($name === null) {
                $created['skipped']++;
                continue;
            }
            $folder = node_create_folder($userId, $parentId, $name);
            $created['folders']++;
            $sub = archive_import_tree($userId, (int) $folder['id'], $full);
            $created['created'] += $sub['created'];
            $created['folders'] += $sub['folders'];
            $created['bytes'] += $sub['bytes'];
            $created['skipped'] += $sub['skipped'];
            continue;
        }

        if (!is_file($full)) {
            $created['skipped']++;
            continue;
        }

        $name = safe_filename($item);
        if ($name === null) {
            $created['skipped']++;
            continue;
        }

        $size = (int) filesize($full);
        try {
            assert_quota($userId, $size);
        } catch (RuntimeException $e) {
            $created['skipped']++;
            continue;
        }

        $mime = mime_of($name, (string) @file_get_contents($full, false, null, 0, 12));
        try {
            $blob = blob_ingest_file($full, null, $mime);
        } catch (Throwable $e) {
            $created['skipped']++;
            continue;
        }

        $node = node_create_file($userId, $parentId, $name, $blob, $mime);
        $created['created']++;
        $created['bytes'] += (int) $node['size'];

        if (str_starts_with($mime, 'image/')) {
            make_thumbnail(blob_path((string) $blob['hash']), (string) $blob['hash']);
        }
    }

    return $created;
}

function archive_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $dir . '/' . $item;
        if (is_dir($full) && !is_link($full)) {
            archive_rmtree($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($dir);
}

/* -------------------------------------------------------------- 在线压缩 */

/**
 * 把选中的节点压成一个 zip 存回网盘。
 */
function compress_nodes(int $userId, array $ids, string $name, int $targetParentId): array
{
    $nodes = nodes_by_ids($userId, $ids);
    if ($nodes === []) {
        throw new RuntimeException('没有可压缩的条目。');
    }

    $name = safe_filename($name) ?? throw new RuntimeException('压缩包名不合法');
    if (!str_ends_with(mb_strtolower($name), '.zip')) {
        $name .= '.zip';
    }

    $tmp = (string) config('trash_path') . '/c-' . bin2hex(random_bytes(8)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建压缩包。');
    }

    $used = [];
    $bytes = 0;
    $single = count($nodes) === 1;
    $hashes = archive_prefetch_hashes($userId, $nodes);

    try {
        foreach ($nodes as $node) {
            $prefix = $single ? '' : safe_zip_name((string) $node['name']) . '/';
            if ($node['kind'] === 'folder') {
                zip_add_folder($zip, $userId, $node, $prefix, $used, $bytes, $hashes);
            } else {
                zip_add_file($zip, $node, $prefix . (string) $node['name'], $used, $bytes, $hashes);
            }
        }
        $zip->close();
    } catch (RuntimeException $e) {
        $zip->close();
        @unlink($tmp);
        throw $e;
    }

    if (!is_file($tmp) || $bytes === 0) {
        @unlink($tmp);
        throw new RuntimeException('压缩包是空的。');
    }

    assert_quota($userId, (int) filesize($tmp));
    $blob = blob_ingest_file($tmp, null, 'application/zip');
    $created = node_create_file($userId, $targetParentId, $name, $blob, 'application/zip', 'zip');

    return ['node' => $created, 'bytes' => (int) $created['size']];
}
