<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array $settings */
/** @var array $folder */
/** @var list<array> $children */
/** @var bool $hasMore */
/** @var int $nextOffset */
/** @var array{folders:int,files:int,bytes:int} $childStats */
/** @var list<array> $crumbs */
/** @var list<array> $tags */
/** @var string $section */
/** @var array $opts */
/** @var int $retention */
/** @var array{used:int,total:int} $quota */
/** @var list<array{id:int,name:string}> $uploadSources */
/** @var int $uploadSourceId */

$viewMode = (string) ($settings['view_mode'] ?? 'list');
$showHidden = (bool) ($settings['show_hidden'] ?? false);
$isTrash = $section === 'trash';
$isFav = $section === 'favorites';

/** 渲染一行文件 */
$renderRow = static function (array $n) use ($isTrash): string {
    $isFolder = $n['kind'] === 'folder';
    $href = $isFolder
        ? url('files.list', ['folder' => (int) $n['id']])
        : url('preview.open', ['id' => (int) $n['id']]);

    $badges = '';
    if ($n['locked']) {
        $badges .= '<span class="badge" title="已锁定">' . lucide('lock', 'badge__ico') . '</span>';
    }
    if ($n['hidden']) {
        $badges .= '<span class="badge" title="已隐藏">' . lucide('eye-off', 'badge__ico') . '</span>';
    }
    if ($n['favorite']) {
        $badges .= '<span class="badge badge--star" title="已收藏">' . lucide('star', 'badge__ico') . '</span>';
    }
    if (($n['shareCount'] ?? 0) > 0) {
        $badges .= '<span class="badge" title="已分享">' . lucide('link-2', 'badge__ico') . '</span>';
    }
    if (($n['memberCount'] ?? 0) > 0) {
        $badges .= '<span class="badge" title="协作中">' . lucide('users', 'badge__ico') . '</span>';
    }
    if (($n['cosStatus'] ?? '') !== '') {
        [$cosIco, $cosTitle] = match ((string) $n['cosStatus']) {
            'ok'      => ['check-circle', 'COS 已同步'],
            'pending' => ['refresh-cw', 'COS 推送中'],
            'failed'  => ['circle-alert', 'COS 推送失败'],
            'stale'   => ['alert-triangle', 'COS 副本失效'],
            default   => ['cloud', 'COS 副本'],
        };
        $badges .= '<span class="badge badge--cos badge--cos-' . e((string) $n['cosStatus']) . '" title="' . e($cosTitle) . '">' . lucide($cosIco, 'badge__ico') . '</span>';
    }

    $tagChips = '';
    foreach (array_slice($n['tags'] ?? [], 0, 3) as $tag) {
        $tagChips .= '<span class="chip" style="--chip:' . e((string) $tag['color']) . '">'
            . e((string) $tag['name']) . '</span>';
    }

    $colorDot = $n['color'] !== null
        ? '<span class="row__color" style="--dot:' . e((string) $n['color']) . '"></span>'
        : '';

    $name = $isTrash
        ? '<span class="row__name row__name--static">' . e((string) $n['name']) . '</span>'
        : '<a class="row__name" href="' . e($href) . '" data-name-link>' . e((string) $n['name']) . '</a>';

    return '<li class="row" data-id="' . (int) $n['id'] . '" data-kind="' . e($n['kind']) . '"'
        . ' data-name="' . e((string) $n['name']) . '" data-type="' . e($n['type']) . '">'
        . '<span class="row__pick"><input type="checkbox" class="pick" aria-label="选择 ' . e((string) $n['name']) . '"></span>'
        . '<span class="row__main">'
        . '<span class="row__icon row__icon--' . e($n['type']) . '">' . lucide($n['icon']) . '</span>'
        . $name . $colorDot . $badges . $tagChips
        . '</span>'
        . '<span class="row__size">' . ($isFolder ? (int) $n['childCount'] . ' 项' : e((string) $n['sizeText'])) . '</span>'
        . '<span class="row__time">' . e($isTrash ? (string) $n['deleted'] : (string) $n['updatedText']) . '</span>'
        . '<span class="row__act">'
        . '<button class="iconbtn" type="button" data-menu aria-label="更多操作">' . lucide('more-horizontal') . '</button>'
        . '</span>'
        . '</li>';
};

