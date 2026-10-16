<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $items */
/** @var array{used:int,total:int} $quota */

$active = count(array_filter($items, static fn (array $s): bool => $s['status'] === 'active'));
$totalViews = array_sum(array_map(static fn (array $s): int => (int) $s['views'], $items));
$totalDowns = array_sum(array_map(static fn (array $s): int => (int) $s['downloads'], $items));

$permLabel = static fn (string $p): string => match ($p) {
    'preview'  => '仅预览',
    'upload'   => '可下载并上传',
    default    => '可下载',
};
?>
<div class="app" data-app data-section="shares" data-page="shares">
    <?php view('partials/spine', ['section' => 'shares', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">分享</h1>
                    <p class="pagehead__sub">
                        给文件或文件夹生成一个链接，可以加提取密码、设有效期、限制权限。
                        在文件列表里右键「分享」也能创建。
                    </p>
                </div>
            </div>

            <div class="grid grid--4" style="margin-bottom:22px">
                <div class="tile">
                    <span class="tile__label">生效中</span>
                    <span class="tile__value"><?= $active ?><small>条</small></span>
                </div>
                <div class="tile">
                    <span class="tile__label">累计浏览</span>
                    <span class="tile__value"><?= $totalViews ?></span>
                </div>
                <div class="tile">
                    <span class="tile__label">累计下载</span>
                    <span class="tile__value"><?= $totalDowns ?></span>
                </div>
                <div class="tile tile--signal">
                    <span class="tile__label">历史总数</span>
                    <span class="tile__value"><?= count($items) ?></span>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">分享记录</h2>
                    <span class="panel__note">含已过期与已取消</span>
                </div>

                <?php if ($items === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('share-2', 'blank__ico') ?>
                            <p class="blank__title">还没有分享</p>
                            <p class="blank__hint">到文件列表里选中一项，右键选「分享」就能生成链接。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>内容</th><th>权限</th><th>链接</th><th>有效期</th>
                                    <th class="num">浏览</th><th class="num">下载</th><th>状态</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $s): ?>
                                    <?php
                                    $live = $s['status'] === 'active';
                                    $isFolder = ($s['node']['kind'] ?? 'file') === 'folder';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="tbl__name">
                                                <?= lucide($isFolder ? 'folder' : ($s['node']['icon'] ?? 'file')) ?>
                                                <span><?= e((string) ($s['node']['name'] ?? '—')) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="pill">
                                                <?= e($permLabel((string) $s['permission'])) ?>
                                                <?= $s['hasPassword'] ? ' · 需密码' : '' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="tbl__name tbl__link">
                                                <code class="tbl__url" title="<?= e((string) $s['url']) ?>"><?= e((string) $s['url']) ?></code>
                                                <button class="minibtn" type="button" data-share-copy="<?= e((string) $s['url']) ?>" aria-label="复制链接"><?= lucide('copy') ?></button>
                                                <button class="minibtn" type="button" data-share-qr="<?= (int) $s['id'] ?>" aria-label="二维码"><?= lucide('qr-code') ?></button>
                                            </div>
                                        </td>
                                        <td class="muted nowrap"><?= e((string) $s['expiresText']) ?></td>
                                        <td class="num"><?= (int) $s['views'] ?></td>
                                        <td class="num"><?= (int) $s['downloads'] ?></td>
                                        <td>
                                            <span class="pill<?= $live ? '' : ' pill--muted' ?>">
                                                <span class="pill__dot" style="--pill:<?= $live ? '#4ad07a' : 'rgba(244,242,236,.32)' ?>"></span>
                                                <?= $live ? '生效中' : '已失效' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="tbl__acts">
                                                <button class="chipbtn" type="button" data-share-visits="<?= (int) $s['id'] ?>">访问记录</button>
                                                <?php if ($live): ?>
                                                    <button class="chipbtn chipbtn--danger" type="button"
                                                            data-api="shares.revoke"
                                                            data-payload="<?= e(json_encode(['ids' => [(int) $s['id']]])) ?>"
                                                            data-confirm="取消后该链接立即失效。"
                                                            data-title="取消分享" data-label="取消分享" data-danger="1"
                                                            data-after="reload">取消</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
