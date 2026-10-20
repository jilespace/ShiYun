<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var bool $loggedIn */
/** @var bool $allowRegistration */
/** @var bool $firstRun */

$appName = (string) config('app_name');
$appNameEn = (string) config('app_name_en');

$headline = ['把散落的文件，', '收进同一片云。'];
$charIndex = 0;

/** 逐字包裹，用于遮罩升起（与认证页同一套动效） */
$chars = static function (string $text) use (&$charIndex): string {
    $out = '';
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $out .= '<span class="ch" style="--i:' . $charIndex . '"><i>' . e($ch) . '</i></span>';
        $charIndex++;
    }
    return $out;
};

/* 入口决策：
   已登录 → 网盘；全新部署 → 创建第一个账号；开放注册 → 注册；其余 → 登录 */
$entryRoute = $loggedIn
    ? url('files.list')
    : ($firstRun ? url('auth.register') : ($allowRegistration ? url('auth.register') : url('auth.login')));
$entryLabel = $loggedIn ? '进入网盘'
    : ($firstRun ? '创建第一个账号'
    : ($allowRegistration ? '创建你的云空间' : '登录，进入云空间'));
?>

<div class="rail" aria-hidden="true"><span>拾云官网 · OFFICIAL SITE</span></div>

<main class="shell shell--site">

    <!-- ============================================================ 顶栏 -->
    <header class="topbar">
        <a class="brand" href="<?= e(url('home.index')) ?>">
            <?= lucide('archive', 'brand__mark') ?>
            <span class="brand__name"><?= e($appName) ?></span>
            <span class="brand__en"><?= e($appNameEn) ?></span>
        </a>

        <nav class="snav" aria-label="页面导航">
            <a href="#capabilities">功能</a>
            <a href="#preview">预览</a>
            <a href="#share">分享</a>
            <a href="#storage">存储</a>
            <a href="#deploy">部署</a>
        </nav>

        <div class="topbar__act">
            <?php if ($loggedIn): ?>
                <a class="btn-solid" data-magnetic href="<?= e(url('files.list')) ?>">
                    <span>进入网盘</span><?= lucide('arrow-right', 'btn-solid__ico') ?>
                </a>
            <?php else: ?>
                <a class="link-quiet" href="<?= e(url('auth.login')) ?>">登录</a>
                <?php if ($allowRegistration || $firstRun): ?>
                    <a class="btn-solid" data-magnetic href="<?= e(url('auth.register')) ?>">
                        <span>注册</span><?= lucide('arrow-right', 'btn-solid__ico') ?>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <!-- ============================================================ 主视觉 -->
    <section class="hero">
        <div class="hero__type">
            <p class="kicker">SELF-HOSTED CLOUD DRIVE · 自托管网盘</p>

            <h1 class="display" aria-label="<?= e($headline[0] . $headline[1]) ?>">
                <span class="ln ln--1" aria-hidden="true"><?= $chars($headline[0]) ?></span>
                <span class="ln ln--2" aria-hidden="true"><?= $chars($headline[1]) ?></span>
            </h1>

            <p class="lede">一块属于你自己的云端空间。上传、整理、预览、分享、挂载——一台自己的服务器，一套 PHP 跑完，文件始终在你手里。</p>

            <div class="hero__act">
                <a class="cta" data-magnetic href="<?= e($entryRoute) ?>">
                    <span class="cta__label"><?= e($entryLabel) ?></span>
                    <span class="cta__ico"><?= lucide('arrow-right') ?></span>
                </a>
                <a class="btn-line" href="#capabilities">
                    <span>看看它能做什么</span><?= lucide('arrow-down') ?>
                </a>
            </div>

            <ul class="facts-row">
                <li>PHP ≥ 8.1</li>
                <li>无构建步骤</li>
                <li>无 Composer 依赖</li>
                <li>数据不出你的服务器</li>
            </ul>
        </div>

        <!-- 产品窗口：档案抽屉 -->
        <aside class="site-dock">
            <div class="win">
                <div class="win__bar">
                    <span class="win__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                    <span class="win__title"><?= e($appName) ?> — 我的文件</span>
                    <span class="win__clock" data-clock>--:--:--</span>
                </div>

                <div class="win__path" aria-hidden="true">
                    <?= lucide('archive') ?><b>我的文件</b><i class="sep">/</i>资料<i class="sep">/</i>2026
                </div>

                <ul class="win__list">
                    <li class="win__row" style="--i:0">
                        <?= lucide('folder') ?>
                        <span class="win__name">合同归档</span>
                        <span class="win__meta">12 项</span>
                    </li>
                    <li class="win__row" style="--i:1">
                        <?= lucide('file-image') ?>
                        <span class="win__name">海边黄昏.jpg</span>
                        <span class="win__meta">8.2 MB</span>
                    </li>
                    <li class="win__row" style="--i:2">
                        <?= lucide('film') ?>
                        <span class="win__name">城市延时.mp4</span>
                        <span class="win__meta">312 MB</span>
                    </li>
                    <li class="win__row" style="--i:3">
                        <?= lucide('file-text') ?>
                        <span class="win__name">方案-终稿.pdf</span>
                        <span class="win__meta"><i class="dot" style="background:var(--signal-2)"></i>24.6 MB</span>
                    </li>
                    <li class="win__row" style="--i:4">
                        <?= lucide('file-code') ?>
                        <span class="win__name">deploy.sh</span>
                        <span class="win__meta">4 KB</span>
                    </li>
                </ul>

                <div class="win__foot">
                    <span class="chip"><?= lucide('cloud-upload') ?>上传</span>
                    <span class="meter"><span class="meter__track"><span class="meter__fill" style="--fill:4%"></span></span></span>
                </div>
            </div>

            <div class="float-chip" aria-hidden="true">
                <span class="float-chip__ico"><?= lucide('link') ?></span>
                <span class="float-chip__txt">
                    <b>分享链接已生成</b>
                    <i>提取码 4 位 · 7 天有效</i>
                </span>
            </div>
        </aside>
    </section>

    <!-- ============================================================ 数据带 -->
    <section class="strip" data-reveal>
        <div><b>1 TB</b><span>起步容量</span></div>
        <div><b>200 GiB</b><span>单文件上限</span></div>
        <div><b>SHA-256</b><span>内容寻址 · 秒传</span></div>
        <div><b>3 通道</b><span>REST · WebDAV · RSS</span></div>
    </section>

    <!-- ============================================================ 01 功能 -->
    <section id="capabilities" class="sect">
        <header class="sect__head" data-reveal>
            <p class="sect__meta"><span class="sect__no">01</span><span class="sect__tag">CAPABILITIES</span></p>
            <h2 class="sect__title">文件，就该这样管</h2>
            <p class="sect__lede">列表与网格两种视图，排序、批量、回收站、标签与颜色标记；该自动的交给规则，该防的交给锁。</p>
        </header>

        <div class="grid6">
            <article class="card" data-reveal style="--d:0ms">
                <span class="card__ico"><?= lucide('upload') ?></span>
                <h3>分片续传</h3>
                <p>拖拽上传、整目录上传，大文件自动分片断点续传，单文件最高 200 GiB。</p>
            </article>
            <article class="card" data-reveal style="--d:70ms">
                <span class="card__ico"><?= lucide('zap') ?></span>
                <h3>内容寻址</h3>
                <p>SHA-256 寻址落盘，同一份内容只存一份，重复文件秒传。</p>
            </article>
            <article class="card" data-reveal style="--d:140ms">
                <span class="card__ico"><?= lucide('layers') ?></span>
                <h3>版本历史</h3>
                <p>覆盖与修改都可追溯：历史版本可列出、下载、还原、删除。</p>
            </article>
            <article class="card" data-reveal style="--d:210ms">
                <span class="card__ico"><?= lucide('sliders-horizontal') ?></span>
                <h3>自动化规则</h3>
                <p>按扩展名、文件名、类型、大小命中，自动打标签、上色、移动、锁定。</p>
            </article>
            <article class="card" data-reveal style="--d:280ms">
                <span class="card__ico"><?= lucide('search') ?></span>
                <h3>全文检索</h3>
                <p>文件名模糊匹配，文本内容走 MySQL ngram 全文索引，一发即中。</p>
            </article>
            <article class="card" data-reveal style="--d:350ms">
                <span class="card__ico"><?= lucide('shield-check') ?></span>
                <h3>安全底座</h3>
                <p>登录限流、私密空间、操作日志、预处理语句，全链路把关。</p>
            </article>
        </div>
    </section>

    <!-- ============================================================ 02 预览 -->
    <section id="preview" class="sect">
        <div class="duo">
            <div class="duo__txt" data-reveal>
                <header class="sect__head sect__head--loose">
                    <p class="sect__meta"><span class="sect__no">02</span><span class="sect__tag">PREVIEW</span></p>
                    <h2 class="sect__title">打开还是原来的样子</h2>
                    <p class="sect__lede">不用下载、不用装软件。图片、视频、音频、PDF、文本与 Markdown 直接渲染；Office 文档由服务端解析后在浏览器内呈现，全程不经过第三方。</p>
                </header>
            </div>
            <div class="duo__panel" data-reveal style="--d:120ms">
                <div class="pane-glass">
                    <p class="pane-glass__label">浏览器内直接预览</p>
                    <div class="fmt-grid">
                        <span class="fmt"><?= lucide('image') ?>图片</span>
                        <span class="fmt"><?= lucide('film') ?>视频</span>
                        <span class="fmt"><?= lucide('music') ?>音频</span>
                        <span class="fmt"><?= lucide('file-text') ?>PDF</span>
                        <span class="fmt"><?= lucide('file-code') ?>文本 / 代码</span>
                        <span class="fmt"><?= lucide('book-open') ?>Markdown</span>
                        <span class="fmt fmt--hi"><?= lucide('file-text') ?>DOCX</span>
                        <span class="fmt fmt--hi"><?= lucide('file-text') ?>XLSX</span>
                        <span class="fmt fmt--hi"><?= lucide('file-text') ?>PPTX</span>
                    </div>
                    <p class="pane-glass__note">媒体支持进度拖动：对象存储走预签名直链，Range 请求不占本机带宽。</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ 03 分享 -->
    <section id="share" class="sect">
        <div class="duo duo--flip">
            <div class="duo__txt" data-reveal>
                <header class="sect__head sect__head--loose">
                    <p class="sect__meta"><span class="sect__no">03</span><span class="sect__tag">SHARING</span></p>
                    <h2 class="sect__title">发出去的是链接，不是文件</h2>
                    <p class="sect__lede">提取码、有效期、权限随链接一起设置；谁在什么时候看过、下载过，访问记录里都有。要回就撤销，链接即刻失效。</p>
                </header>
            </div>
            <div class="duo__panel" data-reveal style="--d:120ms">
                <div class="pane-glass">
                    <p class="pane-glass__label">一个链接发出去</p>
                    <div class="linkbox">
                        <span class="linkbox__label"><?= lucide('link', 'linkbox__ico') ?>分享链接（7 天内有效）</span>
                        <span class="linkbox__url">https://pan.example.com/s/9f2c8a1d7e</span>
                    </div>
                    <div class="perm">
                        <span class="perm__on"><?= lucide('key-round') ?>提取码</span>
                        <span class="perm__on"><?= lucide('download') ?>可下载</span>
                        <span><?= lucide('eye') ?>仅预览</span>
                        <span><?= lucide('cloud-upload') ?>可上传</span>
                    </div>
                    <p class="access"><?= lucide('history') ?>2 小时前 · 对方预览了 3 个文件，下载 1 次</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ 04 存储 -->
    <section id="storage" class="sect">
        <div class="duo">
            <div class="duo__txt" data-reveal>
                <header class="sect__head sect__head--loose">
                    <p class="sect__meta"><span class="sect__no">04</span><span class="sect__tag">STORAGE</span></p>
                    <h2 class="sect__title">文件落在哪，由你决定</h2>
                    <p class="sect__lede">内置本地仓，也能挂载 S3 兼容对象存储。上传时按文件选择落盘位置，重要文件可把副本推到外部存储做冗余。</p>
                </header>
            </div>
            <div class="duo__panel" data-reveal style="--d:120ms">
                <div class="src-grid">
                    <article class="src">
                        <div class="src__head"><?= lucide('hard-drive', 'src__ico') ?><b>本地仓</b><span>LOCAL</span></div>
                        <p>内容寻址存储：同一份内容只落一份盘，重复文件秒传，磁盘不浪费。</p>
                    </article>
                    <article class="src">
                        <div class="src__head"><?= lucide('cloud', 'src__ico') ?><b>S3 兼容对象存储</b><span>S3</span></div>
                        <p>按文件选择存储源，重要文件可推送副本做异地冗余。</p>
                        <div class="src__tags"><span>腾讯云 COS</span><span>阿里云 OSS</span><span>MinIO</span></div>
                    </article>
                </div>
                <p class="duo__note"><?= lucide('zap') ?>预签名直链：预览与下载直连对象存储，不占本机带宽。</p>
            </div>
        </div>
    </section>

    <!-- ============================================================ 05 部署 -->
    <section id="deploy" class="sect">
        <div class="duo duo--flip">
            <div class="duo__txt" data-reveal>
                <header class="sect__head sect__head--loose">
                    <p class="sect__meta"><span class="sect__no">05</span><span class="sect__tag">DEPLOY</span></p>
                    <h2 class="sect__title">一套 PHP 跑完</h2>
                    <p class="sect__lede">没有构建步骤，没有 Composer 依赖，不需要 Node。传到服务器、填好数据库、打开站点——三步，五分钟。</p>
                </header>
            </div>
            <div class="duo__panel" data-reveal style="--d:120ms">
                <div class="term">
                    <div class="term__bar">
                        <span class="win__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span class="term__title"><?= lucide('terminal') ?>服务器</span>
                    </div>
                    <div class="term__body">
