<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var array $settings */
/** @var array $counters */
/** @var string $tab */
/** @var array{used:int,total:int} $quota */

$webdavUrl = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . url('webdav.handle');

$usedPct = $quota['total'] > 0 ? min(100, $quota['used'] / $quota['total'] * 100) : 0;
$quotaGb = $quota['total'] > 0 ? round($quota['total'] / 1024 ** 3, 1) : 0;
?>
<div class="app" data-app data-section="settings" data-page="settings">
    <?php view('partials/spine', ['section' => 'settings', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <?php require BASE_PATH . '/app/views/partials/settings_tabs.php'; ?>

            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">个人资料</h1>
                    <p class="pagehead__sub">昵称、用户名与简介。用户名同时也是登录凭证之一。</p>
                </div>
            </div>

            <div class="grid grid--2">
                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">账号</h2></div>
                    <div class="panel__body">
                        <form data-api="settings.profile" data-ok="资料已保存">
                            <label class="mfield">
                                <span class="mfield__label">昵称</span>
                                <input class="minput" name="display_name" value="<?= e((string) $user['display_name']) ?>" required>
                            </label>
                            <label class="mfield">
                                <span class="mfield__label">用户名</span>
                                <input class="minput" name="username" value="<?= e((string) $user['username']) ?>"
                                       pattern="[A-Za-z0-9_.\-]{3,40}" required>
                            </label>
                            <label class="mfield">
                                <span class="mfield__label">简介</span>
                                <textarea class="minput minput--area" name="bio"
                                          placeholder="一句话介绍自己"><?= e((string) ($user['bio'] ?? '')) ?></textarea>
                            </label>
                            <div class="formactions">
                                <button class="mbtn mbtn--primary" type="submit">保存</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">账号概览</h2></div>
                    <div class="panel__body">
                        <dl class="kv">
                            <dt>邮箱</dt><dd><?= e((string) $user['email']) ?></dd>
                            <dt>注册于</dt><dd><?= e(date('Y-m-d', (int) strtotime((string) $user['created_at']))) ?></dd>
                            <dt>上次登录</dt><dd><?= e($user['last_login_at'] !== null ? (string) $user['last_login_at'] : '—') ?></dd>
                            <dt>生效分享</dt><dd><?= (int) $counters['shares'] ?> 条</dd>
                            <dt>API 令牌</dt><dd><?= (int) $counters['tokens'] ?> 个</dd>
                            <dt>存储源</dt><dd><?= (int) $counters['sources'] ?> 个</dd>
                        </dl>

                        <div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--line)">
                            <span class="tile__label">WebDAV 挂载地址</span>
                            <div class="urlbox">
                                <code><?= e($webdavUrl) ?></code>
                                <button class="minibtn" type="button" data-copy-webdav="<?= e($webdavUrl) ?>" aria-label="复制"><?= lucide('copy') ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel" style="margin-top:22px">
                <div class="panel__head">
                    <h2 class="panel__title">偏好设置</h2>
                    <span class="panel__note">影响列表展示与传输行为</span>
                </div>
                <div class="panel__body">
                    <form data-api="settings.preferences" data-ok="偏好已保存">
                        <div class="formgrid">
                            <label class="mfield">
                                <span class="mfield__label">默认视图</span>
                                <select class="select" style="width:100%" name="view">
                                    <option value="list"<?= ($settings['view_mode'] ?? '') === 'list' ? ' selected' : '' ?>>列表</option>
                                    <option value="grid"<?= ($settings['view_mode'] ?? '') === 'grid' ? ' selected' : '' ?>>网格</option>
                                </select>
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">默认分享权限</span>
                                <select class="select" style="width:100%" name="defaultSharePerm">
                                    <option value="preview"<?= ($settings['default_share_perm'] ?? '') === 'preview' ? ' selected' : '' ?>>仅预览</option>
                                    <option value="download"<?= ($settings['default_share_perm'] ?? '') === 'download' ? ' selected' : '' ?>>可下载</option>
                                    <option value="upload"<?= ($settings['default_share_perm'] ?? '') === 'upload' ? ' selected' : '' ?>>可上传</option>
                                </select>
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">回收站保留 <b data-retention-out>30 天</b></span>
                                <input class="range" type="range" name="trashRetention" data-retention-range
                                       min="1" max="365" value="<?= (int) ($settings['trash_retention_days'] ?? 30) ?>">
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">上传限速 <b data-upload-limit-out>不限速</b></span>
                                <input class="range" type="range" name="uploadLimit" data-upload-limit
                                       min="0" max="20480" step="256" value="<?= (int) ($settings['upload_speed_limit'] ?? 0) ?>">
                            </label>

                            <label class="mfield">
                                <span class="mfield__label">下载限速 <b data-download-limit-out>不限速</b></span>
                                <input class="range" type="range" name="downloadLimit" data-download-limit
                                       min="0" max="20480" step="256" value="<?= (int) ($settings['download_speed_limit'] ?? 0) ?>">
                            </label>

                            <p class="hint">限速仅对存在本地的文件生效；已托管到 COS 的文件下载时直连对象存储，不受此处限速影响。</p>

                            <div class="mfield">
                                <span class="mfield__label">开关</span>
                                <label class="switch">
                                    <input type="checkbox" name="showHidden"<?= !empty($settings['show_hidden']) ? ' checked' : '' ?>>
                                    <span class="switch__track"></span>
                                    <span>默认显示隐藏文件</span>
                                </label>
                                <label class="switch" style="margin-top:12px">
                                    <input type="checkbox" name="notifyLogin"<?= !empty($settings['notify_login']) ? ' checked' : '' ?>>
                                    <span class="switch__track"></span>
                                    <span>异常登录提醒</span>
                                </label>
                            </div>
                        </div>

                        <div class="formactions">
                            <button class="mbtn mbtn--primary" type="submit">保存偏好</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="panel" style="margin-top:22px">
                <div class="panel__head">
                    <h2 class="panel__title">容量与配额</h2>
                    <span class="panel__note">回收站与私密空间同样计入用量</span>
                </div>
                <div class="panel__body">
                    <div class="gauge" style="max-width:520px">
                        <div class="gauge__row">
                            <span class="gauge__label">已用</span>
                            <span class="gauge__val"><?= e(format_bytes((float) $quota['used'])) ?> / <?= e(format_bytes((float) $quota['total'])) ?></span>
                        </div>
                        <span class="gauge__track">
                            <span class="gauge__fill" style="--fill:<?= e(number_format($usedPct, 2)) ?>%"></span>
                        </span>
                        <span class="gauge__hint">已使用 <?= e(number_format($usedPct, 2)) ?>%</span>
                    </div>

                    <form data-api="settings.quota_update" data-ok="配额已更新" data-reload="true"
                          style="margin-top:22px;max-width:340px">
                        <label class="mfield">
                            <span class="mfield__label">调整总配额（GB）</span>
                            <input class="minput" name="quotaGb" type="number" min="1" step="1" value="<?= e((string) $quotaGb) ?>">
                        </label>
                        <div class="formactions">
                            <button class="mbtn" type="submit">更新配额</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<?php view('partials/modal') ?>
