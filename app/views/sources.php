<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $sources */
/** @var array $drivers */
/** @var int $primaryUsed */
/** @var array{used:int,total:int} $quota */
?>
<div class="app" data-app data-section="sources" data-page="sources"
     data-drivers="<?= e(json_encode($drivers, JSON_UNESCAPED_UNICODE)) ?>">
    <?php view('partials/spine', ['section' => 'sources', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">存储源</h1>
                    <p class="pagehead__sub">
                        文件内容按 SHA-256 存在内置仓库里（同内容只落一份盘）。这里可以再加外部存储，
                        把副本推到 S3、FTP 或 SFTP 上做冗余。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost" type="button" id="new-source">
                        <?= lucide('plus', 'ghost__ico') ?><span>添加存储源</span>
                    </button>
                </div>
            </div>

            <div class="grid grid--2" style="margin-bottom:22px">
                <div class="tile">
                    <span class="tile__label">内置仓库</span>
                    <span class="tile__value"><?= e(format_bytes((float) $primaryUsed)) ?></span>
                    <span class="tile__hint">内容寻址 · 自动去重 · 秒传</span>
                </div>
                <div class="tile">
                    <span class="tile__label">外部存储源</span>
                    <span class="tile__value"><?= count($sources) ?><small>个</small></span>
                    <span class="tile__hint">用于冗余副本与迁移</span>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">已接入</h2>
                    <span class="panel__note">密钥只以掩码回显</span>
                </div>

                <?php if ($sources === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('server', 'blank__ico') ?>
                            <p class="blank__title">还没有外部存储源</p>
                            <p class="blank__hint">加上一个 S3 或 FTP，就可以把重要文件同步出第二份。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th>名称</th><th>驱动</th><th>状态</th>
                                    <th class="num">已挂载</th><th class="num">已推送</th><th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sources as $src): ?>
                                    <tr>
                                        <td>
                                            <div class="tbl__name"><?= lucide('server') ?>
                                                <span><?= e((string) $src['name']) ?></span></div>
                                        </td>
                                        <td class="muted"><?= e((string) $src['driverLabel']) ?></td>
                                        <td>
                                            <span class="pill<?= $src['status'] === 'ok' ? '' : ' pill--on' ?>">
                                                <span class="pill__dot" style="--pill:<?= $src['status'] === 'ok' ? '#4ad07a' : '#ff5a5a' ?>"></span>
                                                <?= $src['status'] === 'ok' ? '正常' : '异常' ?>
                                            </span>
                                        </td>
                                        <td class="num"><?= (int) $src['nodeCount'] ?></td>
                                        <td class="num"><?= e(format_bytes((float) $src['used_bytes'])) ?></td>
                                        <td>
                                            <div class="tbl__acts">
                                                <button class="chipbtn" type="button" data-source-test="<?= (int) $src['id'] ?>">测试连通</button>
                                                <?php if ($src['driver'] !== 'local'): ?>
                                                    <button class="chipbtn" type="button" data-source-push="<?= (int) $src['id'] ?>">同步副本</button>
                                                <?php endif; ?>
                                                <button class="chipbtn chipbtn--danger" type="button"
                                                        data-api="sources.delete"
                                                        data-payload="<?= e(json_encode(['id' => (int) $src['id']])) ?>"
                                                        data-confirm="移除后不会删除任何文件内容，只是解除绑定。"
                                                        data-title="移除存储源" data-label="移除" data-danger="1"
                                                        data-after="reload">移除</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel" style="margin-top:22px">
                <div class="panel__head"><h2 class="panel__title">支持的驱动</h2></div>
                <div class="tblwrap">
                    <table class="tbl">
                        <thead><tr><th>驱动</th><th>可用性</th><th>说明</th></tr></thead>
                        <tbody>
                            <?php foreach ($drivers as $key => $info): ?>
                                <tr>
                                    <td><div class="tbl__name"><?= lucide('plug') ?><span><?= e((string) $info['label']) ?></span></div></td>
                                    <td>
                                        <span class="pill<?= $info['available'] ? '' : ' pill--muted' ?>">
                                            <?= $info['available'] ? '可用' : '不可用' ?>
                                        </span>
                                    </td>
                                    <td class="muted"><?= e((string) $info['note']) ?></td>
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
