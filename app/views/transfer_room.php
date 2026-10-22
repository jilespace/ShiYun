<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var bool $gone */
/** @var bool $locked */
/** @var string $token */
/** @var array|null $room */
/** @var list<array> $files */
/** @var string $roomUrl */
$appName = (string) config('app_name');
?>
<div class="tp" data-page="transfer-room" data-token="<?= e($token) ?>" data-room-url="<?= e($roomUrl) ?>">
    <header class="tp__head">
        <a class="tp__brand" href="<?= e(url('transfer.index')) ?>">
            <?= lucide('archive', 'tp__mark') ?>
            <span><?= e($appName) ?> · 临时传输</span>
        </a>
        <a class="tp__login" href="<?= e(url('transfer.index')) ?>">再建一个</a>
    </header>

    <main class="tp__main">
        <?php if ($gone): ?>
            <section class="tp-lock">
                <h2>房间不存在或已关闭</h2>
                <p class="tp__hint">可能已过期、被创建者关闭，或链接不完整。</p>
                <a class="mbtn mbtn--primary" href="<?= e(url('transfer.index')) ?>">创建一个新房间</a>
            </section>
        <?php elseif ($locked): ?>
            <section class="tp-lock">
                <h2>需要提取密码</h2>
                <p class="tp__hint">这个房间设置了密码，输入后才能查看文件。</p>
                <label class="mfield">
                    <span class="mfield__label">提取密码</span>
                    <input class="minput" type="password" data-unlock-pass maxlength="32" autocomplete="off">
                </label>
                <button class="mbtn mbtn--primary" type="button" data-unlock><?= lucide('unlock', 'mbtn__ico') ?>解锁</button>
            </section>
        <?php else: ?>
            <section class="tp-room__head">
                <div>
                    <span class="tp__label">取件码</span>
                    <b class="tp-room__code"><?= e((string) $room['code']) ?></b>
                </div>
                <div class="tp-room__stats">
                    <span>剩余 <b data-expires></b></span>
                    <span><b data-used></b> / <b data-max></b></span>
                    <span><b data-count></b> 个文件</span>
                </div>
                <div class="tp__row" style="margin-left:auto">
                    <button class="chipbtn" type="button" data-copy-link><?= lucide('copy', 'chipbtn__ico') ?>复制链接</button>
                    <a class="chipbtn" href="?r=transfer.qr&amp;t=<?= e($token) ?>" target="_blank" rel="noopener">二维码</a>
                </div>
            </section>

            <label class="mfield" style="max-width:280px;margin-bottom:16px">
                <span class="mfield__label">你的称呼（可选）</span>
                <input class="minput" data-nickname maxlength="40" placeholder="让主人知道是谁传的">
            </label>

            <div class="tp-drop" data-drop role="button" tabindex="0" aria-label="选择要上传的文件">
                <?= lucide('cloud-upload') ?>
                <p class="tp-drop__title">把文件拖到这里，或点击选择</p>
                <p class="tp-drop__hint">双方都能上传和下载；单个文件不超过房间剩余容量。</p>
                <input type="file" multiple hidden data-file>
            </div>
            <ul class="tp-queue" data-queue></ul>

            <section class="tp-files">
                <div class="panel__head">
                    <h2 class="panel__title">文件</h2>
                    <span class="panel__note" data-files-note>每 3 秒自动刷新</span>
                </div>
                <div class="tblwrap">
                    <table class="tbl">
                        <thead><tr><th>名称</th><th class="num">大小</th><th>上传者</th><th>时间</th><th></th></tr></thead>
                        <tbody data-files></tbody>
                    </table>
                </div>
            </section>

            <div class="tp__row" data-creator-actions hidden style="margin-top:24px">
                <button class="mbtn mbtn--danger" type="button" data-close><?= lucide('trash-2', 'mbtn__ico') ?>关闭房间</button>
                <span class="tp__hint">关闭后链接立即失效，文件会被删除。</span>
            </div>
        <?php endif; ?>
    </main>

    <footer class="tp__foot">
        <span>文件临时保存在本服务器，到期或关闭后删除；管理员理论上可读取内容。</span>
        <span>公网使用请确保站点启用 HTTPS。</span>
    </footer>
</div>

<?php view('partials/modal') ?>
