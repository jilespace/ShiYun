<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $rows */
/** @var list<array> $devices */
/** @var int $total */
/** @var int $pageNo */
/** @var int $per */
/** @var array{used:int,total:int} $quota */

$pages = max(1, (int) ceil($total / max(1, $per)));
$failCount = count(array_filter($rows, static fn (array $r): bool => $r['status'] !== 'success'));

/** 最近的新设备：出现次数为 1 的设备视为「不常见」 */
$unusual = array_values(array_filter($devices, static fn (array $d): bool => (int) $d['times'] <= 1));
?>
<div class="app" data-app data-section="settings" data-page="loginlog">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php
            $tab = 'logs';
            require BASE_PATH . '/app/views/partials/settings_tabs.php';
            ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">登录日志</h1>
                    <p class="pagehead__sub">
                        每一次登录尝试都记录在案。连续 5 次失败会锁定该账号 15 分钟。
                        看到不认识的设备或 IP，请立刻改密。
                    </p>
                </div>
            </div>

            <?php if ($unusual !== []): ?>
                <div class="hint hint--warn" style="margin-bottom:20px">
                    <?= lucide('alert-triangle') ?>
                    <span>
                        有 <?= count($unusual) ?> 台设备只成功登录过一次，属于常见异常信号：
                        <?php foreach (array_slice($unusual, 0, 3) as $d): ?>
                            <b><?= e((string) $d['device']) ?></b>（<?= e(time_ago((string) $d['last_at'])) ?>）
                        <?php endforeach; ?>
                        如果不是你自己的设备，建议立即修改密码。
                    </span>
                </div>
            <?php endif; ?>

            <div class="grid grid--3" style="margin-bottom:22px">
                <div class="tile">
                    <span class="tile__label">记录总数</span>
                    <span class="tile__value"><?= $total ?></span>
                </div>
                <div class="tile">
                    <span class="tile__label">本页失败</span>
                    <span class="tile__value"><?= $failCount ?></span>
                    <span class="tile__hint">失败与锁定都会记下来</span>
                </div>
                <div class="tile">
                    <span class="tile__label">常用设备</span>
                    <span class="tile__value"><?= count($devices) ?><small>台</small></span>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">最近 <?= $per ?> 条</h2>
                    <?php if ($pages > 1): ?>
                        <span class="panel__note">第 <?= $pageNo ?> / <?= $pages ?> 页</span>
                    <?php endif; ?>
                </div>

                <?php if ($rows === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('history', 'blank__ico') ?>
                            <p class="blank__title">暂无记录</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr><th>时间</th><th>结果</th><th>设备</th><th>IP</th><th>说明</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $status = (string) $row['status'];
                                    $tone = match ($status) {
                                        'success' => '#4ad07a',
                                        'locked'  => '#ffa23a',
                                        default   => '#ff5a5a',
                                    };
                                    $label = match ($status) {
                                        'success' => '成功',
                                        'locked'  => '已锁定',
                                        default   => '失败',
                                    };
                                    ?>
                                    <tr>
                                        <td class="muted nowrap"><?= e((string) $row['created_at']) ?></td>
                                        <td class="nowrap">
                                            <span class="pill">
                                                <span class="pill__dot" style="--pill:<?= $tone ?>"></span><?= e($label) ?>
                                            </span>
                                        </td>
                                        <td class="muted"><?= e((string) ($row['device'] ?? '—')) ?></td>
                                        <td class="muted nowrap"><?= e((string) $row['ip']) ?></td>
                                        <td class="muted"><?= e((string) ($row['reason'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ($pages > 1): ?>
                    <div class="panel__body" style="border-top:1px solid var(--line)">
                        <nav class="pager">
                            <?php if ($pageNo > 1): ?>
                                <a href="<?= e(url('security.logs', ['page' => $pageNo - 1])) ?>">上一页</a>
                            <?php endif; ?>
                            <span class="is-on"><?= $pageNo ?></span>
                            <?php if ($pageNo < $pages): ?>
                                <a href="<?= e(url('security.logs', ['page' => $pageNo + 1])) ?>">下一页</a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel" style="margin-top:22px">
                <div class="panel__head"><h2 class="panel__title">设备汇总</h2></div>
                <div class="tblwrap">
                    <table class="tbl">
                        <thead><tr><th>设备</th><th class="num">成功次数</th><th>最近一次</th></tr></thead>
                        <tbody>
                            <?php foreach ($devices as $d): ?>
                                <tr>
                                    <td><?= e((string) $d['device']) ?></td>
                                    <td class="num"><?= (int) $d['times'] ?></td>
                                    <td class="muted nowrap"><?= e(time_ago((string) $d['last_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
