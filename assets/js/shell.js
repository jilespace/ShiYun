/* 拾云 · 应用外壳
   所有登录后页面共用：请求封装、提示条、对话框、账户菜单、侧栏开合、传输抽屉骨架。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const SY = window.SY = window.SY || {};
    const icon = SY.icon || (() => '');

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    SY.$ = $;
    SY.$$ = $$;

    /* ══════════════════════════════════════════════════════════ 请求 */

    async function api(route, payload, opts) {
        const options = opts || {};
        const method = options.method || 'POST';
        const init = {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        let url = CFG.base + '?r=' + encodeURIComponent(route);
        if (method === 'GET') {
            const params = new URLSearchParams();
            Object.entries(payload || {}).forEach(([k, v]) => params.set(k, v));
            url += '&' + params.toString();
        } else {
            init.headers['X-CSRF-Token'] = CFG.csrf;
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(payload || {});
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), options.timeout || 30000);
        init.signal = controller.signal;
        let res;
        try {
            res = await fetch(url, init);
        } catch (e) {
            throw new Error(e && e.name === 'AbortError' ? '请求超时，请重试。' : '网络异常，请检查连接。');
        } finally {
            window.clearTimeout(timer);
        }
        let body = null;
        try {
            body = await res.json();
        } catch (e) {
            throw new Error('服务器返回了无法解析的响应。');
        }
        if (!res.ok || !body || body.ok !== true) {
            const err = new Error((body && body.error) || ('请求失败（' + res.status + '）'));
            err.code = body && body.code;
            err.status = res.status;
            err.extra = body;
            throw err;
        }
        return body.data;
    }

    SY.api = api;

    /* ══════════════════════════════════════════════════════════ 工具 */

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    function humanSize(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        let i = 0;
        let v = Number(bytes) || 0;
        while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
        return (i === 0 ? Math.round(v) : v.toFixed(1)) + ' ' + units[i];
    }

    SY.escapeHtml = escapeHtml;
    SY.humanSize = humanSize;

    /** 刷新侧栏容量仪表（增删文件后调用） */
    async function refreshQuota() {
        const fill = $('.gauge__fill');
        const val = $('.gauge__val');
        const hint = $('.gauge__hint');
        if (!fill || !val) return;

        try {
            const q = await api('settings.quota', {}, { method: 'GET' });
            fill.style.setProperty('--fill', q.percent + '%');
            val.textContent = q.usedText;
            if (hint) hint.textContent = '共 ' + q.totalText;
        } catch (e) {
            /* 静默失败，不影响主流程 */
        }
    }

    SY.refreshQuota = refreshQuota;

    /* ══════════════════════════════════════════════════════════ 提示 */

    function toast(message, kind) {
        const host = $('[data-toasts]');
        if (!host) return;
        const el = document.createElement('div');
        el.className = 'toast toast--' + (kind === 'err' ? 'err' : 'ok');
        el.innerHTML = icon(kind === 'err' ? 'circle-alert' : 'check-circle') + '<span></span>';
        el.lastChild.textContent = message;
        host.appendChild(el);
        setTimeout(() => {
            el.classList.add('is-out');
            setTimeout(() => el.remove(), 280);
        }, kind === 'err' ? 4200 : 2600);
    }

    SY.toast = toast;

    /* ══════════════════════════════════════════════════════════ 对话框 */

    const modal = $('[data-modal]');
    const modalTitle = $('[data-modal-title]');
    const modalBody = $('[data-modal-body]');
    const modalFoot = $('[data-modal-foot]');
    let onClose = null;
    let lastFocus = null;

    function modalFocusables() {
        if (!modal) return [];
        return Array.from(modal.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter((el) => el.offsetParent !== null);
    }

    function openModal(title, bodyHtml, footHtml, closeFn) {
        if (!modal) return;
        lastFocus = document.activeElement;
        modalTitle.textContent = title;
        modalBody.innerHTML = bodyHtml;
        modalFoot.innerHTML = footHtml || '';
        modal.hidden = false;
        onClose = closeFn || null;

        // 优先聚焦主操作按钮，其次首个可聚焦元素（含底部按钮）
        const target = modalBody.querySelector('input, textarea, select, button')
            || modalFoot.querySelector('[data-x="yes"]')
            || modalFocusables()[0];
        if (target) {
            setTimeout(() => target.focus(), 30);
        }
    }

    function closeModal() {
        if (!modal) return;
        modal.hidden = true;
        modalBody.innerHTML = '';
        modalFoot.innerHTML = '';
        const fn = onClose;
        onClose = null;
        if (fn) fn();
        // 关闭后把焦点还给触发元素
        if (lastFocus && lastFocus.isConnected) {
            lastFocus.focus();
        }
        lastFocus = null;
    }

    SY.openModal = openModal;
    SY.closeModal = closeModal;

    $$('[data-modal-close]').forEach((el) => el.addEventListener('click', closeModal));

    // Tab 焦点陷阱：在弹窗内循环，避免穿透到背景
    if (modal) {
        modal.addEventListener('keydown', (e) => {
            if (e.key !== 'Tab') return;
            const list = modalFocusables();
            if (list.length === 0) {
                e.preventDefault();
                return;
            }
            const first = list[0];
            const last = list[list.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
    }

    /** 确认框，返回 Promise<boolean> */
    function confirmDialog(title, message, confirmLabel, danger) {
        return new Promise((resolve) => {
            openModal(
                title,
                '<div class="mwarn">' + icon('alert-triangle') + '<span>' + escapeHtml(message) + '</span></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn ' + (danger ? 'mbtn--danger' : 'mbtn--primary') + '" type="button" data-x="yes">'
                + escapeHtml(confirmLabel) + '</button>',
                () => resolve(false)
            );
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => {
                closeModal();
                resolve(false);
            });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', () => {
                onClose = null;
                closeModal();
                resolve(true);
            });
        });
    }

    SY.confirm = confirmDialog;

    /** 单行输入框，返回 Promise<string|null> */
    function promptDialog(title, label, value, placeholder) {
        return new Promise((resolve) => {
            openModal(title,
                '<div class="mfield"><span class="mfield__label">' + escapeHtml(label) + '</span>'
                + '<input class="minput" data-prompt value="' + escapeHtml(value || '') + '"'
                + ' placeholder="' + escapeHtml(placeholder || '') + '"></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">确定</button>',
                () => resolve(null)
            );
            const input = modalBody.querySelector('[data-prompt]');
            input.focus();
            input.select();
            const submit = () => {
                const v = input.value.trim();
                if (!v) return;
                onClose = null;
                closeModal();
                resolve(v);
            };
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => { closeModal(); resolve(null); });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', submit);
        });
    }

    SY.prompt = promptDialog;

    /* ══════════════════════════════════════════════════════════ 账户菜单 */

    const whoMenu = $('[data-who-menu]');
    const whoBtn = $('[data-who-btn]');

    function closeWho() {
        if (!whoMenu) return;
        whoMenu.hidden = true;
        whoBtn.setAttribute('aria-expanded', 'false');
    }

    if (whoBtn && whoMenu) {
        whoBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = whoMenu.hidden;
            whoMenu.hidden = !open;
            whoBtn.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', (e) => {
            if (whoMenu.hidden) return;
            if (e.target.closest('[data-who]')) return;
            closeWho();
        });
    }

    /* ══════════════════════════════════════════════════════════ 侧栏（窄屏） */

    const app = $('[data-app]');
    const burger = $('[data-burger]');

    if (app && burger) {
        burger.addEventListener('click', () => app.classList.toggle('is-nav-open'));
        document.addEventListener('click', (e) => {
            if (!app.classList.contains('is-nav-open')) return;
            if (e.target.closest('.spine') || e.target.closest('[data-burger]')) return;
            app.classList.remove('is-nav-open');
        });
    }

    /* ══════════════════════════════════════════════════════════ 传输抽屉骨架 */

    const queue = $('[data-queue]');
    if (queue) {
        const toggle = $('[data-task-toggle]');
        if (toggle) {
            toggle.addEventListener('click', () => { queue.hidden = !queue.hidden; });
        }
        const close = $('[data-queue-close]');
        if (close) close.addEventListener('click', () => { queue.hidden = true; });

        const clear = $('[data-queue-clear]');
        if (clear) {
            clear.addEventListener('click', () => {
                if (SY.transfer) SY.transfer.clearFinished();
            });
        }

        SY.queue = {
            show() { queue.hidden = false; },
            el: $('[data-queue-list]'),
            sum: $('[data-queue-sum]'),
            dot: $('[data-task-dot]'),
        };
    }

    /* ══════════════════════════════════════════════════════════ 快捷键 */

    document.addEventListener('keydown', (e) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''));

        if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey) {
            const omni = $('.omni__in');
            if (omni) {
                e.preventDefault();
                omni.focus();
            }
            return;
        }
        if (e.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    /** 通用列表排序/筛选表单提交（下拉即提交） */
    $$('[data-auto-submit]').forEach((el) => {
        el.addEventListener('change', () => {
            const form = el.closest('form');
            if (form) form.submit();
        });
    });
})();
