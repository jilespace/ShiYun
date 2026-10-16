<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $tags */
/** @var list<string> $palette */
/** @var array{used:int,total:int} $quota */

$totalUse = array_sum(array_map(static fn (array $t): int => (int) $t['use_count'], $tags));
?>
<div class="app" data-app data-section="tags" data-page="tags" data-palette="<?= e(json_encode($palette)) ?>">
    <?php view('partials/spine', ['section' => 'tags', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">标签</h1>
                    <p class="pagehead__sub">
                        <?= count($tags) ?> 个标签，覆盖 <?= $totalUse ?> 处标记。标签用在文件上，方便跨目录归类。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost" type="button" id="new-tag">
                        <?= lucide('plus', 'ghost__ico') ?><span>新建标签</span>
                    </button>
                </div>
            </div>

            <?php if ($tags === []): ?>
                <div class="blank">
                    <?= lucide('tag', 'blank__ico') ?>
                    <p class="blank__title">还没有标签</p>
                    <p class="blank__hint">标签可以跨目录归类文件——比如把不同项目里的素材都标成「参考图」。</p>
                </div>
            <?php else: ?>
                <div class="grid grid--3">
                    <?php foreach ($tags as $tag): ?>
                        <div class="tagcard" style="--tag:<?= e((string) $tag['color']) ?>">
                            <span class="tagcard__swatch" aria-hidden="true"></span>
                            <a class="tagcard__name" href="<?= e(url('search.results', ['tag' => (int) $tag['id']])) ?>"
                               style="text-decoration:none;flex:1;min-width:0"><?= e((string) $tag['name']) ?></a>
                            <span class="tagcard__count"><?= (int) $tag['use_count'] ?></span>
                            <span class="tagcard__acts">
                                <button class="minibtn" type="button" aria-label="重命名"
                                        data-tag-rename="<?= (int) $tag['id'] ?>" data-name="<?= e((string) $tag['name']) ?>">
                                    <?= lucide('pencil') ?>
                                </button>
                                <button class="minibtn" type="button" aria-label="删除"
                                        data-tag-delete="<?= (int) $tag['id'] ?>">
                                    <?= lucide('trash-2') ?>
                                </button>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
