<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    http_response_code(404);
    exit;
}

/** @var array $user */
/** @var list<array> $tasks */
/** @var array $engine */
/** @var list<array> $folders */
/** @var array{used:int,total:int} $quota */

$rootId = user_root_id((int) $user['id']);

$cronUrl = (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . '/index.php?r=tasks.cron&key=' . rawurlencode((string) config('cron_key', ''));

// 显示时在 key= 之后允许换行：否则自动断行会从密钥中间切开，
// 密钥被拆成两半后很难核对。整段密钥落到下一行才读得清。
$cronSplit = explode('&key=', $cronUrl, 2);
$cronHtml = e($cronSplit[0]) . '&amp;key=<wbr>' . e($cronSplit[1] ?? '');
?>
<div class="app" data-app data-section="tasks" data-page="tasks">
    <?php view('partials/spine', ['section' => 'tasks', 'user' => $user, 'quota' => $quota]) ?>

    <main class="stage">
        <?php view('partials/bar', ['barTools' => '', 'user' => $user]) ?>

        <div class="page">
            <div class="pagehead">
                <div class="pagehead__text">
                    <h1 class="pagehead__title">传输任务</h1>
                    <p class="pagehead__sub">
                        把直链或磁力丢进来，服务器替你下好再存进指定目录。
                        队列按段推进，断点会保留；配好下方的计划任务后，关掉浏览器也会继续跑。
                    </p>
                </div>
                <div class="pagehead__acts">
                    <button class="ghost ghost--sm" type="button" data-task-refresh>
                        <?= lucide('refresh-cw', 'ghost__ico') ?><span>刷新</span>
                    </button>
                </div>
            </div>

            <div class="grid grid--2" style="margin-bottom:22px">
                <div class="panel">
                    <div class="panel__head"><h2 class="panel__title">新建任务</h2></div>
                    <div class="panel__body">
                        <form data-task-form>
                            <div class="formgrid">
                                <label class="mfield">
                                    <span class="mfield__label">类型</span>
                                    <select class="select" style="width:100%" name="kind">
                                        <option value="offline">离线下载（HTTP / FTP 直链）</option>
                                        <option value="bt">BT / 磁力</option>
                                    </select>
                                </label>
                                <label class="mfield">
                                    <span class="mfield__label">保存到</span>
                                    <select class="select" style="width:100%" name="folder">
                                        <?php foreach ($folders as $f): ?>
                                            <option value="<?= (int) $f['id'] ?>"><?= $f['private'] ? '［私密］' : '' ?><?= e((string) $f['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <label class="mfield" style="margin-top:16px">
                                <span class="mfield__label">地址</span>
                                <input class="minput" name="url" required
                                       placeholder="https://example.com/big.iso 或 magnet:?xt=urn:btih:…">
                            </label>

                            <div class="formactions">
                                <button class="mbtn mbtn--primary" type="submit">加入队列</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel__head">
                        <h2 class="panel__title">下载引擎</h2>
                        <span class="panel__note"><?= e((string) $engine['label']) ?></span>
                    </div>
                    <div class="panel__body">
                        <?php if ($engine['btReady']): ?>
                            <p class="hint"><?= e((string) $engine['message']) ?></p>
                        <?php else: ?>
                            <div class="hint hint--warn">
                                <?= lucide('alert-triangle') ?>
                                <span><?= e((string) $engine['message']) ?></span>
                            </div>
                        <?php endif; ?>

                        <dl class="kv" style="margin-top:18px">
                            <dt>离线下载</dt><dd><span class="pill pill--on">可用</span></dd>
                            <dt>BT / 磁力</dt>
                            <dd><span class="pill<?= $engine['btReady'] ? ' pill--on' : ' pill--muted' ?>">
                                <?= $engine['btReady'] ? '可用' : '未配置引擎' ?></span></dd>
                            <dt>推进方式</dt><dd class="muted">页面加载时分段推进，单次约 12 秒</dd>
                        </dl>

                        <p class="hint" style="margin-top:18px">
                            想让队列在没人开页面时也继续跑，在宝塔「计划任务」里加一条
                            <b>每分钟</b>访问下面地址的任务即可：
                        </p>
                        <div class="urlbox">
                            <code><?= $cronHtml ?></code>
                            <button class="minibtn" type="button" data-copy-cron="<?= e($cronUrl) ?>" aria-label="复制地址"><?= lucide('copy') ?></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel__head">
                    <h2 class="panel__title">任务队列</h2>
                    <span class="panel__note" data-task-count><?= count($tasks) ?> 个</span>
                </div>

                <div class="blank" data-task-blank<?= $tasks === [] ? '' : ' hidden' ?>>
                    <?= lucide('cloud-download', 'blank__ico') ?>
                    <p class="blank__title">队列是空的</p>
                    <p class="blank__hint">在上面粘一条直链试试，下好的文件会直接出现在你选的目录里。</p>
                </div>

                <div class="tblwrap">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th>类型</th><th>任务</th><th>状态</th><th class="num">进度</th>
                                <th class="num">已下载</th><th>目标目录</th><th>创建</th><th></th>
                            </tr>
                        </thead>
                        <tbody data-task-rows>
                            <?php foreach ($tasks as $t): ?>
                                <?php
                                $cls = $t['status'] === 'done' ? ' prog--done' : ($t['status'] === 'failed' ? ' prog--failed' : '');
                                $state = match ($t['status']) {
                                    'queued'  => '排队中',
                                    'running' => '进行中',
                                    'paused'  => '已暂停',
                                    'done'    => '已完成',
                                    'failed'  => $t['error'] !== '' ? $t['error'] : '失败',
                                    'canceled' => '已取消',
                                    default   => $t['status'],
                                };
                                ?>
                                <tr data-task-row="<?= (int) $t['id'] ?>">
                                    <td class="nowrap"><span class="pill"><?= e((string) $t['kindLabel']) ?></span></td>
                                    <td>
                                        <div class="tbl__name">
                                            <?= lucide($t['kind'] === 'bt' ? 'git-branch' : 'cloud-download') ?>
                                            <span><?= e((string) $t['title']) ?></span>
                                        </div>
                                        <span class="prog<?= $cls ?>" style="margin-top:8px">
                                            <span class="prog__fill" style="--w:<?= (int) $t['progress'] ?>%"></span>
                                        </span>
                                    </td>
                                    <td class="muted nowrap"><?= e($state) ?></td>
                                    <td class="num"><?= (int) $t['progress'] ?>%</td>
                                    <td class="num muted"><?= e((string) $t['receivedText']) ?> / <?= e((string) $t['totalText']) ?></td>
                                    <td class="muted nowrap"><?= e($t['folderName'] !== '' ? (string) $t['folderName'] : '—') ?></td>
                                    <td class="muted nowrap"><?= e((string) $t['createdText']) ?></td>
                                    <td>
                                        <div class="tbl__acts">
                                            <?php if (in_array($t['status'], ['queued', 'running'], true)): ?>
                                                <button class="minibtn" type="button" data-task-act="pause" data-id="<?= (int) $t['id'] ?>" aria-label="暂停"><?= lucide('pause') ?></button>
                                            <?php endif; ?>
                                            <?php if (in_array($t['status'], ['paused', 'failed'], true)): ?>
                                                <button class="minibtn" type="button" data-task-act="resume" data-id="<?= (int) $t['id'] ?>" aria-label="继续"><?= lucide('play') ?></button>
                                            <?php endif; ?>
                                            <?php if (in_array($t['status'], ['queued', 'running', 'paused'], true)): ?>
                                                <button class="minibtn" type="button" data-task-act="cancel" data-id="<?= (int) $t['id'] ?>" aria-label="取消"><?= lucide('x') ?></button>
                                            <?php else: ?>
                                                <button class="minibtn" type="button" data-task-act="delete" data-id="<?= (int) $t['id'] ?>" aria-label="移除"><?= lucide('trash-2') ?></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
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
