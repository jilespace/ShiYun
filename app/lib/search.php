<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   检索：文件名用 LIKE，内容用 file_index 上的 ngram 全文索引。
   ngram 解析器让中文也能按词检索，不必额外装分词服务。
   ========================================================================= */

const INDEX_TEXT_CAP = 512 * 1024;   // 单个文件最多索引 512 KB 文本

/** 该文件是否值得做内容索引 */
function indexable(string $mime, string $ext): bool
{
    if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
        return true;
    }
    return is_textual($mime, $ext);
}

/**
 * 为文件建立内容索引（幂等）。非文本文件只记录元信息。
 */
function search_index_node(int $userId, array $node): void
{
    if ($node['kind'] !== 'file' || $node['blob_id'] === null) {
        return;
    }

    $ext = (string) ($node['ext'] ?? '');
    $mime = (string) ($node['mime'] ?? '');
    $hash = (string) q_val('SELECT hash FROM blobs WHERE id = ?', [(int) $node['blob_id']]);
    $path = $hash !== '' ? blob_path($hash) : '';

    $text = '';
    $meta = ['width' => null, 'height' => null, 'duration' => null, 'pages' => null];

    if ($path !== '' && is_file($path) && indexable($mime, $ext)) {
        if (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
            $html = ooxml_render($path, $ext);
            $text = $html !== null ? trim(strip_tags($html)) : '';
        } else {
            $fh = @fopen($path, 'rb');
            if ($fh !== false) {
                $raw = (string) fread($fh, INDEX_TEXT_CAP);
                fclose($fh);
                if (str_starts_with($raw, "\xEF\xBB\xBF")) {
                    $raw = substr($raw, 3);
                }
                if (!mb_check_encoding($raw, 'UTF-8')) {
                    $converted = @mb_convert_encoding($raw, 'UTF-8', 'GB18030');
                    $raw = $converted !== false ? $converted : '';
                }
                $text = trim($raw);
            }
        }
    }

    if (str_starts_with($mime, 'image/') && $path !== '' && is_file($path)) {
        $info = @getimagesize($path);
        if ($info !== false) {
            $meta['width'] = (int) $info[0];
            $meta['height'] = (int) $info[1];
        }
    }

    q_exec(
        'INSERT INTO file_index (node_id, user_id, mime, width, height, duration, pages, text_content, indexed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            mime = VALUES(mime), width = VALUES(width), height = VALUES(height),
            duration = VALUES(duration), pages = VALUES(pages),
            text_content = VALUES(text_content), indexed_at = VALUES(indexed_at)',
        [
            (int) $node['id'],
            $userId,
            $mime !== '' ? mb_substr($mime, 0, 128) : null,
            $meta['width'],
            $meta['height'],
            $meta['duration'],
            $meta['pages'],
            $text !== '' ? mb_substr($text, 0, 4 * 1024 * 1024) : null,
            now(),
        ]
    );
}

/** 删除索引 */
function search_forget_node(int $nodeId): void
{
    q_exec('DELETE FROM file_index WHERE node_id = ?', [$nodeId]);
}

/**
 * 统一检索入口。
 *
 * @param array{q?:string,scope?:string,type?:string,tag?:int,from?:string,to?:string,
 *              min?:int,max?:int,folder?:int,sort?:string,dir?:string,limit?:int,offset?:int} $f
 * @return array{items:list<array>,total:int,facets:array}
 */
