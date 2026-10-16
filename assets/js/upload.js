/* 拾云 · 传输队列
   分片上传、断点续传、失败重试、暂停/继续/取消、限速、秒传。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const SY = window.SY = window.SY || {};
    const icon = SY.icon || (() => '');

    if (!CFG.base) {
        return;
    }

    const MAX_CONCURRENT = 2;
    const MAX_RETRY = 3;
    const INSTANT_HASH_LIMIT = 256 * 1024 * 1024;

    let seq = 0;
    const items = new Map();
    let running = 0;
    let speedLimit = 0;   // KB/s，0 = 不限

    /* ══════════════════════════════════════════════════════ 请求 */

    async function jsonReq(route, payload) {
        const res = await fetch(CFG.base + '?r=' + encodeURIComponent(route), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': CFG.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload || {}),
        });
        const body = await res.json().catch(() => null);
        if (!res.ok || !body || body.ok !== true) {
            const err = new Error((body && body.error) || ('请求失败（' + res.status + '）'));
            err.code = body && body.code;
            err.status = res.status;
            err.extra = body;
            throw err;
        }
        return body.data;
    }

    async function sendChunk(uploadId, index, blob, signal) {
        const res = await fetch(CFG.base + '?r=upload.chunk', {
            method: 'POST',
            credentials: 'same-origin',
            signal: signal,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/octet-stream',
                'X-CSRF-Token': CFG.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'X-Upload-Id': uploadId,
                'X-Chunk-Index': String(index),
            },
            body: blob,
        });
        const body = await res.json().catch(() => null);
        if (!res.ok || !body || body.ok !== true) {
            throw new Error((body && body.error) || '分片上传失败');
        }
        return body.data;
    }

    /* ══════════════════════════════════════════════════════ 工具 */

    // 当前选中的上传目标存储源（0 = 仅本地）
    // 上传目标不再放工具栏下拉（又丑又对不齐），改为上传时在弹窗里选。
    // 这里存「上次确认过」的值，弹窗默认选中它。
    let chosenSource = 0;

    function currentSource() {
        return chosenSource;
    }

    function human(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        let v = Number(bytes) || 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }

    function humanSpeed(bytesPerSec) {
        if (!bytesPerSec) return '—';
        return human(bytesPerSec) + '/s';
    }

    async function sha256(file) {
        if (!window.crypto || !window.crypto.subtle) return null;
        if (file.size > INSTANT_HASH_LIMIT) return null;
        try {
            const buf = await file.arrayBuffer();
            const digest = await window.crypto.subtle.digest('SHA-256', buf);
            return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
        } catch (e) {
            return null;
        }
    }

    /* ══════════════════════════════════════════════════════ 队列渲染 */

    const host = () => (SY.queue ? SY.queue.el : null);

    function render() {
        const list = host();
        if (!list) return;

        const all = Array.from(items.values());
        const active = all.filter((it) => it.status === 'queued' || it.status === 'running' || it.status === 'paused').length;
        const done = all.filter((it) => it.status === 'done').length;
        const failed = all.filter((it) => it.status === 'failed').length;

        if (SY.queue) {
            SY.queue.sum.textContent = all.length
                ? (active ? active + ' 进行中' : (done + ' 已完成' + (failed ? ' · ' + failed + ' 失败' : '')))
                : '';
            if (SY.queue.dot) SY.queue.dot.hidden = active === 0;
        }

        list.innerHTML = all.map((it) => {
            const pct = it.status === 'done' ? 100 : Math.floor(it.progress * 100);
            const stateText = {
                queued: '排队中',
                running: '上传中',
                paused: '已暂停',
                done: it.dedup ? '秒传完成' : '已完成',
                failed: it.error || '失败',
                canceled: '已取消',
            }[it.status] || '';

            const acts = [];
            if (it.status === 'running' || it.status === 'queued') {
                acts.push('<button class="minibtn" type="button" data-q="pause" aria-label="暂停">' + icon('pause') + '</button>');
            }
            if (it.status === 'paused') {
                acts.push('<button class="minibtn" type="button" data-q="resume" aria-label="继续">' + icon('play') + '</button>');
            }
            if (it.status === 'failed') {
                acts.push('<button class="minibtn" type="button" data-q="retry" aria-label="重试">' + icon('refresh-cw') + '</button>');
            }
            if (it.status !== 'done') {
                acts.push('<button class="minibtn" type="button" data-q="cancel" aria-label="取消">' + icon('x') + '</button>');
            } else {
                acts.push('<button class="minibtn" type="button" data-q="drop" aria-label="移除">' + icon('x') + '</button>');
            }

            return '<li class="qitem qitem--' + it.status + '" data-qid="' + it.id + '">'
                + '<div class="qitem__top">'
                + '<span class="qitem__name">' + escapeHtml(it.name) + '</span>'
                + '<span class="qitem__pct">' + pct + '%</span>'
                + '</div>'
                + '<span class="qitem__bar"><span class="qitem__fill" style="width:' + pct + '%"></span></span>'
                + '<div class="qitem__foot">'
                + '<span>' + escapeHtml(stateText) + '</span>'
                + (it.status === 'running' ? '<span>' + humanSpeed(it.speed) + '</span>' : '')
                + '<span>' + human(it.file.size) + '</span>'
                + '<span class="qitem__acts">' + acts.join('') + '</span>'
                + '</div>'
                + '</li>';
        }).join('');
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    function patch(id, changes) {
        const it = items.get(id);
        if (!it) return;
        Object.assign(it, changes);
        render();
    }

    /* ══════════════════════════════════════════════════════ 上传流程 */

    function enqueueFiles(fileList, parentId, nameOverride, sourceId) {
        const files = Array.from(fileList || []);
        if (!files.length) return;

        if (sourceId !== undefined) chosenSource = Number(sourceId) || 0;
        // 改名只在单选时生效；多选时各自保留原文件名
        const renameTo = (files.length === 1 && nameOverride) ? String(nameOverride) : '';

        files.forEach((file) => {
            const id = ++seq;
            const relative = file.webkitRelativePath || '';
            items.set(id, {
                id: id,
                file: file,
                name: renameTo || file.name,
                parent: parentId,
                relative: relative,
                status: 'queued',
                progress: 0,
                uploaded: 0,
                speed: 0,
                error: '',
                uploadId: null,
                chunkSize: CFG.chunkSize || 5 * 1024 * 1024,
                totalChunks: 0,
                received: new Set(),
                retries: 0,
                controller: null,
                lastTick: 0,
                lastBytes: 0,
                dedup: false,
            });
        });

        render();
        if (SY.queue) SY.queue.show();
        pump();
    }

    function pump() {
        while (running < MAX_CONCURRENT) {
            const next = Array.from(items.values()).find((it) => it.status === 'queued');
            if (!next) break;
            running++;
            run(next).finally(() => {
                running--;
                pump();
            });
        }
    }

    async function run(it) {
        if (it.status !== 'queued') return;
        it.status = 'running';
        it.error = '';
        it.retries = 0;
        it.controller = new AbortController();
        it.lastTick = performance.now();
        it.lastBytes = 0;
        // 续传/重试会重跑 run()，必须清零累计，否则进度会翻倍并超过 100%
        it.uploaded = 0;
        it.progress = 0;
        it.speed = 0;
        render();

        try {
            // 1) 建会话（可能命中秒传）
            const hash = await sha256(it.file);
            if (it.status === 'paused') return;

            const init = await jsonReq('upload.init', {
                name: it.name,
                size: it.file.size,
                parent: it.parent,
                relativePath: it.relative,
                hash: hash,
                source: currentSource(),
            });

            if (init.instant) {
                it.dedup = true;
                it.progress = 1;
                patch(it.id, { status: 'done' });
                onUploaded(init.node);
                return;
            }

            it.uploadId = init.uploadId;
            it.chunkSize = init.chunkSize;
            it.totalChunks = init.totalChunks;
            it.received = new Set(init.received || []);

            // 2) 逐片上传（跳过已收到的，实现续传）
            for (let index = 0; index < it.totalChunks; index++) {
                if (it.status === 'canceled') return;
                if (it.status === 'paused') {
                    render();
                    return;
                }
                if (it.received.has(index)) {
                    it.uploaded += chunkLength(it, index);
                    it.progress = it.file.size ? Math.min(0.999, it.uploaded / it.file.size) : 0.999;
                    continue;
                }

                const start = index * it.chunkSize;
                const blob = it.file.slice(start, Math.min(start + it.chunkSize, it.file.size));

                let attempt = 0;
                for (;;) {
                    try {
                        await sendChunk(it.uploadId, index, blob, it.controller.signal);
                        break;
                    } catch (err) {
                        if (err.name === 'AbortError') {
                            if (it.status === 'paused') return;
                            throw err;
                        }
                        attempt++;
                        if (attempt > MAX_RETRY) throw err;
                        it.error = '分片重试 ' + attempt + '/' + MAX_RETRY;
                        render();
                        await sleep(400 * attempt);
                    }
                }

                it.received.add(index);
                it.uploaded += blob.size;
                it.progress = it.file.size ? Math.min(0.999, it.uploaded / it.file.size) : 0.999;

                // 速率
                const now = performance.now();
                const dt = (now - it.lastTick) / 1000;
                if (dt >= 0.4) {
                    it.speed = (it.uploaded - it.lastBytes) / dt;
                    it.lastTick = now;
                    it.lastBytes = it.uploaded;
                }
                render();

                if (speedLimit > 0) {
                    await throttle(blob.size, speedLimit);
                }
            }

            if (it.status === 'paused' || it.status === 'canceled') return;

            // 3) 合并
            patch(it.id, { status: 'running', error: '正在合并…' });
            const done = await jsonReq('upload.complete', { uploadId: it.uploadId });
            it.dedup = !!done.dedup;
            it.progress = 1;
            it.speed = 0;
            patch(it.id, { status: 'done', error: '' });
            onUploaded(done.node);
        } catch (err) {
            if (it.status === 'canceled') return;
            patch(it.id, { status: 'failed', error: err.message || '上传失败', speed: 0 });
        }
    }

    function chunkLength(it, index) {
        const start = index * it.chunkSize;
        return Math.min(it.chunkSize, Math.max(0, it.file.size - start));
    }

    function onUploaded() {
        if (SY.refresh) SY.refresh();
        if (SY.refreshQuota) SY.refreshQuota();
    }

    function sleep(ms) {
        return new Promise((r) => setTimeout(r, ms));
    }

    async function throttle(bytes, limitKB) {
        const expected = bytes / (limitKB * 1024) * 1000;
        const wait = expected - 0;
        if (wait > 0) {
            await sleep(wait);
        }
    }

    /* ══════════════════════════════════════════════════════ 队列操作 */

    function itemOf(el) {
        const li = el.closest('[data-qid]');
        return li ? items.get(Number(li.dataset.qid)) : null;
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-q]');
        if (!btn) return;
        const it = itemOf(btn);
        if (!it) return;
        const act = btn.dataset.q;

        if (act === 'pause') {
            it.status = 'paused';
            if (it.controller) it.controller.abort();
            render();
            return;
        }

        if (act === 'resume') {
            it.status = 'queued';
            it.error = '';
            render();
            pump();
            return;
        }

        if (act === 'retry') {
            it.status = 'queued';
            it.error = '';
            it.retries = 0;
            render();
            pump();
            return;
        }

        if (act === 'cancel') {
            it.status = 'canceled';
            if (it.controller) it.controller.abort();
            if (it.uploadId) {
                jsonReq('upload.cancel', { uploadId: it.uploadId }).catch(() => {});
            }
            items.delete(it.id);
            render();
            return;
        }

        if (act === 'drop') {
            items.delete(it.id);
            render();
        }
    });

    /* ══════════════════════════════════════════════════ 上传前确认弹窗 */

    // 上传目标由视图注入（本地磁盘 + 本人可用的 s3 源）
    const UPLOAD_CFG = (window.__UPLOAD__ && Array.isArray(window.__UPLOAD__.sources) && window.__UPLOAD__.sources.length)
        ? window.__UPLOAD__
        : { sources: [{ id: 0, name: '本地磁盘' }], defaultSource: 0 };

    chosenSource = Number(UPLOAD_CFG.defaultSource) || 0;

    /** 目录树的行（与「移动到…」用的是同一套 .tree 样式）。
        files.tree 的数据里已经包含根目录（parent 为 null 的那些），不要再自行补一行。 */
    function folderRows(treeData, current) {
        const kids = new Map();
        (treeData.tree || []).forEach((n) => {
            const key = n.parent === null ? 'root' : n.parent;
            if (!kids.has(key)) kids.set(key, []);
            kids.get(key).push(n);
        });

        const rows = [];

        const walk = (parentKey, depth) => {
            (kids.get(parentKey) || []).forEach((n) => {
                rows.push('<button class="tree__item' + (Number(n.id) === current ? ' is-on' : '')
                    + '" type="button" data-up-pick="' + Number(n.id) + '" style="padding-left:' + (9 + depth * 16) + 'px">'
                    + icon(n.private ? 'lock-keyhole' : 'folder', 'tree__ico')
                    + '<span>' + escapeHtml(String(n.name)) + '</span></button>');
                walk(n.id, depth + 1);
            });
        };
        walk('root', 0);

        return rows.join('');
    }

    /**
     * 上传前确认：改名（仅单选）、选存储位置、选目标文件夹。
     * 返回 { name, folder, source }；取消返回 null。
     */
    async function askDestination(files, parentId) {
        const single = files.length === 1;

        let treeData = { tree: [], root: Number(parentId) };
        try {
            treeData = await SY.api('files.tree', {}, { method: 'GET' });
        } catch (e) { /* 取不到树就只留当前目录，不阻断上传 */ }

        const names = files.map((f) => f.name);
        const sources = UPLOAD_CFG.sources.map((s) =>
            '<button class="seg__btn' + (Number(s.id) === chosenSource ? ' is-on' : '') + '"'
            + ' type="button" data-up-src="' + Number(s.id) + '">' + escapeHtml(String(s.name)) + '</button>'
        ).join('');

        const fileField = single
            ? '<input class="minput" data-up-name value="' + escapeHtml(names[0]) + '">'
            : '<div class="upfiles">' + files.length + ' 个文件<span class="upfiles__list">'
              + escapeHtml(names.slice(0, 3).join('、')) + (names.length > 3 ? ' 等' : '') + '</span></div>';

        const body = ''
            + '<div class="mfield"><span class="mfield__label">' + (single ? '文件名' : '文件') + '</span>'
            + fileField + '</div>'
            + '<div class="mfield"><span class="mfield__label">存到</span>'
            + '<div class="seg seg--wide" data-up-sources>' + sources + '</div></div>'
            + '<div class="mfield"><span class="mfield__label">存进</span>'
            + '<div class="tree tree--pick" data-up-tree>' + folderRows(treeData, Number(parentId)) + '</div></div>';

        const foot = '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">开始上传</button>';

        return new Promise((resolve) => {
            SY.openModal('上传', body, foot, () => resolve(null));

            let folder = Number(parentId);
            let source = chosenSource;

            const tree = document.querySelector('[data-up-tree]');
            tree?.addEventListener('click', (e) => {
                const item = e.target.closest('[data-up-pick]');
                if (!item) return;
                tree.querySelectorAll('.tree__item').forEach((el) => el.classList.toggle('is-on', el === item));
                folder = Number(item.dataset.upPick);
            });

            document.querySelector('[data-up-sources]')?.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-up-src]');
                if (!btn) return;
                btn.parentElement.querySelectorAll('.seg__btn').forEach((el) => el.classList.toggle('is-on', el === btn));
                source = Number(btn.dataset.upSrc);
            });

            document.querySelector('[data-modal-foot] [data-x="no"]').addEventListener('click', () => {
                resolve(null);
                SY.closeModal();
            });

            document.querySelector('[data-modal-foot] [data-x="yes"]').addEventListener('click', () => {
                const nameEl = document.querySelector('[data-up-name]');
                const name = nameEl ? nameEl.value.trim() : '';
                if (single && name === '') {
                    SY.toast('文件名不能为空', 'err');
                    return;
                }
                // 必须先 resolve 再 closeModal：closeModal 会触发 openModal 传入的 onClose，
                // 而那个回调同样 resolve(null)。Promise 只认第一次结算——顺序反了，
                // 上传永远拿到 null，表现成「点了开始上传没反应」。
                resolve({ name: single ? name : '', folder: folder, source: source });
                SY.closeModal();
            });
        });
    }

    /** 弹出确认框，确认后入队；取消或空选则什么都不做。 */
    async function pickAndEnqueue(fileList, parentId) {
        const files = Array.from(fileList || []);
        if (!files.length) return;

        const picked = await askDestination(files, parentId);
        if (picked === null) return;

        chosenSource = picked.source;
        // 记住这次的选择作为下次默认；失败不影响上传
        SY.api('settings.preferences', { upload_source_id: picked.source }).catch(() => {});

        enqueueFiles(files, picked.folder, picked.name, picked.source);
    }

    /* ══════════════════════════════════════════════════════ 对外接口 */

    SY.transfer = {
        enqueueFiles: enqueueFiles,
        pickAndEnqueue: pickAndEnqueue,
        setSpeedLimit(kb) {
            speedLimit = Math.max(0, Number(kb) || 0);
        },
        clearFinished() {
            Array.from(items.values()).forEach((it) => {
                if (it.status === 'done' || it.status === 'failed' || it.status === 'canceled') {
                    items.delete(it.id);
                }
            });
            render();
        },
        activeCount() {
            return Array.from(items.values()).filter((it) => it.status === 'running' || it.status === 'queued').length;
        },
    };

    render();
})();
