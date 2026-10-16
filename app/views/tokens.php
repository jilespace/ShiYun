<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $tokens */
/** @var string $base */
/** @var list<array{0:string,1:string,2:string}> $endpoints */
/** @var array{used:int,total:int} $quota */

$rssToken = $tokens[0]['id'] ?? null;
?>
<div class="app" data-app data-section="settings" data-page="tokens">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php $tab = 'tokens'; require BASE_PATH . '/app/views/partials/settings_tabs.php'; ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">API 令牌</h1>
                    <p class="pagehead__sub">
                        用令牌可以调用 REST 接口、订阅 RSS，或者把网盘挂到 WebDAV 上。
                        令牌只存哈希，明文只在创建时出现一次。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost" type="button" id="new-token">
                        <?= lucide('plus', 'ghost__ico') ?><span>生成令牌</span>
                    </button>
                </div>
            </div>

            <div class="panel" style="margin-bottom:22px">
                <div class="panel__head">
                    <h2 class="panel__title">已有令牌</h2>
                    <span class="panel__note"><?= count($tokens) ?> 个</span>
                </div>

                <?php if ($tokens === []): ?>
                    <div class="panel__body">
                        <div class="blank">
                            <?= lucide('key-round', 'blank__ico') ?>
                            <p class="blank__title">还没有令牌</p>
                            <p class="blank__hint">生成一个就能开始用 API 或挂载 WebDAV。</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="tblwrap">
                        <table class="tbl">
                            <thead>
                                <tr><th>名称</th><th>创建</th><th>最后使用</th><th>有效期</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tokens as $tk): ?>
                                    <tr>
                                        <td><div class="tbl__name"><?= lucide('key-round') ?><span><?= e((string) $tk['name']) ?></span></div></td>
                                        <td class="muted nowrap"><?= e(time_ago((string) $tk['created_at'])) ?></td>
                                        <td class="muted nowrap"><?= $tk['last_used_at'] !== null ? e(time_ago((string) $tk['last_used_at'])) : '从未使用' ?></td>
                                        <td class="muted nowrap">
                                            <?= $tk['expires_at'] === null
                                                ? '永久'
                                                : e(date('Y-m-d', (int) strtotime((string) $tk['expires_at']))) ?>
                                        </td>
                                        <td>
                                            <div class="tbl__acts">
                                                <button class="chipbtn chipbtn--danger" type="button"
                                                        data-api="api.tokens_revoke"
                                                        data-payload="<?= e(json_encode(['ids' => [(int) $tk['id']]])) ?>"
                                                        data-confirm="使用该令牌的脚本会立即失效。"
                                                        data-title="吊销令牌" data-label="吊销" data-danger="1"
                                                        data-after="reload">吊销</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid--2">
                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">REST 接口</h2></div>
                    <div class="panel__body">
                        <p class="hint">认证：<code>Authorization: Bearer &lt;令牌&gt;</code>，或把令牌放进 <code>?token=</code>。</p>
                        <div class="codeblock" style="margin-top:14px">curl -H "Authorization: Bearer &lt;TOKEN&gt;" \
  "<?= e($base) ?>/<?= e(url('api.handle', ['path' => 'list'])) ?>"</div>
                        <div style="margin-top:16px">
                            <?php foreach ($endpoints as [$method, $path, $desc]): ?>
                                <div class="ep">
                                    <span class="ep__m"><?= e($method) ?></span>
                                    <span class="ep__p"><?= e($path) ?></span>
                                    <span class="ep__d"><?= e($desc) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">其它入口</h2></div>
                    <div class="panel__body">
                        <dl class="kv">
                            <dt>WebDAV</dt>
                            <dd>
                                <div class="urlbox">
                                    <code><?= e($base . '/' . url('webdav.handle')) ?></code>
                                    <button class="minibtn" type="button" data-copy-webdav="<?= e($base . '/' . url('webdav.handle')) ?>" aria-label="复制"><?= lucide('copy') ?></button>
                                </div>
                                <p class="hint" style="margin-top:6px">用户名填邮箱或用户名，密码填网盘密码（也可填 API 令牌）。</p>
                            </dd>

                            <dt>RSS 订阅</dt>
                            <dd>
                                <?php if ($rssToken === null): ?>
                                    <p class="hint">先生成一个令牌，再用它订阅。</p>
                                <?php else: ?>
                                    <p class="hint">把任意令牌拼进地址即可：</p>
                                    <div class="codeblock" style="margin-top:8px"><?= e($base . '/' . url('rss.feed', ['token' => 'TOKEN'])) ?>

最近文件流 · 操作动态流：在后面加 &amp;kind=activity</div>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
