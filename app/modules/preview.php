<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** 预览页：整屏查看器 */
function preview_open(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        redirect(url('files.list'));
    }

    if ($node['kind'] === 'folder') {
        redirect(url('files.list', ['folder' => (int) $node['id']]));
    }

    $kind = kind_of((string) $node['mime'], (string) $node['ext']);
    $ext = (string) $node['ext'];

    $mode = match (true) {
        $kind === 'image' => 'image',
        $kind === 'video' => 'video',
        $kind === 'audio' => 'audio',
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['docx', 'xlsx', 'pptx'], true) => 'office',
        is_textual((string) $node['mime'], $ext) => 'text',
        default => 'binary',
    };

    activity_log($userId, 'file.preview', (int) $node['id'], (string) $node['name']);

    render('preview', [
        'title'     => $node['name'] . ' · 拾云',
        'bodyClass' => 'is-viewer',
        'styles'    => ['/assets/css/viewer.css'],
        'scripts'   => ['/assets/js/icons.js', '/assets/js/viewer.js'],
        'node'      => $node,
        'mode'      => $mode,
        'kind'      => $kind,
        'parent'    => node_get($userId, (int) ($node['parent_id'] ?? 0)),
    ]);
}

/** 原始内容（inline，支持 Range 以便视频拖动） */
function preview_raw(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        http_response_code(404);
        exit;
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        http_response_code(403);
        exit;
    }

    blob_stream(
        (int) $node['blob_id'],
        (string) ($node['mime'] ?? 'application/octet-stream'),
        (string) $node['name'],
        true
    );
}

/** 图片缩略图（按需生成并缓存） */
function preview_thumb(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        http_response_code(404);
        exit;
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        http_response_code(403);
        exit;
    }

    $hash = (string) q_val('SELECT hash FROM blobs WHERE id = ?', [(int) $node['blob_id']]);
    if ($hash === '') {
        http_response_code(404);
        exit;
    }

    $thumb = thumb_path($hash);
    if (!is_file($thumb)) {
        $source = blob_path($hash);
        if (!is_file($source) || make_thumbnail($source, $hash) === null) {
            http_response_code(404);
            exit;
        }
    }

    if (!headers_sent()) {
        header_remove('Content-Type');
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($thumb));
        header('Cache-Control: private, max-age=86400');
    }
    readfile($thumb);
    exit;
}

/** 文本 / 代码 / Markdown 内容 */
function preview_text(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        json_fail('无法预览该条目。', 404);
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    $size = (int) $node['size'];
    $cap = 2 * 1024 * 1024;

    $fh = blob_open((int) $node['blob_id']);
    if ($fh === null) {
        json_fail('文件内容不可读。', 404);
    }
    $raw = $size > $cap ? (string) fread($fh, $cap) : (string) stream_get_contents($fh);
    fclose($fh);

    // 去掉 UTF-8 BOM
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }

    $isUtf8 = mb_check_encoding($raw, 'UTF-8');
    if (!$isUtf8) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
        $isUtf8 = $converted !== false;
        if ($isUtf8) {
            $raw = $converted;
        }
    }

    json_ok([
        'name'     => (string) $node['name'],
        'ext'      => (string) $node['ext'],
        'mime'     => (string) $node['mime'],
        'size'     => $size,
        'sizeText' => format_bytes((float) $size),
        'truncated' => $size > $cap,
        'binary'   => !$isUtf8,
        'content'  => $isUtf8 ? $raw : '',
    ]);
}

/** 保存文本 / Markdown 编辑（旧内容自动留档为版本） */
function preview_save(): void
{
    require_method('POST');
    $userId = require_auth_api();
    require_csrf();

    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        json_fail('只有文件才能编辑。', 404);
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }
    if (!is_textual((string) $node['mime'], (string) $node['ext'])) {
        json_fail('这类文件不支持在线编辑。', 415, 'unsupported');
    }

    $content = (string) input('content', '');
    if ($content === '') {
        json_fail('内容为空；要清空请至少保留一个字符。');
    }
    $cap = 2 * 1024 * 1024;
    if (strlen($content) > $cap) {
        json_fail('编辑内容超过 2 MB 上限，请下载后用本地软件处理。', 413, 'too_large');
    }

    // 走临时文件复用内容寻址入库；默认 UTF-8，不带 BOM
    $tmp = tempnam((string) config('chunk_path'), 'edit');
    if ($tmp === false || file_put_contents($tmp, $content) === false) {
        json_fail('写入临时文件失败。', 500, 'io');
    }

    try {
        $blob = blob_ingest_file($tmp, null, (string) ($node['mime'] ?? 'text/plain'));
    } catch (RuntimeException $e) {
        @unlink($tmp);
        json_fail('保存失败：' . $e->getMessage(), 500, 'io');
    }

    $updated = node_replace_content($userId, $node, $blob);

    // 与上传路径（upload.php 覆盖上传）保持一致：两者都必须做这两件事。
    // 少了 versions_prune，每次编辑都留一条版本，改得越多堆得越狠（版本还持有 blob 引用）；
    // 少了 search_index_node，全文索引仍停在旧内容上——搜得到已删掉的词，搜不到新写的词。
    versions_prune($userId, (int) $updated['id'], 10);
    search_index_node($userId, $updated);

    activity_log($userId, 'file.edit', (int) $node['id'], (string) $node['name']);

    json_ok([
        'id'        => (int) $updated['id'],
        'size'      => (int) $updated['size'],
        'sizeText'  => format_bytes((float) $updated['size']),
        'updated'   => (string) $updated['updated_at'],
    ]);
}

/** Office 文档转 HTML（纯 PHP 解析 OOXML） */
function preview_office(): void
{
    $userId = require_auth_api();
    $node = node_require($userId, input_int('id'));

    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        json_fail('无法预览该条目。', 404);
    }
    if (node_in_private($userId, $node) && !Auth::privateUnlocked()) {
        json_fail('私密空间已锁定。', 403, 'private_locked');
    }

    $ext = (string) $node['ext'];
    if (!in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
        json_fail('暂不支持该格式的在线解析。', 415, 'unsupported');
    }

    $hash = (string) q_val('SELECT hash FROM blobs WHERE id = ?', [(int) $node['blob_id']]);
    $path = blob_path($hash);
    if ($hash === '' || !is_file($path)) {
        json_fail('文件内容不可读。', 404);
    }

    $html = ooxml_render($path, $ext);
    if ($html === null) {
        json_fail('文档解析失败，可能是加密或损坏的文件。', 422, 'parse_failed');
    }

    json_ok([
        'name' => (string) $node['name'],
        'ext'  => $ext,
        'html' => $html,
    ]);
}
