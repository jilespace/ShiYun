<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $rules */
/** @var list<array> $folders */
/** @var array<string,string> $kinds */
/** @var list<string> $palette */
/** @var array{used:int,total:int} $quota */
?>
<div class="app" data-app data-section="settings" data-page="rules"
     data-folders="<?= e(json_encode($folders, JSON_UNESCAPED_UNICODE)) ?>"
     data-kinds="<?= e(json_encode($kinds, JSON_UNESCAPED_UNICODE)) ?>"
     data-palette="<?= e(json_encode($palette)) ?>">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php
            $tab = 'rules';
            require BASE_PATH . '/app/views/partials/settings_tabs.php';
            ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">自动化规则</h1>
                    <p class="pagehead__sub">
                        上传的文件如果符合条件，就自动打标签、上色、归档或锁定。
                        规则只作用于上传那一刻，不会追溯改动，除非你点「回填到已有文件」。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost" type="button" id="new-rule">
                        <?= lucide('plus', 'ghost__ico') ?><span>新建规则</span>
                    </button>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">规则列表</h2>
                    <span class="panel__note">按创建顺序依次尝试，互不冲突</span>
                </div>

                <?php if ($rules === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('sparkles', 'blank__ico') ?>
                            <p class="blank__title">还没有规则</p>
                            <p class="blank__hint">
                                举个例子：扩展名含 <b>psd</b> 的文件自动打上「设计稿」标签并移到「素材」目录。
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr><th>规则</th><th>条件</th><th>动作</th><th class="num">命中</th><th>最近运行</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <tr>
                                        <td>
                                            <div class="tbl__name">
                                                <?= lucide('sparkles') ?>
                                                <span><?= e((string) $rule['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="muted"><?= e(rule_condition_text($rule['conditions'])) ?></td>
                                        <td class="muted"><?= e(rule_action_text($rule['actions'])) ?></td>
                                        <td class="num"><?= (int) $rule['runCount'] ?></td>
                                        <td class="muted nowrap"><?= e((string) $rule['lastRunText']) ?></td>
                                        <td>
                                            <div class="tbl__acts">
                                                <button class="chipbtn<?= $rule['enabled'] ? ' pill--on' : '' ?>" type="button"
                                                        data-rule-toggle="<?= (int) $rule['id'] ?>">
                                                    <?= $rule['enabled'] ? '已启用' : '已停用' ?>
                                                </button>
                                                <button class="chipbtn" type="button" data-rule-backfill="<?= (int) $rule['id'] ?>">回填</button>
                                                <button class="chipbtn chipbtn--danger" type="button"
                                                        data-api="rules.delete"
                                                        data-payload="<?= e(json_encode(['id' => (int) $rule['id']])) ?>"
                                                        data-confirm="删除后不再对新上传生效，已有文件的标签与标记不会被撤销。"
                                                        data-title="删除规则" data-label="删除" data-danger="1"
                                                        data-after="reload">删除</button>
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
                <div class="panel__head"><h2 class="panel__title">能做什么</h2></div>
                <div class="panel__body">
                    <div class="grid grid--2">
                        <div>
                            <span class="tile__label">条件（全部满足才命中）</span>
                            <dl class="kv" style="margin-top:12px">
                                <?php foreach (rule_conditions_schema() as $key => $label): ?>
                                    <dt><?= e($key) ?></dt><dd class="muted"><?= e($label) ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                        <div>
                            <span class="tile__label">动作（可叠加多个）</span>
                            <dl class="kv" style="margin-top:12px">
                                <dt>tag</dt><dd class="muted">打标签（标签不存在会自动建）</dd>
                                <dt>color</dt><dd class="muted">颜色标记，填 #RRGGBB</dd>
                                <dt>move</dt><dd class="muted">移动到目录，填目录名或 id</dd>
                                <dt>hide</dt><dd class="muted">设为隐藏</dd>
                                <dt>lock</dt><dd class="muted">锁定，防止误删误改</dd>
                                <dt>note</dt><dd class="muted">写入备注文字</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
