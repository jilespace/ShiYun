/* 拾云 · 公开分享页
   只做两件事：向上传型分享投递文件，以及给出反馈提示。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const icon = window.SY?.icon || (() => '');

    const drop = document.querySelector('[data-share-drop]');
    const input = document.querySelector('[data-share-file]');
    const queue = document.querySelector('[data-share-queue]');
    const toasts = document.querySelector('[data-toasts]');

    function toast(message, kind) {
        if (!toasts) return;
        const el = document.createElement('div');
        el.className = 'toast toast--' + (kind === 'err' ? 'err' : 'ok');
        el.innerHTML = icon(kind === 'err' ? 'circle-alert' : 'check-circle') + '<span></span>';
        el.lastChild.textContent = message;
        toasts.appendChild(el);
        setTimeout(() => el.remove(), kind === 'err' ? 4200 : 2600);
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
    }

    function token() {
        const params = new URLSearchParams(location.search);
        return params.get('t') || '';
    }

    async function upload(file) {
        const li = document.createElement('li');
        li.innerHTML = '<span class="upqueue__name"></span><span class="upqueue__state">等待中</span>';
        li.querySelector('.upqueue__name').textContent = file.name;
        queue?.appendChild(li);

        const state = li.querySelector('.upqueue__state');
        state.textContent = '上传中';

        const body = new FormData();
        body.append('t', token());
        body.append('file', file);

        try {
            const res = await fetch(CFG.base + '?r=share.upload', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-Token': CFG.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });
            const json = await res.json().catch(() => null);
            if (!res.ok || !json || json.ok !== true) {
                throw new Error((json && json.error) || '上传失败');
            }
            state.textContent = '已完成';
            state.style.color = '#4ad07a';
            toast('「' + file.name + '」已送达');
        } catch (err) {
            state.textContent = '失败';
            state.style.color = '#ff9a9a';
            toast(file.name + '：' + err.message, 'err');
        }
    }

    if (drop && input && queue) {
        drop.addEventListener('click', () => input.click());

        input.addEventListener('change', () => {
            Array.from(input.files || []).forEach(upload);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.add('is-hot');
        }));

        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.remove('is-hot');
        }));

        drop.addEventListener('drop', (e) => {
            Array.from(e.dataTransfer?.files || []).forEach(upload);
        });
    }
})();