/** 渲染一格文件 */
$renderCard = static function (array $n) use ($isTrash): string {
    $isFolder = $n['kind'] === 'folder';
    $href = $isFolder
        ? url('files.list', ['folder' => (int) $n['id']])
        : url('preview.open', ['id' => (int) $n['id']]);

    $art = $n['hasThumb']
        ? '<img src="' . e(url('preview.thumb', ['id' => (int) $n['id']])) . '" alt="" loading="lazy" decoding="async">'
        : lucide($n['icon'], 'card__glyph');

    return '<li class="card" data-id="' . (int) $n['id'] . '" data-kind="' . e($n['kind']) . '"'
        . ' data-name="' . e((string) $n['name']) . '" data-type="' . e($n['type']) . '">'
        . '<span class="card__pick"><input type="checkbox" class="pick" aria-label="选择 ' . e((string) $n['name']) . '"></span>'
        . '<a class="card__body" href="' . e($isTrash ? '#' : $href) . '"' . ($isTrash ? '' : ' data-name-link') . '>'
        . '<span class="card__art card__art--' . e($n['type']) . '">' . $art . '</span>'
        . '<span class="card__name">' . e((string) $n['name']) . '</span>'
        . '<span class="card__meta">' . ($isFolder ? (int) $n['childCount'] . ' 项' : e((string) $n['sizeText']))
        . ' · ' . e($isTrash ? (string) $n['deleted'] : (string) $n['updatedText']) . '</span>'
        . '</a>'
        . '<button class="iconbtn card__menu" type="button" data-menu aria-label="更多操作">' . lucide('more-horizontal') . '</button>'
        . '</li>';
};

/** 上传目标：本地磁盘 + 本人可用的 COS(s3) 源。
    不再放工具栏（下拉又丑又对不齐），改为上传时在弹窗里选；这里只把候选交给前端。 */
$uploadChoices = array_merge(
    [['id' => 0, 'name' => '本地磁盘']],
    array_map(static fn (array $s): array => ['id' => (int) $s['id'], 'name' => (string) $s['name']], $uploadSources)
);

