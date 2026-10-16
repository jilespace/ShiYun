<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $rows */
/** @var list<array> $actions */
/** @var int $total */
/** @var int $pageNo */
/** @var int $per */
/** @var string $filter */
/** @var array{used:int,total:int} $quota */

$pages = max(1, (int) ceil($total / max(1, $per)));
?>
<div class="app" data-app data-section="settings" data-page="activity">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php
            $tab = 'activity';
            require BASE_PATH . '/app/views/partials/settings_tabs.php';
            ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">操作日志</h1>
                    <p class="pagehead__sub">
                        上传、重命名、分享、删除……每一步都留痕。单条文件的操作也会出现在该文件的详情里。
                    </p>
                </div>
            </div>

            <div class="grid grid--2">
                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">记录</h2>
                        <span class="panel__note">共 <?= $total ?> 条</span>
                    </div>

                    <?php if ($rows === []): ?>
                        <div class="panel__body">
                            <div class="blank">
                                <?= lucide('activity', 'blank__ico') ?>
                                <p class="blank__title">暂无记录</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="tblwrap">
                            <table class="tbl">
                                <thead><tr><th>时间</th><th>操作</th><th>详情</th></tr></thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td class="muted nowrap"><?= e(time_ago((string) $row['created_at'])) ?></td>
                                            <td class="nowrap">
                                                <span class="pill">
                                                    <?= e(activity_label((string) $row['action'])) ?>
                                                </span>
                                            </td>
                                            <td class="muted"><?= e((string) ($row['detail'] ?? '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($pages > 1): ?>
                            <div class="panel__body" style="border-top:1px solid var(--line)">
                                <nav class="pager">
                                    <?php
                                    $base = $filter !== '' ? ['action' => $filter] : [];
                                    ?>
                                    <?php if ($pageNo > 1): ?>
                                        <a href="<?= e(url('security.activity', $base + ['page' => $pageNo - 1])) ?>">上一页</a>
                                    <?php endif; ?>
                                    <span class="is-on"><?= $pageNo ?></span>
                                    <?php if ($pageNo < $pages): ?>
                                        <a href="<?= e(url('security.activity', $base + ['page' => $pageNo + 1])) ?>">下一页</a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">按操作类型</h2>
                        <?php if ($filter !== ''): ?>
                            <a class="panel__note" href="<?= e(url('security.activity')) ?>">清除筛选</a>
                        <?php endif; ?>
                    </div>
                    <div class="tblwrap">
                        <table class="tbl">
                            <tbody>
                                <?php foreach ($actions as $a): ?>
                                    <tr>
                                        <td>
                                            <a href="<?= e(url('security.activity', ['action' => (string) $a['action']])) ?>"
                                               style="text-decoration:none;color:<?= $filter === $a['action'] ? 'var(--signal-2)' : 'var(--ink)' ?>">
                                                <?= e(activity_label((string) $a['action'])) ?>
                                            </a>
                                        </td>
                                        <td class="num"><?= (int) $a['times'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
