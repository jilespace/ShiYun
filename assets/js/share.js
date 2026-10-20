/* 拾云 · 公开分享页
   向上传型分享与收取箱投递文件。大文件自动走分片，绕过 PHP 的 post_max_size。 */
(() => {
    'use strict';

    const CFG = window.__SHIYUN__ || {};
    const icon = window.SY?.icon || (() => '');

    const drop = document.querySelector('[data-share-drop]');
    const input = document.querySelector('[data-share-file]');
    const queue = document.querySelector('[data-share-queue]');
    const toasts = document.querySelector('[data-toasts]');
    const left = document.querySelector('[data-inbox-left]');

    function toast(message, kind) {
        if (!toasts) return;
        const el = document.createElement('div');
        el.className = 'toast toast--' + (kind === 'err' ? 'err' : 'ok');
        el.innerHTML = icon(kind === 'err' ? 'circle-alert' : 'check-circle') + '<span></span>';
        el.lastChild.textContent = message;
        toasts.appendChild(el);
        setTimeout(() => el.remove(), kind === 'err' ? 4200 : 2600);
    }

    function token() {
        const params = new URLSearchParams(location.search);
        return params.get('t') || '';
    }

    function senderFields() {
        return {
            sender: document.querySelector('[data-sender]')?.value || '',
            message: document.querySelector('[data-message]')?.value || '',
        };
    }

    function parse(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            return null;
        }
    }

    function failFrom(status, json) {
        const err = new Error((json && json.error) || '上传失败');
        err.code = json && json.code;
        err.status = status;
        return err;
    }

    /** JSON 请求（初始化 / 合并） */
    function postJson(route, payload) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.base + '?r=' + encodeURIComponent(route));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', CFG.csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = () => {
                const json = parse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && json && json.ok === true) {
                    resolve(json.data || {});
                } else {
                    reject(failFrom(xhr.status, json));
                }
            };
            xhr.onerror = () => reject(new Error('网络异常，请检查连接。'));
            xhr.send(JSON.stringify(payload || {}));
        });
    }

    /** 分片请求（裸二进制） */
    function postChunk(uploadId, index, blob, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            // 分片请求体是裸二进制，分享令牌只能放在查询串里
            xhr.open('POST', CFG.base + '?r=shares.upload_chunk&t=' + encodeURIComponent(token()));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('X-CSRF-Token', CFG.csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-Upload-Id', uploadId);
            xhr.setRequestHeader('X-Chunk-Index', String(index));
            if (onProgress) {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) onProgress(e.loaded);
                });
            }
            xhr.onload = () => {
                const json = parse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && json && json.ok === true) {
                    resolve(json.data || {});
                } else {
                    reject(failFrom(xhr.status, json));
                }
            };
            xhr.onerror = () => reject(new Error('网络异常，请检查连接。'));
            xhr.send(blob);
        });
    }

    /** 单片直传（小文件） */
    function postForm(form) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CFG.base + '?r=shares.upload');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', CFG.csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = () => {
                const json = parse(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && json && json.ok === true) {
                    resolve(json.data || {});
                } else {
                    reject(failFrom(xhr.status, json));
                }
            };
            xhr.onerror = () => reject(new Error('网络异常，请检查连接。'));
            xhr.send(form);
        });
    }

    function markFull() {
        drop?.classList.add('is-done');
        const title = drop?.querySelector('.dropmini__title');
        if (title) title.textContent = '这个收取箱已经收满了';
        input?.setAttribute('disabled', 'disabled');
    }

    function noteCapacity(res) {
        if (!left || res.maxFiles === null || res.maxFiles === undefined) return;
        const remain = Math.max(0, res.maxFiles - (res.uploads || 0));
        left.textContent = '· 还能提交 ' + remain + ' 个';
        if (res.full) markFull();
    }

    async function upload(file, state) {
        const chunkSize = Number(CFG.chunkSize) || 5 * 1024 * 1024;
        // 单片只用于小文件；大文件必须分片，否则会被 post_max_size 截断
        if (file.size <= chunkSize) {
            const form = new FormData();
            form.append('t', token());
            const extra = senderFields();
            form.append('sender', extra.sender);
            form.append('message', extra.message);
            form.append('file', file);
            state.textContent = '上传中';
            return postForm(form);
        }

        const init = await postJson('shares.upload_init', {
            t: token(),
            name: file.name,
            size: file.size,
        });
        const size = Number(init.chunkSize);
        const total = Number(init.totalChunks);
        let sent = 0;

        for (let i = 0; i < total; i++) {
            const start = i * size;
            const blob = file.slice(start, Math.min(file.size, start + size));
            await postChunk(init.uploadId, i, blob, (loaded) => {
                state.textContent = Math.min(99, Math.round((sent + loaded) / file.size * 100)) + '%';
            });
            sent += blob.size;
            state.textContent = Math.min(99, Math.round(sent / file.size * 100)) + '%';
        }

        const extra = senderFields();
        return postJson('shares.upload_complete', {
            t: token(),
            uploadId: init.uploadId,
            sender: extra.sender,
            message: extra.message,
        });
    }

    async function handle(file) {
        const li = document.createElement('li');
        li.innerHTML = '<span class="upqueue__name"></span><span class="upqueue__state">等待中</span>';
        li.querySelector('.upqueue__name').textContent = file.name;
        queue?.appendChild(li);

        const state = li.querySelector('.upqueue__state');

        if (CFG.maxUpload && file.size > CFG.maxUpload) {
            state.textContent = '过大';
            state.style.color = '#ff9a9a';
            toast(file.name + '：超过单文件上限', 'err');
            return;
        }

        try {
            const res = await upload(file, state);
            state.textContent = '已送达';
            state.style.color = '#4ad07a';
            toast('「' + file.name + '」已送达');
            noteCapacity(res);
        } catch (err) {
            state.textContent = '失败';
            state.style.color = '#ff9a9a';
            toast(file.name + '：' + err.message, 'err');
            if (err.code === 'full') markFull();
        }
    }

    if (drop && input && queue) {
        drop.addEventListener('click', () => {
            if (input.disabled) return;
            input.click();
        });

        input.addEventListener('change', () => {
            Array.from(input.files || []).forEach(handle);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            if (!input.disabled) drop.classList.add('is-hot');
        }));

        ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => {
            e.preventDefault();
            drop.classList.remove('is-hot');
        }));

        drop.addEventListener('drop', (e) => {
            if (input.disabled) return;
            Array.from(e.dataTransfer?.files || []).forEach(handle);
        });
    }
})();
