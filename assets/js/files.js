/* 拾云 · 工作台客户端
   负责：目录浏览、选择、排序、视图、批量操作、对话框、右键菜单、提示。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const SY = window.SY = window.SY || {};
    const icon = SY.icon || (() => '');

    const app = document.querySelector('[data-app]');
    if (!app) {
        return;
    }

    const modalBody = document.querySelector('[data-modal-body]');
    const modalFoot = document.querySelector('[data-modal-foot]');

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    /* ══════════════════════════════════════════════════════════ 状态 */

    const state = {
        folder: Number(app.dataset.folder) || 0,
        section: app.dataset.section || 'files',
        view: app.dataset.view || 'list',
        sort: app.dataset.sort || 'name',
        dir: app.dataset.dir || 'asc',
        showHidden: app.dataset.showHidden === '1',
        nodes: new Map(),
        picked: new Set(),
        busy: false,
    };

    /* 这些能力由 shell.js 提供，这里只做本地别名，调用点保持不变 */
    const api = SY.api;
    const toast = SY.toast;
    const openModal = SY.openModal;
    const closeModal = SY.closeModal;
    const confirmDialog = SY.confirm;
    const escapeHtml = SY.escapeHtml;
    const humanSize = SY.humanSize;

    /* COS 副本状态：图标名必须已存在于 assets/js/icons.js */
    const COS_STATE = {
        ok: ['check-circle', 'COS 已同步'],
        pending: ['refresh-cw', 'COS 推送中'],
        failed: ['circle-alert', 'COS 推送失败'],
        stale: ['alert-triangle', 'COS 副本失效'],
    };
    const COS_LABEL = { ok: '已同步', pending: '推送中', failed: '推送失败', stale: '副本失效' };

    /* ══════════════════════════════════════════════════════════ 渲染 */

    const rowsHost = $('[data-rows]');
    const cardsHost = $('[data-cards]');
    const voidHost = $('[data-void]');
    const listHead = $('[data-listhead]');
    const listFootEl = $('[data-listfoot]');

    const pageSize = () => Number(app.dataset.pageSize) || 300;

    /* ── 大目录分页 ──
       首屏只渲染服务端给的一页，其余靠「加载更多」哨兵按 offset 续取。
       此前是整个目录一次查完并渲染成 HTML，上万条的目录会把响应和元信息
       IN (...) 查询一起撑爆。 */

    let moreIo = null;
    let loadingMore = false;

    /** 哨兵进入视口就续取下一页 */
    function observeMore(el) {
        if (!('IntersectionObserver' in window)) return;
        if (!moreIo) {
            moreIo = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && entry.target.isConnected) loadMore();
                });
            }, { rootMargin: '400px 0px' });
        }
        moreIo.disconnect();
        moreIo.observe(el);
    }

    /** 维护哨兵：有下一页就放一个，否则移除 */
    function syncMore(hasMore, offset) {
        let sentinel = $('[data-listmore]');

        if (!hasMore) {
            if (sentinel) sentinel.remove();
            return;
        }

        if (!sentinel) {
            sentinel = document.createElement('div');
            sentinel.className = 'listmore';
            sentinel.setAttribute('data-listmore', '');
            sentinel.innerHTML = '<span class="listmore__hint">继续下拉加载更多</span>';
            if (listFootEl && listFootEl.parentNode) {
                listFootEl.parentNode.insertBefore(sentinel, listFootEl.nextSibling);
            } else {
                listHead.parentNode.appendChild(sentinel);
            }
        }

        sentinel.dataset.next = String(offset);
        observeMore(sentinel);
    }

    /** 追加一页：不清空已选、不重建整个列表 */
    function appendList(nodes) {
        if (!nodes || !nodes.length) return;

        const base = rowsHost.children.length;
        nodes.forEach((n) => state.nodes.set(n.id, n));

        rowsHost.insertAdjacentHTML('beforeend', nodes.map((n, i) => rowHtml(n, i + base)).join(''));
        cardsHost.insertAdjacentHTML('beforeend', nodes.map((n, i) => cardHtml(n, i + base)).join(''));

        voidHost.hidden = true;
        listHead.style.display = '';
        if (listFootEl) listFootEl.hidden = false;

        const hint = document.querySelector('.listfoot__more');
        if (hint) hint.textContent = '已显示前 ' + (base + nodes.length) + ' 项，继续下拉加载更多';
    }

    async function loadMore() {
        const sentinel = $('[data-listmore]');
        if (!sentinel || loadingMore) return;

        loadingMore = true;
        sentinel.classList.add('is-loading');

        try {
            const data = await api('files.data', {
                folder: state.folder,
                section: state.section,
                sort: state.sort,
                dir: state.dir,
                type: 'all',
                offset: Number(sentinel.dataset.next) || 0,
                limit: pageSize(),
            }, { method: 'GET' });

            appendList(data.children || []);
            syncMore(!!data.hasMore, rowsHost.children.length);
        } catch (err) {
            const hint = sentinel.querySelector('.listmore__hint');
            if (hint) hint.textContent = err.message + ' · 上滑重试';
        } finally {
            sentinel.classList.remove('is-loading');
            loadingMore = false;
        }
    }

    function nodeHref(n) {
        return n.kind === 'folder'
            ? CFG.base + '?r=files.list&folder=' + n.id
            : CFG.base + '?r=preview.open&id=' + n.id;
    }

    function badges(n) {
        let out = '';
        if (n.locked) out += '<span class="badge" title="已锁定">' + icon('lock', 'badge__ico') + '</span>';
        if (n.hidden) out += '<span class="badge" title="已隐藏">' + icon('eye-off', 'badge__ico') + '</span>';
        if (n.favorite) out += '<span class="badge badge--star" title="已收藏">' + icon('star', 'badge__ico') + '</span>';
        if (n.shareCount > 0) out += '<span class="badge" title="已分享">' + icon('link-2', 'badge__ico') + '</span>';
        if (n.directCount > 0) out += '<span class="badge" title="有直链">' + icon('zap', 'badge__ico') + '</span>';
        if (n.memberCount > 0) out += '<span class="badge" title="协作中">' + icon('users', 'badge__ico') + '</span>';
        const cos = COS_STATE[n.cosStatus];
        if (cos) out += '<span class="badge badge--cos badge--cos-' + escapeHtml(n.cosStatus) + '" title="' + cos[1] + '">' + icon(cos[0], 'badge__ico') + '</span>';
        return out;
    }

    function tagChips(n) {
        return (n.tags || []).slice(0, 3).map((t) =>
            '<span class="chip" style="--chip:' + escapeHtml(t.color) + '">' + escapeHtml(t.name) + '</span>'
        ).join('');
    }

    function rowHtml(n, i) {
        const isFolder = n.kind === 'folder';
        const colorDot = n.color
            ? '<span class="row__color" style="--dot:' + escapeHtml(n.color) + '"></span>' : '';
        return '<li class="row" data-id="' + n.id + '" data-kind="' + n.kind + '" data-type="' + escapeHtml(n.type)
            + '" data-name="' + escapeHtml(n.name) + '" style="--i:' + i + '">'
            + '<span class="row__pick"><input type="checkbox" class="pick" aria-label="选择 ' + escapeHtml(n.name) + '"></span>'
            + '<span class="row__main">'
            + '<span class="row__icon row__icon--' + escapeHtml(n.type) + '">' + icon(n.icon) + '</span>'
            + '<a class="row__name" href="' + nodeHref(n) + '" data-name-link>' + escapeHtml(n.name) + '</a>'
            + colorDot + badges(n) + tagChips(n)
            + '</span>'
            + '<span class="row__size">' + (isFolder ? n.childCount + ' 项' : escapeHtml(n.sizeText)) + '</span>'
            + '<span class="row__time">' + escapeHtml(n.updatedText) + '</span>'
            + '<span class="row__act"><button class="iconbtn" type="button" data-menu aria-label="更多操作">'
            + icon('more-horizontal') + '</button></span>'
            + '</li>';
    }

    function cardHtml(n, i) {
        const isFolder = n.kind === 'folder';
        const art = n.hasThumb
            ? '<img src="' + CFG.base + '?r=preview.thumb&id=' + n.id + '" alt="" loading="lazy" decoding="async">'
            : icon(n.icon, 'card__glyph');
        return '<li class="card" data-id="' + n.id + '" data-kind="' + n.kind + '" data-type="' + escapeHtml(n.type)
            + '" data-name="' + escapeHtml(n.name) + '" style="--i:' + i + '">'
            + '<span class="card__pick"><input type="checkbox" class="pick" aria-label="选择 ' + escapeHtml(n.name) + '"></span>'
            + '<a class="card__body" href="' + nodeHref(n) + '" data-name-link>'
            + '<span class="card__art card__art--' + escapeHtml(n.type) + '">' + art + '</span>'
            + '<span class="card__name">' + escapeHtml(n.name) + '</span>'
            + '<span class="card__meta">' + (isFolder ? n.childCount + ' 项' : escapeHtml(n.sizeText))
            + ' · ' + escapeHtml(n.updatedText) + '</span>'
            + '</a>'
            + '<button class="iconbtn card__menu" type="button" data-menu aria-label="更多操作">'
            + icon('more-horizontal') + '</button>'
            + '</li>';
    }

    function renderList(nodes) {
        state.nodes.clear();
        nodes.forEach((n) => state.nodes.set(n.id, n));

        rowsHost.innerHTML = nodes.map(rowHtml).join('');
        cardsHost.innerHTML = nodes.map(cardHtml).join('');

        const empty = nodes.length === 0;
        voidHost.hidden = !empty;
        listHead.style.display = empty ? 'none' : '';

        const foot = $('[data-listfoot]');
        if (foot) {
            foot.hidden = empty;
            const folders = nodes.filter((n) => n.kind === 'folder').length;
            const files = nodes.length - folders;
            const bytes = nodes.reduce((sum, n) => sum + (n.kind === 'file' ? n.size : 0), 0);
            const footText = $('[data-listfoot-text]');
            if (footText) {
                footText.textContent =
                    folders + ' 个文件夹 · ' + files + ' 个文件 · 合计 ' + humanSize(bytes);
            }
        }

        state.picked.clear();
        syncBulk();
    }

    SY.renderList = renderList;

    /* ══════════════════════════════════════════════════════════ 选择 */

    const bulk = $('[data-bulk]');
    const bulkCount = $('[data-bulk-count]');

    function syncBulk() {
        bulk.hidden = state.picked.size === 0;
        bulkCount.textContent = String(state.picked.size);
        $$('.row, .card').forEach((el) => {
            const on = state.picked.has(Number(el.dataset.id));
            el.classList.toggle('is-picked', on);
            const box = el.querySelector('.pick');
            if (box) box.checked = on;
        });
        const all = $('[data-pick-all]');
        if (all) {
            all.checked = state.picked.size > 0 && state.picked.size === state.nodes.size;
        }
    }

    function pickedIds() {
        return Array.from(state.picked);
    }

    function pickedNodes() {
        return pickedIds().map((id) => state.nodes.get(id)).filter(Boolean);
    }

    function togglePick(id, on) {
        if (on) state.picked.add(id); else state.picked.delete(id);
        syncBulk();
    }

    document.addEventListener('change', (e) => {
        const box = e.target.closest('.pick');
        if (!box) return;
        const holder = box.closest('.row, .card');
        if (!holder) return;
        togglePick(Number(holder.dataset.id), box.checked);
    });

    const pickAll = $('[data-pick-all]');
    if (pickAll) {
        pickAll.addEventListener('change', () => {
            if (pickAll.checked) {
                state.nodes.forEach((n) => state.picked.add(n.id));
            } else {
                state.picked.clear();
            }
            syncBulk();
        });
    }

    /* ══════════════════════════════════════════════════════════ 加载目录 */

    let loadSeq = 0;

    async function loadFolder(folderId, push) {
        // 用请求序号代替 busy 丢弃：并发导航/排序时保留最后一次，过期结果直接作废
        const seq = ++loadSeq;
        state.busy = true;
        listHead.style.opacity = '0.4';

        try {
            // offset: 0 走分页分支：大目录只渲染首页，其余交给哨兵续取
            const data = await api('files.data', {
                folder: folderId,
                section: state.section,
                sort: state.sort,
                dir: state.dir,
                type: 'all',
                offset: 0,
                limit: pageSize(),
            }, { method: 'GET' });

            if (seq !== loadSeq) return;   // 已有更新的请求，本次结果作废

            state.folder = folderId;
            app.dataset.folder = String(folderId);
            renderList(data.children);
            syncMore(!!data.hasMore, data.children.length);

            // 收藏与回收站没有层级，路径栏保持静态
            if (state.section === 'files') {
                renderCrumbs(data.crumbs);
            }

            if (push && state.section === 'files') {
                const target = CFG.base + '?r=files.list&folder=' + folderId;
                history.pushState({ folder: folderId }, '', target);
                document.title = data.folder.name + ' · 拾云';
            }
        } catch (err) {
            if (seq !== loadSeq) return;
            toast(err.message, 'err');
        } finally {
            if (seq === loadSeq) {
                state.busy = false;
                listHead.style.opacity = '';
            }
        }
    }

    SY.loadFolder = loadFolder;

    function renderCrumbs(crumbs) {
        const host = $('[data-crumbs]');
        host.innerHTML = crumbs.map((c, i) => {
            const last = i === crumbs.length - 1;
            const sep = i > 0 ? icon('chevron-right', 'crumbs__sep') : '';
            if (last) {
                return sep + '<span class="crumbs__here" aria-current="page">' + escapeHtml(c.name) + '</span>';
            }
            return sep + '<a class="crumbs__link" href="' + CFG.base + '?r=files.list&folder=' + c.id + '">'
                + escapeHtml(c.name) + '</a>';
        }).join('');
    }

    /* ══════════════════════════════════════════════════════════ 视图与排序 */

    $$('[data-view-set]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const view = btn.dataset.viewSet;
            if (view === state.view) return;
            state.view = view;
            app.dataset.view = view;
            $$('[data-view-set]').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            try {
                await api('files.view', { view: view });
            } catch (err) {
                toast(err.message, 'err');
            }
        });
    });

    const sortMenu = $('[data-sort-menu]');
    const sortBtn = $('[data-sort-btn]');
    const sortLabel = $('[data-sort-label]');
    const SORT_LABELS = { name: '名称', time: '修改时间', size: '大小', type: '类型' };

    // 排序控件只在「我的文件 / 收藏」渲染，回收站没有。
    // 这里的绑定必须有守卫：模块级抛错会中断其后**所有**代码，
    // 让整个交互层（右键菜单、拖放、快捷键…）在回收站页一起失效。
    function closeSortMenu() {
        if (!sortMenu) return;
        sortMenu.hidden = true;
        if (sortBtn) sortBtn.setAttribute('aria-expanded', 'false');
    }

    if (sortBtn && sortMenu) {
        sortBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = sortMenu.hidden;
            sortMenu.hidden = !open;
            sortBtn.setAttribute('aria-expanded', String(open));
        });
    }

    document.addEventListener('click', () => {
        if (sortMenu && !sortMenu.hidden) closeSortMenu();
    });

    function applySort(key) {
        if (state.sort === key) {
            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sort = key;
            state.dir = key === 'time' ? 'desc' : 'asc';
        }
        if (sortLabel) sortLabel.textContent = SORT_LABELS[state.sort] || '名称';
        app.dataset.sort = state.sort;
        app.dataset.dir = state.dir;

        $$('[data-sort-key]').forEach((el) => {
            el.classList.toggle('is-on', el.dataset.sortKey === state.sort);
            const ico = el.querySelector('.sortmenu__ico');
            if (ico && el.dataset.sortKey === state.sort) {
                ico.outerHTML = icon(state.dir === 'desc' ? 'arrow-down' : 'arrow-up', 'sortmenu__ico');
            } else if (ico) {
                ico.outerHTML = icon('arrow-up', 'sortmenu__ico');
            }
        });

        closeSortMenu();
        loadFolder(state.folder, false);
        api('files.view', { sort: state.sort, dir: state.dir }).catch(() => {});
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-sort-key]');
        if (btn) applySort(btn.dataset.sortKey);
    });

    $('[data-hidden-toggle]')?.addEventListener('click', async function () {
        state.showHidden = !state.showHidden;
        this.setAttribute('aria-pressed', String(state.showHidden));
        this.innerHTML = icon(state.showHidden ? 'eye' : 'eye-off');
        try {
            await api('files.view', { showHidden: state.showHidden });
            toast(state.showHidden ? '已显示隐藏文件' : '已隐藏隐藏文件');
            location.reload();
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* ══════════════════════════════════════════════════════════ 目录树选择器 */

    async function pickFolder(title, excludeId, hint) {
        const data = await api('files.tree', { exclude: excludeId || 0 }, { method: 'GET' });

        const children = new Map();
        data.tree.forEach((n) => {
            const key = n.parent === null ? 'root' : n.parent;
            if (!children.has(key)) children.set(key, []);
            children.get(key).push(n);
        });

        const rows = [];
        const walk = (parentKey, depth) => {
            (children.get(parentKey) || []).forEach((n) => {
                rows.push('<button class="tree__item' + (Number(n.id) === Number(data.root) ? ' is-on' : '')
                    + '" type="button" data-pick="' + n.id + '"'
                    + ' style="padding-left:' + (9 + depth * 16) + 'px">'
                    + icon(n.private ? 'lock-keyhole' : 'folder', 'tree__ico')
                    + '<span>' + escapeHtml(n.name) + '</span></button>');
                walk(n.id, depth + 1);
            });
        };
        walk('root', 0);

        return new Promise((resolve) => {
            let chosen = data.root;

            openModal(title,
                '<div class="tree" data-tree>' + rows.join('') + '</div>'
                + (hint ? '<p class="mhint" style="margin-top:12px">' + escapeHtml(hint) + '</p>' : ''),
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">确定</button>',
                () => resolve(null)
            );

            const tree = modalBody.querySelector('[data-tree]');
            tree.addEventListener('click', (e) => {
                const item = e.target.closest('[data-pick]');
                if (!item) return;
                $$('.tree__item', tree).forEach((el) => el.classList.toggle('is-on', el === item));
                chosen = Number(item.dataset.pick);
            });

            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => {
                resolve(null);
                closeModal();
            });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', () => {
                // 必须先 resolve 再 close：closeModal() 会触发 openModal 传入的 onClose，
                // 而那个回调同样会 resolve（false/null）。Promise 只认第一次结算——
                // 顺序反了的话动作永远拿到 false/null，表现为「点了确定没反应」。
                resolve(chosen);
                closeModal();
            });
        });
    }

    /* ══════════════════════════════════════════════════════════ 操作 */

    async function doRename(id) {
        const n = state.nodes.get(id);
        if (!n) return;

        const ok = await new Promise((resolve) => {
            openModal('重命名',
                '<div class="mfield"><span class="mfield__label">新名称</span>'
                + '<input class="minput" data-name value="' + escapeHtml(n.name) + '"></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">保存</button>',
                () => resolve(false)
            );
            const input = modalBody.querySelector('[data-name]');
            input.focus();
            input.select();
            const submit = () => {
                const v = input.value.trim();
                if (!v || v === n.name) { resolve(false); closeModal(); return; }
                resolve(v);
                closeModal();
            };
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') submit();
            });
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => { closeModal(); resolve(false); });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', submit);
        });

        if (!ok) return;

        try {
            const data = await api('files.rename', { id: id, name: ok });
            state.nodes.set(id, data.node);
            const el = $('.row[data-id="' + id + '"], .card[data-id="' + id + '"]');
            if (el) {
                el.dataset.name = data.node.name;
                const nameEl = el.querySelector('.row__name, .card__name');
                if (nameEl) nameEl.textContent = data.node.name;
            }
            toast('已重命名为「' + data.node.name + '」');
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doMove(ids) {
        if (!ids.length) return;
        const target = await pickFolder('移动到…', ids.length === 1 ? ids[0] : 0,
            '移动后原位置不再保留；想留副本请用「复制到…」。');
        if (target === null) return;
        try {
            const data = await api('files.move', { ids: ids, target: target });
            toast('已移动 ' + data.count + ' 项');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doCopy(ids) {
        if (!ids.length) return;
        const target = await pickFolder('复制到…', 0,
            '原文件留在原位置，这里只多出一份副本。');
        if (target === null) return;
        try {
            const data = await api('files.copy', { ids: ids, target: target });
            toast('已复制 ' + data.count + ' 项');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doDelete(ids) {
        if (!ids.length) return;
        const ok = await confirmDialog(
            '移入回收站',
            '将移动 ' + ids.length + ' 项到回收站，之后可以恢复。',
            '移入回收站'
        );
        if (!ok) return;
        try {
            const data = await api('files.delete', { ids: ids });
            toast('已移入回收站 ' + data.count + ' 项');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doRestore(ids) {
        if (!ids.length) return;
        try {
            const data = await api('files.restore', { ids: ids });
            toast('已恢复 ' + data.count + ' 项');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doPurge(ids) {
        if (!ids.length) return;
        const ok = await confirmDialog(
            '彻底删除',
            '这 ' + ids.length + ' 项将被永久删除，无法恢复，占用的空间会立即释放。',
            '永久删除',
            true
        );
        if (!ok) return;
        try {
            const data = await api('files.purge', { ids: ids });
            toast('已永久删除 ' + data.count + ' 项');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function setFlags(ids, patch, message) {
        if (!ids.length) return;
        try {
            await api('files.flags', Object.assign({ ids: ids }, patch));
            toast(message);
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    function doDownload(ids) {
        if (!ids.length) return;
        const url = ids.length === 1
            ? CFG.base + '?r=download.file&id=' + ids[0]
            : CFG.base + '?r=download.zip&ids=' + ids.join(',');
        const a = document.createElement('a');
        a.href = url;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    async function doColor(ids) {
        const COLORS = ['#3d3aff', '#8a88ff', '#4ad07a', '#ffa23a', '#ff5a5a', '#e8d44d', '#3ad0d0'];
        const ok = await new Promise((resolve) => {
            openModal('颜色标记',
                '<div class="swatches" data-sw>'
                + '<button class="swatch swatch--none" type="button" data-c="" aria-label="清除标记"></button>'
                + COLORS.map((c) => '<button class="swatch" type="button" data-c="' + c + '" style="--sw:' + c + '" aria-label="' + c + '"></button>').join('')
                + '</div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>',
                () => resolve(null)
            );
            modalBody.querySelector('[data-sw]').addEventListener('click', (e) => {
                const btn = e.target.closest('[data-c]');
                if (!btn) return;
                resolve(btn.dataset.c);
                closeModal();
            });
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => { closeModal(); resolve(null); });
        });
        if (ok === null) return;
        setFlags(ids, { color: ok }, ok === '' ? '已清除颜色标记' : '已标记颜色');
    }

    async function doNote(id) {
        const n = state.nodes.get(id);
        if (!n) return;
        const ok = await new Promise((resolve) => {
            openModal('文件备注',
                '<div class="mfield"><span class="mfield__label">给「' + escapeHtml(n.name) + '」写点什么</span>'
                + '<textarea class="minput minput--area" data-note placeholder="例如：这是最终版，别动">'
                + escapeHtml(n.note || '') + '</textarea></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">保存</button>',
                () => resolve(null)
            );
            const area = modalBody.querySelector('[data-note]');
            area.focus();
            const submit = () => {
                const v = area.value;
                resolve(v);
                closeModal();
            };
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => { closeModal(); resolve(null); });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', submit);
        });
        if (ok === null) return;
        setFlags([id], { note: ok }, '备注已保存');
    }

    async function doInfo(id) {
        try {
            const data = await api('files.info', { id: id }, { method: 'GET' });
            const n = data.info;
            const facts = [
                ['类型', n.kind === 'folder' ? '文件夹' : (n.mime || '未知')],
                ['大小', n.kind === 'folder'
                    ? n.stats.files + ' 个文件 · ' + n.stats.folders + ' 个文件夹 · ' + humanSize(n.stats.bytes)
                    : n.sizeText],
                ['位置', n.private ? '私密空间' : '我的文件'],
                ['修改', n.updated],
                ['创建', n.created],
            ];
            if (n.note) facts.push(['备注', n.note]);
            if (n.tags && n.tags.length) facts.push(['标签', n.tags.map((t) => t.name).join('、')]);

            // COS 副本：未同步时不占位；失败/失效时可一键重推
            let cosRow = '';
            if (n.cosStatus) {
                const retry = (n.cosStatus === 'failed' || n.cosStatus === 'stale');
                cosRow = '<dt>COS 副本</dt><dd><span data-cos-state>'
                    + escapeHtml(COS_LABEL[n.cosStatus] || '未同步') + '</span>'
                    + (retry
                        ? '<button class="chipbtn" type="button" data-cos-retry data-node-id="'
                            + n.id + '">' + icon('refresh-cw', 'chipbtn__ico') + '<span>重新推送</span></button>'
                        : '')
                    + '</dd>';
            }

            const acts = (n.activity || []).length
                ? '<div class="mfield"><span class="mfield__label">动态</span><div class="facts">'
                    + n.activity.map((a) => '<dt>' + escapeHtml(a.when) + '</dt><dd>'
                        + escapeHtml(a.label + (a.detail ? ' · ' + a.detail : '')) + '</dd>').join('')
                    + '</div></div>'
                : '';

            openModal(n.name,
                '<dl class="facts">' + facts.map(([k, v]) =>
                    '<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(String(v)) + '</dd>').join('') + cosRow + '</dl>' + acts,
                '<button class="mbtn mbtn--primary" type="button" data-x="ok">知道了</button>'
            );
            modalFoot.querySelector('[data-x="ok"]').addEventListener('click', closeModal);
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    async function doShare(ids) {
        if (ids.length !== 1) {
            toast('请选择单个文件或文件夹再创建分享。', 'err');
            return;
        }
        const id = ids[0];
        const n = state.nodes.get(id);
        if (!n) return;

        const folderOpt = n.kind === 'folder'
            ? '<option value="inbox">收取箱（只收不发）</option>'
            : '';
        const body = '<div class="mfield"><span class="mfield__label">访问权限</span>'
            + '<select class="minput" data-perm>'
            + '<option value="preview">仅预览</option>'
            + '<option value="download" selected>可下载</option>'
            + (n.kind === 'folder' ? '<option value="upload">可下载并上传</option>' : '')
            + folderOpt
            + '</select></div>'
            + '<div class="mfield"><span class="mfield__label">有效期</span>'
            + '<select class="minput" data-exp>'
            + '<option value="0">永久有效</option>'
            + '<option value="1">1 天</option>'
            + '<option value="7" selected>7 天</option>'
            + '<option value="30">30 天</option>'
            + '</select></div>'
            + '<div class="mfield"><span class="mfield__label">下载次数</span>'
            + '<select class="minput" data-dl>'
            + '<option value="0" selected>不限次数</option>'
            + '<option value="1">仅 1 次（一次性链接）</option>'
            + '<option value="5">5 次</option>'
            + '<option value="20">20 次</option>'
            + '</select></div>'
            + '<div class="mfield" data-inbox-only hidden><span class="mfield__label">收取说明（对方会看到）</span>'
            + '<input class="minput" data-note maxlength="500" placeholder="例如：请把项目资料传到这里"></div>'
            + '<div class="mfield" data-inbox-only hidden><span class="mfield__label">最多收几个文件</span>'
            + '<select class="minput" data-maxfiles>'
            + '<option value="0">不限</option>'
            + '<option value="20">20 个</option>'
            + '<option value="50" selected>50 个</option>'
            + '<option value="200">200 个</option>'
            + '</select></div>'
            + '<div class="mfield"><span class="mfield__label">提取密码（留空则不需要）</span>'
            + '<input class="minput" data-pass maxlength="32" placeholder="可选"></div>';

        openModal('分享「' + n.name + '」', body,
            '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">生成链接</button>');

        // 仅预览与收取箱都没有下载动作，次数限制对它无意义——切换权限时直接禁用；
        // 收取箱还要露出「收取说明 / 最多收几个」两个字段。
        const permSel = modalBody.querySelector('[data-perm]');
        const dlSel = modalBody.querySelector('[data-dl]');
        const noteInput = modalBody.querySelector('[data-note]');
        const maxfilesSel = modalBody.querySelector('[data-maxfiles]');
        const syncPerm = () => {
            const isInbox = permSel.value === 'inbox';
            Array.from(modalBody.querySelectorAll('[data-inbox-only]'))
                .forEach((el) => { el.hidden = !isInbox; });
            dlSel.disabled = isInbox || permSel.value === 'preview';
            if (dlSel.disabled) dlSel.value = '0';
        };
        permSel.addEventListener('change', syncPerm);
        syncPerm();

        modalFoot.querySelector('[data-x="no"]').addEventListener('click', closeModal);
        modalFoot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const data = await api('shares.create', {
                    id: id,
                    permission: permSel.value,
                    days: Number(modalBody.querySelector('[data-exp]').value),
                    maxDownloads: Number(dlSel.value),
                    note: noteInput ? noteInput.value : '',
                    maxFiles: maxfilesSel ? Number(maxfilesSel.value) : 0,
                    password: modalBody.querySelector('[data-pass]').value,
                });
                const link = new URL(data.share.url, location.href).href;
                const modalEl = document.querySelector('[data-modal]');
                if (modalEl && modalEl.hidden) return;   // 用户已关闭弹窗，不再弹回
                openModal('分享已创建',
                    '<div class="mfield"><span class="mfield__label">分享链接</span>'
                    + '<input class="minput" data-link readonly value="' + escapeHtml(link) + '"></div>'
                    + (data.share.password ? '<p class="mhint" style="margin-top:10px">提取密码：<b>'
                        + escapeHtml(data.share.password) + '</b></p>' : '')
                    + '<p class="mhint" style="margin-top:10px">' + escapeHtml(data.share.expiresText) + '</p>'
                    + (data.share.isInbox
                        ? '<p class="mhint" style="margin-top:6px">这是收取箱：对方打开只能上传，'
                            + '看不到文件夹里已有的内容。</p>'
                        : '')
                    + (data.share.maxDownloads
                        ? '<p class="mhint" style="margin-top:6px">'
                            + (data.share.maxDownloads === 1
                                ? '一次性链接：下载 1 次后自动失效。'
                                : '最多可下载 ' + data.share.maxDownloads + ' 次。')
                            + '</p>'
                        : ''),
                    '<button class="mbtn" type="button" data-x="qr">' + '二维码' + '</button>'
                    + '<button class="mbtn mbtn--primary" type="button" data-x="copy">复制链接</button>'
                );
                const input = modalBody.querySelector('[data-link]');
                input.focus();
                input.select();
                modalFoot.querySelector('[data-x="copy"]').addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(link);
                        toast('链接已复制');
                    } catch (e) {
                        input.select();
                        document.execCommand('copy');
                        toast('链接已复制');
                    }
                });
                modalFoot.querySelector('[data-x="qr"]').addEventListener('click', () => {
                    window.open(CFG.base + '?r=shares.qr&id=' + data.share.id, '_blank', 'noopener');
                });
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                if (btn.isConnected) btn.disabled = false;
            }
        });
    }

    /* 直链：免登录、无落地页的取文件地址 */
    async function doDirect(ids) {
        if (ids.length !== 1) return;
        const id = ids[0];
        const n = state.nodes.get(id);
        if (!n || n.kind !== 'file') return;

        const body = '<p class="mhint">' + escapeHtml(n.name) + '</p>'
            + '<div class="mfield"><span class="mfield__label">来源（留空 = 不限）</span>'
            + '<input class="minput" data-dir-ip placeholder="203.0.113.7 或 203.0.113.0/24"></div>'
            + '<p class="mhint">只支持单个 IP 或 IPv4 网段。走反向代理或内网穿透时请留空——'
            + '那种环境下拿不到访客真实地址，填了会一律拒绝。</p>'
            + '<div class="mfield"><span class="mfield__label">有效期</span>'
            + '<select class="minput" data-dir-days>'
            + '<option value="0" selected>永久</option>'
            + '<option value="7">7 天</option>'
            + '<option value="30">30 天</option>'
            + '<option value="365">1 年</option>'
            + '</select></div>';

        openModal('获取直链', body,
            '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">生成</button>');

        modalFoot.querySelector('[data-x="no"]').addEventListener('click', closeModal);
        modalFoot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const data = await api('direct.create', {
                    id: id,
                    ipAllow: modalBody.querySelector('[data-dir-ip]').value.trim(),
                    days: Number(modalBody.querySelector('[data-dir-days]').value),
                });
                const link = data.link;

                const modalEl = document.querySelector('[data-modal]');
                if (modalEl && modalEl.hidden) return;   // 用户已关闭弹窗，不再弹回

                openModal('直链已生成',
                    '<div class="mfield"><span class="mfield__label">直链（内联）</span>'
                    + '<input class="minput" readonly value="' + escapeHtml(link.url) + '"></div>'
                    + '<div class="mfield"><span class="mfield__label">下载直链</span>'
                    + '<input class="minput" readonly value="' + escapeHtml(link.urlDownload) + '"></div>'
                    + '<p class="mhint">' + escapeHtml(link.expiresText)
                    + (link.ipAllow ? ' · 仅限 ' + escapeHtml(link.ipAllow) : ' · 不限来源') + '</p>',
                    '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');

                $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', closeModal);

                try {
                    await navigator.clipboard.writeText(link.url + '\n' + link.urlDownload);
                    toast('两条链接已复制');
                } catch (err) {
                    toast('请手动选中链接复制', 'err');
                }
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                if (btn.isConnected) btn.disabled = false;
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 右键菜单 */

    const ctx = $('[data-ctx]');

    function closeCtx() {
        ctx.hidden = true;
        ctx.innerHTML = '';
    }

    function openCtx(x, y, node) {
        const inTrash = state.section === 'trash';
        const items = [];

        if (inTrash) {
            items.push(['restore', 'rotate-ccw', '恢复']);
            items.push(['purge', 'trash-2', '彻底删除', true]);
        } else {
            if (node.kind === 'file') {
                items.push(['download', 'download', '下载']);
                items.push(['versions', 'history', '版本历史']);
                items.push(['direct', 'plug', '直链']);
            }
            items.push(['rename', 'pencil', '重命名']);
            items.push(['move', 'move', '移动到…']);
            items.push(['copy', 'copy', '复制到…']);
            items.push(['share', 'share-2', '分享']);
            items.push(['info', 'info', '详细信息']);
            items.push(['-']);
            items.push(['tag', 'tag', '打标签']);
            items.push(['favorite', 'star', node.favorite ? '取消收藏' : '收藏']);
            items.push(['color', 'palette', '颜色标记']);
            items.push(['note', 'file-text', '备注']);
            items.push(['lock', node.locked ? 'unlock' : 'lock', node.locked ? '解锁' : '锁定']);
            items.push(['hidden', 'eye-off', node.hidden ? '取消隐藏' : '隐藏']);
            items.push(['-']);
            items.push(['archive', 'file-archive', '打包成压缩包']);
            if (node.kind === 'file' && String(node.ext || '').match(/^(zip|7z|rar|tar|gz|tgz|bz2|xz)$/)) {
                items.push(['extract', 'scissors', '在线解压']);
            }
            items.push(['-']);
            items.push(['delete', 'trash-2', '移入回收站', true]);
        }

        ctx.innerHTML = items.map((it) => {
            if (it[0] === '-') return '<hr>';
            return '<button type="button" data-ctx-act="' + it[0] + '"'
                + (it[3] ? ' class="is-danger"' : '') + '>' + icon(it[1]) + '<span>' + it[2] + '</span></button>';
        }).join('');

        ctx.hidden = false;
        const rect = ctx.getBoundingClientRect();
        ctx.style.left = Math.max(8, Math.min(x, window.innerWidth - rect.width - 10)) + 'px';
        ctx.style.top = Math.max(8, Math.min(y, window.innerHeight - rect.height - 10)) + 'px';

        ctx.onclick = (e) => {
            const btn = e.target.closest('[data-ctx-act]');
            if (!btn) return;
            const act = btn.dataset.ctxAct;
            closeCtx();
            runAction(act, [node.id]);
        };
    }

    /** 给选中的条目挂标签：列出既有标签 + 新建 */
    async function doTag(ids) {
        if (!ids.length) return;

        const modalBody = document.querySelector('[data-modal-body]');
        const modalFoot = document.querySelector('[data-modal-foot]');

        let index;
        try {
            index = await api('tags.index', {}, { method: 'GET' });
        } catch (err) {
            toast(err.message, 'err');
            return;
        }

        const picked = new Set();
        const list = () => index.tags.map((t) => {
            const on = picked.has(t.id);
            return '<button class="tree__item' + (on ? ' is-on' : '') + '" type="button" data-tag="' + t.id + '">'
                + '<span class="tagcard__swatch" style="--tag:' + escapeHtml(t.color) + ';background:' + escapeHtml(t.color) + '"></span>'
                + '<span style="flex:1;min-width:0">' + escapeHtml(t.name) + '</span>'
                + '<span class="tagcard__count">' + t.use_count + '</span></button>';
        }).join('');

        openModal('打标签',
            (index.tags.length
                ? '<div class="tree" data-taglist>' + list() + '</div>'
                : '<p class="mhint">还没有标签，先在下面建一个。</p>')
            + '<div class="mfield" style="margin-top:16px">'
            + '<span class="mfield__label">新建标签</span>'
            + '<input class="minput" data-newtag placeholder="输入后回车即可创建"></div>',
            '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">应用到 ' + ids.length + ' 项</button>');

        const host = modalBody.querySelector('[data-taglist]');
        host?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-tag]');
            if (!btn) return;
            const id = Number(btn.dataset.tag);
            if (picked.has(id)) {
                picked.delete(id);
                btn.classList.remove('is-on');
            } else {
                picked.add(id);
                btn.classList.add('is-on');
            }
        });

        const newTag = modalBody.querySelector('[data-newtag]');
        newTag.addEventListener('keydown', async (e) => {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const name = newTag.value.trim();
            if (!name) return;
            try {
                const created = await api('tags.create', { name, color: index.palette[0] });
                index.tags.push({ id: created.tag.id, name, color: created.tag.color, use_count: 0 });
                picked.add(created.tag.id);
                newTag.value = '';
                const tree = modalBody.querySelector('[data-taglist]');
                if (tree) {
                    tree.innerHTML = list();
                } else {
                    modalBody.insertAdjacentHTML('afterbegin', '<div class="tree" data-taglist>' + list() + '</div>');
                    modalBody.querySelector('[data-taglist]').addEventListener('click', (ev) => {
                        const btn = ev.target.closest('[data-tag]');
                        if (btn) {
                            const id = Number(btn.dataset.tag);
                            if (picked.has(id)) { picked.delete(id); btn.classList.remove('is-on'); }
                            else { picked.add(id); btn.classList.add('is-on'); }
                        }
                    });
                }
            } catch (err) {
                toast(err.message, 'err');
            }
        });

        modalFoot.querySelector('[data-x="no"]').addEventListener('click', closeModal);
        modalFoot.querySelector('[data-x="yes"]').addEventListener('click', async () => {
            if (!picked.size) {
                toast('请先选择或新建一个标签', 'err');
                return;
            }
            try {
                await api('tags.apply', { ids: ids, tags: Array.from(picked), mode: 'add' });
                closeModal();
                toast('已打上 ' + picked.size + ' 个标签');
                loadFolder(state.folder, false);
            } catch (err) {
                toast(err.message, 'err');
            }
        });
    }

    /** 打包成 zip 存回网盘 */
    async function doCompress(ids) {
        if (!ids.length) return;

        const first = state.nodes.get(ids[0]);
        const suggested = ids.length === 1 && first
            ? String(first.name).replace(/\.[^.]+$/, '')
            : '压缩包-' + new Date().toISOString().slice(0, 10);

        const name = await SY.prompt('打包成压缩包', '压缩包名称', suggested);
        if (!name) return;

        try {
            const data = await api('archive.compress', { ids: ids, name: name, target: state.folder });
            toast('已生成「' + data.node.name + '」（' + data.bytesText + '）');
            loadFolder(state.folder, false);
            SY.refreshQuota();
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    /** 在线解压 */
    async function doExtract(id) {
        const node = state.nodes.get(id);
        if (!node) return;

        const ok = await confirmDialog(
            '在线解压',
            '会把「' + node.name + '」里的内容解压到当前目录，同名条目会自动改名保留。',
            '开始解压'
        );
        if (!ok) return;

        const loading = document.createElement('div');
        loading.className = 'toast toast--ok';
        loading.innerHTML = icon('loader') + '<span>正在解压…</span>';
        document.querySelector('[data-toasts]')?.appendChild(loading);

        try {
            const data = await api('archive.extract', { id: id });
            toast('解压完成：' + data.created + ' 个文件、' + data.folders + ' 个文件夹（' + data.bytesText + '）'
                + (data.skipped ? '，跳过 ' + data.skipped + ' 项' : ''));
            loadFolder(state.folder, false);
            SY.refreshQuota();
        } catch (err) {
            toast(err.message, 'err');
        } finally {
            loading.remove();
        }
    }

    /** 版本历史 */
    async function doVersions(id) {
        const modalBody = document.querySelector('[data-modal-body]');
        const modalFoot = document.querySelector('[data-modal-foot]');

        try {
            const data = await api('versions.list', { id: id }, { method: 'GET' });

            const rows = data.versions.map((v) => '<tr>'
                + '<td class="nowrap">v' + v.versionNo + '</td>'
                + '<td class="num">' + escapeHtml(v.sizeText) + '</td>'
                + '<td class="muted nowrap">' + escapeHtml(v.when) + '</td>'
                + '<td><div class="tbl__acts">'
                + '<a class="minibtn" href="' + CFG.base + '?r=versions.download&id=' + v.id + '" aria-label="下载">' + icon('download') + '</a>'
                + '<button class="minibtn" type="button" data-vrestore="' + v.id + '" aria-label="恢复此版本">' + icon('rotate-ccw') + '</button>'
                + '<button class="minibtn" type="button" data-vdelete="' + v.id + '" aria-label="删除此版本">' + icon('trash-2') + '</button>'
                + '</div></td></tr>').join('');

            // 事件挂在内层容器上，不能挂在 modalBody 上：它是常驻节点，
            // closeModal() 只清 innerHTML、不移除监听器，每次打开都会再叠一层，
            // 结果点一次「恢复」发出多次请求。内层容器每次都是新的，随之销毁。
            openModal('版本历史 · ' + data.node.name,
                '<div data-versions><div class="tblwrap"><table class="tbl"><thead><tr>'
                + '<th>版本</th><th class="num">大小</th><th>时间</th><th></th></tr></thead><tbody>'
                + '<tr><td class="nowrap"><b>当前</b></td><td class="num">' + escapeHtml(data.current.sizeText) + '</td>'
                + '<td class="muted nowrap">' + escapeHtml(data.current.when) + '</td><td></td></tr>'
                + (rows || '<tr><td colspan="4" class="muted">还没有历史版本。同名覆盖上传时会自动留档。</td></tr>')
                + '</tbody></table></div>'
                + '<p class="mhint" style="margin-top:14px">历史版本占用 ' + escapeHtml(data.usageText) + '，'
                + '同名文件重新上传时会自动保留最近 10 个版本。</p></div>',
                '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');

            modalFoot.querySelector('[data-x="ok"]').addEventListener('click', closeModal);

            modalBody.querySelector('[data-versions]').addEventListener('click', async (e) => {
                const restore = e.target.closest('[data-vrestore]');
                if (restore) {
                    try {
                        await api('versions.restore', { id: id, versionId: Number(restore.dataset.vrestore) });
                        toast('已恢复该版本');
                        closeModal();
                        loadFolder(state.folder, false);
                        SY.refreshQuota();
                    } catch (err) {
                        toast(err.message, 'err');
                    }
                    return;
                }

                const del = e.target.closest('[data-vdelete]');
                if (del) {
                    try {
                        await api('versions.delete', { ids: [Number(del.dataset.vdelete)] });
                        toast('版本已删除');
                        closeModal();
                        doVersions(id);
                    } catch (err) {
                        toast(err.message, 'err');
                    }
                }
            });
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    function runAction(act, ids) {
        switch (act) {
            case 'download': doDownload(ids); break;
            case 'rename': doRename(ids[0]); break;
            case 'move': doMove(ids); break;
            case 'copy': doCopy(ids); break;
            case 'delete': doDelete(ids); break;
            case 'restore': doRestore(ids); break;
            case 'purge': doPurge(ids); break;
            case 'share': doShare(ids); break;
            case 'direct': doDirect(ids); break;
            case 'info': doInfo(ids[0]); break;
            case 'note': doNote(ids[0]); break;
            case 'color': doColor(ids); break;
            case 'tag': doTag(ids); break;
            case 'archive': doCompress(ids); break;
            case 'extract': doExtract(ids[0]); break;
            case 'versions': doVersions(ids[0]); break;
            case 'favorite': {
                const n = state.nodes.get(ids[0]);
                setFlags(ids, { favorite: !(n && n.favorite) }, n && n.favorite ? '已取消收藏' : '已收藏');
                break;
            }
            case 'lock': {
                const n = state.nodes.get(ids[0]);
                setFlags(ids, { locked: !(n && n.locked) }, n && n.locked ? '已解锁' : '已锁定');
                break;
            }
            case 'hidden': {
                const n = state.nodes.get(ids[0]);
                setFlags(ids, { hidden: !(n && n.hidden) }, n && n.hidden ? '已取消隐藏' : '已隐藏');
                break;
            }
            case 'clear':
                state.picked.clear();
                syncBulk();
                break;
            default:
                break;
        }
    }

    /* 事件委托：行内菜单按钮、右键、批量按钮 */
    document.addEventListener('click', (e) => {
        const menuBtn = e.target.closest('[data-menu]');
        if (menuBtn) {
            e.preventDefault();
            const holder = menuBtn.closest('.row, .card');
            const node = state.nodes.get(Number(holder.dataset.id));
            if (node) {
                const r = menuBtn.getBoundingClientRect();
                openCtx(r.left - 150, r.bottom + 6, node);
            }
            return;
        }

        const bulkBtn = e.target.closest('[data-act]');
        if (bulkBtn) {
            const act = bulkBtn.dataset.act;
            const ids = act === 'clear' ? [] : pickedIds();
            if (act === 'color') doColor(ids);
            else if (act === 'favorite') setFlags(ids, { favorite: true }, '已收藏');
            else if (act === 'lock') setFlags(ids, { locked: true }, '已锁定');
            else if (act === 'hidden') setFlags(ids, { hidden: true }, '已隐藏');
            else if (act === 'archive') doCompress(ids);
            else runAction(act, ids);
        }
    });

    /* COS 副本一键重推 */
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-cos-retry]');
        if (!btn) return;
        btn.disabled = true;
        try {
            await api('sources.retryOne', { id: Number(btn.dataset.nodeId) });
            toast('已加入推送队列');
            const stateEl = btn.parentElement ? btn.parentElement.querySelector('[data-cos-state]') : null;
            if (stateEl) stateEl.textContent = '推送中';
            btn.hidden = true;
        } catch (err) {
            toast(err.message, 'err');
            btn.disabled = false;
        }
    });

    document.addEventListener('contextmenu', (e) => {
        const holder = e.target.closest('.row, .card');
        if (!holder) return;
        e.preventDefault();
        const node = state.nodes.get(Number(holder.dataset.id));
        if (!node) return;
        if (!state.picked.has(node.id)) {
            state.picked.clear();
            state.picked.add(node.id);
            syncBulk();
        }
        openCtx(e.clientX, e.clientY, node);
    });

    document.addEventListener('click', (e) => {
        if (!ctx.hidden && !e.target.closest('[data-ctx]') && !e.target.closest('[data-menu]')) {
            closeCtx();
        }
    });

    /* ══════════════════════════════════════════════════════════ 新建文件夹 */

    $('[data-new-folder]')?.addEventListener('click', async () => {
        const name = await new Promise((resolve) => {
            openModal('新建文件夹',
                '<div class="mfield"><span class="mfield__label">文件夹名称</span>'
                + '<input class="minput" data-name placeholder="未命名文件夹"></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">创建</button>',
                () => resolve(null)
            );
            const input = modalBody.querySelector('[data-name]');
            input.focus();
            const submit = () => {
                const v = input.value.trim();
                if (!v) return;
                resolve(v);
                closeModal();
            };
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
            modalFoot.querySelector('[data-x="no"]').addEventListener('click', () => { closeModal(); resolve(null); });
            modalFoot.querySelector('[data-x="yes"]').addEventListener('click', submit);
        });

        if (!name) return;
        try {
            await api('files.create_folder', { parent: state.folder, name: name });
            toast('已创建「' + name + '」');
            loadFolder(state.folder, false);
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* ══════════════════════════════════════════════════════════ 键盘 */

    document.addEventListener('keydown', (e) => {
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''));

        if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey) {
            e.preventDefault();
            $('.omni__in').focus();
            return;
        }
        if (e.key === 'Escape') {
            const modalEl = $('[data-modal]');
            if (modalEl && !modalEl.hidden) { closeModal(); return; }
            if (!ctx.hidden) { closeCtx(); return; }
            if (state.picked.size) { state.picked.clear(); syncBulk(); }
            return;
        }
        if (typing) return;

        if ((e.key === 'Delete' || e.key === 'Backspace') && state.picked.size) {
            e.preventDefault();
            if (state.section === 'trash') doPurge(pickedIds());
            else doDelete(pickedIds());
        }
        if (e.key === 'a' && (e.metaKey || e.ctrlKey)) {
            e.preventDefault();
            state.nodes.forEach((n) => state.picked.add(n.id));
            syncBulk();
        }
        if (e.key === 'F2' && state.picked.size === 1) {
            e.preventDefault();
            doRename(pickedIds()[0]);
        }
    });

    /* ══════════════════════════════════════════════════════════ 导航 */

    document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-name-link], .crumbs__link');
        if (!link) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        const url = new URL(link.href, location.href);
        if (url.searchParams.get('r') !== 'files.list') return;
        e.preventDefault();
        loadFolder(Number(url.searchParams.get('folder')) || 0, true);
    });

    window.addEventListener('popstate', () => {
        const folder = Number(new URL(location.href).searchParams.get('folder')) || 0;
        loadFolder(folder, false);
    });

    /* ══════════════════════════════════════════════════════════ 拖放上传 */

    const dropzone = $('[data-dropzone]');
    const dropHint = $('[data-dropzone-hint]');
    const fileInput = $('[data-file-input]');
    let dragDepth = 0;

    function enterDrag(e) {
        if (!e.dataTransfer || !Array.from(e.dataTransfer.types || []).includes('Files')) return;
        dragDepth++;
        dropHint.textContent = '松手后确认存到哪';
        dropzone.hidden = false;
    }

    function leaveDrag() {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) dropzone.hidden = true;
    }

    window.addEventListener('dragenter', (e) => { e.preventDefault(); enterDrag(e); });
    window.addEventListener('dragover', (e) => { e.preventDefault(); });
    window.addEventListener('dragleave', (e) => { e.preventDefault(); leaveDrag(); });
    window.addEventListener('drop', (e) => {
        e.preventDefault();
        dragDepth = 0;
        dropzone.hidden = true;
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            if (SY.transfer) SY.transfer.pickAndEnqueue(e.dataTransfer.files, state.folder);
            else toast('上传模块未就绪。', 'err');
        }
    });

    $$('[data-upload-trigger]').forEach((btn) => {
        btn.addEventListener('click', () => fileInput.click());
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length) {
            if (SY.transfer) SY.transfer.pickAndEnqueue(fileInput.files, state.folder);
            fileInput.value = '';
        }
    });

    // 整目录上传：webkitRelativePath 会被带上，服务端据此建目录链
    const folderInput = document.querySelector('[data-folder-input]');
    const folderBtn = document.querySelector('[data-upload-folder]');
    if (folderInput && folderBtn) {
        folderBtn.addEventListener('click', () => folderInput.click());
        folderInput.addEventListener('change', () => {
            if (folderInput.files && folderInput.files.length) {
                if (SY.transfer) SY.transfer.pickAndEnqueue(folderInput.files, state.folder);
                folderInput.value = '';
            }
        });
    }

    /* 清空回收站 */
    const emptyTrash = document.querySelector('[data-empty-trash]');
    if (emptyTrash) {
        emptyTrash.addEventListener('click', async () => {
            const ok = await confirmDialog(
                '清空回收站',
                '回收站里的所有条目将被永久删除，无法恢复，占用的空间会立即释放。',
                '永久清空',
                true
            );
            if (!ok) return;
            try {
                const data = await api('files.empty_trash', {});
                toast('已永久删除 ' + data.count + ' 项');
                loadFolder(state.folder, false);
                SY.refreshQuota();
            } catch (err) {
                toast(err.message, 'err');
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 初始 */

    $$('.row, .card').forEach((el, i) => el.style.setProperty('--i', String(i)));

    // 首屏的行是服务端渲染的，state.nodes 只在 renderList() 里被填充。
    // 不在这里灌一遍，刚打开页面时全选、右键菜单、行内「更多操作」，
    // 以及重命名/移动/复制/删除等对话框全部静默失效——取不到节点就直接 return，
    // 要等发生一次 AJAX 刷新才「自己好了」。
    if (Array.isArray(window.__FILES__)) {
        window.__FILES__.forEach((n) => state.nodes.set(Number(n.id), n));
    }

    syncBulk();

    // 供上传模块在完成后刷新
    SY.refresh = () => loadFolder(state.folder, false);
})();
