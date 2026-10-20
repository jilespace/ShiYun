<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $node */
/** @var string $mode */
/** @var string $kind */
/** @var array|null $parent */

$rawUrl = url('preview.raw', ['id' => (int) $node['id']]);
$backUrl = $parent !== null && $parent['kind'] === 'folder'
    ? url('files.list', ['folder' => (int) $parent['id']])
    : url('files.list');
?>
<div class="viewer" data-viewer data-id="<?= (int) $node['id'] ?>" data-mode="<?= e($mode) ?>">

    <header class="viewer__bar">
        <a class="iconbtn" href="<?= e($backUrl) ?>" aria-label="返回" data-back>
            <?= lucide('arrow-left') ?>
        </a>

        <div class="viewer__ident">
            <span class="viewer__ico viewer__ico--<?= e($kind) ?>"><?= lucide(icon_for_kind($kind, (string) $node['ext'])) ?></span>
            <span class="viewer__name" title="<?= e((string) $node['name']) ?>"><?= e((string) $node['name']) ?></span>
            <span class="viewer__meta"><?= e(format_bytes((float) $node['size'])) ?></span>
        </div>

        <div class="viewer__tools">
            <?php if ($mode === 'image'): ?>
                <button class="iconbtn" type="button" data-zoom-out aria-label="缩小"><?= lucide('minus') ?></button>
                <span class="viewer__zoom" data-zoom-label>100%</span>
                <button class="iconbtn" type="button" data-zoom-in aria-label="放大"><?= lucide('plus') ?></button>
                <button class="iconbtn" type="button" data-similar aria-label="找相似照片" title="找相似照片">
                    <?= lucide('layers') ?>
                </button>
                <span class="viewer__rule"></span>
            <?php endif; ?>
            <?php if ($mode === 'text' && is_textual((string) $node['mime'], (string) $node['ext']) && (int) $node['size'] <= 2 * 1024 * 1024): ?>
                <button class="iconbtn" type="button" data-edit aria-label="在线编辑" title="在线编辑">
                    <?= lucide('pencil') ?>
                </button>
                <span class="viewer__rule"></span>
            <?php endif; ?>
            <button class="iconbtn" type="button" data-info aria-label="详细信息"><?= lucide('info') ?></button>
            <a class="iconbtn" href="<?= e(url('download.file', ['id' => (int) $node['id']])) ?>" aria-label="下载">
                <?= lucide('download') ?>
            </a>
            <button class="iconbtn" type="button" data-share aria-label="分享"><?= lucide('share-2') ?></button>
            <a class="iconbtn" href="<?= e($backUrl) ?>" aria-label="关闭"><?= lucide('x') ?></a>
        </div>
    </header>

    <div class="viewer__stage" data-stage>
        <?php if ($mode === 'image'): ?>
            <div class="vimg" data-pan>
                <img src="<?= e($rawUrl) ?>" alt="<?= e((string) $node['name']) ?>" data-image>
            </div>

        <?php elseif ($mode === 'video'): ?>
            <div class="vmedia">
                <video class="vmedia__el" src="<?= e($rawUrl) ?>" controls preload="metadata" playsinline
                       data-media></video>
            </div>

        <?php elseif ($mode === 'audio'): ?>
            <div class="vaudio">
                <span class="vaudio__art"><?= lucide('music', 'vaudio__glyph') ?></span>
                <p class="vaudio__name"><?= e((string) $node['name']) ?></p>
                <audio class="vaudio__el" src="<?= e($rawUrl) ?>" controls preload="metadata" data-media></audio>
            </div>

        <?php elseif ($mode === 'pdf'): ?>
            <iframe class="vframe" src="<?= e($rawUrl) ?>" title="<?= e((string) $node['name']) ?>" data-frame></iframe>

        <?php elseif ($mode === 'office'): ?>
            <div class="voffice" data-office>
                <div class="voffice__load">
                    <?= lucide('loader', 'voffice__spin') ?>
                    <span>正在解析文档…</span>
                </div>
            </div>

        <?php elseif ($mode === 'text'): ?>
            <div class="vtext" data-text>
                <div class="voffice__load">
                    <?= lucide('loader', 'voffice__spin') ?>
                    <span>正在读取…</span>
                </div>
            </div>

        <?php else: ?>
            <div class="vbinary">
                <span class="vbinary__glyph"><?= lucide(icon_for_kind($kind, (string) $node['ext']), 'vbinary__ico') ?></span>
                <p class="vbinary__title">这类文件没法在浏览器里直接打开</p>
                <p class="vbinary__hint">下载到本地再用对应的软件查看。</p>
                <a class="cta cta--inline" href="<?= e(url('download.file', ['id' => (int) $node['id']])) ?>">
                    <span class="cta__label">下载文件</span>
                    <span class="cta__ico"><?= lucide('download') ?></span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <div class="viewer__rail" aria-hidden="true">
        <span><?= e(strtoupper((string) $node['ext'])) ?> · <?= e($kind) ?></span>
    </div>
</div>

<div class="modal" data-modal hidden>
    <div class="modal__scrim" data-modal-close></div>
    <div class="modal__panel" role="dialog" aria-modal="true" aria-labelledby="preview-modal-title">
        <header class="modal__head">
            <h2 class="modal__title" id="preview-modal-title" data-modal-title></h2>
            <button class="iconbtn" type="button" data-modal-close aria-label="关闭"><?= lucide('x') ?></button>
        </header>
        <div class="modal__body" data-modal-body></div>
        <footer class="modal__foot" data-modal-foot></footer>
    </div>
</div>

<div class="toasts" data-toasts aria-live="polite"></div>
