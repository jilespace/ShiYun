<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array $config */
/** @var array{used:int,total:int} $quota */
$initial = (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="app" data-app data-section="objects" data-page="objects"
     data-config="<?= e($initial) ?>">
    <?php view('partials/spine', ['section' => 'objects', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">对象存储</h1>
                    <p class="pagehead__sub">
                        用一组 COS / S3 凭据管理桶和对象。上传下载走预签名直连，密钥只留在服务端。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <select class="select" data-account-select aria-label="对象存储账号"></select>
                    <button class="ghost" type="button" data-account-new>
                        <?= lucide('plus', 'ghost__ico') ?><span>添加账号</span>
                    </button>
                    <button class="ghost" type="button" data-account-edit>
                        <?= lucide('pencil', 'ghost__ico') ?><span>编辑</span>
                    </button>
                    <button class="ghost" type="button" data-account-test>
                        <?= lucide('plug', 'ghost__ico') ?><span>测试连接</span>
                    </button>
                </div>
            </div>

            <div class="blank" data-no-account hidden>
                <?= lucide('database', 'blank__ico') ?>
                <p class="blank__title">还没有对象存储账号</p>
                <p class="blank__hint">添加一组腾讯云 COS 或通用 S3 密钥，就能在这里管理桶和对象。</p>
                <button class="mbtn mbtn--primary" type="button" data-account-new>添加账号</button>
            </div>

            <section data-bucket-view hidden>
                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">存储桶</h2>
                        <div class="panel__acts">
                            <button class="chipbtn" type="button" data-bucket-reload>
                                <?= lucide('refresh-cw', 'chipbtn__ico') ?>刷新
                            </button>
                            <button class="chipbtn" type="button" data-bucket-new>
                                <?= lucide('plus', 'chipbtn__ico') ?>新建桶
                            </button>
                        </div>
                    </div>
                    <div class="panel__body" data-bucket-grid>
                        <div class="blank"><?= lucide('loader', 'blank__ico') ?>
                            <p class="blank__hint">正在读取桶列表…</p></div>
                    </div>
                </div>
            </section>

            <section data-detail-view hidden>
                <div class="objdetail__head">
                    <button class="chipbtn" type="button" data-detail-back>
                        <?= lucide('arrow-left', 'chipbtn__ico') ?>全部桶
                    </button>
                    <div class="objdetail__title" data-detail-title></div>
                    <div class="objdetail__acts" data-detail-acts></div>
                </div>

                <div class="seg2" data-detail-tabs>
                    <a href="#" class="is-on" data-tab="objects">对象</a>
                    <a href="#" data-tab="settings">设置</a>
                </div>

                <div data-tab-panel="objects">
                    <div class="objbar">
                        <div class="objcrumb" data-crumb></div>
                        <div class="objbar__tools">
                            <input class="minput" type="search" data-obj-search placeholder="按前缀搜索当前目录">
                            <select class="select" data-upload-class aria-label="上传存储类型">
                                <option value="">默认存储类型</option>
                                <?php foreach ($config['classes'] as $key => $label): ?>
                                    <option value="<?= e((string) $key) ?>"><?= e((string) $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="chipbtn" type="button" data-obj-upload>
                                <?= lucide('upload', 'chipbtn__ico') ?>上传
                            </button>
                            <button class="chipbtn" type="button" data-obj-mkdir>
                                <?= lucide('folder-plus', 'chipbtn__ico') ?>新建文件夹
                            </button>
                            <button class="chipbtn" type="button" data-obj-stat>
                                <?= lucide('bar-chart-3', 'chipbtn__ico') ?>统计
                            </button>
                        </div>
                    </div>

                    <p class="mhint" data-upload-note></p>

                    <div class="objsel" data-obj-selection hidden>
                        <span data-obj-selected-count></span>
                        <button class="chipbtn chipbtn--danger" type="button" data-obj-delete-selected>删除所选</button>
                    </div>

                    <div data-obj-host>
                        <div class="blank"><?= lucide('loader', 'blank__ico') ?>
                            <p class="blank__hint">正在读取对象…</p></div>
                    </div>
                </div>

                <div data-tab-panel="settings" hidden>
                    <div class="objsettings" data-settings-host></div>
                </div>
            </section>

            <input type="file" data-upload-input multiple hidden>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