/** 顶栏工具 */
$barTools = '';
if (!$isTrash) {
    $barTools = '<button class="ghost" type="button" data-upload-trigger>'
        . lucide('cloud-upload', 'ghost__ico') . '<span>上传</span></button>'
        . '<div class="seg" role="group" aria-label="视图切换">'
        . '<button class="seg__btn' . ($viewMode === 'list' ? ' is-on' : '') . '" type="button" data-view-set="list" aria-pressed="' . ($viewMode === 'list' ? 'true' : 'false') . '" aria-label="列表视图">' . lucide('list') . '</button>'
        . '<button class="seg__btn' . ($viewMode === 'grid' ? ' is-on' : '') . '" type="button" data-view-set="grid" aria-pressed="' . ($viewMode === 'grid' ? 'true' : 'false') . '" aria-label="网格视图">' . lucide('layout-grid') . '</button>'
        . '</div>'
        . '<button class="iconbtn" type="button" data-hidden-toggle aria-pressed="' . ($showHidden ? 'true' : 'false')
        . '" aria-label="显示隐藏文件" title="显示隐藏文件">' . lucide($showHidden ? 'eye' : 'eye-off') . '</button>';
}
?>
<div class="app" data-app data-section="<?= e($section) ?>" data-view="<?= e($viewMode) ?>"
     data-folder="<?= (int) $folder['id'] ?>" data-sort="<?= e($opts['sort']) ?>" data-dir="<?= e($opts['dir']) ?>"
     data-show-hidden="<?= $showHidden ? '1' : '0' ?>" data-page-size="<?= (int) $pageSize ?>">

    <?php view('partials/spine', ['section' => $section, 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => $barTools, 'user' => $user]) ?>

        <div class="trail">
            <nav class="crumbs" aria-label="路径" data-crumbs>
                <?php if ($isTrash || $isFav): ?>
                    <span class="crumbs__here" aria-current="page"><?= e($isTrash ? '回收站' : '收藏') ?></span>
                    <span class="crumbs__note"><?= $isTrash
                        ? '保留 ' . (int) $retention . ' 天，超期自动清理'
                        : '所有打了星标的条目' ?></span>
                <?php else: ?>
                    <?php foreach ($crumbs as $i => $crumb): ?>
                        <?php $last = $i === count($crumbs) - 1; ?>
                        <?php if ($i > 0): ?><?= lucide('chevron-right', 'crumbs__sep') ?><?php endif; ?>
                        <?php if ($last): ?>
                            <span class="crumbs__here" aria-current="page"><?= e((string) $crumb['name']) ?></span>
                        <?php else: ?>
                            <a class="crumbs__link" href="<?= e(url('files.list', ['folder' => (int) $crumb['id']])) ?>"><?= e((string) $crumb['name']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>

            <div class="trail__tools">
                <?php if (!$isTrash && !$isFav && node_in_private((int) $user['id'], $folder)): ?>
                    <form method="post" action="<?= e(url('security.private')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="lock">
                        <button class="ghost ghost--sm" type="submit">
                            <?= lucide('lock', 'ghost__ico') ?><span>锁定私密空间</span>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($isTrash): ?>
                    <button class="ghost ghost--sm ghost--danger" type="button" data-empty-trash>
                        <?= lucide('trash-2', 'ghost__ico') ?><span>清空回收站</span>
                    </button>
                <?php else: ?>
                    <button class="ghost ghost--sm" type="button" data-new-folder>
                        <?= lucide('folder-plus', 'ghost__ico') ?><span>新建文件夹</span>
                    </button>
                    <button class="ghost ghost--sm" type="button" data-upload-folder>
                        <?= lucide('folder', 'ghost__ico') ?><span>上传文件夹</span>
                    </button>
                    <div class="sortwrap">
                        <button class="ghost ghost--sm" type="button" data-sort-btn aria-haspopup="menu" aria-expanded="false">
                            <?= lucide('arrow-up-down', 'ghost__ico') ?><span data-sort-label><?php
                                echo e(['name' => '名称', 'time' => '修改时间', 'size' => '大小', 'type' => '类型'][$opts['sort']] ?? '名称');
                            ?></span>
                        </button>
                        <div class="sortmenu" data-sort-menu hidden role="menu">
                            <?php foreach (['name' => '名称', 'time' => '修改时间', 'size' => '大小', 'type' => '类型'] as $key => $label): ?>
                                <button type="button" role="menuitem" data-sort-key="<?= e($key) ?>"
                                        class="<?= $opts['sort'] === $key ? 'is-on' : '' ?>">
                                    <span><?= e($label) ?></span>
                                    <?php /* 方向箭头只属于当前排序项——每项都挂一个 ↑ 会让方向失去意义 */ ?>
                                    <?php if ($opts['sort'] === $key): ?>
                                        <?= lucide($opts['dir'] === 'desc' ? 'arrow-down' : 'arrow-up', 'sortmenu__ico') ?>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bulk" data-bulk hidden>
            <span class="bulk__count"><b data-bulk-count>0</b> 项已选</span>
            <div class="bulk__acts">
                <?php if ($isTrash): ?>
                    <button class="chipbtn" type="button" data-act="restore"><?= lucide('rotate-ccw', 'chipbtn__ico') ?>恢复</button>
                    <button class="chipbtn chipbtn--danger" type="button" data-act="purge"><?= lucide('trash-2', 'chipbtn__ico') ?>彻底删除</button>
                <?php else: ?>
                    <button class="chipbtn" type="button" data-act="download"><?= lucide('download', 'chipbtn__ico') ?>下载</button>
                    <button class="chipbtn" type="button" data-act="move"><?= lucide('move', 'chipbtn__ico') ?>移动</button>
                    <button class="chipbtn" type="button" data-act="copy"><?= lucide('copy', 'chipbtn__ico') ?>复制</button>
                    <button class="chipbtn" type="button" data-act="favorite"><?= lucide('star', 'chipbtn__ico') ?>收藏</button>
                    <button class="chipbtn" type="button" data-act="tag"><?= lucide('tag', 'chipbtn__ico') ?>标签</button>
                    <button class="chipbtn" type="button" data-act="archive"><?= lucide('file-archive', 'chipbtn__ico') ?>打包</button>
                    <button class="chipbtn" type="button" data-act="share"><?= lucide('share-2', 'chipbtn__ico') ?>分享</button>
                    <button class="chipbtn" type="button" data-act="color"><?= lucide('palette', 'chipbtn__ico') ?>标记</button>
                    <button class="chipbtn" type="button" data-act="lock"><?= lucide('lock', 'chipbtn__ico') ?>锁定</button>
                    <button class="chipbtn" type="button" data-act="hidden"><?= lucide('eye-off', 'chipbtn__ico') ?>隐藏</button>
                    <button class="chipbtn chipbtn--danger" type="button" data-act="delete"><?= lucide('trash-2', 'chipbtn__ico') ?>删除</button>
                <?php endif; ?>
                <button class="chipbtn chipbtn--quiet" type="button" data-act="clear">取消</button>
            </div>
        </div>

        <div class="listwrap" data-listwrap>
            <div class="listhead" data-listhead>
                <span class="listhead__pick"><input type="checkbox" class="pick" data-pick-all aria-label="全选"></span>
                <button class="listhead__col listhead__col--name" type="button" data-sort-key="name">名称</button>
                <button class="listhead__col listhead__col--size" type="button" data-sort-key="size">大小</button>
                <button class="listhead__col listhead__col--time" type="button" data-sort-key="time"><?= $isTrash ? '删除时间' : '修改时间' ?></button>
                <span class="listhead__col listhead__col--act"></span>
            </div>

            <ul class="rows" data-rows>
                <?php foreach ($children as $i => $n): ?>
                    <?php $row = $renderRow($n); echo str_replace('class="row"', 'class="row" style="--i:' . $i . '"', $row); ?>
                <?php endforeach; ?>
            </ul>

            <ul class="cards" data-cards>
                <?php foreach ($children as $i => $n): ?>
                    <?php echo str_replace('class="card"', 'class="card" style="--i:' . $i . '"', $renderCard($n)); ?>
                <?php endforeach; ?>
            </ul>

            <div class="listfoot" data-listfoot<?= $children === [] ? ' hidden' : '' ?>>
                <span data-listfoot-text><?= e(sprintf(
                    '%d 个文件夹 · %d 个文件 · 合计 %s',
                    (int) $childStats['folders'],
                    (int) $childStats['files'],
                    format_bytes((float) $childStats['bytes'])
                )) ?></span>
                <?php if ($hasMore): ?>
                    <span class="listfoot__more">已显示前 <?= count($children) ?> 项，继续下拉加载更多</span>
                <?php endif; ?>
            </div>

            <?php if ($hasMore): ?>
                <?php /* 哨兵：进入视口就按 offset 续取下一页，避免一次性渲染整个大目录 */ ?>
                <div class="listmore" data-listmore data-next="<?= (int) $nextOffset ?>">
                    <span class="listmore__hint">继续下拉加载更多</span>
                </div>
            <?php endif; ?>

            <div class="void" data-void<?= $children === [] ? '' : ' hidden' ?>>
                <span class="void__glyph"><?= lucide($isTrash ? 'trash-2' : ($isFav ? 'star' : 'folder-open'), 'void__ico') ?></span>
                <p class="void__title"><?= e(match (true) {
                    $isTrash => '回收站是空的',
                    $isFav   => '还没有收藏',
                    default  => '这里还什么都没有',
                }) ?></p>
                <p class="void__hint"><?= e(match (true) {
                    $isTrash => '删除的文件会在这里暂存，保留期内可随时恢复。',
                    $isFav   => '在文件上右键，或用批量操作栏的「收藏」把常用条目收进来。',
                    default  => '把文件拖到这里，或点右上角「上传」开始。',
                }) ?></p>
                <?php if (!$isTrash && !$isFav): ?>
                    <button class="cta cta--inline" type="button" data-upload-trigger>
                        <span class="cta__label">上传文件</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <section class="queue" data-queue hidden aria-label="传输队列">
        <header class="queue__head">
            <span class="queue__title">传输</span>
            <span class="queue__sum" data-queue-sum></span>
            <div class="queue__acts">
                <button class="iconbtn" type="button" data-queue-clear aria-label="清除已完成"><?= lucide('trash-2') ?></button>
                <button class="iconbtn" type="button" data-queue-close aria-label="收起"><?= lucide('chevron-down') ?></button>
            </div>
        </header>
        <ul class="queue__list" data-queue-list></ul>
    </section>
</div>

<div class="dropzone" data-dropzone hidden aria-hidden="true">
    <div class="dropzone__frame">
        <?= lucide('cloud-upload', 'dropzone__ico') ?>
        <p class="dropzone__title">松手后确认存放位置</p>
        <p class="dropzone__hint" data-dropzone-hint>文件会存到当前目录</p>
    </div>
</div>

<input type="file" multiple hidden data-file-input>
<input type="file" multiple hidden webkitdirectory directory data-folder-input>

<?php view('partials/modal') ?>

<script>
// 首屏的行是服务端渲染的，但 files.js 的 state.nodes 只在 renderList() 里被填充。
// 不把同一份数据交给前端的话，刚打开页面时全选、右键菜单、行内「更多操作」
// 以及重命名/移动/复制/删除等全部对话框都会静默失效，直到发生一次 AJAX 刷新才「自己好了」。
window.__FILES__ = <?= json_encode(array_values($children), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '[]' ?>;

// 上传弹窗的可选存放位置（本地磁盘 + 本人可用的 s3 源），以及上次记住的选择
window.__UPLOAD__ = {
    sources: <?= json_encode($uploadChoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: '[]' ?>,
    defaultSource: <?= (int) $uploadSourceId ?>
};
</script>
<div class="ctxmenu" data-ctx hidden role="menu"></div>
