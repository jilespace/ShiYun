/* 拾云 · 临时传输首页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const CFG = window.__SHIYUN__ || {};
    const $ = SY.$;
    const api = SY.api;
    const toast = SY.toast;

    const root = $('[data-page="transfer"]');
    if (!root) return;

    const result = $('[data-create-result]');
    const codeEl = $('[data-result-code]');
    const linkEl = $('[data-result-link]');
    const qrEl = $('[data-result-qr]');
    const enterEl = $('[data-result-enter]');

    function qrUrl(token) {
        return (CFG.base || 'index.php') + '?r=transfer.qr&t=' + encodeURIComponent(token);
    }

    function showResult(data) {
        const token = data.room.token;
        localStorage.setItem('transfer.ck.' + token, data.creator_key);
        codeEl.textContent = data.room.code;
        linkEl.value = data.room.absoluteUrl;
        qrEl.src = qrUrl(token);
        enterEl.href = data.room.url;
        result.hidden = false;
        result.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    $('[data-create]')?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        btn.disabled = true;
        try {
            const data = await api('transfer.create', {
                password: $('[data-create-pass]')?.value || '',
            });
            showResult(data);
            toast('房间已创建');
        } catch (err) {
            toast(err.message, 'err');
        } finally {
            btn.disabled = false;
        }
    });

    $('[data-join]')?.addEventListener('click', async (ev) => {
        const btn = ev.currentTarget;
        const code = ($('[data-join-code]')?.value || '').trim();
        if (!/^\d{6}$/.test(code)) {
            toast('请输入 6 位取件码', 'err');
            return;
        }
        btn.disabled = true;
        try {
            const data = await api('transfer.join', {
                code: code,
                password: $('[data-join-pass]')?.value || '',
            });
            location.href = data.url;
        } catch (err) {
            toast(err.message, 'err');
            btn.disabled = false;
        }
    });

    $('[data-join-code]')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') $('[data-join]')?.click();
    });

    $('[data-result-copy]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(linkEl.value);
            toast('链接已复制');
        } catch (e) {
            linkEl.select();
            if (document.execCommand) document.execCommand('copy');
        }
    });
})();
