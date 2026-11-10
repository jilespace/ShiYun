<?php

declare(strict_types=1);

namespace Shiyun\Lib;

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   文件条目的 HTML 渲染。
   行、卡片、徽标、标签此前散落在 views/files.php 的闭包里；集中到这里后，
   服务端首屏与 files.data 的增量片段共用同一套标记，避免两边各改一遍。
   ========================================================================= */

final class NodeView
{
    /** 单个列表行 */
    public static function row(array $n, int $index = 0, bool $isTrash = false): string
    {
        $isFolder = $n['kind'] === 'folder';
        $href = $isFolder
            ? \url('files.list', ['folder' => (int) $n['id']])
            : \url('preview.open', ['id' => (int) $n['id']]);

        $name = $isTrash
            ? '<span class="row__name row__name--static" title="' . \e((string) $n['name']) . '">' . \e((string) $n['name']) . '</span>'
            : '<a class="row__name" href="' . \e($href) . '" data-name-link title="' . \e((string) $n['name']) . '">' . \e((string) $n['name']) . '</a>';

        $colorDot = $n['color'] !== null
            ? '<span class="row__color" style="--dot:' . \e((string) $n['color']) . '"></span>'
            : '';

        return '<li class="row" data-id="' . (int) $n['id'] . '" data-kind="' . \e($n['kind']) . '"'
            . ' data-name="' . \e((string) $n['name']) . '" data-type="' . \e($n['type']) . '"'
            . ' style="--i:' . $index . '">'
            . '<span class="row__pick"><input type="checkbox" class="pick" aria-label="选择 ' . \e((string) $n['name']) . '"></span>'
            . '<span class="row__main">'
            . '<span class="row__icon row__icon--' . \e($n['type']) . '">' . \lucide($n['icon']) . '</span>'
            . $name . $colorDot . self::badges($n) . self::tagChips($n)
            . '</span>'
            . '<span class="row__size">' . ($isFolder ? (int) $n['childCount'] . ' 项' : \e((string) $n['sizeText'])) . '</span>'
            . '<span class="row__time"' . ($isTrash ? '' : ' title="' . \e((string) $n['updated']) . '"') . '>'
            . \e($isTrash ? (string) $n['deleted'] : (string) $n['updatedText']) . '</span>'
            . '<span class="row__act">'
            . '<button class="iconbtn" type="button" data-menu aria-label="更多操作" aria-haspopup="menu">' . \lucide('more-horizontal') . '</button>'
            . '</span>'
            . '</li>';
    }

    /** 单个网格卡片 */
    public static function card(array $n, int $index = 0, bool $isTrash = false): string
    {
        $isFolder = $n['kind'] === 'folder';
        $href = $isFolder
            ? \url('files.list', ['folder' => (int) $n['id']])
            : \url('preview.open', ['id' => (int) $n['id']]);

        $art = $n['hasThumb']
            ? '<img src="' . \e(\url('preview.thumb', ['id' => (int) $n['id']])) . '" alt="" loading="lazy" decoding="async">'
            : \lucide($n['icon'], 'card__glyph');

        return '<li class="card" data-id="' . (int) $n['id'] . '" data-kind="' . \e($n['kind']) . '"'
            . ' data-name="' . \e((string) $n['name']) . '" data-type="' . \e($n['type']) . '"'
            . ' style="--i:' . $index . '">'
            . '<span class="card__pick"><input type="checkbox" class="pick" aria-label="选择 ' . \e((string) $n['name']) . '"></span>'
            . '<a class="card__body" href="' . \e($isTrash ? '#' : $href) . '"' . ($isTrash ? '' : ' data-name-link') . '>'
            . '<span class="card__art card__art--' . \e($n['type']) . '">' . $art . '</span>'
            . '<span class="card__name" title="' . \e((string) $n['name']) . '">' . \e((string) $n['name']) . '</span>'
            . '<span class="card__meta"' . ($isTrash ? '' : ' title="' . \e((string) $n['updated']) . '"') . '>'
            . ($isFolder ? (int) $n['childCount'] . ' 项' : \e((string) $n['sizeText']))
            . ' · ' . \e($isTrash ? (string) $n['deleted'] : (string) $n['updatedText']) . '</span>'
            . '</a>'
            . '<button class="iconbtn card__menu" type="button" data-menu aria-label="更多操作" aria-haspopup="menu">' . \lucide('more-horizontal') . '</button>'
            . '</li>';
    }

    /** 一页条目的片段；首屏和增量接口都走这里 */
    public static function fragment(array $nodes, string $view, int $start = 0, bool $isTrash = false): string
    {
        $grid = $view === 'grid';
        $html = '';
        foreach (array_values($nodes) as $i => $n) {
            $html .= $grid
                ? self::card($n, $start + $i, $isTrash)
                : self::row($n, $start + $i, $isTrash);
        }
        return $html;
    }

    private static function badges(array $n): string
    {
        $out = '';
        if ($n['locked']) {
            $out .= '<span class="badge" title="已锁定">' . \lucide('lock', 'badge__ico') . '</span>';
        }
        if ($n['hidden']) {
            $out .= '<span class="badge" title="已隐藏">' . \lucide('eye-off', 'badge__ico') . '</span>';
        }
        if ($n['favorite']) {
            $out .= '<span class="badge badge--star" title="已收藏">' . \lucide('star', 'badge__ico') . '</span>';
        }
        if (($n['shareCount'] ?? 0) > 0) {
            $out .= '<span class="badge" title="已分享">' . \lucide('link-2', 'badge__ico') . '</span>';
        }
        if (($n['directCount'] ?? 0) > 0) {
            $out .= '<span class="badge" title="有直链">' . \lucide('zap', 'badge__ico') . '</span>';
        }
        if (($n['memberCount'] ?? 0) > 0) {
            $out .= '<span class="badge" title="协作中">' . \lucide('users', 'badge__ico') . '</span>';
        }
        if (($n['cosStatus'] ?? '') !== '') {
            [$icon, $title] = match ((string) $n['cosStatus']) {
                'ok'      => ['check-circle', 'COS 已同步'],
                'pending' => ['refresh-cw', 'COS 推送中'],
                'failed'  => ['circle-alert', 'COS 推送失败'],
                'stale'   => ['alert-triangle', 'COS 副本失效'],
                default   => ['cloud', 'COS 副本'],
            };
            $out .= '<span class="badge badge--cos badge--cos-' . \e((string) $n['cosStatus'])
                . '" title="' . \e($title) . '">' . \lucide($icon, 'badge__ico') . '</span>';
        }
        return $out;
    }

    private static function tagChips(array $n): string
    {
        $out = '';
        foreach (array_slice($n['tags'] ?? [], 0, 3) as $tag) {
            $out .= '<span class="chip" style="--chip:' . \e((string) $tag['color']) . '">'
                . \e((string) $tag['name']) . '</span>';
        }
        return $out;
    }
}