function search_nodes(int $userId, array $f): array
{
    $q = trim((string) ($f['q'] ?? ''));
    $scope = (string) ($f['scope'] ?? 'name');       // name | content | all
    $type = (string) ($f['type'] ?? 'all');
    $limit = max(1, min(200, (int) ($f['limit'] ?? 60)));
    $offset = max(0, (int) ($f['offset'] ?? 0));

    $where = ['n.user_id = ?', 'n.deleted_at IS NULL', 'n.is_private = 0'];
    $params = [$userId];
    $join = '';

    if ($q !== '') {
        if ($scope === 'content') {
            $join = ' INNER JOIN file_index fi ON fi.node_id = n.id';
            $where[] = 'MATCH(fi.text_content) AGAINST (? IN BOOLEAN MODE)';
            $params[] = search_boolean_query($q);
        } elseif ($scope === 'all') {
            $join = ' LEFT JOIN file_index fi ON fi.node_id = n.id';
            $where[] = '(n.name_key LIKE ? OR MATCH(fi.text_content) AGAINST (? IN BOOLEAN MODE))';
            $params[] = '%' . mb_strtolower($q) . '%';
            $params[] = search_boolean_query($q);
        } else {
            $where[] = 'n.name_key LIKE ?';
            $params[] = '%' . mb_strtolower($q) . '%';
        }
    } elseif ($scope === 'content') {
        $join = ' INNER JOIN file_index fi ON fi.node_id = n.id';
    }

    if ($type !== 'all' && $type !== '') {
        if ($type === 'folder') {
            $where[] = 'n.kind = ?';
            $params[] = 'folder';
        } else {
            $where[] = 'n.kind = ?';
            $params[] = 'file';
            $kinds = [
                'image'    => ['image/'],
                'video'    => ['video/'],
                'audio'    => ['audio/'],
            ];
            if (isset($kinds[$type])) {
                $where[] = 'n.mime LIKE ?';
                $params[] = $kinds[$type][0] . '%';
            } else {
                $exts = match ($type) {
                    'archive'  => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz', 'tgz'],
                    'code'     => ['js', 'ts', 'php', 'py', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'rb', 'sh', 'sql', 'css', 'html', 'json', 'yml', 'yaml', 'xml'],
                    'document' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'txt', 'md', 'csv', 'odt'],
                    default    => [],
                };
                if ($exts === []) {
                    return ['items' => [], 'total' => 0, 'facets' => search_facets($userId)];
                }
                $where[] = 'n.ext IN (' . implode(',', array_fill(0, count($exts), '?')) . ')';
                $params = [...$params, ...$exts];
            }
        }
    }

    if (!empty($f['tag'])) {
        $join .= ' INNER JOIN node_tags nt ON nt.node_id = n.id';
        $where[] = 'nt.tag_id = ?';
        $params[] = (int) $f['tag'];
    }

    if (!empty($f['from'])) {
        $where[] = 'n.updated_at >= ?';
        $params[] = (string) $f['from'] . ' 00:00:00';
    }
    if (!empty($f['to'])) {
        $where[] = 'n.updated_at <= ?';
        $params[] = (string) $f['to'] . ' 23:59:59';
    }
    if (!empty($f['min'])) {
        $where[] = 'n.size >= ?';
        $params[] = (int) $f['min'];
    }
    if (!empty($f['max'])) {
        $where[] = 'n.size <= ?';
        $params[] = (int) $f['max'];
    }
    if (!empty($f['folder'])) {
        $folder = node_get($userId, (int) $f['folder']);
        if ($folder !== null) {
            $where[] = 'n.path LIKE ?';
            $params[] = (string) $folder['path'] . '%';
        }
    }

    $sortMap = [
        'name' => 'n.name_key',
        'size' => 'n.size',
        'time' => 'n.updated_at',
        'type' => 'n.ext',
    ];
    $sort = $sortMap[(string) ($f['sort'] ?? 'time')] ?? 'n.updated_at';
    $dir = strtolower((string) ($f['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $clause = implode(' AND ', $where);

    $total = (int) q_val("SELECT COUNT(DISTINCT n.id) FROM nodes n{$join} WHERE {$clause}", $params);

    $rows = q_all(
        "SELECT DISTINCT n.* FROM nodes n{$join} WHERE {$clause}
         ORDER BY {$sort} {$dir} LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    // 片段与所在路径改为批量取回，避免逐行 N+1
    $snippets = [];
    if ($q !== '' && ($scope === 'content' || $scope === 'all') && $rows !== []) {
        $snippets = search_snippets_batch(array_map(static fn (array $r): int => (int) $r['id'], $rows), $q);
    }
    $paths = search_paths_batch($userId, $rows);

    $items = [];
    foreach (node_attach_meta($userId, $rows) as $row) {
        $item = node_public($row);
        $item['snippet'] = $snippets[(int) $row['id']] ?? null;
        $item['pathText'] = $paths[(int) $row['id']] ?? '';
        $items[] = $item;
    }

    return ['items' => $items, 'total' => $total, 'facets' => search_facets($userId)];
}

/** 批量取命中片段：用 LOCATE/SUBSTRING 只取窗口，避免把整段文本读进内存 */
function search_snippets_batch(array $nodeIds, string $q): array
{
    $nodeIds = array_values(array_filter(array_map('intval', $nodeIds), static fn (int $i): bool => $i > 0));
    if ($nodeIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($nodeIds), '?'));
    $needle = mb_strtolower($q);

    $out = [];
    foreach (q_all(
        "SELECT node_id,
                LOCATE(?, LOWER(text_content)) AS pos,
                SUBSTRING(text_content, GREATEST(1, LOCATE(?, LOWER(text_content)) - 40), 200) AS snip
         FROM file_index WHERE node_id IN ({$in})",
        [$needle, $needle, ...$nodeIds]
    ) as $r) {
        $pos = (int) $r['pos'];
        if ($pos <= 0 || (string) $r['snip'] === '') {
            continue;
        }
        $out[(int) $r['node_id']] = ($pos > 41 ? '…' : '') . (string) $r['snip'] . '…';
    }
    return $out;
}

/** 批量还原节点所在路径文本（一次取回全部祖先名） */
function search_paths_batch(int $userId, array $rows): array
{
    $rootId = user_root_id($userId);
    $rowIds = [];
    $need = [];
    foreach ($rows as $row) {
        $ids = array_values(array_filter(
            explode('/', trim((string) $row['path'], '/')),
            static fn (string $v): bool => $v !== ''
        ));
        array_pop($ids);   // 末段是自身，路径文本不含自身
        $rowIds[(int) $row['id']] = $ids;
        foreach ($ids as $i) {
            $need[(int) $i] = (int) $i;
        }
    }

    $names = [];
    if ($need !== []) {
        $in = implode(',', array_fill(0, count($need), '?'));
        foreach (q_all("SELECT id, name FROM nodes WHERE user_id = ? AND id IN ({$in})", [$userId, ...array_values($need)]) as $r) {
            $names[(int) $r['id']] = (string) $r['name'];
        }
    }

    $out = [];
    foreach ($rowIds as $nid => $ids) {
        $crumbs = [];
        foreach ($ids as $i) {
            $i = (int) $i;
            $crumbs[] = $i === $rootId ? '我的文件' : ($names[$i] ?? '…');
        }
        $out[$nid] = implode(' / ', $crumbs);
    }
    return $out;
}

/** 把用户输入转成 BOOLEAN MODE 查询串 */
function search_boolean_query(string $q): string
{
    $terms = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach (array_slice($terms, 0, 8) as $term) {
        $clean = preg_replace('/[+\-><()~*"@]/u', ' ', $term) ?? '';
        $clean = trim($clean);
        if ($clean !== '') {
            $out[] = '+' . $clean . '*';
        }
    }
    return $out === [] ? '""' : implode(' ', $out);
}

/** 供筛选面板用的计数 */
function search_facets(int $userId): array
{
    $row = q_one(
        'SELECT
            SUM(kind = ?) AS folders,
            SUM(kind = ?) AS files,
            SUM(mime LIKE ?) AS images,
            SUM(mime LIKE ?) AS videos,
            SUM(mime LIKE ?) AS audios
         FROM nodes WHERE user_id = ? AND deleted_at IS NULL',
        ['folder', 'file', 'image/%', 'video/%', 'audio/%', $userId]
    );

    return [
        'folders' => (int) ($row['folders'] ?? 0),
        'files'   => (int) ($row['files'] ?? 0),
        'images'  => (int) ($row['images'] ?? 0),
        'videos'  => (int) ($row['videos'] ?? 0),
        'audios'  => (int) ($row['audios'] ?? 0),
    ];
}

/** 全量重建索引（用户主动触发） */
function search_rebuild(int $userId, int $limit = 500): array
{
    $rows = q_all(
        'SELECT * FROM nodes WHERE user_id = ? AND kind = ? AND deleted_at IS NULL
         ORDER BY updated_at DESC LIMIT ' . max(1, min(5000, $limit)),
        [$userId, 'file']
    );

    $indexed = 0;
    foreach ($rows as $row) {
        search_index_node($userId, $row);
        $indexed++;
    }

    return ['indexed' => $indexed];
}
