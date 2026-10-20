<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $items */
/** @var array{used:int,total:int} $quota */
/** @var list<array> $directs */
/** @var string $tab */

$active = count(array_filter($items, static fn (array $s): bool => $s['status'] === 'active'));
$totalViews = array_sum(array_map(static fn (array $s): int => (int) $s['views'], $items));
$totalDowns = array_sum(array_map(static fn (array $s): int => (int) $s['downloads'], $items));

$permLabel = static fn (string $p): string => match ($p) {
    'preview'  => '仅预览',
    'upload'   => '可下载并上传',
    'inbox'    => '收取箱',
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
                        文件夹还能开成收取箱：对方只能上传，看不到里面的内容。
                        在文件列表里右键「分享」也能创建。
                    </p>
                </div>
            </div>

            <div class="seg2" style="margin-bottom:18px">
                <a href="<?= e(url('shares.list')) ?>"
                   class="<?= $tab === 'shares' ? 'is-on' : '' ?>">分享链接</a>
                <a href="<?= e(url('shares.list', ['tab' => 'direct'])) ?>"
                   class="<?= $tab === 'direct' ? 'is-on' : '' ?>">直链</a>
            </div>

            <?php if ($tab === 'shares'): ?>
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
                                    <th class="num">浏览</th><th class="num">下载 / 收到</th><th>状态</th><th></th>
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
                                                <?php if (!empty($s['isInbox'])): ?>
                                                    <?= ($s['maxFiles'] ?? null) !== null
                                                        ? ' · 上限 ' . (int) $s['maxFiles']
                                                        : ' · 不限量' ?>
                                                <?php else: ?>
                                                    <?= (int) ($s['maxDownloads'] ?? 0) === 1
                                                        ? ' · 一次'
                                                        : ((int) ($s['maxDownloads'] ?? 0) > 1
                                                            ? ' · 限 ' . (int) $s['maxDownloads'] . ' 次'
                                                            : '') ?>
                                                <?php endif; ?>
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
                                        <td class="num">
                                            <?php if (!empty($s['isInbox'])): ?>
                                                <?= (int) $s['uploads'] ?>
                                            <?php else: ?>
                                                <?= (int) $s['downloads'] ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="pill<?= $live ? '' : ' pill--muted' ?>">
                                                <span class="pill__dot" style="--pill:<?= $live ? '#4ad07a' : 'rgba(244,242,236,.32)' ?>"></span>
                                                <?= $live ? '生效中' : (!empty($s['inboxFull']) ? '已收满' : '已失效') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="tbl__acts">
                                                <?php if (!empty($s['isInbox'])): ?>
                                                    <button class="chipbtn" type="button"
                                                            data-share-received="<?= (int) $s['id'] ?>">查看收到</button>
                                                <?php endif; ?>
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
            <?php endif; ?>

            <?php if ($tab === 'direct'): ?>
            <?php
            // 文件在回收站的直链实际不可用，不计入「生效中」。
            $dActive = count(array_filter($directs,
                static fn (array $d): bool => $d['active'] && !$d['nodeTrashed']));
            $dHits = array_sum(array_map(static fn (array $d): int => (int) $d['hits'], $directs));
            $dBound = count(array_filter($directs, static fn (array $d): bool => $d['ipAllow'] !== ''));
            ?>
            <div class="grid grid--4" style="margin-bottom:22px">
                <div class="tile">
                    <span class="tile__label">生效中</span>
                    <span class="tile__value"><?= $dActive ?><small>条</small></span>
                </div>
                <div class="tile">
                    <span class="tile__label">累计命中</span>
                    <span class="tile__value"><?= $dHits ?></span>
                </div>
                <div class="tile">
                    <span class="tile__label">带来源限制</span>
                    <span class="tile__value"><?= $dBound ?></span>
                </div>
                <div class="tile tile--signal">
                    <span class="tile__label">历史总数</span>
                    <span class="tile__value"><?= count($directs) ?></span>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">直链</h2>
                    <span class="panel__note">免登录、无落地页</span>
                </div>

                <?php if ($directs === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('plug', 'blank__ico') ?>
                            <p class="blank__title">还没有直链</p>
                            <p class="blank__hint">到文件列表里右键一个文件，选「直链」就能生成。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="hint" style="margin:0 0 14px">
                        填了来源限制的直链，在反向代理或内网穿透后面拿不到访客真实地址，会一律拒绝——那种环境请留空。
                    </p>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>文件</th><th>直链</th><th>来源限制</th><th>有效期</th>
                                    <th class="num">命中</th><th>最近访问</th><th>状态</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($directs as $d): ?>
                                    <?php
                                    // 三种状态：生效中 / 文件在回收站（直链返回 410）/ 已失效（撤销或过期）
                                    $dLive  = $d['active'] && !$d['nodeTrashed'];
                                    $dTrash = $d['active'] && $d['nodeTrashed'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="tbl__name">
                                                <?= lucide((string) ($d['node']['icon'] ?? 'file')) ?>
                                                <span><?= e((string) ($d['node']['name'] ?? '—')) ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="tbl__name tbl__link">
                                                <code class="tbl__url" title="<?= e((string) $d['url']) ?>"><?= e((string) $d['url']) ?></code>
                                                <button class="minibtn" type="button"
                                                        data-direct-copy="<?= e((string) $d['url']) ?>"
                                                        aria-label="复制直链"><?= lucide('copy') ?></button>
                                                <button class="minibtn" type="button"
                                                        data-direct-copy="<?= e((string) $d['urlDownload']) ?>"
                                                        aria-label="复制下载直链" title="下载直链（&amp;dl=1）"><?= lucide('download') ?></button>
                                            </div>
                                        </td>
                                        <td class="muted nowrap"><?= $d['ipAllow'] !== '' ? e((string) $d['ipAllow']) : '不限' ?></td>
                                        <td class="muted nowrap"><?= e((string) $d['expiresText']) ?></td>
                                        <td class="num"><?= (int) $d['hits'] ?></td>
                                        <td class="muted nowrap"><?= e((string) $d['lastAccess']) ?></td>
                                        <td>
                                            <span class="pill<?= $dLive ? '' : ' pill--muted' ?>">
                                                <span class="pill__dot" style="--pill:<?= $dLive ? '#4ad07a' : 'rgba(244,242,236,.32)' ?>"></span>
                                                <?= $dLive ? '生效中' : ($dTrash ? '文件在回收站' : '已失效') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="tbl__acts">
                                                <?php if ($d['active']): ?>
                                                    <button class="chipbtn chipbtn--danger" type="button"
                                                            data-api="direct.revoke"
                                                            data-payload="<?= e(json_encode(['ids' => [(int) $d['id']]])) ?>"
                                                            data-confirm="作废后这条直链立即失效，且不可恢复。"
                                                            data-title="作废直链" data-label="作废" data-danger="1"
                                                            data-after="reload">作废</button>
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
            <?php endif; ?>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