<span class="p">$</span> <span class="k">cp</span> app/config.example.php app/config.php
<span class="p">$</span> <span class="k">vim</span> app/config.php <span class="c"># 填上数据库账号密码</span>
<span class="c"># 浏览器打开站点，创建第一个账号——表结构自动建好</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================ 收尾 -->
    <section class="finale">
        <p class="sect__meta sect__meta--center" data-reveal><span class="sect__no">—</span><span class="sect__tag">GET STARTED</span></p>
        <h2 class="finale__title" data-reveal style="--d:60ms">现在，把文件交给拾云。</h2>
        <p class="finale__lede" data-reveal style="--d:120ms">起步容量 1 TB。数据在你的服务器上，规则由你来定。</p>
        <div class="finale__act" data-reveal style="--d:180ms">
            <a class="cta cta--center" data-magnetic href="<?= e($entryRoute) ?>">
                <span class="cta__label"><?= e($entryLabel) ?></span>
                <span class="cta__ico"><?= lucide('arrow-right') ?></span>
            </a>
        </div>
    </section>

    <!-- ============================================================ 页脚 -->
    <footer class="foot">
        <span class="foot__c">© <?= date('Y') ?> <?= e($appName) ?> · 自托管的个人网盘</span>
        <nav class="foot__nav">
            <?php if ($loggedIn): ?>
                <a href="<?= e(url('files.list')) ?>">我的文件</a>
            <?php else: ?>
                <a href="<?= e(url('auth.login')) ?>">登录</a>
                <?php if ($allowRegistration || $firstRun): ?>
                    <a href="<?= e(url('auth.register')) ?>">注册</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="#">隐私政策</a>
            <a href="#">服务条款</a>
        </nav>
    </footer>
</main>
