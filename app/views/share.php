<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var string $state  ok | locked | gone */
/** @var string $token */
/** @var array|null $share */
/** @var array|null $node */
/** @var string|null $owner */
/** @var list<array> $listing */
/** @var list<array> $crumbs */
/** @var int $current */
/** @var int $rootId */
/** @var array<string,string> $errors */

$appName = (string) config('app_name');
$isInbox = ($share['permission'] ?? '') === 'inbox';
// 收取箱既不列目录也不能下载：只有「可下载」和「可下载并上传」才给下载入口
$canDownload = in_array((string) ($share['permission'] ?? 'preview'), ['download', 'upload'], true);
$canUpload = in_array((string) ($share['permission'] ?? ''), SHARE_UPLOAD_PERMISSIONS, true)
    && ($node['kind'] ?? '') === 'folder';

// 「已失效」状态下 shares_resolve() 只传 title/state/token，不会传 crumbs；
// 不兜底会让过期链接的落地页直接 500，而不是显示「分享不存在」。
$crumbs = array_values(array_filter($crumbs ?? [], static fn (array $c): bool => (int) $c['id'] !== 0));
?>
<div class="sharewrap">
    <div class="sharebrand">
        <?= lucide('archive') ?>
        <span><?= e($appName) ?> · 分享</span>
    </div>

    <?php if ($state === 'gone'): ?>
        <div class="sharebox">
            <div class="sharebox__head">
                <span class="sharebox__glyph"><?= lucide('x-circle') ?></span>
                <div class="sharebox__meta">
                    <h1 class="sharebox__name"><?= e($title) ?></h1>
                    <p class="sharebox__sub">链接可能已过期、被取消，或内容已被移除</p>
                </div>
            </div>
            <div class="sharebox__body">
                <p class="hint">如果你确信链接没问题，请联系分享者重新生成一条。</p>
            </div>
        </div>

    <?php elseif ($state === 'locked'): ?>
        <div class="sharebox">
            <div class="sharebox__head">
                <span class="sharebox__glyph"><?= lucide('lock-keyhole') ?></span>
                <div class="sharebox__meta">
                    <h1 class="sharebox__name"><?= e((string) $node['name']) ?></h1>
                    <p class="sharebox__sub">这条分享需要提取密码</p>
                </div>
            </div>
            <div class="sharebox__body">
                <?php if (isset($errors['form'])): ?>
                    <div class="note" role="alert">
                        <?= lucide('circle-alert', 'note__ico') ?><span><?= e($errors['form']) ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(url('shares.unlock')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="t" value="<?= e($token) ?>">
                    <label class="mfield">
                        <span class="mfield__label">提取密码</span>
                        <input class="minput" name="password" type="password" required autofocus
                               autocomplete="off" placeholder="请输入分享者给你的密码">
                    </label>
                    <div class="formactions">
                        <button class="mbtn mbtn--primary" type="submit">解锁查看</button>
                    </div>
                </form>
            </div>
        </div>

    <?php else: ?>
        <div class="sharebox">
            <div class="sharebox__head">
                <span class="sharebox__glyph">
                    <?= lucide(($node['kind'] ?? 'file') === 'folder' ? 'folder-open' : (string) ($node['icon'] ?? 'file')) ?>
                </span>
                <div class="sharebox__meta">
                    <h1 class="sharebox__name"><?= e((string) $node['name']) ?></h1>
                    <p class="sharebox__sub">
                        <?php if ($isInbox): ?>
                            <?= e((string) $owner) ?> 的收取箱 · 只能上传，看不到里面的内容
                            · <?= e((string) ($share['expiresText'] ?? '')) ?>
                        <?php else: ?>
                            <?= e((string) $owner) ?> 分享 ·
                            <?= ($node['kind'] ?? '') === 'folder'
                                ? (int) $node['childCount'] . ' 个项目'
                                : e((string) $node['sizeText']) ?>
                            · <?= e((string) ($share['expiresText'] ?? '')) ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="sharebox__acts">
                    <?php if ($canDownload): ?>
                        <a class="mbtn mbtn--primary"
                           href="<?= e(url('shares.download', ['t' => $token])) ?>">
                            <?= ($node['kind'] ?? '') === 'folder' ? '打包下载' : '下载' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="sharebox__body">
                <?php if (isset($notice)): ?>
                    <div class="note note--info" role="status">
                        <?= lucide('info', 'note__ico') ?><span><?= e($notice) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($isInbox): ?>
                    <?php if (($share['inboxNote'] ?? '') !== ''): ?>
                        <div class="inboxnote"><?= e((string) $share['inboxNote']) ?></div>
                    <?php endif; ?>

                <?php elseif (($node['kind'] ?? '') === 'file'): ?>
                    <?php
                    $mime = (string) ($node['mime'] ?? '');
                    $ext = (string) ($node['ext'] ?? '');
                    $kind = (string) ($node['type'] ?? 'other');
                    $raw = url('shares.raw', ['t' => $token]);
                    ?>
                    <?php if ($kind === 'image'): ?>
                        <img src="<?= e($raw) ?>" alt="<?= e((string) $node['name']) ?>"
                             style="max-width:100%;display:block;border:1px solid var(--line)">
                    <?php elseif ($kind === 'video'): ?>
                        <video src="<?= e($raw) ?>" controls playsinline
                               style="width:100%;max-height:56vh;background:#000;border:1px solid var(--line)"></video>
                    <?php elseif ($kind === 'audio'): ?>
                        <audio src="<?= e($raw) ?>" controls style="width:100%"></audio>
                    <?php else: ?>
                        <dl class="kv">
                            <dt>文件名</dt><dd><?= e((string) $node['name']) ?></dd>
                            <dt>大小</dt><dd><?= e((string) $node['sizeText']) ?></dd>
                            <dt>类型</dt><dd><?= e($mime !== '' ? $mime : '未知') ?></dd>
                            <dt>修改时间</dt><dd><?= e((string) $node['updatedText']) ?></dd>
                        </dl>
                        <p class="hint" style="margin-top:16px">
                            这类文件没法在浏览器里直接打开<?= $canDownload ? '，点右上角下载到本地查看。' : '，这条分享只允许预览，请联系分享者开放下载权限。' ?>
                        </p>
                    <?php endif; ?>

                <?php else: ?>
                    <?php if ($crumbs !== []): ?>
                        <nav class="crumbs" style="margin-bottom:14px" aria-label="路径">
                            <?php foreach ($crumbs as $i => $crumb): ?>
                                <?php $last = $i === count($crumbs) - 1; ?>
                                <?php if ($i > 0): ?><?= lucide('chevron-right', 'crumbs__sep') ?><?php endif; ?>
                                <?php if ($last): ?>
                                    <span class="crumbs__here"><?= e((string) $crumb['name']) ?></span>
                                <?php else: ?>
                                    <a class="crumbs__link"
                                       href="<?= e(url('shares.open', ['t' => $token, 'folder' => (int) $crumb['id']])) ?>"><?= e((string) $crumb['name']) ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>

                    <?php if ($listing === []): ?>
                        <p class="hint">这个文件夹是空的。</p>
                    <?php else: ?>
                        <ul class="sharelist">
                            <?php foreach ($listing as $item): ?>
                                <?php $isFolder = $item['kind'] === 'folder'; ?>
                                <li>
                                    <span class="sharelist__icon"><?= lucide((string) $item['icon']) ?></span>
                                    <?php if ($isFolder): ?>
                                        <a class="sharelist__name"
                                           href="<?= e(url('shares.open', ['t' => $token, 'folder' => (int) $item['id']])) ?>">
                                            <?= e((string) $item['name']) ?>
                                        </a>
                                        <span class="sharelist__size"><?= (int) $item['childCount'] ?> 项</span>
                                    <?php elseif ($canDownload): ?>
                                        <a class="sharelist__name"
                                           href="<?= e(url('shares.download', ['t' => $token, 'id' => (int) $item['id']])) ?>">
                                            <?= e((string) $item['name']) ?>
                                        </a>
                                        <span class="sharelist__size"><?= e((string) $item['sizeText']) ?></span>
                                    <?php else: ?>
                                        <span class="sharelist__name"><?= e((string) $item['name']) ?></span>
                                        <span class="sharelist__size"><?= e((string) $item['sizeText']) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($canUpload): ?>
                    <?php if ($isInbox): ?>
                        <div class="inboxfields">
                            <label class="mfield">
                                <span class="mfield__label">你的称呼（可选）</span>
                                <input class="minput" data-sender maxlength="40" placeholder="让主人知道是谁传的">
                            </label>
                            <label class="mfield">
                                <span class="mfield__label">留言（可选）</span>
                                <input class="minput" data-message maxlength="300" placeholder="一两句说明">
                            </label>
                        </div>
                    <?php endif; ?>

                    <div class="dropmini" data-share-drop style="margin-top:20px">
                        <?= lucide('cloud-upload') ?>
                        <p class="dropmini__title">把文件拖到这里，或点击选择</p>
                        <p class="dropmini__hint">
                            <?php if ($isInbox): ?>
                                文件会直接送到 <?= e((string) $owner) ?> 的「<?= e((string) $node['name']) ?>」
                                <?php if (($share['maxFiles'] ?? null) !== null): ?>
                                    <span data-inbox-left>· 还能提交
                                        <?= max(0, (int) $share['maxFiles'] - (int) $share['uploads']) ?> 个</span>
                                <?php endif; ?>
                            <?php else: ?>
                                上传到「<?= e((string) $node['name']) ?>」
                            <?php endif; ?>
                        </p>
                        <input type="file" multiple hidden data-share-file>
                    </div>
                    <ul class="upqueue" data-share-queue></ul>
                <?php endif; ?>
            </div>
        </div>

        <p class="sharefoot">
            <?= e($appName) ?> · 分享内容由上传者提供，请自行判断安全性
        </p>
    <?php endif; ?>
</div>

<div class="toasts" data-toasts aria-live="polite"></div>
