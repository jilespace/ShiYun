/* 拾云 · 相册：无限滚动 + 图片淡入 */
(() => {
    'use strict';

    const album = document.querySelector('[data-album]');
    if (!album) {
        return;
    }

    const CFG = window.__SHIYUN__ || {};

    // 缩略图加载完成后淡入，避免网格跳动
    album.addEventListener('load', (e) => {
        if (e.target instanceof HTMLImageElement) {
            e.target.classList.add('is-ready');
        }
    }, true);

    album.querySelectorAll('.alb__img').forEach((img) => {
        if (img.complete && img.naturalWidth > 0) {
            img.classList.add('is-ready');
        }
    });

    /* ── 无限滚动 ── */

    const more = document.querySelector('[data-more]');
    if (!more || !('IntersectionObserver' in window)) {
        return;
    }

    let page = Number(more.dataset.next) - 1;
    let busy = false;

    async function loadNext() {
        if (busy) return;
        busy = true;
        more.classList.add('is-loading');

        try {
            const res = await fetch(CFG.base + '?r=files.album_data&page=' + (page + 1), {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const body = await res.json();
            if (!res.ok || !body || body.ok !== true) {
                throw new Error((body && body.error) || '加载失败');
            }

            page = Number(body.data.page);
            for (const group of body.data.groups) {
                // 翻页边界上，新一页的首个月份常常与上一页末组相同。
                // 直接 append 会出现两个一模一样的时间标题，所以能并就并进已有分组。
                const last = album.lastElementChild;
                const lastTitle = last && last.querySelector('.alb__month');
                let grid;
                let title = lastTitle;

                if (lastTitle && lastTitle.textContent.replace(/\d+$/, '') === group.month) {
                    grid = last.querySelector('.alb__grid');
                    title = lastTitle;
                } else {
                    const section = document.createElement('section');
                    section.className = 'alb__group';
                    section.innerHTML = '<h2 class="alb__month"></h2><div class="alb__grid"></div>';
                    title = section.querySelector('.alb__month');
                    title.append(document.createTextNode(group.month));
                    grid = section.querySelector('.alb__grid');
                    album.append(section);
                }

                for (const n of group.items) {
                    const cell = document.createElement('a');
                    cell.className = 'alb__cell';
                    cell.title = n.name;
                    cell.href = CFG.base + '?r=preview.open&id=' + n.id;
                    const img = document.createElement('img');
                    img.className = 'alb__img';
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    img.alt = n.name;
                    img.addEventListener('load', () => img.classList.add('is-ready'));
                    img.onerror = () => {
                        img.onerror = null;
                        img.src = CFG.base + '?r=preview.raw&id=' + n.id;
                    };
                    img.src = CFG.base + '?r=preview.thumb&id=' + n.id;
                    const cap = document.createElement('span');
                    cap.className = 'alb__cap';
                    cap.textContent = n.name;
                    cell.append(img, cap);
                    grid.append(cell);
                }

                // 分组计数跟着更新（标题里的 <i> 是张数）
                const count = title.querySelector('i');
                if (count) {
                    count.textContent = String(grid.childElementCount);
                } else {
                    const badge = document.createElement('i');
                    badge.textContent = String(grid.childElementCount);
                    title.append(badge);
                }
            }

            if (!body.data.hasMore) {
                more.remove();
            }
        } catch (err) {
            more.querySelector('.alb__more-hint').textContent = err.message + ' · 上滑重试';
        } finally {
            more.classList.remove('is-loading');
            busy = false;
        }
    }

    const io = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                loadNext();
            }
        }
    }, { rootMargin: '600px 0px' });

    io.observe(more);
})();
