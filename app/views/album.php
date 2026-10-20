<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array<string, list<array>> $groups 月份 => 节点列表 */
/** @var int $page */
/** @var bool $hasMore */
/** @var int $total */
/** @var array{used:int,total:int} $quota */
?>
<div class="app" data-app data-section="album" data-page="album">

    <?php view('partials/spine', ['section' => 'album', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="trail">
            <nav class="crumbs" aria-label="位置">
                <span class="crumbs__here" aria-current="page">相册</span>
                <span class="crumbs__note"><?= (int) $total ?> 张图片 · 按上传时间倒序</span>
            </nav>
        </div>

        <?php if ($groups === []): ?>
            <div class="listwrap">
                <div class="void">
                    <span class="void__glyph"><?= lucide('image', 'void__ico') ?></span>
                    <p class="void__title">相册还空着</p>
                    <p class="void__hint">上传任意图片后，这里会按月份把照片铺开。</p>
                    <a class="cta cta--inline" href="<?= e(url('files.list')) ?>">
                        <span class="cta__label">去上传</span>
                        <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="listwrap alb" data-album>
                <?php foreach ($groups as $month => $items): ?>
                    <section class="alb__group">
                        <h2 class="alb__month"><?= e((string) $month) ?><i><?= count($items) ?></i></h2>
                        <div class="alb__grid">
                            <?php foreach ($items as $n): ?>
                                <a class="alb__cell" href="<?= e(url('preview.open', ['id' => (int) $n['id']])) ?>"
                                   title="<?= e((string) $n['name']) ?>">
                                    <img class="alb__img" loading="lazy" decoding="async" alt="<?= e((string) $n['name']) ?>"
                                         src="<?= e(url('preview.thumb', ['id' => (int) $n['id']])) ?>"
                                         onerror="this.onerror=null;this.src='<?= e(url('preview.raw', ['id' => (int) $n['id']])) ?>'">
                                    <span class="alb__cap"><?= e((string) $n['name']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php if ($hasMore): ?>
                    <div class="alb__more" data-more data-next="<?= (int) $page + 1 ?>">
                        <span class="alb__more-hint">继续下拉加载更多</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php view('partials/modal') ?>
