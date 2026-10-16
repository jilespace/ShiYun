<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/* =========================================================================
   Office 文档在线预览。
   .docx / .xlsx / .pptx 本质是 ZIP + XML，本机没有 LibreOffice，
   所以直接在 PHP 里解包解析，输出成 HTML。不需要任何外部程序。
   ========================================================================= */

const OOXML_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const OOXML_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const OOXML_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

function ooxml_open(string $path): ?ZipArchive
{
    if (!class_exists('ZipArchive')) {
        return null;
    }
    $zip = new ZipArchive();
    return $zip->open($path) === true ? $zip : null;
}

function ooxml_xml(ZipArchive $zip, string $entry): ?DOMDocument
{
    $raw = $zip->getFromName($entry);
    if ($raw === false || $raw === '') {
        return null;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

/* ------------------------------------------------------------------ docx */

function ooxml_docx(string $path): ?string
{
    $zip = ooxml_open($path);
    if ($zip === null) {
        return null;
    }

    $doc = ooxml_xml($zip, 'word/document.xml');
    if ($doc === null) {
        $zip->close();
        return null;
    }

    $html = [];
    foreach ($doc->getElementsByTagNameNS(OOXML_W, 'p') as $p) {
        $style = '';
        foreach ($p->getElementsByTagNameNS(OOXML_W, 'pStyle') as $s) {
            $style = strtolower((string) $s->getAttributeNS(OOXML_W, 'val'));
            break;
        }

        $text = '';
        foreach ($p->getElementsByTagNameNS(OOXML_W, 't') as $t) {
            $text .= (string) $t->textContent;
        }
        // 制表与换行
        foreach ($p->getElementsByTagNameNS(OOXML_W, 'tab') as $tab) {
            $text .= "\t";
        }

        $text = trim($text);
        if ($text === '') {
            $html[] = '<p class="doc__gap"></p>';
            continue;
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (preg_match('/^heading([1-6])$/', $style, $m) === 1) {
            $level = (int) $m[1];
            $html[] = "<h{$level} class=\"doc__h\">{$escaped}</h{$level}>";
        } elseif ($style === 'title') {
            $html[] = "<h1 class=\"doc__title\">{$escaped}</h1>";
        } elseif (str_starts_with($style, 'listparagraph')) {
            $html[] = "<p class=\"doc__li\">{$escaped}</p>";
        } else {
            $html[] = "<p>{$escaped}</p>";
        }
    }

    $zip->close();

    return $html === [] ? null : '<div class="doc">' . implode('', $html) . '</div>';
}

/* ------------------------------------------------------------------ xlsx */

function ooxml_xlsx(string $path): ?string
{
    $zip = ooxml_open($path);
    if ($zip === null) {
        return null;
    }

    // 共享字符串表
    $shared = [];
    $ssDoc = ooxml_xml($zip, 'xl/sharedStrings.xml');
    if ($ssDoc !== null) {
        foreach ($ssDoc->getElementsByTagNameNS(OOXML_S, 'si') as $si) {
            $buf = '';
            foreach ($si->getElementsByTagNameNS(OOXML_S, 't') as $t) {
                $buf .= (string) $t->textContent;
            }
            $shared[] = $buf;
        }
    }

    // 工作表清单
    $sheets = [];
    $wb = ooxml_xml($zip, 'xl/workbook.xml');
    if ($wb !== null) {
        $i = 0;
        foreach ($wb->getElementsByTagNameNS(OOXML_S, 'sheet') as $sheet) {
            $i++;
            $sheets[$i] = (string) $sheet->getAttribute('name');
        }
    }

    $html = [];
    $sheetNo = 0;

    for ($n = 1; $n <= max(1, count($sheets)); $n++) {
        $sheetDoc = ooxml_xml($zip, "xl/worksheets/sheet{$n}.xml");
        if ($sheetDoc === null) {
            continue;
        }
        $sheetNo++;

        $rows = [];
        foreach ($sheetDoc->getElementsByTagNameNS(OOXML_S, 'row') as $row) {
            $cells = [];
            foreach ($row->getElementsByTagNameNS(OOXML_S, 'c') as $c) {
                $ref = (string) $c->getAttribute('r');
                $type = (string) $c->getAttribute('t');
                $value = '';

                if ($type === 's') {
                    $idx = (int) $c->getElementsByTagNameNS(OOXML_S, 'v')->item(0)?->textContent;
                    $value = $shared[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    foreach ($c->getElementsByTagNameNS(OOXML_S, 't') as $t) {
                        $value .= (string) $t->textContent;
                    }
                } else {
                    $vNode = $c->getElementsByTagNameNS(OOXML_S, 'v')->item(0);
                    $value = $vNode !== null ? (string) $vNode->textContent : '';
                }

                // 列号（A、B、…）用于跨列填充
                preg_match('/^([A-Z]+)/', $ref, $m);
                $col = ooxml_col_index($m[1] ?? 'A');
                $cells[$col] = $value;
            }

            if ($cells === []) {
                continue;
            }
            $width = max(array_keys($cells)) + 1;
            $rowHtml = [];
            for ($c = 0; $c < $width; $c++) {
                $val = $cells[$c] ?? '';
                $cls = $val === '' ? ' class="doc__cell--empty"' : '';
                $rowHtml[] = '<td' . $cls . '>' . htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            }
            $rows[] = '<tr>' . implode('', $rowHtml) . '</tr>';
        }

        $label = $sheets[$n] ?? ('工作表 ' . $n);
        $html[] = '<div class="doc__sheet"><h3 class="doc__h">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . ($rows === []
                ? '<p class="doc__gap">（空工作表）</p>'
                : '<div class="doc__scroll"><table class="doc__table">' . implode('', $rows) . '</table></div>')
            . '</div>';
    }

    $zip->close();

    return $html === [] ? null : '<div class="doc">' . implode('', $html) . '</div>';
}

/** A → 0, B → 1, AA → 26 … */
function ooxml_col_index(string $letters): int
{
    $index = 0;
    foreach (str_split($letters) as $ch) {
        $index = $index * 26 + (ord($ch) - 64);
    }
    return max(0, $index - 1);
}

/* ------------------------------------------------------------------ pptx */

function ooxml_pptx(string $path): ?string
{
    $zip = ooxml_open($path);
    if ($zip === null) {
        return null;
    }

    $slides = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m) === 1) {
            $slides[(int) $m[1]] = $name;
        }
    }
    ksort($slides);

    $html = [];
    $no = 0;
    foreach ($slides as $entry) {
        $no++;
        $doc = ooxml_xml($zip, $entry);
        if ($doc === null) {
            continue;
        }

        $blocks = [];
        foreach ($doc->getElementsByTagNameNS(OOXML_A, 'p') as $p) {
            $text = '';
            foreach ($p->getElementsByTagNameNS(OOXML_A, 't') as $t) {
                $text .= (string) $t->textContent;
            }
            $text = trim($text);
            if ($text !== '') {
                $blocks[] = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        $html[] = '<section class="slide"><span class="slide__no">' . $no . '</span>'
            . ($blocks === []
                ? '<p class="doc__gap">（本页无文本）</p>'
                : '<div class="slide__body">' . implode('', array_map(
                    static fn (string $t): string => "<p>{$t}</p>",
                    $blocks
                )) . '</div>')
            . '</section>';
    }

    $zip->close();

    return $html === [] ? null : '<div class="deck">' . implode('', $html) . '</div>';
}

/** 按扩展名分派 */
function ooxml_render(string $path, string $ext): ?string
{
    return match ($ext) {
        'docx' => ooxml_docx($path),
        'xlsx' => ooxml_xlsx($path),
        'pptx' => ooxml_pptx($path),
        default => null,
    };
}
