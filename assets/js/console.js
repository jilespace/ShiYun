/* 拾云 · 控制台页脚本
   通用表单提交 + 各页面的专属交互。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const CFG = window.__SHIYUN__ || {};
    const icon = SY.icon || (() => '');
    const $ = SY.$;
    const $$ = SY.$$;
    const api = SY.api;
    const toast = SY.toast;
    const escapeHtml = SY.escapeHtml;

    const root = $('[data-page]');
    if (!root) {
        return;
    }

    /* ══════════════════════════════════════════════ 通用：声明式表单提交 */

    function formPayload(form) {
        const data = {};
        $$('input, select, textarea', form).forEach((el) => {
            if (!el.name || el.disabled) return;
            if (el.type === 'checkbox') {
                data[el.name] = el.checked;
            } else if (el.type === 'radio') {
                if (el.checked) data[el.name] = el.value;
            } else {
                data[el.name] = el.value;
            }
        });
        return data;
    }

    // 带 data-confirm 的普通 POST 表单：提交前二次确认。
    // 原来写成 onsubmit="return confirm(...)"，但内联事件处理器被 CSP 禁掉了。
    // 排除 data-api 的表单——那些走下面的 AJAX 提交，preventDefault 拦不住
    // 另一个监听器，会导致「点了取消照旧提交」。
    $$('form[data-confirm]:not([data-api])').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    $$('form[data-api]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const route = form.dataset.api;
            const btn = form.querySelector('[type="submit"]');
            const label = btn ? btn.textContent : '';

            if (btn) {
                btn.disabled = true;
                btn.textContent = '处理中…';
            }

            try {
                const result = await api(route, formPayload(form));

                const message = form.dataset.ok || '已保存';
                toast(typeof message === 'function' ? message(result) : message);

                if (form.dataset.reset !== 'false') {
                    const resetTargets = $$('input[type="password"]', form);
                    resetTargets.forEach((el) => { el.value = ''; });
                }
                if (form.dataset.reload === 'true') {
                    setTimeout(() => location.reload(), 600);
                }
                if (form.dataset.done) {
                    SY.toast(result && result.note ? result.note : '已完成');
                }
            } catch (err) {
                toast(err.message, 'err');
                if (err.extra && err.extra.errors) {
                    Object.entries(err.extra.errors).forEach(([field, msg]) => {
                        const input = form.querySelector('[name="' + field + '"]');
                        if (input) {
                            input.closest('.mfield, .fld')?.classList.add('is-invalid');
                            toast(msg, 'err');
                        }
                    });
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = label;
                }
            }
        });
    });

    /* ══════════════════════════════════════════════ 通用：确认型按钮 */

    async function runConfirmAction(btn) {
        const route = btn.dataset.api;
        let payload = {};
        if (btn.dataset.payload) {
            try {
                payload = JSON.parse(btn.dataset.payload);
            } catch (e) {
                toast('操作参数异常', 'err');
                return;
            }
        }
        const question = btn.dataset.confirm || '确定执行该操作？';

        const ok = await SY.confirm(btn.dataset.title || '确认操作', question, btn.dataset.label || '确定', btn.dataset.danger === '1');
        if (!ok) return;

        try {
            const result = await api(route, payload);
            toast(btn.dataset.ok || '已完成');
            if (btn.dataset.after === 'reload') {
                setTimeout(() => location.reload(), 500);
            } else if (typeof btn.dataset.after === 'string' && btn.dataset.after.startsWith('remove:')) {
                const sel = btn.dataset.after.slice(7);
                document.querySelector(sel)?.remove();
            }
        } catch (err) {
            toast(err.message, 'err');
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-api][data-confirm]');
        if (btn) runConfirmAction(btn);
    });

    /* ══════════════════════════════════════════════════════════ 标签页 */

    function pageTags() {
        const palette = JSON.parse(root.dataset.palette || '[]');

        function colorPicker(current) {
            return '<div class="swatches" data-pick>'
                + palette.map((c) => '<button class="swatch' + (c === current ? ' is-on' : '')
                    + '" type="button" data-c="' + c + '" style="--sw:' + c + '"></button>').join('')
                + '</div>';
        }

        $('#new-tag')?.addEventListener('click', () => {
            SY.openModal('新建标签',
                '<div class="mfield"><span class="mfield__label">标签名</span>'
                + '<input class="minput" data-name placeholder="例如：设计素材"></div>'
                + '<div class="mfield"><span class="mfield__label">颜色</span>' + colorPicker(palette[0]) + '</div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">创建</button>');

            let color = palette[0];
            const swatches = document.querySelector('[data-pick]');
            swatches.addEventListener('click', (e) => {
                const s = e.target.closest('[data-c]');
                if (!s) return;
                color = s.dataset.c;
                SY.$$('[data-c]', swatches).forEach((el2) => el2.classList.toggle('is-on', el2 === s));
            });

            const foot = $('[data-modal-foot]');
            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                if (btn.disabled) return;
                const name = document.querySelector('[data-name]').value.trim();
                if (!name) return;
                btn.disabled = true;
                try {
                    await api('tags.create', { name, color });
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    if (btn.isConnected) btn.disabled = false;
                }
            });
        });

        document.addEventListener('click', async (e) => {
            const rename = e.target.closest('[data-tag-rename]');
            if (rename) {
                const id = Number(rename.dataset.tagRename);
                const current = rename.dataset.name;
                const next = await SY.prompt('重命名标签', '新名称', current);
                if (!next || next === current) return;
                try {
                    await api('tags.rename', { id, name: next });
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                }
                return;
            }

            const del = e.target.closest('[data-tag-delete]');
            if (del) {
                const id = Number(del.dataset.tagDelete);
                const ok = await SY.confirm('删除标签', '标签会从所有文件上移除，文件本身不受影响。', '删除标签', true);
                if (!ok) return;
                try {
                    await api('tags.delete', { id });
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                }
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 分享页 */

    function pageShares() {
        document.addEventListener('click', async (e) => {
            const qr = e.target.closest('[data-share-qr]');
            if (qr) {
                window.open(CFG.base + '?r=shares.qr&id=' + qr.dataset.shareQr, '_blank', 'noopener');
                return;
            }

            const received = e.target.closest('[data-share-received]');
            if (received) {
                try {
                    const data = await api('shares.received', { id: Number(received.dataset.shareReceived) }, { method: 'GET' });
                    const rows = data.items.length
                        ? '<div class="tblwrap"><table class="tbl"><thead><tr>'
                            + '<th>文件</th><th>来自</th><th>留言</th><th>时间</th><th>IP</th>'
                            + '</tr></thead><tbody>'
                            + data.items.map((n) => '<tr>'
                                + '<td class="tbl__name">' + escapeHtml(n.name)
                                + '<span class="muted"> · ' + escapeHtml(n.sizeText) + '</span></td>'
                                + '<td>' + (n.sender ? escapeHtml(n.sender) : '<span class="muted">匿名</span>') + '</td>'
                                + '<td class="muted">' + (n.message ? escapeHtml(n.message) : '—') + '</td>'
                                + '<td class="nowrap muted">' + escapeHtml(n.when) + '</td>'
                                + '<td class="muted">' + escapeHtml(n.ip) + '</td>'
                            + '</tr>').join('')
                            + '</tbody></table></div>'
                        : '<p class="hint">还没有收到文件。把链接发给对方，对方打开就能上传。</p>';

                    SY.openModal('收到的文件 · ' + data.count,
                        rows,
                        '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');
                    $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', SY.closeModal);
                } catch (err) {
                    toast(err.message, 'err');
                }
                return;
            }

            const visits = e.target.closest('[data-share-visits]');
            if (visits) {
                try {
                    const data = await api('shares.visits', { id: Number(visits.dataset.shareVisits) }, { method: 'GET' });
                    const rows = data.visits.length
                        ? '<div class="tblwrap"><table class="tbl"><thead><tr><th>时间</th><th>动作</th><th>设备</th><th>IP</th></tr></thead><tbody>'
                            + data.visits.map((v) => '<tr><td class="nowrap muted">' + escapeHtml(v.when) + '</td>'
                                + '<td>' + escapeHtml(v.action) + '</td>'
                                + '<td class="muted">' + escapeHtml(v.device) + '</td>'
                                + '<td class="muted">' + escapeHtml(v.ip) + '</td></tr>').join('')
                            + '</tbody></table></div>'
                        : '<p class="hint">还没有访问记录。</p>';

                    SY.openModal('访问记录',
                        '<dl class="kv" style="margin-bottom:16px">'
                        + '<dt>浏览</dt><dd>' + data.share.views + ' 次</dd>'
                        + '<dt>下载</dt><dd>' + data.share.downloads + ' 次</dd>'
                        + '</dl>' + rows,
                        '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');
                    $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', SY.closeModal);
                } catch (err) {
                    toast(err.message, 'err');
                }
                return;
            }

            const copy = e.target.closest('[data-share-copy]');
            if (copy) {
                const link = copy.dataset.shareCopy;
                try {
                    await navigator.clipboard.writeText(link);
                    toast('链接已复制');
                } catch (err) {
                    toast('复制失败，请手动选中链接', 'err');
                }
            }

            const dirCopy = e.target.closest('[data-direct-copy]');
            if (dirCopy) {
                try {
                    await navigator.clipboard.writeText(dirCopy.dataset.directCopy);
                    toast('直链已复制');
                } catch (err) {
                    toast('复制失败，请手动选中链接', 'err');
                }
                return;
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 存储源 */

    function pageSources() {
        const drivers = JSON.parse(root.dataset.drivers || '{}');

        function fieldsFor(driver) {
            const info = drivers[driver];
            if (!info) return '';
            return info.fields.map((f) => '<label class="mfield"><span class="mfield__label">'
                + escapeHtml(f.label) + (f.required ? '' : '（可选）') + '</span>'
                + '<input class="minput" data-cfg="' + escapeHtml(f.key) + '"'
                + (f.secret ? ' type="password"' : '') + ' autocomplete="off"></label>').join('');
        }

        $('#new-source')?.addEventListener('click', () => {
            const options = Object.entries(drivers)
                .filter(([, v]) => v.available)
                .map(([k, v]) => '<option value="' + k + '">' + escapeHtml(v.label) + '</option>').join('');

            SY.openModal('添加存储源',
                '<div class="mfield"><span class="mfield__label">名称</span>'
                + '<input class="minput" data-name placeholder="例如：备份到 OSS"></div>'
                + '<div class="mfield"><span class="mfield__label">驱动</span>'
                + '<select class="select" style="width:100%" data-driver>' + options + '</select></div>'
                + '<div data-fields></div>'
                + '<p class="hint" data-note style="margin-top:14px"></p>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">添加</button>');

            const driverSel = document.querySelector('[data-driver]');
            const fieldHost = document.querySelector('[data-fields]');
            const note = document.querySelector('[data-note]');

            const refresh = () => {
                fieldHost.innerHTML = fieldsFor(driverSel.value);
                note.textContent = drivers[driverSel.value]?.note || '';
            };
            driverSel.addEventListener('change', refresh);
            refresh();

            const foot = $('[data-modal-foot]');
            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                if (btn.disabled) return;
                btn.disabled = true;
                const config = {};
                SY.$$('[data-cfg]', fieldHost).forEach((el) => { config[el.dataset.cfg] = el.value; });

                try {
                    await api('sources.create', {
                        name: document.querySelector('[data-name]').value.trim(),
                        driver: driverSel.value,
                        config,
                    });
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    if (btn.isConnected) btn.disabled = false;
                }
            });
        });

        document.addEventListener('click', async (e) => {
            const test = e.target.closest('[data-source-test]');
            if (test) {
                test.disabled = true;
                try {
                    const r = await api('sources.test', { id: Number(test.dataset.sourceTest) });
                    toast(r.message, r.ok ? 'ok' : 'err');
                    // 无论成败都刷新状态列，避免成功时仍显示旧状态
                    setTimeout(() => location.reload(), 1200);
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    test.disabled = false;
                }
                return;
            }

            const push = e.target.closest('[data-source-push]');
            if (push) {
                push.disabled = true;
                push.textContent = '同步中…';
                try {
                    const r = await api('sources.push', { id: Number(push.dataset.sourcePush) });
                    toast('已同步 ' + r.pushed + ' 个对象（' + r.bytesText + '）'
                        + (r.failed ? '，' + r.failed + ' 个失败' : ''));
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                    push.disabled = false;
                    push.textContent = '同步副本';
                }
            }
        });

        /* ── 存储桶管理 ── */

        const bucketPanel = document.querySelector('[data-bucket-panel]');
        const bucketHost = document.querySelector('[data-bucket-host]');

        if (bucketPanel && bucketHost) {
            const accountSel = document.querySelector('[data-bucket-account]');
            const currentSource = () => Number(accountSel.value) || 0;
            let openedBucket = '';
            let refreshObjects = null;

            function bucketTable(data) {
                if (!data.buckets.length) {
                    return '<div class="blank">' + icon('database')
                        + '<p class="blank__title">这个账号下还没有桶</p>'
                        + '<p class="blank__hint">点右上角「新建桶」建一个，建完就能一键绑定成存储源。</p></div>';
                }

                return '<div class="tblwrap"><table class="tbl"><thead><tr>'
                    + '<th>桶名</th><th>地域</th><th>创建时间</th><th>绑定</th><th>操作</th>'
                    + '</tr></thead><tbody>'
                    + data.buckets.map((b) => {
                        const name = escapeHtml(b.name);
                        const bindCell = b.bound
                            ? '<span class="pill">' + escapeHtml(b.boundBy) + '</span>'
                            : '<span class="muted">未绑定</span>';
                        // 「查看内容」恒可用；绑定中的桶不给删改入口——桶内对象正是本地仓的副本记录
                        const acts = '<button class="chipbtn" type="button" data-bucket-peek="' + name + '">查看内容</button>'
                            + (b.bound
                                ? '<span class="muted">使用中，不可删改</span>'
                                : '<button class="chipbtn" type="button" data-bucket-bind="' + name + '">绑定为存储源</button>'
                                    + '<button class="chipbtn chipbtn--danger" type="button" data-bucket-del="' + name + '">删除</button>');

                        return '<tr>'
                            + '<td><div class="tbl__name">' + icon('database') + '<span>' + name + '</span></div></td>'
                            + '<td class="muted">' + escapeHtml(b.region || '—') + '</td>'
                            + '<td class="muted">' + escapeHtml(b.created ? b.created.slice(0, 10) : '—') + '</td>'
                            + '<td>' + bindCell + '</td>'
                            + '<td><div class="tbl__acts">' + acts + '</div></td>'
                            + '</tr>';
                    }).join('')
                    + '</tbody></table></div>';
            }

            function bucketError(message) {
                return '<div class="blank">' + icon('alert-triangle')
                    + '<p class="blank__title">读不到桶列表</p>'
                    + '<p class="blank__hint">' + escapeHtml(message) + '</p></div>';
            }

            async function loadBuckets() {
                bucketHost.innerHTML = '<div class="blank">' + icon('loader')
                    + '<p class="blank__hint">正在读取桶列表…</p></div>';
                try {
                    const data = await api('sources.buckets', { source: currentSource() }, { method: 'GET' });
                    bucketHost.innerHTML = bucketTable(data);
                } catch (err) {
                    bucketHost.innerHTML = bucketError(err.message);
                }
            }

            // 列桶要真打一次对象存储。页面上不看这块就不该付这个代价，所以只在
            // 面板进入视口后才加载；不支持 IntersectionObserver 时退回立即加载。
            let loaded = false;
            const loadOnce = () => {
                if (loaded) return;
                loaded = true;
                loadBuckets();
            };
            if ('IntersectionObserver' in window) {
                const io = new IntersectionObserver((entries) => {
                    for (const entry of entries) {
                        if (entry.isIntersecting) {
                            loadOnce();
                            io.disconnect();
                        }
                    }
                }, { rootMargin: '200px' });
                io.observe(bucketPanel);
            } else {
                loadOnce();
            }

            accountSel?.addEventListener('change', () => {
                loaded = true;
                loadBuckets();
            });
            document.querySelector('[data-bucket-reload]')?.addEventListener('click', () => {
                loaded = true;
                loadBuckets();
            });

            document.querySelector('[data-bucket-new]')?.addEventListener('click', async () => {
                const name = await SY.prompt('新建存储桶', '桶名', '', '小写字母、数字、- 与 .，3–63 位');
                if (name === null) return;
                try {
                    const r = await api('sources.bucket_create', { source: currentSource(), bucket: name.trim() });
                    toast(r.message || '桶已创建');
                    loaded = true;
                    loadBuckets();
                } catch (err) {
                    toast(err.message, 'err');
                }
            });

            /* 桶内对象：查看 / 删单个 / 清空 */

            async function renderObjects() {
                const body = $('[data-modal-body]');
                if (!body) return;

                let data;
                try {
                    data = await api('sources.bucket_objects', { source: currentSource(), bucket: openedBucket });
                } catch (err) {
                    body.innerHTML = '<div class="mwarn">' + icon('alert-triangle')
                        + '<span>' + escapeHtml(err.message) + '</span></div>';
                    return;
                }

                if (!data.count) {
                    body.innerHTML = '<p class="mhint">这个桶是空的。</p>'
                        + (data.bound
                            ? '<div class="mwarn" style="margin-top:14px">' + icon('alert-triangle')
                                + '<span>该桶正被存储源使用，桶内对象是本地仓的副本记录。</span></div>'
                            : '');
                    return;
                }

                body.innerHTML = '<p class="mhint" style="margin-bottom:12px">共 ' + data.count + ' 个对象'
                    + (data.truncated ? '（只显示前 200 个）' : '') + '，合计 ' + escapeHtml(data.totalText) + '</p>'
                    + '<ul class="objlist">'
                    + data.objects.map((o) => '<li>'
                        + '<span class="objlist__key" title="' + escapeHtml(o.key) + '">' + escapeHtml(o.key) + '</span>'
                        + '<span class="objlist__size">' + SY.humanSize(o.size) + '</span>'
                        + (data.bound ? ''
                            : '<button class="iconbtn" type="button" data-obj-del="' + escapeHtml(o.key) + '"'
                                + ' aria-label="删除该对象">' + icon('trash-2') + '</button>')
                        + '</li>').join('')
                    + '</ul>'
                    + (data.bound
                        ? '<div class="mwarn" style="margin-top:14px">' + icon('alert-triangle')
                            + '<span>该桶正被存储源使用，桶内对象是本地仓的副本记录，这里只能查看。</span></div>'
                        : '');
            }

            function openObjects(bucket) {
                openedBucket = bucket;
                refreshObjects = renderObjects;

                SY.openModal('桶内对象 · ' + bucket,
                    '<div class="blank">' + icon('loader') + '<p class="blank__hint">正在读取…</p></div>',
                    '<button class="mbtn mbtn--danger" type="button" data-x="purge">清空桶</button>'
                    + '<button class="mbtn" type="button" data-x="close">关闭</button>');

                const foot = $('[data-modal-foot]');
                foot.querySelector('[data-x="close"]').addEventListener('click', SY.closeModal);
                foot.querySelector('[data-x="purge"]').addEventListener('click', async (ev) => {
                    // 清空不可逆：必须原样输入桶名
                    const typed = await SY.prompt('清空存储桶', '原样输入桶名以确认：' + openedBucket, '', openedBucket);
                    if (typed === null) return;

                    const btn = ev.currentTarget;
                    btn.disabled = true;
                    try {
                        const r = await api('sources.bucket_purge', {
                            source: currentSource(),
                            bucket: openedBucket,
                            confirm: typed.trim(),
                        });
                        toast(r.message + (r.remaining ? '，仍有剩余，可再点一次' : ''));
                        await renderObjects();
                        loadBuckets();
                    } catch (err) {
                        toast(err.message, 'err');
                    } finally {
                        if (btn.isConnected) btn.disabled = false;
                    }
                });

                renderObjects();
            }

            /* 桶相关的点击统一走一个 document 级代理：
               弹窗里的元素是动态生成的，逐次绑定会在反复开关后叠加监听器 */
            document.addEventListener('click', async (e) => {
                const peek = e.target.closest('[data-bucket-peek]');
                if (peek) {
                    openObjects(peek.dataset.bucketPeek);
                    return;
                }

                const delObj = e.target.closest('[data-obj-del]');
                if (delObj) {
                    delObj.disabled = true;
                    try {
                        const r = await api('sources.bucket_object_delete', {
                            source: currentSource(),
                            bucket: openedBucket,
                            key: delObj.dataset.objDel,
                        });
                        toast(r.message || '已删除');
                        if (refreshObjects) await refreshObjects();
                        loadBuckets();
                    } catch (err) {
                        toast(err.message, 'err');
                        delObj.disabled = false;
                    }
                    return;
                }

                const bind = e.target.closest('[data-bucket-bind]');
                if (bind) {
                    bind.disabled = true;
                    const bucket = bind.dataset.bucketBind;
                    try {
                        const r = await api('sources.bucket_bind', { source: currentSource(), bucket: bucket, name: bucket });
                        toast('已绑定为存储源「' + r.name + '」');
                        setTimeout(() => location.reload(), 700);
                    } catch (err) {
                        toast(err.message, 'err');
                        bind.disabled = false;
                    }
                    return;
                }

                const del = e.target.closest('[data-bucket-del]');
                if (del) {
                    const bucket = del.dataset.bucketDel;
                    const typed = await SY.prompt('删除存储桶', '原样输入桶名以确认：' + bucket, '', bucket);
                    if (typed === null) return;

                    del.disabled = true;
                    try {
                        const r = await api('sources.bucket_delete', {
                            source: currentSource(),
                            bucket: bucket,
                            confirm: typed.trim(),
                        });
                        toast(r.message || '桶已删除');
                        loadBuckets();
                    } catch (err) {
                        toast(err.message, 'err');
                        del.disabled = false;
                    }
                }
            });
        }
    }

    /* ══════════════════════════════════════════════════════════ 任务页 */

    function pageTasks() {
        let timer = 0;
        let inflight = false;

        async function refresh() {
            if (inflight) return;   // 上一次轮询未完成则跳过，避免请求重叠与乱序覆盖
            inflight = true;
            try {
                const data = await api('tasks.data', {}, { method: 'GET' });
                const host = $('[data-task-rows]');
                if (!host) return;

                const countEl = $('[data-task-count]');
                if (countEl) countEl.textContent = data.tasks.length + ' 个';

                if (!data.tasks.length) {
                    host.innerHTML = '';
                    $('[data-task-blank]')?.removeAttribute('hidden');
                    return;
                }
                $('[data-task-blank]')?.setAttribute('hidden', '');

                host.innerHTML = data.tasks.map((t) => {
                    const cls = t.status === 'done' ? ' prog--done' : (t.status === 'failed' ? ' prog--failed' : '');
                    const state = {
                        queued: '排队中', running: '进行中', paused: '已暂停',
                        done: '已完成', failed: t.error || '失败', canceled: '已取消',
                    }[t.status] || t.status;

                    const acts = [];
                    if (['queued', 'running'].includes(t.status)) {
                        acts.push('<button class="minibtn" type="button" data-task-act="pause" data-id="' + t.id + '" aria-label="暂停">' + icon('pause') + '</button>');
                    }
                    if (['paused', 'failed'].includes(t.status)) {
                        acts.push('<button class="minibtn" type="button" data-task-act="resume" data-id="' + t.id + '" aria-label="继续">' + icon('play') + '</button>');
                    }
                    if (['queued', 'running', 'paused'].includes(t.status)) {
                        acts.push('<button class="minibtn" type="button" data-task-act="cancel" data-id="' + t.id + '" aria-label="取消">' + icon('x') + '</button>');
                    } else {
                        acts.push('<button class="minibtn" type="button" data-task-act="delete" data-id="' + t.id + '" aria-label="移除">' + icon('trash-2') + '</button>');
                    }

                    return '<tr data-task-row="' + t.id + '">'
                        + '<td class="nowrap"><span class="pill">' + escapeHtml(t.kindLabel) + '</span></td>'
                        + '<td><div class="tbl__name">' + icon(t.kind === 'bt' ? 'git-branch' : 'cloud-download')
                        + '<span>' + escapeHtml(t.title) + '</span></div>'
                        + '<span class="prog' + cls + '" style="margin-top:8px"><span class="prog__fill" style="--w:' + t.progress + '%"></span></span></td>'
                        + '<td class="muted nowrap">' + escapeHtml(state) + '</td>'
                        + '<td class="num">' + t.progress + '%</td>'
                        + '<td class="num muted">' + escapeHtml(t.receivedText) + ' / ' + escapeHtml(t.totalText) + '</td>'
                        + '<td class="muted nowrap">' + escapeHtml(t.folderName || '—') + '</td>'
                        + '<td class="muted nowrap">' + escapeHtml(t.createdText) + '</td>'
                        + '<td><div class="tbl__acts">' + acts.join('') + '</div></td>'
                        + '</tr>';
                }).join('');
            } catch (err) {
                /* 静默：轮询失败不打扰用户 */
            } finally {
                inflight = false;
            }
        }

        const form = $('[data-task-form]');
        form?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('[type="submit"]');
            const label = btn.textContent;
            btn.disabled = true;
            btn.textContent = '提交中…';
            try {
                await api('tasks.create', {
                    kind: form.querySelector('[name="kind"]').value,
                    url: form.querySelector('[name="url"]').value.trim(),
                    folder: Number(form.querySelector('[name="folder"]').value),
                });
                toast('任务已加入队列');
                form.querySelector('[name="url"]').value = '';
                await refresh();
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                btn.disabled = false;
                btn.textContent = label;
            }
        });

        document.addEventListener('click', async (e) => {
            const act = e.target.closest('[data-task-act]');
            if (!act) return;
            try {
                await api('tasks.control', { ids: [Number(act.dataset.id)], action: act.dataset.taskAct });
                await refresh();
            } catch (err) {
                toast(err.message, 'err');
            }
        });

        $('[data-task-refresh]')?.addEventListener('click', refresh);

        $('[data-copy-cron]')?.addEventListener('click', async (e) => {
            try {
                await navigator.clipboard.writeText(e.currentTarget.dataset.copyCron);
                toast('计划任务地址已复制');
            } catch (err) {
                toast('复制失败', 'err');
            }
        });

        refresh();
        timer = window.setInterval(refresh, 2000);
        // 切到后台暂停轮询，回到前台立即刷新
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(timer);
                timer = 0;
            } else if (!timer) {
                refresh();
                timer = window.setInterval(refresh, 2000);
            }
        });
        window.addEventListener('beforeunload', () => clearInterval(timer));
    }

    /* ══════════════════════════════════════════════════════════ 搜索页 */

    function pageSearch() {
        const form = $('[data-search-form]');
        form?.addEventListener('submit', () => {
            // 让服务端渲染结果页，保持可分享的 URL
        });

        // 结果里的关键词高亮
        SY.$$('[data-snippet]').forEach((el) => {
            const q = root.dataset.q || '';
            if (!q) return;
            const safe = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            el.innerHTML = escapeHtml(el.textContent).replace(new RegExp('(' + safe + ')', 'gi'), '<mark>$1</mark>');
        });

        $('#reindex')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            btn.disabled = true;
            btn.textContent = '索引中…';
            try {
                const r = await api('search.reindex', {});
                toast('已索引 ' + r.indexed + ' 个文件');
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                btn.disabled = false;
                btn.textContent = '重建内容索引';
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 设置页 */

    function pageSettings() {
        // 滑块轨道是自己画的（原生轨道在深色页上是浅灰），填充比例写进 --fill
        $$('.range').forEach((el) => {
            const paint = () => {
                const min = Number(el.min || 0);
                const max = Number(el.max || 100);
                const pct = max > min ? ((Number(el.value) - min) / (max - min)) * 100 : 0;
                el.style.setProperty('--fill', pct.toFixed(2) + '%');
            };
            el.addEventListener('input', paint);
            paint();
        });

        const retention = $('[data-retention-range]');
        if (retention) {
            const out = $('[data-retention-out]');
            const sync = () => { out.textContent = retention.value + ' 天'; };
            retention.addEventListener('input', sync);
            sync();
        }

        const up = $('[data-upload-limit]');
        const down = $('[data-download-limit]');
        [[up, '[data-upload-limit-out]'], [down, '[data-download-limit-out]']].forEach(([el, sel]) => {
            if (!el) return;
            const out = $(sel);
            const sync = () => {
                const v = Number(el.value);
                out.textContent = v === 0 ? '不限速' : v + ' KB/s';
            };
            el.addEventListener('input', sync);
            sync();
        });

        $('[data-copy-webdav]')?.addEventListener('click', async (e) => {
            const value = e.currentTarget.dataset.copyWebdav;
            try {
                await navigator.clipboard.writeText(value);
                toast('WebDAV 地址已复制');
            } catch (err) {
                toast('复制失败', 'err');
            }
        });

        $('[data-copy-rss]')?.addEventListener('click', async (e) => {
            const value = e.currentTarget.dataset.copyRss;
            try {
                await navigator.clipboard.writeText(value);
                toast('RSS 地址已复制');
            } catch (err) {
                toast('复制失败', 'err');
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 令牌页 */

    function pageTokens() {
        $('#new-token')?.addEventListener('click', () => {
            SY.openModal('创建 API 令牌',
                '<div class="mfield"><span class="mfield__label">用途备注</span>'
                + '<input class="minput" data-name placeholder="例如：手机同步脚本"></div>'
                + '<div class="mfield"><span class="mfield__label">有效期</span>'
                + '<select class="select" style="width:100%" data-days>'
                + '<option value="0">永久</option><option value="30">30 天</option>'
                + '<option value="90" selected>90 天</option><option value="365">1 年</option>'
                + '</select></div>',
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">生成</button>');

            const foot = $('[data-modal-foot]');
            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                if (btn.disabled) return;
                btn.disabled = true;
                try {
                    const r = await api('api.tokens_create', {
                        name: document.querySelector('[data-name]').value.trim() || 'API 令牌',
                        days: Number(document.querySelector('[data-days]').value),
                    });

                    const modalEl = document.querySelector('[data-modal]');
                    if (modalEl && modalEl.hidden) return;   // 用户已关闭弹窗，不再弹回

                    SY.openModal('令牌已生成',
                        '<div class="mwarn">' + icon('alert-triangle')
                        + '<span>这串令牌只显示这一次，请立刻复制保存。</span></div>'
                        + '<div class="mfield" style="margin-top:16px">'
                        + '<span class="mfield__label">令牌</span>'
                        + '<input class="minput" data-token readonly value="' + escapeHtml(r.token) + '"></div>',
                        '<button class="mbtn mbtn--primary" type="button" data-x="copy">复制令牌</button>');

                    const input = document.querySelector('[data-token]');
                    input.focus();
                    input.select();
                    $('[data-modal-foot]').querySelector('[data-x="copy"]').addEventListener('click', async () => {
                        try {
                            await navigator.clipboard.writeText(r.token);
                        } catch (err) {
                            input.select();
                            document.execCommand('copy');
                        }
                        toast('令牌已复制');
                        setTimeout(() => location.reload(), 700);
                    });
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    if (btn.isConnected) btn.disabled = false;
                }
            });
        });
    }

    /* ══════════════════════════════════════════════════════════ 自动化规则 */

    function pageRules() {
        const folders = JSON.parse(root.dataset.folders || '[]');
        const kinds = JSON.parse(root.dataset.kinds || '{}');
        const palette = JSON.parse(root.dataset.palette || '[]');

        const CONDITION_FIELDS = [
            ['ext', '扩展名包含', '如 psd,ai,sketch'],
            ['nameHas', '文件名包含', '如 截图'],
            ['kind', '类型', ''],
            ['minSize', '不小于（KB）', '如 1024'],
            ['maxSize', '不大于（KB）', '如 102400'],
        ];

        function conditionInputs() {
            return CONDITION_FIELDS.map(([key, label, ph]) => {
                if (key === 'kind') {
                    const opts = Object.entries(kinds)
                        .map(([k, v]) => '<option value="' + k + '">' + v + '</option>').join('');
                    return '<div class="mfield"><span class="mfield__label">' + label + '（可留空）</span>'
                        + '<select class="select" style="width:100%" data-cond="kind">'
                        + '<option value="">不限</option>' + opts + '</select></div>';
                }
                return '<div class="mfield"><span class="mfield__label">' + label + '（可留空）</span>'
                    + '<input class="minput" data-cond="' + key + '" placeholder="' + ph + '"></div>';
            }).join('');
        }

        function actionEditor() {
            return '<div data-actions></div>'
                + '<button class="chipbtn" type="button" data-add-action style="margin-top:10px">'
                + '再加一个动作</button>';
        }

        function actionRow(index) {
            const folderOpts = folders.map((f) => '<option value="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</option>').join('');
            const colorOpts = palette.map((c) => '<option value="' + escapeHtml(c) + '">' + escapeHtml(c) + '</option>').join('');

            return '<div class="mfield" data-action-row style="margin-top:12px">'
                + '<span class="mfield__label">动作 ' + (index + 1) + '</span>'
                + '<div style="display:flex;gap:8px">'
                + '<select class="select" data-act-type style="width:150px">'
                + '<option value="tag">打标签</option>'
                + '<option value="color">颜色标记</option>'
                + '<option value="move">移动到目录</option>'
                + '<option value="hide">设为隐藏</option>'
                + '<option value="lock">锁定</option>'
                + '<option value="note">写入备注</option>'
                + '</select>'
                + '<input class="minput" data-act-value style="flex:1" placeholder="标签名">'
                + '</div>'
                + '<div data-act-hint class="mfield__label" style="margin-top:6px">标签不存在会自动创建</div>'
                + '<input type="hidden" data-folder-opts value="' + escapeHtml(folderOpts) + '">'
                + '<input type="hidden" data-color-opts value="' + escapeHtml(colorOpts) + '">'
                + '</div>';
        }

        $('#new-rule')?.addEventListener('click', () => {
            SY.openModal('新建自动化规则',
                '<div class="mfield"><span class="mfield__label">规则名称</span>'
                + '<input class="minput" data-name placeholder="例如：设计稿自动归档"></div>'
                + '<div class="mfield" style="margin-top:16px"><span class="mfield__label">条件</span></div>'
                + conditionInputs()
                + '<div class="mfield" style="margin-top:20px"><span class="mfield__label">动作</span></div>'
                + actionEditor(),
                '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">创建规则</button>');

            const body = document.querySelector('[data-modal-body]');
            const actionHost = body.querySelector('[data-actions]');

            const refreshActionRow = (row) => {
                const type = row.querySelector('[data-act-type]').value;
                const value = row.querySelector('[data-act-value]');
                const hint = row.querySelector('[data-act-hint]');

                if (type === 'move') {
                    value.outerHTML = '<select class="select" data-act-value style="flex:1">'
                        + row.querySelector('[data-folder-opts]').value + '</select>';
                    hint.textContent = '选一个目录；也可以用目录 id';
                } else if (type === 'color') {
                    value.outerHTML = '<select class="select" data-act-value style="flex:1">'
                        + row.querySelector('[data-color-opts]').value + '</select>';
                    hint.textContent = '给文件加一个颜色标记';
                } else if (type === 'hide' || type === 'lock') {
                    value.outerHTML = '<input class="minput" data-act-value style="flex:1" disabled placeholder="无需填写">';
                    hint.textContent = type === 'hide' ? '上传后自动隐藏' : '上传后自动锁定，防止误删';
                } else {
                    value.outerHTML = '<input class="minput" data-act-value style="flex:1" placeholder="'
                        + (type === 'note' ? '备注内容' : '标签名') + '">';
                    hint.textContent = type === 'note' ? '写进文件的备注栏' : '标签不存在会自动创建';
                }
            };

            const addRow = () => {
                actionHost.insertAdjacentHTML('beforeend', actionRow(actionHost.children.length));
                const row = actionHost.lastElementChild;
                row.querySelector('[data-act-type]').addEventListener('change', () => refreshActionRow(row));
                return row;
            };

            addRow();
            body.querySelector('[data-add-action]').addEventListener('click', addRow);

            const foot = $('[data-modal-foot]');
            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            foot.querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
                const btn = ev.currentTarget;
                if (btn.disabled) return;
                btn.disabled = true;
                const conditions = {};
                SY.$$('[data-cond]', body).forEach((el) => {
                    if (el.value.trim() !== '') conditions[el.dataset.cond] = el.value.trim();
                });

                const actions = SY.$$('[data-action-row]', body).map((row) => ({
                    type: row.querySelector('[data-act-type]').value,
                    value: row.querySelector('[data-act-value]').value.trim(),
                }));

                try {
                    await api('rules.create', {
                        name: body.querySelector('[data-name]').value.trim(),
                        conditions: conditions,
                        actions: actions,
                    });
                    toast('规则已创建');
                    setTimeout(() => location.reload(), 600);
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    if (btn.isConnected) btn.disabled = false;
                }
            });
        });

        document.addEventListener('click', async (e) => {
            const toggle = e.target.closest('[data-rule-toggle]');
            if (toggle) {
                try {
                    const r = await api('rules.toggle', { id: Number(toggle.dataset.ruleToggle) });
                    toast(r.enabled ? '规则已启用' : '规则已停用');
                    location.reload();
                } catch (err) {
                    toast(err.message, 'err');
                }
                return;
            }

            const backfill = e.target.closest('[data-rule-backfill]');
            if (backfill) {
                backfill.disabled = true;
                const label = backfill.textContent;
                backfill.textContent = '回填中…';
                try {
                    const r = await api('rules.backfill', { id: Number(backfill.dataset.ruleBackfill) });
                    toast('命中 ' + r.matched + ' 个文件，执行 ' + r.actions + ' 个动作');
                } catch (err) {
                    toast(err.message, 'err');
                } finally {
                    backfill.disabled = false;
                    backfill.textContent = label;
                }
            }
        });
    }

    /* ══════════════════════════════════════════════════════════ 两步验证 */

    function pageTotp() {
        const block = document.querySelector('[data-copy-secret]');
        if (!block) {
            return;
        }

        const tip = document.querySelector('[data-copy-tip]');
        // 展示时按 4 位分组，复制时去掉空格——认证器只认连续字符
        const raw = block.textContent.replace(/\s+/g, '');

        block.addEventListener('click', async () => {
            let ok = true;
            try {
                await navigator.clipboard.writeText(raw);
            } catch (err) {
                ok = false;
            }
            if (!ok) {
                // 剪贴板 API 不可用（非 HTTPS 等）时退化为选中，让用户自行复制
                const range = document.createRange();
                range.selectNodeContents(block);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                if (tip) {
                    tip.textContent = '已选中，请按 Ctrl+C 复制';
                }
                return;
            }

            block.classList.add('is-copied');
            if (tip) {
                tip.textContent = '已复制到剪贴板';
                tip.classList.add('is-copied');
            }
            toast('密钥已复制');
            window.setTimeout(() => {
                block.classList.remove('is-copied');
                if (tip) {
                    tip.textContent = '点击即可复制';
                    tip.classList.remove('is-copied');
                }
            }, 1800);
        });
    }

    /* ══════════════════════════════════════════════════════════ 重复文件清理 */

    function pageStats() {
        document.addEventListener('click', async (e) => {
            if (!e.target.closest('[data-dupes]')) {
                return;
            }

            let data;
            try {
                data = await api('stats.duplicates', {}, { method: 'GET' });
            } catch (err) {
                toast(err.message, 'err');
                return;
            }

            const groups = data.groups || [];
            if (!groups.length) {
                SY.openModal('重复文件', '<p class="hint">暂时没有发现内容重复的文件。</p>',
                    '<button class="mbtn mbtn--primary" type="button" data-x="ok">知道了</button>');
                $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', SY.closeModal);
                return;
            }

            // 每组默认保留最早创建的一份，其余勾选待清理
            const body = groups.map((g, gi) => {
                const rows = g.nodes.map((n, ni) => {
                    const keep = ni === 0;
                    return '<label class="duperow">'
                        + '<input type="checkbox" data-dupe-id="' + n.id + '"'
                        + ' data-group="' + gi + '"' + (keep ? '' : ' checked') + '>'
                        + '<span class="duperow__name">' + escapeHtml(n.name) + '</span>'
                        + '<span class="duperow__meta">' + escapeHtml(n.sizeText) + ' · '
                        + escapeHtml(String(n.created).slice(0, 16)) + '</span>'
                        + '<span class="duperow__keep"' + (keep ? '' : ' hidden') + '>保留</span>'
                        + '</label>';
                }).join('');

                return '<section class="dupegroup" data-dupe-group="' + gi + '">'
                    + '<header class="dupegroup__head">'
                    + '<span class="dupegroup__name">' + escapeHtml(g.sample) + '</span>'
                    + '<span class="dupegroup__meta">' + g.copies + ' 份 · 每份 ' + escapeHtml(g.sizeText)
                    + ' · 可省 ' + escapeHtml(g.wastedText) + '</span>'
                    + '</header>' + rows + '</section>';
            }).join('');

            SY.openModal('清理重复文件',
                '<p class="hint">内容完全相同的副本会占用配额。勾选的条目将移入回收站，'
                + '清空回收站后才会真正释放空间；每组至少保留一份。</p>'
                + '<div class="dupelist">' + body + '</div>',
                '<span class="dupenote" data-dupe-note></span>'
                + '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes">清理选中</button>');

            const foot = $('[data-modal-foot]');
            const okBtn = foot.querySelector('[data-x="yes"]');
            const note = foot.querySelector('[data-dupe-note]');
            const list = $('[data-modal-body]');

            function sync() {
                const boxes = $$('[data-dupe-id]', list);
                let checked = 0;
                let bytes = 0;
                const perGroup = {};
                const firstOpen = {};

                boxes.forEach((box) => {
                    const g = box.dataset.group;
                    perGroup[g] = perGroup[g] || { total: 0, checked: 0 };
                    perGroup[g].total++;
                    if (box.checked) {
                        checked++;
                        perGroup[g].checked++;
                        bytes += Number(groups[Number(g)].size) || 0;
                    } else if (!firstOpen[g]) {
                        // 只有每组第一份未勾选的才标「保留」，否则会满屏都是保留
                        firstOpen[g] = box;
                    }
                });

                boxes.forEach((box) => {
                    const tag = box.closest('.duperow').querySelector('.duperow__keep');
                    tag.hidden = firstOpen[box.dataset.group] !== box;
                });

                // 某一组全被勾选时禁止提交
                const bad = Object.values(perGroup).some((g) => g.checked >= g.total);
                okBtn.disabled = checked === 0 || bad;
                note.textContent = bad
                    ? '每组至少要保留一份'
                    : (checked ? '已选 ' + checked + ' 项 · 可省 ' + SY.humanSize(bytes) : '');
            }

            list.addEventListener('change', sync);
            sync();

            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            okBtn.addEventListener('click', async () => {
                const ids = $$('[data-dupe-id]', list).filter((b) => b.checked).map((b) => Number(b.dataset.dupeId));
                if (!ids.length) {
                    toast('请先选择要清理的副本', 'err');
                    return;
                }

                okBtn.disabled = true;
                const label = okBtn.textContent;
                okBtn.textContent = '处理中…';
                try {
                    const result = await api('stats.duplicate_delete', { ids: ids });
                    SY.closeModal();
                    toast('已移入回收站 ' + result.trashed + ' 项 · ' + result.bytesText);
                    window.setTimeout(() => location.reload(), 800);
                } catch (err) {
                    toast(err.message, 'err');
                    okBtn.disabled = false;
                    okBtn.textContent = label;
                }
            });
        });
    }

    /* ------------------------------------------------------- 冷数据备份 */

    function pageStatsCold() {
        document.addEventListener('click', async (e) => {
            if (!e.target.closest('[data-cold]')) {
                return;
            }

            let data;
            try {
                data = await api('stats.cold', {}, { method: 'GET' });
            } catch (err) {
                toast(err.message, 'err');
                return;
            }

            const items = data.items || [];
            if (!items.length) {
                SY.openModal('冷数据', '<p class="hint">暂时没有符合条件（' + data.days + ' 天未访问、5 MB 以上）的文件。</p>',
                    '<button class="mbtn mbtn--primary" type="button" data-x="ok">知道了</button>');
                $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', SY.closeModal);
                return;
            }

            const sizeOf = {};
            items.forEach((n) => { sizeOf[n.id] = n.size; });

            const rows = items.map((n) =>
                '<label class="duperow">'
                + '<input type="checkbox" data-cold-id="' + n.id + '" checked>'
                + '<span class="duperow__name">' + escapeHtml(n.name) + '</span>'
                + '<span class="duperow__meta">' + escapeHtml(n.sizeText) + ' · 上次访问 '
                + escapeHtml(n.lastSeen) + '</span>'
                + '</label>').join('');

            const sources = data.sources || [];
            const picker = sources.length
                ? '<div class="mfield" style="margin-top:14px"><span class="mfield__label">备份到</span>'
                    + '<select class="minput" data-cold-source>'
                    + sources.map((s) => '<option value="' + s.id + '">' + escapeHtml(s.name)
                        + '（' + escapeHtml(String(s.driver).toUpperCase()) + '）</option>').join('')
                    + '</select></div>'
                : '<p class="hint">还没有云端存储源。先到「存储源」里添加一个 COS / S3，再回来备份。</p>';

            SY.openModal('冷数据备份',
                '<p class="hint">这些文件超过 ' + data.days + ' 天没被打开过，共有 ' + data.count
                + ' 个、合计 ' + escapeHtml(data.bytesText) + '。备份只增加云端副本，不会删除本地文件。</p>'
                + '<div class="dupelist">' + rows + '</div>' + picker,
                '<span class="dupenote" data-cold-note></span>'
                + '<button class="mbtn" type="button" data-x="no">取消</button>'
                + '<button class="mbtn mbtn--primary" type="button" data-x="yes"'
                + (sources.length ? '' : ' disabled') + '>备份到云端</button>');

            const foot = $('[data-modal-foot]');
            const okBtn = foot.querySelector('[data-x="yes"]');
            const note = foot.querySelector('[data-cold-note]');
            const list = $('[data-modal-body]');

            function sync() {
                let count = 0;
                let bytes = 0;
                $$('[data-cold-id]', list).forEach((box) => {
                    if (box.checked) {
                        count++;
                        bytes += Number(sizeOf[Number(box.dataset.coldId)]) || 0;
                    }
                });
                okBtn.disabled = count === 0 || !sources.length;
                note.textContent = count ? '已选 ' + count + ' 项 · ' + SY.humanSize(bytes) : '';
            }

            list.addEventListener('change', sync);
            sync();

            foot.querySelector('[data-x="no"]').addEventListener('click', SY.closeModal);
            okBtn.addEventListener('click', async () => {
                const ids = $$('[data-cold-id]', list).filter((b) => b.checked)
                    .map((b) => Number(b.dataset.coldId));
                if (!ids.length) {
                    toast('请先选择要备份的文件', 'err');
                    return;
                }
                const sourceId = Number(list.querySelector('[data-cold-source]').value);
                okBtn.disabled = true;
                const label = okBtn.textContent;
                okBtn.textContent = '排队中…';
                try {
                    const result = await api('stats.cold_backup', { ids: ids, sourceId: sourceId });
                    SY.closeModal();
                    toast('已排队 ' + result.queued + ' 个备份任务，可在「任务」里查看进度');
                } catch (err) {
                    toast(err.message, 'err');
                    okBtn.disabled = false;
                    okBtn.textContent = label;
                }
            });
        });
    }

    /* ══════════════════════════════════════════════════════════ 分发 */

    const pages = {
        tags: pageTags,
        shares: pageShares,
        sources: pageSources,
        tasks: pageTasks,
        search: pageSearch,
        settings: pageSettings,
        tokens: pageTokens,
        rules: pageRules,
        totp: pageTotp,
        stats: () => { pageStats(); pageStatsCold(); },
    };

    const name = root.dataset.page;
    if (pages[name]) {
        pages[name]();
    }
})();
