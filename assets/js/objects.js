/* 拾云 · 对象存储管理页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const icon = SY.icon || (() => '');
    const $ = SY.$;
    const $$ = SY.$$;
    const api = SY.api;
    const toast = SY.toast;
    const escapeHtml = SY.escapeHtml;
    const humanSize = SY.humanSize;

    const root = $('[data-page="objects"]');
    if (!root) return;

    const cfg = (() => {
        try { return JSON.parse(root.dataset.config || '{}'); } catch (e) { return {}; }
    })();

    const state = {
        accounts: Array.isArray(cfg.accounts) ? cfg.accounts.slice() : [],
        account: Number(cfg.accountId) || 0,
        bucket: String(cfg.bucket || ''),
        prefix: String(cfg.prefix || ''),
        bound: cfg.bound || {},
        providers: cfg.providers || {},
        regions: cfg.regions || [],
        classes: cfg.classes || {},
        archiveClasses: cfg.archiveClasses || [],
        selected: new Set(),
        objects: [],
        cursor: '',
        history: [],
        query: '',
        sortKey: 'name',
        sortDir: 1,
        lastData: null,
    };
    if (!state.account && state.accounts.length) state.account = Number(state.accounts[0].id);
    if (!state.accounts.length) state.bucket = '';
    // URL 里的账号可能刚被删除：非零 id 会让上面的兜底失效，必须回到第一个可用账号
    if (state.account && !state.accounts.some((a) => Number(a.id) === Number(state.account))) {
        state.account = state.accounts.length ? Number(state.accounts[0].id) : 0;
    }

    const el = {
        select: $('[data-account-select]'),
        noAccount: $('[data-no-account]'),
        bucketView: $('[data-bucket-view]'),
        detailView: $('[data-detail-view]'),
        grid: $('[data-bucket-grid]'),
        title: $('[data-detail-title]'),
        detailActs: $('[data-detail-acts]'),
        crumb: $('[data-crumb]'),
        objHost: $('[data-obj-host]'),
        settingsHost: $('[data-settings-host]'),
        selection: $('[data-obj-selection]'),
        selectedCount: $('[data-obj-selected-count]'),
        search: $('[data-obj-search]'),
        uploadInput: $('[data-upload-input]'),
    };

    function account() {
        return state.accounts.find((a) => Number(a.id) === Number(state.account)) || null;
    }

    function updateQuery() {
        const params = new URLSearchParams(location.search);
        if (state.account) params.set('account', String(state.account)); else params.delete('account');
        if (state.bucket) params.set('bucket', state.bucket); else params.delete('bucket');
        if (state.prefix) params.set('prefix', state.prefix); else params.delete('prefix');
        history.replaceState(null, '', location.pathname + '?' + params.toString());
    }

    function renderAccounts() {
        if (!el.select) return;
        el.select.innerHTML = state.accounts.map((a) =>
            '<option value="' + Number(a.id) + '"' + (Number(a.id) === Number(state.account) ? ' selected' : '') + '>'
            + escapeHtml(a.name) + ' · ' + escapeHtml((state.providers[a.provider] || a.provider)) + '</option>'
        ).join('');
        const has = state.accounts.length > 0;
        el.noAccount?.toggleAttribute('hidden', has);
        el.bucketView?.toggleAttribute('hidden', !has || !!state.bucket);
        el.detailView?.toggleAttribute('hidden', !state.bucket);
        if (state.account) updateQuery();
    }

    /* ---------------------------------------------------------- 账号表单 */

    function accountForm(a) {
        const isEdit = !!a;
        const providers = Object.entries(state.providers).map(([k, v]) =>
            '<option value="' + k + '"' + (a && a.provider === k ? ' selected' : '') + '>' + escapeHtml(v) + '</option>'
        ).join('');
        const regions = state.regions.map((r) =>
            '<option value="' + r + '"' + (a && a.region === r ? ' selected' : '') + '>' + r + '</option>'
        ).join('');
        return '<div class="mform">'
            + '<label class="mfield"><span class="mfield__label">名称</span>'
            + '<input class="minput" data-f="name" value="' + escapeHtml(a ? a.name : '') + '" maxlength="64"></label>'
            + '<label class="mfield"><span class="mfield__label">服务商</span>'
            + '<select class="select" data-f="provider">' + providers + '</select></label>'
            + '<label class="mfield"><span class="mfield__label">地域</span>'
            + '<input class="minput" data-f="region" list="obj-regions" value="' + escapeHtml(a ? a.region : '') + '">'
            + '<datalist id="obj-regions">' + regions + '</datalist></label>'
            + '<label class="mfield"><span class="mfield__label">Endpoint</span>'
            + '<input class="minput" data-f="endpoint" value="' + escapeHtml(a ? a.endpoint : '') + '" placeholder="https://cos.ap-guangzhou.myqcloud.com"></label>'
            + '<label class="mfield"><span class="mfield__label">Access Key</span>'
            + '<input class="minput" data-f="access_key" value="' + escapeHtml(a ? a.access_key : '') + '" autocomplete="off"></label>'
            + '<label class="mfield"><span class="mfield__label">Secret Key</span>'
            + '<input class="minput" type="password" data-f="secret_key" value="' + (a && a.secret_key ? '••••••' : '') + '" autocomplete="new-password" placeholder="' + (isEdit ? '留空或保持掩码表示不修改' : '') + '"></label>'
            + '<label class="mfield"><span class="mfield__label">临时令牌（STS，可选）</span>'
            + '<input class="minput" type="password" data-f="session_token" value="' + (a && a.session_token ? '••••••' : '') + '" autocomplete="new-password"></label>'
            + '<p class="mhint">建议使用只授予目标桶权限的子账号密钥。</p>'
            + '</div>';
    }

    function openAccount(a) {
        SY.openModal(a ? '编辑账号' : '添加账号', accountForm(a),
            (a ? '<button class="mbtn mbtn--danger" type="button" data-x="del">删除账号</button>' : '')
            + '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">保存</button>');
        const body = $('[data-modal-body]');
        const foot = $('[data-modal-foot]');

        // 选 COS + 地域时自动填 endpoint；用户手改过就不再覆盖
        const providerEl = body.querySelector('[data-f="provider"]');
        const regionEl = body.querySelector('[data-f="region"]');
        const endpointEl = body.querySelector('[data-f="endpoint"]');
        const syncEndpoint = () => {
            if (!endpointEl || endpointEl.dataset.touched === '1') return;
            if (providerEl && providerEl.value === 'cos' && regionEl && regionEl.value) {
                endpointEl.value = 'https://cos.' + regionEl.value + '.myqcloud.com';
            }
        };
        regionEl?.addEventListener('change', syncEndpoint);
        providerEl?.addEventListener('change', syncEndpoint);
        endpointEl?.addEventListener('input', () => { endpointEl.dataset.touched = '1'; });
        syncEndpoint();

        foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
        foot.querySelector('[data-x="del"]')?.addEventListener('click', async () => {
            const ok = await SY.confirm('删除账号', '只删除凭据，不影响已绑定的存储源。', '删除', true);
            if (!ok) return;
            try {
                await api('objects.account_delete', { id: Number(a.id) });
                SY.closeModal();
                toast('账号已删除');
                location.reload();
            } catch (err) {
                toast(err.message, 'err');
            }
        });
        foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            const payload = { id: a ? Number(a.id) : 0 };
            $$('[data-f]', body).forEach((input) => { payload[input.dataset.f] = input.value; });
            btn.disabled = true;
            try {
                await api('objects.account_save', payload);
                SY.closeModal();
                toast('账号已保存');
                location.reload();
            } catch (err) {
                toast(err.message, 'err');
                btn.disabled = false;
            }
        });
    }

    $$('[data-account-new]').forEach((btn) => btn.addEventListener('click', () => openAccount(null)));
    $('[data-account-edit]')?.addEventListener('click', () => {
        const a = account();
        if (!a) { toast('先添加一个账号', 'err'); return; }
        openAccount(a);
    });
    $('[data-account-test]')?.addEventListener('click', async (ev) => {
        const a = account();
        if (!a) { toast('先添加一个账号', 'err'); return; }
        const btn = ev.currentTarget;
        btn.disabled = true;
        try {
            const r = await api('objects.account_test', { id: Number(a.id) });
            toast('连接成功，账号下有 ' + r.count + ' 个桶');
        } catch (err) {
            toast(err.message, 'err');
        } finally {
            btn.disabled = false;
        }
    });

    el.select?.addEventListener('change', () => {
        state.account = Number(el.select.value) || 0;
        state.bucket = '';
        state.prefix = '';
        state.query = '';
        state.cursor = '';
        state.history = [];
        if (el.search) el.search.value = '';
        renderAccounts();
        if (typeof window.__objectsLoadBuckets === 'function') window.__objectsLoadBuckets();
    });

    /* 后续任务在这里挂载：桶列表、对象列表、上传、设置 */
    window.__objects = { state, cfg, el, account, updateQuery, renderAccounts };

    /* ---------------------------------------------------------- 桶概览 */

    async function loadBuckets() {
        if (!state.account) return;
        el.grid.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取桶列表…</p></div>';
        try {
            const data = await api('objects.buckets', { account: state.account }, { method: 'GET' });
            renderBuckets(data.buckets || []);
        } catch (err) {
            // 子账号可能没有 ListBuckets 权限；给一条直接按桶名进入的路
            el.grid.innerHTML = '<div class="blank">' + icon('alert-triangle')
                + '<p class="blank__title">读不到桶列表</p>'
                + '<p class="blank__hint">' + escapeHtml(err.message) + '</p>'
                + '<div class="objbar" style="justify-content:center;margin-top:12px">'
                + '<input class="minput" data-manual-bucket placeholder="直接输入桶名，例如 photos-1250000000">'
                + '<button class="chipbtn" type="button" data-manual-enter>直接进入</button>'
                + '</div></div>';
        }
    }
    window.__objectsLoadBuckets = loadBuckets;

    function renderBuckets(buckets) {
        if (!buckets.length) {
            el.grid.innerHTML = '<div class="blank">' + icon('database')
                + '<p class="blank__title">这个账号下还没有桶</p>'
                + '<p class="blank__hint">点右上角「新建桶」建一个，建完就能绑定为存储源。</p></div>';
            return;
        }
        el.grid.innerHTML = '<div class="objgrid">' + buckets.map((b) => {
            const name = escapeHtml(b.name);
            const bound = b.bound
                ? '<span class="pill">' + escapeHtml(b.boundBy) + '</span>'
                : '<span class="muted">未绑定</span>';
            return '<button class="objcard" type="button" data-bucket-open="' + name + '">'
                + '<span class="objcard__name">' + icon('database') + '<span>' + name + '</span></span>'
                + '<span class="objcard__meta">'
                + '<span>' + escapeHtml(b.region || '—') + '</span>'
                + '<span>' + escapeHtml(b.created ? b.created.slice(0, 10) : '—') + '</span>'
                + '</span><span class="objcard__meta">' + bound + '</span></button>';
        }).join('') + '</div>';
    }

    el.grid.addEventListener('click', (e) => {
        const manual = e.target.closest('[data-manual-enter]');
        if (manual) {
            const input = el.grid.querySelector('[data-manual-bucket]');
            const name = (input?.value || '').trim().toLowerCase();
            if (!name) { toast('请输入桶名', 'err'); return; }
            state.bucket = name;
            state.prefix = '';
            state.cursor = '';
            state.history = [];
            state.selected.clear();
            renderAccounts();
            window.__objectsEnterBucket();
            return;
        }

        const card = e.target.closest('[data-bucket-open]');
        if (card) {
            state.bucket = card.dataset.bucketOpen;
            state.prefix = '';
            state.cursor = '';
            state.history = [];
            state.selected.clear();
            renderAccounts();
            if (typeof window.__objectsEnterBucket === 'function') window.__objectsEnterBucket();
        }
    });

    $('[data-bucket-reload]')?.addEventListener('click', loadBuckets);
    $('[data-bucket-new]')?.addEventListener('click', async () => {
        const name = await SY.prompt('新建存储桶', '桶名', '', '小写字母、数字、- 与 .；COS 需带 -APPID');
        if (name === null) return;
        try {
            const r = await api('objects.bucket_create', { account: state.account, bucket: name.trim() });
            toast(r.message || '桶已创建');
            loadBuckets();
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    function renderDetailHead() {
        el.title.textContent = state.bucket;
        const boundBy = Object.prototype.hasOwnProperty.call(state.bound, state.bucket)
            ? state.bound[state.bucket] : null;
        el.detailActs.innerHTML = boundBy
            ? '<span class="pill">' + escapeHtml(boundBy) + '</span>'
            : '<button class="chipbtn" type="button" data-bucket-bind>'
                + icon('link') + '绑定为存储源</button>'
                + '<button class="chipbtn" type="button" data-bucket-purge>清空桶</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-bucket-del>删除桶</button>';
    }
    window.__objectsEnterBucket = function () {
        // 进入桶时清掉上一个桶遗留的搜索/分页/设置状态，并让在飞响应失效
        state.reqToken = (state.reqToken || 0) + 1;
        state.query = '';
        state.cursor = '';
        state.history = [];
        if (el.search) el.search.value = '';
        const objectsTab = $('[data-tab="objects"]');
        const settingsTab = $('[data-tab="settings"]');
        if (objectsTab && settingsTab) {
            objectsTab.classList.add('is-on');
            settingsTab.classList.remove('is-on');
        }
        $$('[data-tab-panel]').forEach((panel) => {
            panel.toggleAttribute('hidden', panel.dataset.tabPanel !== 'objects');
        });
        if (el.settingsHost) el.settingsHost.innerHTML = '';
        el.bucketView.hidden = true;
        el.detailView.hidden = false;
        renderDetailHead();
        updateQuery();
        if (typeof window.__objectsLoadObjects === 'function') window.__objectsLoadObjects();
    };
    $('[data-detail-back]')?.addEventListener('click', () => {
        state.bucket = '';
        state.prefix = '';
        state.query = '';
        state.cursor = '';
        state.history = [];
        if (el.search) el.search.value = '';
        state.selected.clear();
        renderAccounts();
        loadBuckets();
    });

    el.detailActs.addEventListener('click', async (e) => {
        if (e.target.closest('[data-bucket-bind]')) {
            try {
                const r = await api('objects.bucket_bind', {
                    account: state.account, bucket: state.bucket, name: state.bucket,
                });
                toast('已绑定为存储源「' + r.name + '」');
                setTimeout(() => location.reload(), 600);
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        if (e.target.closest('[data-bucket-del]')) {
            const typed = await SY.prompt('删除存储桶', '原样输入桶名以确认：' + state.bucket, '', state.bucket);
            if (typed === null) return;
            try {
                const r = await api('objects.bucket_delete', {
                    account: state.account, bucket: state.bucket, confirm: typed.trim(),
                });
                toast(r.message || '桶已删除');
                state.bucket = '';
                state.prefix = '';
                renderAccounts();
                loadBuckets();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        if (e.target.closest('[data-bucket-purge]')) {
            const typed = await SY.prompt('清空存储桶', '原样输入桶名以确认：' + state.bucket, '', state.bucket);
            if (typed === null) return;
            try {
                const r = await api('objects.bucket_purge', {
                    account: state.account, bucket: state.bucket, confirm: typed.trim(),
                });
                toast(r.message + (r.remaining ? '，仍有剩余，可再点一次' : ''));
                loadObjects();
            } catch (err) {
                toast(err.message, 'err');
            }
        }
    });

    /* ---------------------------------------------------------- 对象列表 */

    function parentPrefix(prefix) {
        const trimmed = prefix.replace(/\/$/, '');
        const pos = trimmed.lastIndexOf('/');
        return pos === -1 ? '' : trimmed.slice(0, pos + 1);
    }

    function renderCrumb() {
        const parts = state.prefix.split('/').filter(Boolean);
        let acc = '';
        const items = ['<button type="button" data-crumb-to="">' + escapeHtml(state.bucket) + '</button>'];
        parts.forEach((part) => {
            acc += part + '/';
            items.push('<span>/</span><button type="button" data-crumb-to="' + escapeHtml(acc) + '">'
                + escapeHtml(part) + '</button>');
        });
        el.crumb.innerHTML = items.join('');
    }

    async function loadObjects() {
        if (!state.account || !state.bucket) return;
        const token = ++state.reqToken;
        const bucket = state.bucket;
        renderCrumb();
        el.objHost.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取对象…</p></div>';
        state.selected.clear();
        updateSelection();
        const params = {
            account: state.account,
            bucket: state.bucket,
            prefix: state.prefix,
            q: state.query,
            cursor: state.cursor,
            max_keys: state.query !== '' ? 200 : 100,
        };
        try {
            const data = await api('objects.list', params, { method: 'GET' });
            if (token !== state.reqToken || bucket !== state.bucket) return;
            state.objects = data.objects || [];
            state.lastData = data;
            renderObjects(data);
        } catch (err) {
            if (token !== state.reqToken || bucket !== state.bucket) return;
            el.objHost.innerHTML = '<div class="blank">' + icon('alert-triangle')
                + '<p class="blank__title">读不到对象</p>'
                + '<p class="blank__hint">' + escapeHtml(err.message) + '</p></div>';
        }
    }
    window.__objectsLoadObjects = loadObjects;

    function iconFor(key) {
        const ext = (key.split('.').pop() || '').toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) return 'file-image';
        if (['mp4', 'mov', 'mkv', 'webm'].includes(ext)) return 'file-video';
        if (['mp3', 'wav', 'flac', 'm4a'].includes(ext)) return 'file-audio';
        if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'file-archive';
        if (['js', 'ts', 'php', 'py', 'go', 'java', 'json', 'xml'].includes(ext)) return 'file-code';
        return 'file';
    }

    function renderObjects(data) {
        // 已绑定的桶只读：保留下载与详情，隐藏复制/删除等写操作
        const readOnly = !!data.boundBy;
        $$('[data-obj-upload], [data-obj-mkdir]').forEach((btn) => { btn.disabled = readOnly; });
        const rows = [];
        const objects = (data.objects || []).slice().sort((a, b) => {
            const pick = (o) => state.sortKey === 'size'
                ? Number(o.size)
                : (state.sortKey === 'modified' ? String(o.modified || '') : String(o.name || ''));
            const av = pick(a);
            const bv = pick(b);
            if (av < bv) return -state.sortDir;
            if (av > bv) return state.sortDir;
            return 0;
        });
        (data.folders || []).forEach((f) => {
            rows.push('<tr data-folder="' + escapeHtml(f.prefix) + '">'
                + '<td><input class="objrow__check" type="checkbox" disabled></td>'
                + '<td><div class="tbl__name objrow__folder">' + icon('folder')
                + '<span>' + escapeHtml(f.name) + '/</span></div></td>'
                + '<td class="muted">—</td><td class="muted">—</td><td></td></tr>');
        });
        objects.forEach((o) => {
            const key = escapeHtml(o.key);
            const isFolder = o.key.endsWith('/') && Number(o.size) === 0;
            const acts = '<button class="chipbtn" type="button" data-obj-download="' + key + '">下载</button>'
                + (readOnly ? '' : '<button class="chipbtn" type="button" data-obj-copy="' + key + '">复制/移动</button>')
                + '<button class="chipbtn" type="button" data-obj-info="' + key + '">详情</button>'
                + (readOnly ? '' : '<button class="chipbtn chipbtn--danger" type="button" data-obj-del="' + key + '">删除</button>');
            rows.push('<tr data-key="' + key + '">'
                + '<td><input class="objrow__check" type="checkbox" data-obj-check value="' + key + '"'
                + (readOnly ? ' disabled' : (state.selected.has(o.key) ? ' checked' : '')) + '></td>'
                + '<td><div class="tbl__name' + (isFolder ? ' objrow__folder' : '') + '">'
                + icon(isFolder ? 'folder' : iconFor(o.key))
                + '<span>' + escapeHtml(o.name) + '</span></div></td>'
                + '<td class="num">' + (isFolder ? '—' : humanSize(o.size)) + '</td>'
                + '<td class="muted nowrap">' + escapeHtml((o.modified || '').slice(0, 16).replace('T', ' ')) + '</td>'
                + '<td><div class="tbl__acts">' + acts + '</div></td></tr>');
        });

        const notice = readOnly
            ? '<div class="mwarn" style="margin-bottom:12px">' + icon('alert-triangle')
                + '<span>该桶已绑定为存储源「' + escapeHtml(data.boundBy) + '」，对象页只读。</span></div>'
            : '';
        if (!rows.length) {
            el.objHost.innerHTML = notice + '<div class="blank">' + icon('archive')
                + '<p class="blank__title">这个前缀下没有对象</p>'
                + '<p class="blank__hint">'
                + (readOnly ? '该桶已绑定为存储源，内容只读，可下载查看。' : '拖放文件到页面，或点「上传」。')
                + '</p></div>';
        } else {
            const note = data.truncated
                ? (state.query !== '' ? '已截断，仅显示前 ' + objects.length + ' 个对象' : '还有更多，可翻页')
                : '已到末页';
            el.objHost.innerHTML = notice + '<div class="tblwrap"><table class="tbl"><thead><tr>'
                + '<th style="width:34px"><input type="checkbox" data-obj-check-all aria-label="全选"'
                + (readOnly ? ' disabled' : '') + '></th>'
                + '<th><button class="objsort" type="button" data-sort="name">名称</button></th>'
                + '<th class="num"><button class="objsort" type="button" data-sort="size">大小</button></th>'
                + '<th><button class="objsort" type="button" data-sort="modified">更新时间</button></th>'
                + '<th>操作</th>'
                + '</tr></thead><tbody>' + rows.join('') + '</tbody></table></div>'
                + '<div class="objbar" style="margin-top:14px">'
                + '<span class="muted">' + note + '</span>'
                + '<div class="objbar__tools">'
                + '<button class="chipbtn" type="button" data-obj-prev' + (state.history.length ? '' : ' disabled') + '>上一页</button>'
                + '<button class="chipbtn" type="button" data-obj-next' + (data.truncated && data.next_token ? '' : ' disabled') + '>下一页</button>'
                + '</div></div>';
        }
        state.nextToken = data.next_token || '';
        state.truncated = !!data.truncated;
    }

    function updateSelection() {
        const count = state.selected.size;
        el.selection?.toggleAttribute('hidden', count === 0);
        if (el.selectedCount) el.selectedCount.textContent = '已选 ' + count + ' 项';
    }

    el.objHost.addEventListener('change', (e) => {
        const all = e.target.closest('[data-obj-check-all]');
        if (all) {
            $$('[data-obj-check]', el.objHost).forEach((box) => {
                box.checked = all.checked;
                if (all.checked) state.selected.add(box.value); else state.selected.delete(box.value);
            });
            updateSelection();
            return;
        }
        const box = e.target.closest('[data-obj-check]');
        if (box) {
            if (box.checked) state.selected.add(box.value); else state.selected.delete(box.value);
            updateSelection();
        }
    });

    el.objHost.addEventListener('click', (e) => {
        const sort = e.target.closest('[data-sort]');
        if (sort) {
            const key = sort.dataset.sort;
            state.sortDir = state.sortKey === key ? -state.sortDir : 1;
            state.sortKey = key;
            if (state.lastData) renderObjects(state.lastData);
            return;
        }

        const folder = e.target.closest('[data-folder]');
        if (folder) {
            state.prefix = folder.dataset.folder;
            state.query = '';
            state.cursor = '';
            state.history = [];
            if (el.search) el.search.value = '';
            loadObjects();
            updateQuery();
            return;
        }
        const next = e.target.closest('[data-obj-next]');
        if (next && state.nextToken) {
            state.history.push(state.cursor);
            state.cursor = state.nextToken;
            loadObjects();
            return;
        }
        const prev = e.target.closest('[data-obj-prev]');
        if (prev && state.history.length) {
            state.cursor = state.history.pop();
            loadObjects();
        }
    });

    el.crumb.addEventListener('click', (e) => {
        const to = e.target.closest('[data-crumb-to]');
        if (to) {
            state.prefix = to.dataset.crumbTo === state.bucket ? '' : to.dataset.crumbTo;
            state.query = '';
            state.cursor = '';
            state.history = [];
            if (el.search) el.search.value = '';
            loadObjects();
            updateQuery();
        }
    });

    el.search?.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        state.query = el.search.value.trim();
        state.cursor = '';
        state.history = [];
        loadObjects();
    });

    /* ---------------------------------------------------------- 上传 */

    const PART_THRESHOLD = 64 * 1024 * 1024;

    function xhrPut(url, body, headers, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url, true);
            Object.entries(headers || {}).forEach(([k, v]) => xhr.setRequestHeader(k, v));
            xhr.upload.onprogress = (e) => {
                if (onProgress && e.lengthComputable) onProgress(e.loaded, e.total);
            };
            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.getResponseHeader('ETag') || '');
                } else {
                    reject(new Error('上传失败（HTTP ' + xhr.status + '）'));
                }
            };
            xhr.onerror = () => reject(new Error('网络错误或 CORS 拒绝'));
            xhr.send(body);
        });
    }

    async function ensureCors() {
        const data = await api('objects.cors_check', { account: state.account, bucket: state.bucket }, { method: 'GET' });
        if (data.ok) return true;
        const allow = await SY.confirm(
            '桶的 CORS 未放行本站',
            (data.issues || []).join('；') + '。是否把 ' + data.origin + ' 加入该桶的 CORS？',
            '一键允许本站',
            false
        );
        if (!allow) return false;
        await api('objects.cors_allow', { account: state.account, bucket: state.bucket });
        toast('已写入 CORS 规则');
        return true;
    }

    function selectedStorageClass() {
        return $('[data-upload-class]')?.value || '';
    }

    async function uploadSingle(file, key, progress) {
        let lastErr = null;
        for (let attempt = 1; attempt <= 3; attempt++) {
            try {
                const sign = await api('objects.upload_sign', {
                    account: state.account, bucket: state.bucket, key: key,
                    storage_class: selectedStorageClass(),
                    content_type: file.type || 'application/octet-stream',
                });
                await xhrPut(sign.url, file, sign.headers, progress);
                return;
            } catch (err) {
                lastErr = err;
            }
        }
        throw lastErr || new Error('上传失败');
    }

    async function uploadMultipart(file, key, progress) {
        const init = await api('objects.upload_init', {
            account: state.account, bucket: state.bucket, key: key, size: file.size,
            storage_class: selectedStorageClass(),
            content_type: file.type || 'application/octet-stream',
        });
        const partSize = Number(init.part_size) || 8 * 1024 * 1024;
        const total = Math.ceil(file.size / partSize) || 1;
        const parts = new Array(total);
        let doneBytes = 0;
        let next = 1;
        let cancelled = false;

        async function worker() {
            while (next <= total && !cancelled) {
                const partNumber = next++;
                const start = (partNumber - 1) * partSize;
                const blob = file.slice(start, Math.min(start + partSize, file.size));
                let etag = '';
                let attempt = 0;
                while (!etag && attempt < 3) {
                    attempt++;
                    try {
                        const sign = await api('objects.upload_part', {
                            account: state.account, bucket: state.bucket, key: key,
                            upload_id: init.upload_id, part_number: partNumber,
                        });
                        etag = await xhrPut(sign.url, blob, {}, (loaded) => {
                            progress(doneBytes + loaded, file.size);
                        });
                    } catch (err) {
                        if (attempt >= 3) {
                            cancelled = true;
                            throw err;
                        }
                    }
                }
                if (cancelled) return;
                if (!etag) throw new Error('分片 ' + partNumber + ' 没有返回 ETag');
                parts[partNumber - 1] = { part_number: partNumber, etag: etag };
                doneBytes += blob.size;
                progress(doneBytes, file.size);
            }
        }

        try {
            await Promise.all([worker(), worker(), worker()]);
            await api('objects.upload_complete', {
                account: state.account, bucket: state.bucket, key: key,
                upload_id: init.upload_id, parts: parts.filter(Boolean),
            });
        } catch (err) {
            cancelled = true;
            await api('objects.upload_abort', {
                account: state.account, bucket: state.bucket, key: key, upload_id: init.upload_id,
            }).catch(() => {});
            throw err;
        }
    }

    async function uploadFiles(files) {
        if (!files.length || !state.bucket) return;
        if (Object.prototype.hasOwnProperty.call(state.bound, state.bucket)) {
            toast('该桶已绑定为存储源，只读', 'err');
            return;
        }
        let corsOk = false;
        try {
            corsOk = await ensureCors();
        } catch (err) {
            toast(err.message, 'err');
            return;
        }
        if (!corsOk) return;
        for (const file of Array.from(files)) {
            const key = state.prefix + file.name;
            const note = $('[data-upload-note]');
            if (note) note.textContent = '正在上传 ' + file.name;
            try {
                if (file.size > PART_THRESHOLD) {
                    await uploadMultipart(file, key, (loaded, total) => {
                        if (note) note.textContent = '上传 ' + file.name + ' · ' + Math.round(loaded / total * 100) + '%';
                    });
                } else {
                    await uploadSingle(file, key, (loaded, total) => {
                        if (note) note.textContent = '上传 ' + file.name + ' · ' + Math.round(loaded / total * 100) + '%';
                    });
                }
                toast('已上传 ' + file.name);
            } catch (err) {
                toast(file.name + '：' + err.message, 'err');
            }
        }
        loadObjects();
    }

    $('[data-obj-upload]')?.addEventListener('click', () => el.uploadInput?.click());
    el.uploadInput?.addEventListener('change', () => {
        uploadFiles(el.uploadInput.files);
        el.uploadInput.value = '';
    });

    document.addEventListener('dragover', (e) => {
        if (!state.bucket || !el.detailView || el.detailView.hidden) return;
        e.preventDefault();
    });
    document.addEventListener('drop', (e) => {
        if (!state.bucket || !el.detailView || el.detailView.hidden) return;
        e.preventDefault();
        if (e.dataTransfer && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });

    /* ---------------------------------------------------------- 下载与详情 */

    async function downloadKey(key) {
        try {
            const r = await api('objects.download_sign', {
                account: state.account, bucket: state.bucket, key: key,
            });
            const a = document.createElement('a');
            a.href = r.url;
            a.target = '_blank';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            a.remove();
        } catch (err) {
            if (err.code === 'restore_required') {
                const days = await SY.prompt('对象在归档存储中', '解冻天数（1–30）', '1');
                if (days === null) return;
                try {
                    const r = await api('objects.restore', {
                        account: state.account, bucket: state.bucket, key: key, days: Number(days) || 1,
                    });
                    toast(r.message);
                } catch (e2) {
                    toast(e2.message, 'err');
                }
                return;
            }
            toast(err.message, 'err');
        }
    }

    async function showInfo(key) {
        try {
            const m = await api('objects.head', {
                account: state.account, bucket: state.bucket, key: key,
            });
            SY.openModal('对象详情 · ' + key,
                '<div class="dupelist">'
                + '<div><span>大小</span><b>' + humanSize(m.size) + '</b></div>'
                + '<div><span>类型</span><b>' + escapeHtml(m.content_type || '—') + '</b></div>'
                + '<div><span>ETag</span><b>' + escapeHtml(m.etag || '—') + '</b></div>'
                + '<div><span>更新时间</span><b>' + escapeHtml(m.last_modified || '—') + '</b></div>'
                + '<div><span>存储类型</span><b>' + escapeHtml(m.storage_class || '—') + '</b></div>'
                + '</div>',
                '<button class="mbtn" type="button" data-x="close">关闭</button>');
            $('[data-modal-foot]').querySelector('[data-x="close"]').addEventListener('click', SY.closeModal);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    el.objHost.addEventListener('click', (e) => {
        const down = e.target.closest('[data-obj-download]');
        if (down) { downloadKey(down.dataset.objDownload); return; }
        const info = e.target.closest('[data-obj-info]');
        if (info) { showInfo(info.dataset.objInfo); }
    });

    /* ---------------------------------------------------------- 复制/删除/文件夹 */

    function chooseCopyMode(key) {
        return new Promise((resolve) => {
            SY.openModal('复制还是移动？',
                '<p class="mhint">对象：' + escapeHtml(key) + '。移动会删除源对象。</p>',
                '<button class="mbtn" type="button" data-x="copy">复制</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="move">移动</button>'
                + '<button class="mbtn" type="button" data-x="cancel">取消</button>',
                () => resolve(null));
            const foot = $('[data-modal-foot]');
            const pick = (mode) => {
                // 先 resolve 再 close：closeModal 会触发 onClose 的 resolve(null)
                resolve(mode);
                SY.closeModal();
            };
            foot.querySelector('[data-x="copy"]').addEventListener('click', () => pick('copy'));
            foot.querySelector('[data-x="move"]').addEventListener('click', () => pick('move'));
            foot.querySelector('[data-x="cancel"]').addEventListener('click', SY.closeModal);
        });
    }

    async function copyKey(key) {
        const mode = await chooseCopyMode(key);
        if (mode === null) return;
        const target = await SY.prompt('目标对象键', '输入完整目标键', key);
        if (target === null) return;

        let overwrite = false;
        try {
            await api('objects.head', {
                account: state.account, bucket: state.bucket, key: target.trim(),
            });
            const ok = await SY.confirm('目标已存在', '覆盖现有对象？', '覆盖', true);
            if (!ok) return;
            overwrite = true;
        } catch (err) {
            if (err.code !== 'not_found') {
                toast(err.message, 'err');
                return;
            }
        }

        try {
            const r = await api('objects.copy', {
                account: state.account, bucket: state.bucket,
                source: key, target: target.trim(), mode: mode, overwrite: overwrite,
            });
            toast(r.message);
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function deleteKeys(keys) {
        if (!keys.length) return;
        const ok = await SY.confirm('删除对象', '将删除 ' + keys.length + ' 个对象，此操作不可撤销。', '删除', true);
        if (!ok) return;
        try {
            const r = await api('objects.delete', {
                account: state.account, bucket: state.bucket, keys: keys,
            });
            toast(r.message);
            state.selected.clear();
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    el.objHost.addEventListener('click', (e) => {
        const copy = e.target.closest('[data-obj-copy]');
        if (copy) { copyKey(copy.dataset.objCopy); return; }
        const del = e.target.closest('[data-obj-del]');
        if (del) { deleteKeys([del.dataset.objDel]); }
    });

    $('[data-obj-delete-selected]')?.addEventListener('click', () => deleteKeys(Array.from(state.selected)));

    $('[data-obj-mkdir]')?.addEventListener('click', async () => {
        const name = await SY.prompt('新建文件夹', '文件夹名称', '');
        if (name === null) return;
        try {
            await api('objects.mkdir', {
                account: state.account, bucket: state.bucket,
                prefix: state.prefix, name: name.trim(),
            });
            toast('文件夹已创建');
            loadObjects();
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* ---------------------------------------------------------- 设置 */

    function settingCard(title, note, body) {
        return '<section class="objset"><div class="objset__head"><h3>' + escapeHtml(title) + '</h3>'
            + '<span class="panel__note">' + escapeHtml(note) + '</span></div>'
            + '<div class="objset__body">' + body + '</div></section>';
    }

    async function loadSettings() {
        if (!state.bucket) return;
        const token = ++state.reqToken;
        const bucket = state.bucket;
        const readOnly = Object.prototype.hasOwnProperty.call(state.bound, bucket)
            ? state.bound[bucket] : null;
        el.settingsHost.innerHTML = '<div class="blank">' + icon('loader')
            + '<p class="blank__hint">正在读取设置…</p></div>';
        const sections = ['versioning', 'website', 'cors', 'lifecycle', 'tagging', 'acl', 'policy'];
        const cards = [];
        for (const section of sections) {
            try {
                const data = await api('objects.settings', {
                    account: state.account, bucket: bucket, section: section,
                }, { method: 'GET' });
                if (token !== state.reqToken || bucket !== state.bucket) return;
                cards.push(renderSetting(section, data));
            } catch (err) {
                if (token !== state.reqToken || bucket !== state.bucket) return;
                cards.push(settingCard(section, '', '<p class="mwarn">'
                    + icon('alert-triangle') + '<span>' + escapeHtml(err.message) + '</span></p>'));
            }
        }
        if (token !== state.reqToken || bucket !== state.bucket) return;
        const notice = readOnly
            ? '<div class="mwarn" style="margin-bottom:12px">' + icon('alert-triangle')
                + '<span>该桶已绑定为存储源「' + escapeHtml(readOnly) + '」，设置只读。</span></div>'
            : '';
        el.settingsHost.innerHTML = notice + cards.join('');
        if (readOnly) {
            $$('button, input, textarea, select', el.settingsHost).forEach((node) => {
                node.disabled = true;
            });
        }
    }
    window.__objectsLoadSettings = loadSettings;

    function renderSetting(section, data) {
        const v = data.value || {};
        if (section === 'versioning') {
            return settingCard('版本控制', '开启后可恢复历史版本',
                '<p class="mhint">当前：' + escapeHtml(v || '从未启用') + '</p>'
                + '<div class="objbar__tools" style="margin-top:10px">'
                + '<button class="chipbtn" type="button" data-set-versioning="Enabled">启用</button>'
                + '<button class="chipbtn" type="button" data-set-versioning="Suspended">暂停</button></div>');
        }
        if (section === 'website') {
            return settingCard('静态网站', '把桶当作静态站点',
                '<label class="mfield"><span class="mfield__label">索引文档</span>'
                + '<input class="minput" data-set="index" value="' + escapeHtml(v.index || 'index.html') + '"></label>'
                + '<label class="mfield" style="margin-top:8px"><span class="mfield__label">错误文档</span>'
                + '<input class="minput" data-set="error" value="' + escapeHtml(v.error || '') + '"></label>'
                + '<button class="chipbtn" type="button" data-set-website="1" style="margin-top:10px">保存</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-set-website-off="1"'
                + ' style="margin-top:10px;margin-left:8px">停用</button>');
        }
        if (section === 'cors') {
            // 用 JSON 原样回显，避免保存时丢掉 headers/expose/max_age 或拆散多来源规则
            const rules = JSON.stringify(v.rules || [], null, 2);
            return settingCard('CORS', '浏览器直传需要放行本站',
                '<textarea class="minput" data-set="cors" style="min-height:180px">'
                + escapeHtml(rules) + '</textarea>'
                + '<p class="mhint">JSON 规则数组，字段：origins / methods / headers / expose / max_age。</p>'
                + '<button class="chipbtn" type="button" data-set-cors="1" style="margin-top:10px">保存</button>');
        }
        if (section === 'lifecycle') {
            const raw = data.raw || '';
            return settingCard('生命周期', '按时间转存储或删除',
                '<textarea class="minput" data-set="lifecycle">' + escapeHtml(raw) + '</textarea>'
                + '<p class="mhint">直接编辑 LifecycleConfiguration XML；保存会整体替换现有配置。</p>'
                + '<button class="chipbtn" type="button" data-lifecycle-preset style="margin-top:10px">生成常用规则</button>'
                + '<button class="chipbtn" type="button" data-set-lifecycle="1" style="margin-top:10px;margin-left:8px">保存</button>'
                + '<button class="chipbtn chipbtn--danger" type="button" data-lifecycle-delete'
                + ' style="margin-top:10px;margin-left:8px">删除配置</button>');
        }
        if (section === 'tagging') {
            const tags = (v.tags || []).map((t) => t.key + '=' + t.value).join('\n');
            return settingCard('标签', '键值对',
                '<textarea class="minput" data-set="tags">' + escapeHtml(tags) + '</textarea>'
                + '<p class="mhint">每行一个 <code>key=value</code>。</p>'
                + '<button class="chipbtn" type="button" data-set-tagging="1" style="margin-top:10px">保存</button>');
        }
        if (section === 'acl') {
            const grants = (v.grants || [])
                .map((g) => g.permission + ' · ' + (g.name || g.type))
                .join('\n');
            return settingCard('访问权限', '公开前请确认',
                '<select class="select" data-set="acl">'
                + '<option value="private"' + (v.canned === 'private' ? ' selected' : '') + '>私有</option>'
                + '<option value="public-read"' + (v.canned === 'public-read' ? ' selected' : '') + '>公共读</option>'
                + '</select>'
                + '<button class="chipbtn" type="button" data-set-acl="1" style="margin-top:10px">保存</button>'
                + '<pre class="mhint" style="white-space:pre-wrap;margin-top:10px">'
                + escapeHtml(grants || '无额外授权') + '</pre>');
        }
        const json = data.json ? JSON.stringify(data.json, null, 2) : '未设置策略';
        return settingCard('桶策略', '只读', '<pre class="mhint" style="white-space:pre-wrap">'
            + escapeHtml(json) + '</pre>');
    }

    function escapeXml(value) {
        return String(value).replace(/[<>&'"]/g, (c) => ({
            '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;',
        }[c]));
    }

    el.settingsHost.addEventListener('click', async (e) => {
        const preset = e.target.closest('[data-lifecycle-preset]');
        if (preset) {
            const prefix = await SY.prompt('生命周期规则', '前缀（留空表示全部对象）', '');
            if (prefix === null) return;
            const transitionDays = await SY.prompt('转存储', 'N 天后转归档（0 = 不转）', '30');
            if (transitionDays === null) return;
            const expireDays = await SY.prompt('过期删除', 'N 天后删除（0 = 不删除）', '365');
            if (expireDays === null) return;
            const abortDays = await SY.prompt('清理分片', 'N 天后清理未完成分片（0 = 不清理）', '7');
            if (abortDays === null) return;

            const t = Number(transitionDays) || 0;
            const e2 = Number(expireDays) || 0;
            const a = Number(abortDays) || 0;
            let body = '<ID>shiyun-lifecycle</ID>'
                + '<Filter><Prefix>' + escapeXml(prefix) + '</Prefix></Filter><Status>Enabled</Status>';
            if (t > 0) {
                body += '<Transition><Days>' + t + '</Days><StorageClass>ARCHIVE</StorageClass></Transition>';
            }
            if (e2 > 0) body += '<Expiration><Days>' + e2 + '</Days></Expiration>';
            if (a > 0) {
                body += '<AbortIncompleteMultipartUpload><DaysAfterInitiation>'
                    + a + '</DaysAfterInitiation></AbortIncompleteMultipartUpload>';
            }
            const xml = '<?xml version="1.0" encoding="UTF-8"?><LifecycleConfiguration><Rule>'
                + body + '</Rule></LifecycleConfiguration>';
            const area = preset.closest('.objset__body').querySelector('[data-set="lifecycle"]');
            if (area) area.value = xml;
            return;
        }

        const lifeDel = e.target.closest('[data-lifecycle-delete]');
        if (lifeDel) {
            const ok = await SY.confirm('删除生命周期配置', '这会移除桶上全部生命周期规则。', '删除', true);
            if (!ok) return;
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket,
                    section: 'lifecycle', payload: { mode: 'delete' },
                });
                toast('已删除生命周期配置');
                loadSettings();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        const versioning = e.target.closest('[data-set-versioning]');
        if (versioning) {
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket, section: 'versioning',
                    payload: { status: versioning.dataset.setVersioning },
                });
                toast('已保存');
                loadSettings();
            } catch (err) { toast(err.message, 'err'); }
            return;
        }
        const off = e.target.closest('[data-set-website-off]');
        if (off) {
            try {
                await api('objects.setting_save', {
                    account: state.account, bucket: state.bucket, section: 'website',
                    payload: { enabled: false },
                });
                toast('已停用静态网站');
                loadSettings();
            } catch (err) {
                toast(err.message, 'err');
            }
            return;
        }

        const save = e.target.closest('[data-set-website], [data-set-cors], [data-set-lifecycle], [data-set-tagging], [data-set-acl]');
        if (!save) return;
        const body = save.closest('.objset__body');
        const val = (name) => body.querySelector('[data-set="' + name + '"]')?.value || '';
        let section = '';
        let payload = {};
        if (save.hasAttribute('data-set-website')) {
            section = 'website';
            payload = { enabled: true, index: val('index'), error: val('error') };
        } else if (save.hasAttribute('data-set-cors')) {
            section = 'cors';
            let rules;
            try {
                rules = JSON.parse(val('cors'));
            } catch (e) {
                toast('CORS 规则不是合法 JSON', 'err');
                return;
            }
            if (!Array.isArray(rules)) {
                toast('CORS 规则必须是数组', 'err');
                return;
            }
            payload = { rules: rules };
        } else if (save.hasAttribute('data-set-lifecycle')) {
            section = 'lifecycle';
            payload = { mode: 'xml', xml: val('lifecycle') };
        } else if (save.hasAttribute('data-set-tagging')) {
            section = 'tagging';
            const tags = val('tags').split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
                const pos = line.indexOf('=');
                return pos === -1 ? { key: line, value: '' } : { key: line.slice(0, pos), value: line.slice(pos + 1) };
            });
            payload = { tags: tags };
        } else {
            section = 'acl';
            const canned = val('acl');
            if (canned === 'public-read'
                && !(await SY.confirm('设为公共读', '桶内对象将可被任何人读取，确认继续？', '设为公共读', true))) {
                return;
            }
            payload = { canned: canned };
        }
        try {
            await api('objects.setting_save', {
                account: state.account, bucket: state.bucket, section: section, payload: payload,
            });
            toast('已保存');
            loadSettings();
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* 页签切换 */
    $$('[data-tab]').forEach((tab) => tab.addEventListener('click', (e) => {
        e.preventDefault();
        const name = tab.dataset.tab;
        $$('[data-tab]').forEach((t) => t.classList.toggle('is-on', t === tab));
        $$('[data-tab-panel]').forEach((panel) => {
            panel.toggleAttribute('hidden', panel.dataset.tabPanel !== name);
        });
        if (name === 'settings') loadSettings();
    }));

    /* 统计 */
    $('[data-obj-stat]')?.addEventListener('click', async () => {
        try {
            const r = await api('objects.stat', { account: state.account, bucket: state.bucket });
            toast('扫描 ' + r.pages + ' 页：' + r.count + ' 个对象，' + r.bytesText
                + (r.partial ? '（已到上限，结果不完整）' : ''));
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    renderAccounts();

    // 首屏加载：函数由后续任务定义；这里只负责在定义就绪后调用一次
    if (state.account && state.bucket && typeof window.__objectsEnterBucket === 'function') {
        window.__objectsEnterBucket();
    } else if (state.account && typeof window.__objectsLoadBuckets === 'function') {
        window.__objectsLoadBuckets();
    }
})();
