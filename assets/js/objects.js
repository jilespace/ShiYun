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
        if (state.account) params.set('account', String(state.account));
        if (state.bucket) params.set('bucket', state.bucket);
        if (state.prefix) params.set('prefix', state.prefix);
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
        renderAccounts();
        if (typeof window.__objectsLoadBuckets === 'function') window.__objectsLoadBuckets();
    });

    /* 后续任务在这里挂载：桶列表、对象列表、上传、设置 */
    window.__objects = { state, cfg, el, account, updateQuery, renderAccounts };

    renderAccounts();

    // 首屏加载：函数由后续任务定义；这里只负责在定义就绪后调用一次
    if (state.account && state.bucket && typeof window.__objectsEnterBucket === 'function') {
        window.__objectsEnterBucket();
    } else if (state.account && typeof window.__objectsLoadBuckets === 'function') {
        window.__objectsLoadBuckets();
    }
})();
