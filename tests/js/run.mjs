#!/usr/bin/env node
/* 拾云 · 关键前端交互测试
   用法：node tests/js/run.mjs
   依赖：正在运行的本站、php CLI、headless Chromium（CHROME_BIN 可覆盖）。
   测试用户与会话由 tests/js/fixture.php 创建和清理。 */

import { execFileSync, spawn } from 'node:child_process';
import { existsSync, readdirSync, unlinkSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');
const PORT = String(process.env.TEST_HTTP_PORT || '15244');
const BASE = `http://127.0.0.1:${PORT}/`;
const FIXTURE = join(HERE, 'fixture.php');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failures = 0;

function check(cond, message) {
    if (cond) {
        console.log(`  ok   ${message}`);
    } else {
        failures++;
        console.log(`  FAIL ${message}`);
    }
}

function fixture(action) {
    const args = [FIXTURE, action];
    const opts = { cwd: ROOT, stdio: ['ignore', 'pipe', 'ignore'] };
    const out = process.getuid?.() === 0
        ? execFileSync('runuser', ['-u', 'www', '--', 'php', ...args], opts)
        : execFileSync('php', args, opts);
    const lines = out.toString().trim().split('\n').filter(Boolean);
    return action === 'create' ? JSON.parse(lines.at(-1)) : lines.at(-1);
}

function chromeBin() {
    if (process.env.CHROME_BIN) {
        return process.env.CHROME_BIN;
    }
    const base = '/root/.cache/ms-playwright';
    if (!existsSync(base)) {
        throw new Error('找不到 headless Chromium，请设置 CHROME_BIN');
    }
    const dirs = readdirSync(base)
        .filter((d) => d.startsWith('chromium_headless_shell-'))
        .sort();
    if (dirs.length === 0) {
        throw new Error('找不到 chromium_headless_shell-* 目录，请设置 CHROME_BIN');
    }
    return join(base, dirs.at(-1), 'chrome-headless-shell-linux64', 'chrome-headless-shell');
}

const data = fixture('create');
const sessionFile = join(ROOT, 'runtime/sessions', `sess_${data.sid}`);
const port = 9400 + Math.floor(Math.random() * 400);

let chrome = null;
let ws;
try {
    chrome = spawn(chromeBin(), [
        '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
        `--remote-debugging-port=${port}`, '--window-size=1440,900',
        '--disable-dev-shm-usage', 'about:blank',
    ], { stdio: ['ignore', 'ignore', 'ignore'] });

    ws = await connect();
    const send = ws.send;

    await send('Network.enable');
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride', {
        width: 1440, height: 900, deviceScaleFactor: 1, mobile: false,
    });
    await send('Network.setCookie', {
        name: 'shiyun_sid', value: data.sid, url: BASE,
    });
    await send('Page.addScriptToEvaluateOnNewDocument', {
        source: `window.__jsErrors = [];
            window.addEventListener('error', (e) => window.__jsErrors.push(String(e.message || e.error)));
            window.addEventListener('unhandledrejection', (e) => window.__jsErrors.push(String(e.reason)));`,
    });

    const evalValue = async (expression) => {
        const res = await send('Runtime.evaluate', {
            expression, awaitPromise: true, returnByValue: true,
        });
        if (res.result?.exceptionDetails) {
            throw new Error(res.result.exceptionDetails.text || '页面脚本执行失败');
        }
        return res.result?.result?.value;
    };

    const waitFor = async (expression, timeout = 6000) => {
        const end = Date.now() + timeout;
        while (Date.now() < end) {
            try {
                if (await evalValue(expression)) {
                    return true;
                }
            } catch {
                // 页面导航中，等下一轮
            }
            await sleep(80);
        }
        return false;
    };

    const press = async (key, code, vk, modifiers = 0) => {
        await send('Input.dispatchKeyEvent', {
            type: 'keyDown', key, code, windowsVirtualKeyCode: vk, modifiers,
        });
        await send('Input.dispatchKeyEvent', {
            type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers,
        });
    };

    await send('Page.navigate', { url: `${BASE}index.php?r=files.list` });
    check(await waitFor(`!!document.querySelector('[data-app]')`), '工作台加载完成');
    await sleep(250);

    const snapshot = async () => evalValue(`(() => {
        const app = document.querySelector('[data-app]');
        return {
            view: app.dataset.view,
            rows: document.querySelectorAll('.row').length,
            cards: document.querySelectorAll('.card').length,
            name: document.querySelector('.row__name, .card__name')?.textContent?.trim() || '',
        };
    })()`);

    const first = await snapshot();
    check(first.rows + first.cards >= 1, '目录里有可见条目');
    check(first.name.includes(data.folderName), '服务端首屏渲染了测试文件夹');

    // 视图切换：前端向服务端取另一套片段
    await evalValue(`document.querySelector('.bar [data-view-set="grid"]').click(), true`);
    const gridOk = await waitFor(`document.querySelector('[data-app]').dataset.view === 'grid'
        && document.querySelectorAll('.card').length > 0`);
    const gridState = await snapshot();
    check(gridOk && gridState.cards >= 1 && gridState.rows === 0, '切到网格后只保留卡片节点');

    await evalValue(`document.querySelector('.bar [data-view-set="list"]').click(), true`);
    const listOk = await waitFor(`document.querySelector('[data-app]').dataset.view === 'list'
        && document.querySelectorAll('.row').length > 0`);
    const listState = await snapshot();
    check(listOk && listState.rows >= 1 && listState.cards === 0, '切回列表后只保留行节点');

    // 选择与批量条
    await evalValue(`document.querySelector('.row .pick').click(), true`);
    check(await waitFor(`document.querySelector('[data-bulk]')?.hidden === false`), '勾选后出现批量操作条');
    check((await evalValue(`document.querySelector('[data-bulk-count]').textContent`)) === '1', '批量条计数为 1');
    await evalValue(`document.querySelector('.row .pick').click(), true`);
    check(await waitFor(`document.querySelector('[data-bulk]')?.hidden === true`), '取消勾选后批量条收起');

    // 新建文件夹对话框
    await evalValue(`document.querySelector('[data-new-folder]').click(), true`);
    check(await waitFor(`document.querySelector('[data-modal]')?.hidden === false`), '打开新建文件夹对话框');
    check(await evalValue(`document.querySelector('[data-modal-title]').textContent.includes('新建文件夹')`), '对话框标题正确');
    await evalValue(`document.querySelector('[data-modal-close]').click(), true`);
    check(await waitFor(`document.querySelector('[data-modal]')?.hidden === true`), '对话框可以关闭');

    // 键盘：跳过导航、搜索快捷键、菜单/对话框 Escape
    await evalValue(`document.activeElement?.blur(), true`);
    await press('Tab', 'Tab', 9);
    check(await evalValue(`document.activeElement?.classList.contains('skip')`), 'Tab 首次聚焦到「跳到主要内容」');
    await sleep(260);
    check(await evalValue(`document.querySelector('.skip').getBoundingClientRect().top >= 0`), '跳过链接聚焦时可见');
    await press('Enter', 'Enter', 13);
    await sleep(120);
    check(await evalValue(`document.activeElement?.tagName === 'MAIN'`), '跳过链接把焦点送到主内容');

    await evalValue(`document.activeElement?.blur(), true`);
    await press('/', 'Slash', 191);
    check(await evalValue(`document.activeElement === document.querySelector('.omni__in')`), '按 / 聚焦搜索框');
    await evalValue(`document.activeElement?.blur(), true`);

    await evalValue(`document.querySelector('[data-who-btn]').click(), true`);
    check(await waitFor(`document.querySelector('[data-who-menu]').hidden === false`), '账户菜单可以打开');
    await press('Escape', 'Escape', 27);
    check(await waitFor(`document.querySelector('[data-who-menu]').hidden === true`), 'Escape 关闭账户菜单');
    check(await evalValue(`document.activeElement === document.querySelector('[data-who-btn]')`), '关闭后焦点回到账户按钮');

    await evalValue(`document.querySelector('[data-sort-btn]').click(), true`);
    check(await waitFor(`document.querySelector('[data-sort-menu]').hidden === false`), '排序菜单可以打开');
    await press('Escape', 'Escape', 27);
    check(await waitFor(`document.querySelector('[data-sort-menu]').hidden === true`), 'Escape 关闭排序菜单');
    check(await evalValue(`document.activeElement === document.querySelector('[data-sort-btn]')`), '关闭后焦点回到排序按钮');

    await evalValue(`document.querySelector('[data-sort-btn]').click(), true`);
    await sleep(80);
    await evalValue(`document.querySelector('[data-sort-menu] [data-sort-key="time"]').click(), true`);
    check(await waitFor(`document.querySelector('[data-app]').dataset.sort === 'time'`), '点击排序后切换为按时间');
    check(await waitFor(`document.querySelectorAll('.row').length > 0`), '排序后列表仍正常渲染');

    await evalValue(`document.querySelector('.row').dispatchEvent(new MouseEvent('contextmenu', {
        bubbles: true, cancelable: true, clientX: 320, clientY: 300,
    })), true`);
    check(await waitFor(`document.querySelector('[data-ctx]').hidden === false`), '右键菜单可以打开');
    check(await evalValue(`document.activeElement?.hasAttribute('data-ctx-act')`), '右键菜单打开后焦点进入菜单');
    await press('Escape', 'Escape', 27);
    check(await waitFor(`document.querySelector('[data-ctx]').hidden === true`), 'Escape 关闭右键菜单');

    // 完整文件操作：新建文件夹 → 重命名 → 移入回收站
    await evalValue(`document.querySelector('[data-new-folder]').click(), true`);
    await waitFor(`document.querySelector('[data-modal]')?.hidden === false`);
    await evalValue(`(() => {
        const i = document.querySelector('[data-modal-body] [data-name]');
        i.value = 'js-created';
        i.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
    })()`);
    await evalValue(`document.querySelector('[data-modal-foot] [data-x="yes"]').click(), true`);
    check(await waitFor(`document.querySelector('[data-modal]').hidden === true`), '创建后对话框关闭');
    check(await waitFor(`[...document.querySelectorAll('.row__name')].some((el) => el.textContent === 'js-created')`), '创建后列表出现新文件夹');

    await evalValue(`document.querySelector('.row .pick').click(), true`);
    check(await evalValue(`document.querySelector('[data-pick-all]').indeterminate === true`), '部分选中时全选框显示半选');
    await evalValue(`document.querySelector('.row .pick').click(), true`);
    check(await evalValue(`document.querySelector('[data-pick-all]').indeterminate === false`), '取消选择后全选框恢复');

    await evalValue(`document.activeElement?.blur(), true`);
    await press('a', 'KeyA', 65, 2);
    check(await evalValue(`document.querySelector('[data-bulk]').hidden === false
        && document.querySelector('[data-bulk-count]').textContent === String(document.querySelectorAll('.row, .card').length)`), 'Ctrl+A 全选当前目录');
    await evalValue(`document.querySelector('[data-bulk] [data-act="clear"]').click(), true`);

    await evalValue(`(() => {
        const row = [...document.querySelectorAll('.row')].find((r) => r.dataset.name === 'js-created');
        row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 320, clientY: 320 }));
        return true;
    })()`);
    await waitFor(`document.querySelector('[data-ctx]').hidden === false`);
    await evalValue(`document.querySelector('[data-ctx-act="rename"]').click(), true`);
    await waitFor(`document.querySelector('[data-modal]')?.hidden === false`);
    await evalValue(`(() => {
        const i = document.querySelector('[data-modal-body] [data-name]');
        i.value = 'js-renamed';
        i.dispatchEvent(new Event('input', { bubbles: true }));
        document.querySelector('[data-modal-foot] [data-x="yes"]').click();
        return true;
    })()`);
    check(await waitFor(`[...document.querySelectorAll('.row__name')].some((el) => el.textContent === 'js-renamed')`), '重命名后列表立即更新');

    await evalValue(`(() => {
        const row = [...document.querySelectorAll('.row')].find((r) => r.dataset.name === 'js-renamed');
        row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 320, clientY: 320 }));
        return true;
    })()`);
    await waitFor(`document.querySelector('[data-ctx]').hidden === false`);
    await evalValue(`document.querySelector('[data-ctx-act="delete"]').click(), true`);
    await waitFor(`document.querySelector('[data-modal]')?.hidden === false`);
    await evalValue(`document.querySelector('[data-modal-foot] [data-x="yes"]').click(), true`);
    check(await waitFor(`![...document.querySelectorAll('.row__name')].some((el) => el.textContent === 'js-renamed')`), '删除后条目从列表消失');

    await evalValue(`document.querySelector('[data-new-folder]').click(), true`);
    check(await waitFor(`document.querySelector('[data-modal]')?.hidden === false`), '重新打开对话框');
    await press('Escape', 'Escape', 27);
    check(await waitFor(`document.querySelector('[data-modal]')?.hidden === true`), 'Escape 关闭对话框');

    check(((await evalValue(`window.__jsErrors || []`)) || []).length === 0, '桌面交互无未捕获脚本错误');

    // 主要页面巡检：加载期脚本错误往往会让整页交互静默失效
    const routes = [
        'files.album', 'shares.list', 'tags.list', 'tasks.list', 'stats.show',
        'sources.list', 'objects.index', 'settings.show', 'security.logs',
        'security.activity', 'api.tokens', 'rules.list',
        'files.list&section=trash', 'files.list&section=favorites',
    ];
    let pageErrors = 0;
    for (const route of routes) {
        await send('Page.navigate', { url: `${BASE}index.php?r=${route}` });
        await waitFor(`document.readyState === 'complete' && !!document.querySelector('#content')`);
        await sleep(220);
        const errs = await evalValue(`window.__jsErrors || []`);
        if (errs.length > 0) {
            pageErrors++;
            console.log(`  FAIL ${route} 脚本错误：${errs.join(' | ')}`);
        }
    }
    check(pageErrors === 0, '主要页面加载无未捕获脚本错误');

    // 移动端：工具栏不应溢出、视图切换应搬到轨迹栏
    await send('Emulation.setDeviceMetricsOverride', {
        width: 390, height: 844, deviceScaleFactor: 1, mobile: false,
    });
    await send('Page.navigate', { url: `${BASE}index.php?r=files.list` });
    check(await waitFor(`document.readyState === 'complete' && !!document.querySelector('[data-app]')`), '移动端重新加载完成');
    await sleep(300);
    check(await evalValue(`document.querySelector('.bar').scrollWidth <= innerWidth`), '390px 下顶栏没有横向溢出');
    check(await evalValue(`getComputedStyle(document.querySelector('.trail__view')).display !== 'none'`), '390px 下视图切换位于轨迹栏');

    check(await evalValue(`getComputedStyle(document.querySelector('.spine')).visibility === 'hidden'`), '关闭状态的移动端侧栏不可见');
    await evalValue(`document.querySelector('[data-burger]').click(), true`);
    check(await waitFor(`document.querySelector('[data-app]').classList.contains('is-nav-open')`), '移动端侧栏可以打开');
    check(await evalValue(`getComputedStyle(document.querySelector('.spine')).visibility === 'visible'`), '打开的侧栏可见');
    check(await evalValue(`document.querySelector('[data-burger]').getAttribute('aria-expanded') === 'true'`), '菜单按钮 aria-expanded 同步');
    check(await evalValue(`document.activeElement?.classList.contains('spine__item')`), '侧栏打开后焦点进入导航');
    await press('Escape', 'Escape', 27);
    check(await waitFor(`!document.querySelector('[data-app]').classList.contains('is-nav-open')`), 'Escape 关闭移动端侧栏');
    check(await evalValue(`document.activeElement === document.querySelector('[data-burger]')`), '关闭侧栏后焦点回到菜单按钮');
    check(await evalValue(`document.querySelector('[data-burger]').getAttribute('aria-expanded') === 'false'`), '关闭后 aria-expanded 复位');
    await sleep(350);
    check(await evalValue(`getComputedStyle(document.querySelector('.spine')).visibility === 'hidden'`), '侧栏关闭后退出键盘焦点顺序');

    check(((await evalValue(`window.__jsErrors || []`)) || []).length === 0, '移动端交互无未捕获脚本错误');
} finally {
    if (ws) {
        ws.close();
    }
    if (chrome) {
        chrome.kill('SIGKILL');
    }
    try {
        fixture('destroy');
    } catch {
        // 清理失败不掩盖测试结果
    }
    try {
        unlinkSync(sessionFile);
    } catch {
        // 会话文件可能已被会话回收
    }
}

