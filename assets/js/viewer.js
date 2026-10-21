/* 拾云 · 查看器
   图片缩放平移、Office/文本渲染、分享与详情。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const SY = window.SY = window.SY || {};
    const icon = SY.icon || (() => '');

    const root = document.querySelector('[data-viewer]');
    if (!root) {
        return;
    }

    const id = Number(root.dataset.id);
    const mode = root.dataset.mode;

    const $ = (s, r) => (r || document).querySelector(s);
    const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

    /* ══════════════════════════════════════════════════════ 请求 */

    async function api(route, payload) {
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
            throw new Error((body && body.error) || '请求失败');
        }
        return body.data;
    }

    const toastHost = $('[data-toasts]');

    function toast(message, kind) {
        const el = document.createElement('div');
        el.className = 'toast toast--' + (kind === 'err' ? 'err' : 'ok');
        el.innerHTML = icon(kind === 'err' ? 'circle-alert' : 'check-circle') + '<span></span>';
        el.lastChild.textContent = message;
        toastHost.appendChild(el);
        setTimeout(() => el.remove(), kind === 'err' ? 4000 : 2400);
    }

    // 转义函数的唯一实现在 icons.js（本文件与它同包，且 icons.js 总是先执行）
    const escapeHtml = SY.escapeHtml;

    /* ══════════════════════════════════════════════════════ 弹窗 */

    const modal = $('[data-modal]');

    function openModal(title, bodyHtml, footHtml) {
        $('[data-modal-title]').textContent = title;
        $('[data-modal-body]').innerHTML = bodyHtml;
        $('[data-modal-foot]').innerHTML = footHtml || '';
        modal.hidden = false;
    }

    function closeModal() {
        modal.hidden = true;
    }

    $$('[data-modal-close]').forEach((el) => el.addEventListener('click', closeModal));

    /* ══════════════════════════════════════════════════════ 图片缩放 */

    if (mode === 'image') {
        const pan = $('[data-pan]');
        const img = $('[data-image]');
        const label = $('[data-zoom-label]');
        let scale = 1;
        let tx = 0;
        let ty = 0;
        let dragging = false;
        let sx = 0;
        let sy = 0;

        function apply() {
            img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
            label.textContent = Math.round(scale * 100) + '%';
        }

        function zoom(delta) {
            scale = Math.min(8, Math.max(0.2, scale + delta));
            if (scale <= 1) {
                tx = 0;
                ty = 0;
            }
            apply();
        }

        $('[data-zoom-in]').addEventListener('click', () => zoom(0.25));
        $('[data-zoom-out]').addEventListener('click', () => zoom(-0.25));

        pan.addEventListener('wheel', (e) => {
            e.preventDefault();
            zoom(e.deltaY < 0 ? 0.12 : -0.12);
        }, { passive: false });

        pan.addEventListener('pointerdown', (e) => {
            if (scale <= 1) return;
            dragging = true;
            sx = e.clientX - tx;
            sy = e.clientY - ty;
            pan.classList.add('is-panning');
            pan.setPointerCapture(e.pointerId);
        });

        pan.addEventListener('pointermove', (e) => {
            if (!dragging) return;
            tx = e.clientX - sx;
            ty = e.clientY - sy;
            apply();
        });

        ['pointerup', 'pointercancel'].forEach((ev) => pan.addEventListener(ev, () => {
            dragging = false;
            pan.classList.remove('is-panning');
        }));

        pan.addEventListener('dblclick', () => {
            scale = scale > 1 ? 1 : 2;
            tx = 0;
            ty = 0;
            apply();
        });

        img.addEventListener('load', () => {
            if (img.naturalWidth && img.naturalWidth < 640) {
                scale = 1;
                apply();
            }
        });
    }

    /* ══════════════════════════════════════════════════════ Markdown */

    function renderMarkdown(src) {
        const blocks = [];
        // 先摘出围栏代码，避免被后续规则误伤
        let text = src.replace(/```([\w+-]*)\n([\s\S]*?)```/g, (m, lang, code) => {
            blocks.push('<pre><code data-lang="' + escapeHtml(lang || '') + '">' + escapeHtml(code) + '</code></pre>');
            return '\0BLOCK' + (blocks.length - 1) + '\0';
        });

        const lines = text.split(/\r?\n/);
        const out = [];
        let i = 0;

        // 仅放行安全协议，杜绝 [x](javascript:...) 之类的注入
        const safeHref = (u) => /^(https?:|mailto:|\/|#|\.)/i.test(String(u).trim()) ? u : '#';

        const inline = (s) => escapeHtml(s)
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
            .replace(/~~([^~]+)~~/g, '<del>$1</del>')
            .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, text, url) =>
                '<a href="' + safeHref(url) + '" target="_blank" rel="noopener noreferrer">' + text + '</a>');

        while (i < lines.length) {
            const line = lines[i];

            if (/^\0BLOCK\d+\0$/.test(line.trim())) {
                out.push(line.trim());
                i++;
                continue;
            }

            const heading = line.match(/^(#{1,6})\s+(.*)$/);
            if (heading) {
                const level = Math.min(4, heading[1].length);
                out.push('<h' + level + '>' + inline(heading[2]) + '</h' + level + '>');
                i++;
                continue;
            }

            if (/^\s*(---+|\*\*\*+|___+)\s*$/.test(line)) {
                out.push('<hr>');
                i++;
                continue;
            }

            if (/^\s*>\s?/.test(line)) {
                const buf = [];
                while (i < lines.length && /^\s*>\s?/.test(lines[i])) {
                    buf.push(inline(lines[i].replace(/^\s*>\s?/, '')));
                    i++;
                }
                out.push('<blockquote>' + buf.join('<br>') + '</blockquote>');
                continue;
            }

            // 表格
            if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
                const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
                const head = cells(line);
                i += 2;
                const body = [];
                while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) {
                    body.push(cells(lines[i]));
                    i++;
                }
                out.push('<table><thead><tr>' + head.map((c) => '<th>' + inline(c) + '</th>').join('')
                    + '</tr></thead><tbody>' + body.map((r) => '<tr>' + r.map((c) => '<td>' + inline(c) + '</td>').join('') + '</tr>').join('')
                    + '</tbody></table>');
                continue;
            }

            // 列表
            if (/^\s*([-*+]|\d+\.)\s+/.test(line)) {
                const ordered = /^\s*\d+\./.test(line);
                const buf = [];
                while (i < lines.length && /^\s*([-*+]|\d+\.)\s+/.test(lines[i])) {
                    buf.push('<li>' + inline(lines[i].replace(/^\s*([-*+]|\d+\.)\s+/, '')) + '</li>');
                    i++;
                }
                out.push((ordered ? '<ol>' : '<ul>') + buf.join('') + (ordered ? '</ol>' : '</ul>'));
                continue;
            }

            if (line.trim() === '') {
                i++;
                continue;
            }

            const buf = [];
            while (i < lines.length && lines[i].trim() !== '' && !/^\s*(#{1,6}\s|>|[-*+]\s|\d+\.\s)/.test(lines[i])) {
                buf.push(inline(lines[i]));
                i++;
            }
            out.push('<p>' + buf.join('<br>') + '</p>');
        }

        return out.join('')
            .replace(/\0BLOCK(\d+)\0/g, (m, n) => blocks[Number(n)] || '');
    }

    /* ══════════════════════════════════════════════════════ 内容加载 */

    const stage = $('[data-stage]');

    /* ── 文本 / Markdown：渲染 + 在线编辑 ── */

    const textState = { content: '', ext: '', isMd: false, editing: false, dirty: false };

    function renderTextView() {
        // 正文写进 [data-text-body]；截断提示挂在它外面，
        // 直接整块替换 host 会把提示一并抹掉（提示就永远看不见了）
        const host = $('[data-text-body]') || $('[data-text]');
        if (!host) return;
        if (textState.isMd) {
            host.innerHTML = '<div class="md">' + renderMarkdown(textState.content) + '</div>';
        } else {
            host.innerHTML = '<pre class="code">' + escapeHtml(textState.content) + '</pre>';
        }
    }

    function exitEditor(discard) {
        const host = $('[data-text]');
        if (!host || !textState.editing) return;
        if (!discard && textState.dirty && !window.confirm('改动还没有保存，确定放弃？')) {
            return;
        }
        textState.editing = false;
        textState.dirty = false;
        host.classList.remove('vtext--editing');
        renderTextView();
    }

    function enterEditor() {
        const host = $('[data-text]');
        if (!host || textState.editing) return;

        const bar = document.createElement('div');
        bar.className = 'veditor__bar';
        bar.innerHTML = '<span class="veditor__hint">' + icon('pencil')
            + '<span>编辑中 · <kbd>Ctrl</kbd>+<kbd>S</kbd> 保存，保存后旧内容进入版本历史</span></span>'
            + '<span class="veditor__acts">'
            + '<button class="mbtn" type="button" data-ed-cancel>取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-ed-save>保存</button>'
            + '</span>';

        const ta = document.createElement('textarea');
        ta.className = 'veditor__in' + (textState.isMd ? '' : ' veditor__in--code');
        ta.value = textState.content;
        ta.spellcheck = false;

        host.classList.add('vtext--editing');
        host.innerHTML = '';
        host.append(bar, ta);
        textState.editing = true;
        textState.dirty = false;

        ta.addEventListener('input', () => {
            textState.dirty = ta.value !== textState.content;
        });
        ta.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                saveEditor();
            }
            // Tab 插入两个空格，别把焦点交出去
            if (e.key === 'Tab') {
                e.preventDefault();
                const s = ta.selectionStart;
                const t = ta.selectionEnd;
                ta.value = ta.value.slice(0, s) + '  ' + ta.value.slice(t);
                ta.selectionStart = ta.selectionEnd = s + 2;
                textState.dirty = ta.value !== textState.content;
            }
        });
        bar.querySelector('[data-ed-cancel]').addEventListener('click', () => exitEditor(false));
        bar.querySelector('[data-ed-save]').addEventListener('click', saveEditor);
        ta.focus();
    }

    async function saveEditor() {
        if (!textState.editing) return;
        const ta = $('.veditor__in');
        const btn = $('[data-ed-save]');
        const content = ta.value;
        if (content === textState.content) {
            exitEditor(true);
            return;
        }
        if (btn) { btn.disabled = true; btn.textContent = '保存中…'; }
        try {
            await api('preview.save', { id: id, content: content });
            textState.content = content;
            textState.dirty = false;
            textState.editing = false;
            const host = $('[data-text]');
            host.classList.remove('vtext--editing');
            renderTextView();
            toast('已保存，原内容已存入版本历史');
        } catch (err) {
            toast(err.message, 'err');
            if (btn) { btn.disabled = false; btn.textContent = '保存'; }
        }
    }

    if (mode === 'text') {
        (async () => {
            const host = $('[data-text]');
            try {
                const data = await api('preview.text', { id: id });
                if (data.binary) {
                    host.innerHTML = '<div class="voffice__load">'
                        + icon('alert-triangle') + '<span>这个文件的编码无法识别，可能不是文本。</span></div>';
                    $('[data-edit]')?.remove();
                    return;
                }

                textState.content = data.content;
                textState.ext = (data.ext || '').toLowerCase();
                textState.isMd = ['md', 'markdown'].includes(textState.ext);

                const editable = !data.truncated && $('[data-edit]') !== null;
                if (!editable) {
                    $('[data-edit]')?.remove();
                }

                const notice = data.truncated
                    ? '<div class="vtext__notice">' + icon('alert-triangle')
                        + '<span>文件较大，只显示前 2 MB，完整内容请下载查看，在线编辑不可用。</span></div>'
                    : '';

                const isCode = ['js', 'mjs', 'ts', 'php', 'py', 'java', 'c', 'h', 'cpp', 'go', 'rs', 'rb', 'sh',
                    'sql', 'css', 'json', 'yml', 'yaml', 'xml', 'html', 'htm', 'ini', 'conf', 'toml', 'lock'].includes(textState.ext);

                host.innerHTML = notice + '<div data-text-body></div>';
                if (isCode) {
                    host.classList.add('vtext--code');
                }
                renderTextView();

                $('[data-edit]')?.addEventListener('click', () => {
                    if (textState.editing) {
                        exitEditor(false);
                    } else {
                        enterEditor();
                    }
                });
            } catch (err) {
                host.innerHTML = '<div class="voffice__load">' + icon('circle-alert')
                    + '<span>' + escapeHtml(err.message) + '</span></div>';
                $('[data-edit]')?.remove();
            }
        })();
    }

    if (mode === 'office') {
        (async () => {
            const host = $('[data-office]');
            try {
                const data = await api('preview.office', { id: id });
                host.innerHTML = data.html;
            } catch (err) {
                host.innerHTML = '<div class="voffice__load">' + icon('circle-alert')
                    + '<span>' + escapeHtml(err.message) + '</span></div>';
            }
        })();
    }

    /* ══════════════════════════════════════════════════════ 详情 */

    $('[data-info]').addEventListener('click', async () => {
        try {
            const data = await api('files.info', { id: id });
            const n = data.info;
            const facts = [
                ['名称', n.name],
                ['类型', n.mime || n.ext || '未知'],
                ['大小', n.sizeText],
                ['修改', n.updated],
                ['创建', n.created],
                ['位置', n.private ? '私密空间' : '我的文件'],
            ];
            if (n.note) facts.push(['备注', n.note]);
            if (n.tags && n.tags.length) facts.push(['标签', n.tags.map((t) => t.name).join('、')]);

            const acts = (n.activity || []).length
                ? '<div class="mfield"><span class="mfield__label">动态</span><div class="facts">'
                    + n.activity.map((a) => '<dt>' + escapeHtml(a.when) + '</dt><dd>'
                        + escapeHtml(a.label + (a.detail ? ' · ' + a.detail : '')) + '</dd>').join('')
                    + '</div></div>'
                : '';

            openModal('详细信息',
                '<dl class="facts">' + facts.map(([k, v]) =>
                    '<dt>' + escapeHtml(k) + '</dt><dd>' + escapeHtml(String(v)) + '</dd>').join('') + '</dl>' + acts,
                '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');
            $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', closeModal);
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    /* ══════════════════════════════════════════════════════ 相似照片 */

    $('[data-similar]')?.addEventListener('click', async () => {
        openModal('相似照片', '<p class="mhint">正在比对画面…</p>',
            '<button class="mbtn mbtn--primary" type="button" data-x="ok">关闭</button>');
        $('[data-modal-foot]').querySelector('[data-x="ok"]').addEventListener('click', closeModal);

        try {
            const data = await api('files.similar', { id: id });
            const items = data.items || [];
            if (!items.length) {
                $('[data-modal-body]').innerHTML = '<p class="mhint">没有找到相似的照片。</p>';
                return;
            }
            $('[data-modal-body]').innerHTML = '<p class="mhint">按画面指纹比对，距离越小越像；'
                + '连拍和修图前后的版本通常都在这里。</p>'
                + '<div class="vsim">' + items.map((n) =>
                    '<a class="vsim__cell" href="' + escapeHtml(CFG.base + '?r=preview.open&id=' + n.id) + '">'
                    + '<img src="' + escapeHtml(CFG.base + '?r=preview.thumb&id=' + n.id) + '" alt="'
                    + escapeHtml(n.name) + '" loading="lazy">'
                    + '<span class="vsim__cap">' + escapeHtml(n.name) + '</span>'
                    + '<span class="vsim__dist">' + (n.dist === 0 ? '几乎相同' : '距离 ' + n.dist) + '</span>'
                    + '</a>').join('') + '</div>';
        } catch (err) {
            $('[data-modal-body]').innerHTML = '<p class="hint">' + escapeHtml(err.message) + '</p>';
        }
    });

    /* ══════════════════════════════════════════════════════ 分享 */

    $('[data-share]').addEventListener('click', () => {
        openModal('创建分享链接',
            '<div class="mfield"><span class="mfield__label">访问权限</span>'
            + '<select class="minput" data-perm>'
            + '<option value="preview">仅预览</option>'
            + '<option value="download" selected>可下载</option>'
            + '<option value="upload">可下载并上传</option>'
            + '</select></div>'
            + '<div class="mfield"><span class="mfield__label">有效期</span>'
            + '<select class="minput" data-exp>'
            + '<option value="0">永久有效</option>'
            + '<option value="1">1 天</option><option value="7" selected>7 天</option>'
            + '<option value="30">30 天</option></select></div>'
            + '<div class="mfield"><span class="mfield__label">提取密码（留空则不需要）</span>'
            + '<input class="minput" data-pass maxlength="32" placeholder="可选"></div>',
            '<button class="mbtn" type="button" data-x="no">取消</button>'
            + '<button class="mbtn mbtn--primary" type="button" data-x="yes">生成链接</button>');

        $('[data-modal-foot]').querySelector('[data-x="no"]').addEventListener('click', closeModal);
        $('[data-modal-foot]').querySelector('[data-x="yes"]').addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true;
            try {
                const data = await api('shares.create', {
                    id: id,
                    permission: $('[data-perm]').value,
                    days: Number($('[data-exp]').value),
                    password: $('[data-pass]').value,
                });
                const link = new URL(data.share.url, location.href).href;
                openModal('分享已创建',
                    '<div class="mfield"><span class="mfield__label">分享链接</span>'
                    + '<input class="minput" data-link readonly value="' + escapeHtml(link) + '"></div>'
                    + (data.share.password
                        ? '<p class="mhint" style="margin-top:10px">提取密码：<b>' + escapeHtml(data.share.password) + '</b></p>'
                        : '')
                    + '<p class="mhint" style="margin-top:10px">' + escapeHtml(data.share.expiresText) + '</p>',
                    '<button class="mbtn mbtn--primary" type="button" data-x="copy">复制链接</button>');

                const input = $('[data-link]');
                input.focus();
                input.select();
                $('[data-modal-foot]').querySelector('[data-x="copy"]').addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(link);
                    } catch (e) {
                        input.select();
                        document.execCommand('copy');
                    }
                    toast('链接已复制');
                });
            } catch (err) {
                toast(err.message, 'err');
            } finally {
                if (btn.isConnected) btn.disabled = false;
            }
        });
    });

    /* ══════════════════════════════════════════════════════ 键盘 */

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            if (textState.editing) {
                e.preventDefault();
                saveEditor();
            }
            return;
        }

        if (e.key === 'Escape') {
            if (!modal.hidden) {
                closeModal();
                return;
            }
            if (textState.editing) {
                exitEditor(false);
                return;
            }
            const back = $('[data-back]');
            if (back) location.href = back.href;
        }

        const media = $('[data-media]');
        if (media && e.code === 'Space' && e.target === document.body) {
            e.preventDefault();
            if (media.paused) media.play(); else media.pause();
        }
        const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || ''));
        if (media && !typing && e.key === 'ArrowRight') media.currentTime += 5;
        if (media && !typing && e.key === 'ArrowLeft') media.currentTime -= 5;
    });

    // 编辑未保存时离开页面先确认
    window.addEventListener('beforeunload', (e) => {
        if (textState.editing && textState.dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
