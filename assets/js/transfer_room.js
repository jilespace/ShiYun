/* 拾云 · 临时传输房间页。依赖 shell.js。 */
(() => {
    'use strict';

    const SY = window.SY || {};
    const CFG = window.__SHIYUN__ || {};
    const $ = SY.$;
    const api = SY.api;
    const toast = SY.toast;
    const escapeHtml = SY.escapeHtml;
    const humanSize = SY.humanSize;

    const root = $('[data-page="transfer-room"]');
    if (!root) return;

    const token = root.dataset.token || '';
    const roomUrl = root.dataset.roomUrl || location.href;
    const creatorKey = localStorage.getItem('transfer.ck.' + token) || '';
    const isCreator = creatorKey !== '';

    const filesHost = $('[data-files]');
    const queueHost = $('[data-queue]');
    const drop = $('[data-drop]');
    const fileInput = $('[data-file]');
    const nickname = $('[data-nickname]');

    if (nickname) {
        nickname.value = localStorage.getItem('transfer.name') || '';
        nickname.addEventListener('input', () => localStorage.setItem('transfer.name', nickname.value));
    }

    if (isCreator) {
        $('[data-creator-actions]')?.removeAttribute('hidden');
    }

    function downloadUrl(id) {
        return (CFG.base || 'index.php') + '?r=transfer.download&t=' + encodeURIComponent(token) + '&id=' + encodeURIComponent(id);
    }

    function renderFiles(files) {
        if (!filesHost) return;
        if (!files.length) {
            filesHost.innerHTML = '<tr><td colspan="5" class="muted">还没有文件，先传一个吧。</td></tr>';
            return;
        }
        filesHost.innerHTML = files.map((f) => {
            const name = escapeHtml(f.name);
            const who = f.uploader ? '来自 ' + escapeHtml(f.uploader) : '—';
            const del = isCreator
                ? '<button class="minibtn" type="button" data-del="' + Number(f.id) + '" aria-label="删除">' + SY.icon('trash-2') + '</button>'
                : '';
            return '<tr>'
                + '<td><div class="tbl__name">' + SY.icon('file') + '<a href="' + downloadUrl(f.id) + '">' + name + '</a></div></td>'
                + '<td class="num">' + humanSize(f.size) + '</td>'
                + '<td class="muted">' + who + '</td>'
                + '<td class="muted nowrap">' + escapeHtml((f.createdAt || '').slice(0, 16)) + '</td>'
                + '<td><div class="tbl__acts">'
                + '<a class="chipbtn" href="' + downloadUrl(f.id) + '">下载</a>' + del
                + '</div></td></tr>';
        }).join('');
    }

    function renderState(room) {
        if (!room) return;
        const left = Math.max(0, Number(room.expiresIn) || 0);
        const h = Math.floor(left / 3600);
        const m = Math.floor((left % 3600) / 60);
        const el = $('[data-expires]');
        if (el) el.textContent = h > 0 ? h + ' 小时 ' + m + ' 分' : m + ' 分钟';
        const used = $('[data-used]');
        if (used) used.textContent = humanSize(room.usedBytes);
        const max = $('[data-max]');
        if (max) max.textContent = humanSize(room.maxBytes);
        const count = $('[data-count]');
        if (count) count.textContent = String(room.fileCount);
    }

    let lastIds = null;

    async function load() {
        if (!token || document.hidden) return;
        try {
            const data = await api('transfer.files', { t: token }, { method: 'GET' });
            renderState(data.room);
            const ids = (data.files || []).map((f) => f.id).join(',');
            if (ids !== lastIds) {
                lastIds = ids;
                renderFiles(data.files || []);
            }
        } catch (err) {
            if (err.code === 'room_gone' || err.code === 'password_required') {
                location.reload();
            }
        }
    }

    if (filesHost) {
        load();
        setInterval(load, 3000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) load();
        });
    }

    function postChunk(uploadId, index, blob, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', (CFG.base || 'index.php') + '?r=transfer.upload_chunk&t=' + encodeURIComponent(token));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/octet-stream');
            xhr.setRequestHeader('X-CSRF-Token', CFG.csrf || '');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-Upload-Id', uploadId);
            xhr.setRequestHeader('X-Chunk-Index', String(index));
            xhr.upload.onprogress = (e) => {
                if (onProgress && e.lengthComputable) onProgress(e.loaded);
            };
            xhr.onload = () => {
                let data = null;
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data.data || {});
                } else {
                    const err = new Error((data && data.error) || ('上传失败（' + xhr.status + '）'));
                    err.code = data && data.code;
                    reject(err);
                }
            };
            xhr.onerror = () => reject(new Error('网络异常，请检查连接。'));
            xhr.send(blob);
        });
    }

    async function uploadOne(file, row) {
        const sender = (nickname?.value || '').trim();
        const init = await api('transfer.upload_init', {
            t: token, name: file.name, size: file.size, sender: sender,
        });
        const size = Number(init.chunkSize) || 5 * 1024 * 1024;
        const total = Number(init.totalChunks) || 1;
        let sent = 0;
        try {
            for (let i = 0; i < total; i++) {
                if (file.size === 0) break;
                const start = i * size;
                const blob = file.slice(start, Math.min(file.size, start + size));
                let ok = false;
                let lastErr = null;
                for (let attempt = 0; attempt < 3 && !ok; attempt++) {
                    try {
                        await postChunk(init.uploadId, i, blob, (loaded) => {
                            row.textContent = file.name + ' · ' + Math.min(99, Math.round((sent + loaded) / file.size * 100)) + '%';
                        });
                        ok = true;
                    } catch (err) {
                        lastErr = err;
                    }
                }
                if (!ok) throw lastErr || new Error('分片上传失败');
                sent += blob.size;
                row.textContent = file.name + ' · ' + Math.min(99, Math.round(sent / file.size * 100)) + '%';
            }
            await api('transfer.upload_complete', { t: token, uploadId: init.uploadId });
            row.textContent = file.name + ' · 完成';
        } catch (err) {
            await api('transfer.upload_abort', { t: token, uploadId: init.uploadId }).catch(() => {});
            row.textContent = file.name + ' · 失败：' + err.message;
            throw err;
        }
    }

    async function uploadFiles(list) {
        if (!list || !list.length) return;
        for (const file of Array.from(list)) {
            const row = document.createElement('li');
            row.textContent = file.name + ' · 准备中';
            queueHost?.appendChild(row);
            try {
                await uploadOne(file, row);
            } catch (err) {
                toast(err.message, 'err');
            }
        }
        lastIds = null;
        load();
        setTimeout(() => { if (queueHost) queueHost.innerHTML = ''; }, 2500);
    }

    fileInput?.addEventListener('change', () => {
        uploadFiles(fileInput.files);
        fileInput.value = '';
    });

    drop?.addEventListener('click', () => fileInput?.click());
    drop?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fileInput?.click();
        }
    });
    document.addEventListener('dragover', (e) => {
        if (!drop) return;
        e.preventDefault();
        drop.classList.add('is-hot');
    });
    document.addEventListener('dragleave', () => drop?.classList.remove('is-hot'));
    document.addEventListener('drop', (e) => {
        if (!drop) return;
        e.preventDefault();
        drop.classList.remove('is-hot');
        if (e.dataTransfer?.files?.length) uploadFiles(e.dataTransfer.files);
    });

    filesHost?.addEventListener('click', async (e) => {
        const del = e.target.closest('[data-del]');
        if (!del || !isCreator) return;
        del.disabled = true;
        try {
            await api('transfer.file_delete', { t: token, creator_key: creatorKey, id: Number(del.dataset.del) });
            toast('已删除');
            lastIds = null;
            load();
        } catch (err) {
            toast(err.message, 'err');
            del.disabled = false;
        }
    });

    $('[data-copy-link]')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(roomUrl);
            toast('链接已复制');
        } catch (e) {
            toast('复制失败，请手动选择地址栏', 'err');
        }
    });

    $('[data-close]')?.addEventListener('click', async () => {
        const ok = await SY.confirm('关闭房间', '链接会立即失效，房间内文件将被删除。', '关闭并删除', true);
        if (!ok) return;
        try {
            await api('transfer.close', { t: token, creator_key: creatorKey });
            location.href = (CFG.base || 'index.php') + '?r=transfer.index';
        } catch (err) {
            toast(err.message, 'err');
        }
    });

    $('[data-unlock]')?.addEventListener('click', async () => {
        try {
            await api('transfer.unlock', {
                t: token,
                password: $('[data-unlock-pass]')?.value || '',
            });
            location.reload();
        } catch (err) {
            toast(err.message, 'err');
        }
    });
    $('[data-unlock-pass]')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') $('[data-unlock]')?.click();
    });
})();