console.log(failures === 0 ? '\n全部通过' : `\n${failures} 项失败`);
process.exit(failures === 0 ? 0 : 1);

async function connect() {
    let wsUrl = null;
    for (let i = 0; i < 100 && !wsUrl; i++) {
        try {
            const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            wsUrl = list.find((t) => t.type === 'page')?.webSocketDebuggerUrl || null;
        } catch {
            // DevTools 还没起来
        }
        if (!wsUrl) {
            await sleep(100);
        }
    }
    if (!wsUrl) {
        throw new Error('无法连接 Chromium DevTools');
    }

    const socket = new WebSocket(wsUrl);
    let id = 0;
    const pending = new Map();
    socket.addEventListener('message', (event) => {
        const msg = JSON.parse(event.data);
        if (msg.id && pending.has(msg.id)) {
            pending.get(msg.id)(msg);
            pending.delete(msg.id);
        }
    });
    await new Promise((res, rej) => {
        socket.addEventListener('open', res, { once: true });
        socket.addEventListener('error', rej, { once: true });
    });

    return {
        close: () => socket.close(),
        send: (method, params = {}) => new Promise((res) => {
            const mid = ++id;
            pending.set(mid, res);
            socket.send(JSON.stringify({ id: mid, method, params }));
        }),
    };
}
