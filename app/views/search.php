<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array $filters */
/** @var array $result */
/** @var bool $hasQuery */
/** @var list<array> $tags */
/** @var array|null $activeTag */
/** @var array{used:int,total:int} $quota */

$types = [
    'all'      => '全部',
    'folder'   => '文件夹',
    'image'    => '图片',
    'video'    => '视频',
    'audio'    => '音频',
    'document' => '文档',
    'archive'  => '压缩包',
    'code'     => '代码',
];

$scopes = [
    'name'    => '文件名',
    'content' => '文件内容',
    'all'     => '名称 + 内容',
];
?>
<div class="app" data-app data-section="search" data-page="search" data-q="<?= e((string) $filters['q']) ?>">
    <?php view('partials/spine', ['section' => 'search', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user, 'query' => (string) $filters['q']]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">
                        <?= $filters['q'] !== '' ? '搜索「' . e((string) $filters['q']) . '」' : '搜索与筛选' ?>
                    </h1>
                    <p class="pagehead__sub">
                        <?php if ($hasQuery): ?>
                            命中 <?= (int) $result['total'] ?> 项，显示前 <?= count($result['items']) ?> 项。
                        <?php else: ?>
                            按名称、内容、类型、时间、大小或标签筛选。内容检索需要先建立索引。
                        <?php endif; ?>
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost ghost--sm" type="button" id="reindex">
                        <?= lucide('refresh-cw', 'ghost__ico') ?><span>重建内容索引</span>
                    </button>
                </div>
            </div>

            <form class="filters" method="get" action="<?= e(url('')) ?>" data-search-form>
                <input type="hidden" name="r" value="search.results">

                <input class="minput" name="q" type="search" value="<?= e((string) $filters['q']) ?>"
                       placeholder="关键词" style="min-width:220px">

                <label class="mfield" style="gap:0">
                    <select class="select" name="scope" data-auto-submit>
                        <?php foreach ($scopes as $k => $label): ?>
                            <option value="<?= e($k) ?>"<?= $filters['scope'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <span class="filters__sep" aria-hidden="true"></span>

                <?php foreach ($types as $k => $label): ?>
                    <?php
                    $params = array_filter([
                        'q'     => (string) $filters['q'],
                        'scope' => (string) $filters['scope'],
                        'type'  => $k === 'all' ? null : $k,
                        'tag'   => $filters['tag'] > 0 ? $filters['tag'] : null,
                    ], static fn ($v): bool => $v !== null && $v !== '');
                    ?>
                    <a class="pill<?= $filters['type'] === $k ? ' pill--on' : '' ?>"
                       href="<?= e(url('search.results', $params)) ?>"><?= e($label) ?></a>
                <?php endforeach; ?>

                <span class="filters__sep" aria-hidden="true"></span>

                <input class="minput" name="from" type="date" value="<?= e((string) $filters['from']) ?>"
                       data-auto-submit title="起始修改时间" style="width:150px">
                <input class="minput" name="to" type="date" value="<?= e((string) $filters['to']) ?>"
                       data-auto-submit title="截止修改时间" style="width:150px">

                <label class="mfield" style="gap:0">
                    <select class="select" name="tag" data-auto-submit>
                        <option value="0">全部标签</option>
                        <?php foreach ($tags as $tag): ?>
                            <option value="<?= (int) $tag['id'] ?>"<?= $filters['tag'] === (int) $tag['id'] ? ' selected' : '' ?>>
                                <?= e((string) $tag['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button class="chipbtn" type="submit"><?= lucide('search', 'chipbtn__ico') ?>搜索</button>
                <a class="chipbtn chipbtn--quiet" href="<?= e(url('search.results')) ?>">清空</a>
            </form>

            <?php if (!$hasQuery): ?>
                <div class="blank">
                    <?= lucide('search', 'blank__ico') ?>
                    <p class="blank__title">输入关键词开始</p>
                    <p class="blank__hint">
                        想搜文档里的字？把「范围」切到「文件内容」。中文用 ngram 分词，短语也能命中。
                    </p>
                </div>
            <?php elseif ($result['items'] === []): ?>
                <div class="blank">
                    <?= lucide('search', 'blank__ico') ?>
                    <p class="blank__title">没有匹配的条目</p>
                    <p class="blank__hint">
                        试试放宽条件，或者点右上角「重建内容索引」把文本类文件重新扫一遍。
                    </p>
                </div>
            <?php else: ?>
                <ul class="hits">
                    <?php foreach ($result['items'] as $hit): ?>
                        <?php
                        $isFolder = $hit['kind'] === 'folder';
                        $href = $isFolder
                            ? url('files.list', ['folder' => (int) $hit['id']])
                            : url('preview.open', ['id' => (int) $hit['id']]);
                        ?>
                        <li class="hit">
                            <span class="hit__icon<?= $isFolder ? ' hit__icon--folder' : '' ?>"><?= lucide((string) $hit['icon']) ?></span>
                            <span class="hit__main">
                                <a class="hit__name" href="<?= e($href) ?>"><?= e((string) $hit['name']) ?></a>
                                <span class="hit__meta">
                                    <span><?= e((string) $hit['pathText']) ?></span>
                                    <span>·</span>
                                    <span><?= $isFolder ? (int) $hit['childCount'] . ' 项' : e((string) $hit['sizeText']) ?></span>
                                    <span>·</span>
                                    <span><?= e((string) $hit['updatedText']) ?></span>
                                    <?php foreach (array_slice($hit['tags'], 0, 3) as $tag): ?>
                                        <span class="chip" style="--chip:<?= e((string) $tag['color']) ?>"><?= e((string) $tag['name']) ?></span>
                                    <?php endforeach; ?>
                                </span>
                                <?php if (!empty($hit['snippet'])): ?>
                                    <p class="hit__snippet" data-snippet><?= e((string) $hit['snippet']) ?></p>
                                <?php endif; ?>
                            </span>
                            <span class="hit__when"><?= e((string) $hit['updatedText']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
