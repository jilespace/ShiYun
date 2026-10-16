(() => {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ===================================================== 档案场（画布） */

    // Lucide 图形，24×24 坐标系
    const SHAPES = {
        file: [
            ['path', 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z'],
            ['path', 'M14 2v4a2 2 0 0 0 2 2h4'],
        ],
        folder: [
            ['path', 'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z'],
        ],
        cloud: [
            ['path', 'M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z'],
        ],
        image: [
            ['rect', 3, 3, 18, 18, 2],
            ['circle', 9, 9, 2],
            ['path', 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21'],
        ],
        archive: [
            ['rect', 2, 3, 20, 5, 1],
            ['path', 'M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8'],
            ['path', 'M10 12h4'],
        ],
        music: [
            ['path', 'M9 18V5l12-2v13'],
            ['circle', 6, 18, 3],
            ['circle', 18, 16, 3],
        ],
    };

    function buildPath(shape) {
        const p = new Path2D();
        for (const s of shape) {
            if (s[0] === 'path') {
                p.addPath(new Path2D(s[1]));
            } else if (s[0] === 'rect') {
                if (p.roundRect) {
                    p.roundRect(s[1], s[2], s[3], s[4], s[5]);
                } else {
                    p.rect(s[1], s[2], s[3], s[4]);
                }
            } else if (s[0] === 'circle') {
                p.moveTo(s[1] + s[3], s[2]);
                p.arc(s[1], s[2], s[3], 0, Math.PI * 2);
            }
        }
        return p;
    }

    function startField(canvas) {
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        const paths = {};
        for (const key in SHAPES) {
            paths[key] = buildPath(SHAPES[key]);
        }
        const kinds = Object.keys(paths);

        let width = 0;
        let height = 0;
        let particles = [];
        let target = null;
        let last = performance.now();

        const pointer = { x: -9999, y: -9999 };
        const GLOW = 240;

        const rand = (min, max) => min + Math.random() * (max - min);

        function seed() {
            const count = Math.max(26, Math.min(92, Math.floor((width * height) / 19000)));
            particles = [];
            for (let i = 0; i < count; i++) {
                const bx = Math.random() * width;
                const by = Math.random() * height;
                particles.push({
                    bx,
                    by,
                    x: bx,
                    y: by,
                    vx: 0,
                    vy: 0,
                    size: rand(15, 30),
                    rot: rand(-0.5, 0.5),
                    spin: rand(-0.00035, 0.00035),
                    alpha: rand(0.05, 0.15),
                    amp: rand(8, 26),
                    phase: Math.random() * Math.PI * 2,
                    speed: rand(0.00018, 0.00042),
                    path: paths[kinds[(Math.random() * kinds.length) | 0]],
                });
            }
        }

        function resize() {
            const dpr = Math.min(window.devicePixelRatio || 1, 2);
            width = canvas.clientWidth;
            height = canvas.clientHeight;
            canvas.width = Math.round(width * dpr);
            canvas.height = Math.round(height * dpr);
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            seed();
            if (reduceMotion) {
                draw(performance.now());
            }
        }

        function draw(now) {
            ctx.clearRect(0, 0, width, height);
            const repel = 165;
            const repel2 = repel * repel;

            for (const p of particles) {
                const hx = p.bx + Math.cos(now * p.speed + p.phase) * p.amp;
                const hy = p.by + Math.sin(now * p.speed * 1.2 + p.phase) * p.amp;

                if (!reduceMotion) {
                    // 弹簧回位
                    p.vx += (hx - p.x) * 0.006;
                    p.vy += (hy - p.y) * 0.006;

                    // 聚焦时向码头流动
                    if (target) {
                        p.vx += (target.x - p.x) * 0.0009;
                        p.vy += (target.y - p.y) * 0.0009;
                    }

                    // 指针斥力
                    const dx = p.x - pointer.x;
                    const dy = p.y - pointer.y;
                    const d2 = dx * dx + dy * dy;
                    if (d2 < repel2) {
                        const d = Math.sqrt(d2) || 1;
                        const f = ((repel - d) / repel) * 1.5;
                        p.vx += (dx / d) * f;
                        p.vy += (dy / d) * f;
                    }

                    p.vx *= 0.9;
                    p.vy *= 0.9;
                    p.x += p.vx;
                    p.y += p.vy;
                    p.rot += p.spin;
                } else {
                    p.x = hx;
                    p.y = hy;
                }

                // 靠近指针的图标被点亮
                let t = 0;
                if (pointer.x > -1000) {
                    const gd = Math.hypot(p.x - pointer.x, p.y - pointer.y);
                    if (gd < GLOW) {
                        t = 1 - gd / GLOW;
                    }
                }

                const alpha = Math.min(0.85, p.alpha + t * 0.55);
                const cr = Math.round(244 + (138 - 244) * t);
                const cg = Math.round(242 + (136 - 242) * t);
                const cb = Math.round(236 + (255 - 236) * t);

                const s = p.size / 24;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.scale(s, s);
                ctx.lineWidth = 1.7 / s;
                ctx.strokeStyle = `rgba(${cr},${cg},${cb},${alpha})`;
                ctx.stroke(p.path);
                ctx.restore();
            }

            // 指针处的柔光
            if (pointer.x > -1000) {
                ctx.save();
                ctx.globalCompositeOperation = 'lighter';
                const grad = ctx.createRadialGradient(pointer.x, pointer.y, 0, pointer.x, pointer.y, GLOW);
                grad.addColorStop(0, 'rgba(61, 58, 255, 0.14)');
                grad.addColorStop(0.5, 'rgba(61, 58, 255, 0.05)');
                grad.addColorStop(1, 'rgba(61, 58, 255, 0)');
                ctx.fillStyle = grad;
                ctx.fillRect(pointer.x - GLOW, pointer.y - GLOW, GLOW * 2, GLOW * 2);
                ctx.restore();
            }
        }

        function loop(now) {
            draw(now);
            last = now;
            requestAnimationFrame(loop);
        }

        window.addEventListener(
            'pointermove',
            (event) => {
                const r = canvas.getBoundingClientRect();
                pointer.x = event.clientX - r.left;
                pointer.y = event.clientY - r.top;
            },
            { passive: true }
        );

        window.addEventListener('pointerleave', () => {
            pointer.x = -9999;
            pointer.y = -9999;
        });

        let resizeTimer = 0;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(resize, 160);
        });

        // 表单聚焦时，图标向码头聚拢
        const dock = document.querySelector('[data-dock]');
        if (dock) {
            const focus = () => {
                const r = dock.getBoundingClientRect();
                target = { x: r.left + r.width / 2, y: r.top + r.height / 2 };
            };
            const blur = () => {
                target = null;
            };
            dock.addEventListener('focusin', focus);
            dock.addEventListener('focusout', () => setTimeout(() => {
                if (!dock.contains(document.activeElement)) {
                    blur();
                }
            }, 0));
        }

        resize();
        if (!reduceMotion) {
            requestAnimationFrame(loop);
        }
    }

    const canvas = document.querySelector('[data-field]');
    if (canvas) {
        startField(canvas);
    }

    /* ===================================================== 磁吸按钮 */

    if (!reduceMotion) {
        document.querySelectorAll('[data-magnetic]').forEach((el) => {
            el.addEventListener('pointermove', (event) => {
                const r = el.getBoundingClientRect();
                const x = (event.clientX - (r.left + r.width / 2)) * 0.3;
                const y = (event.clientY - (r.top + r.height / 2)) * 0.4;
                el.style.transform = `translate(${x.toFixed(1)}px, ${y.toFixed(1)}px)`;
            });
            el.addEventListener('pointerleave', () => {
                el.style.transform = '';
            });
        });
    }

    /* ===================================================== 标签切换 */

    const tabs = document.querySelector('[data-tabs]');
    if (tabs) {
        const panes = document.querySelectorAll('[data-pane]');
        const links = tabs.querySelectorAll('[data-goto]');
        const ink = tabs.querySelector('.tabs__ink');

        const moveInk = () => {
            const active = tabs.querySelector('.tab[aria-selected="true"]');
            if (active && ink) {
                ink.style.width = `${active.offsetWidth}px`;
                ink.style.transform = `translateX(${active.offsetLeft}px)`;
            }
        };

        const dock = tabs.closest('[data-dock]');
        let heightTimer = 0;

        const activate = (name, updateUrl, link) => {
            if (dock) {
                dock.style.height = '';
                dock.style.transition = '';
                dock.style.overflow = '';
            }
            const from = dock ? dock.offsetHeight : 0;

            tabs.dataset.active = name;
            panes.forEach((pane) => {
                pane.hidden = pane.dataset.pane !== name;
            });
            links.forEach((link) => {
                link.setAttribute('aria-selected', String(link.dataset.goto === name));
            });
            moveInk();

            // 平滑过渡码头高度，避免切换时页面跳动
            if (dock && !reduceMotion) {
                const to = dock.offsetHeight;
                if (from && to && from !== to) {
                    dock.style.height = `${from}px`;
                    dock.style.overflow = 'hidden';
                    void dock.offsetHeight;
                    dock.style.transition = 'height 0.42s cubic-bezier(0.16, 1, 0.3, 1)';
                    dock.style.height = `${to}px`;
                    // 用定时器兜底复位（transitionend 不总是可靠触发）
                    clearTimeout(heightTimer);
                    heightTimer = window.setTimeout(() => {
                        dock.style.height = '';
                        dock.style.transition = '';
                        dock.style.overflow = '';
                    }, 460);
                }
            }

            const first = document.querySelector(`[data-pane="${name}"] input`);
            if (first) {
                first.focus({ preventScroll: true });
            }
            if (updateUrl) {
                history.replaceState(null, '', link ? link.href : `index.php?r=${name}`);
            }
        };

        links.forEach((link) => {
            link.addEventListener('click', (event) => {
                if (!link.dataset.goto) {
                    return;
                }
                // 目标 pane 不存在（登录/注册等单 pane 页面）→ 交给浏览器整页导航
                if (!document.querySelector('[data-pane="' + link.dataset.goto + '"]')) {
                    return;
                }
                event.preventDefault();
                activate(link.dataset.goto, true, link);
            });
        });

        moveInk();
        window.addEventListener('resize', moveInk);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(moveInk);
        }
    }

    /* ===================================================== 密码显隐 */

    document.querySelectorAll('[data-reveal]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.closest('.fld__box')?.querySelector('input');
            if (!input) {
                return;
            }
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
        });
    });

    /* ===================================================== 密码强度 */

    const strengthInput = document.querySelector('[data-strength-input]');
    const strength = document.querySelector('[data-strength]');
    if (strengthInput && strength) {
        const text = strength.querySelector('[data-strength-text]');
        const labels = ['密码强度', '偏弱', '一般', '不错', '很强'];

        const score = (value) => {
            if (!value) {
                return 0;
            }
            let s = 0;
            if (value.length >= 8) s += 1;
            if (value.length >= 12) s += 1;
            if (/(?=.*[a-z])(?=.*[A-Z])/.test(value) || /(?=.*[A-Za-z])(?=.*\d)/.test(value)) s += 1;
            if (/[^A-Za-z0-9]/.test(value)) s += 1;
            return Math.min(4, s);
        };

        const update = () => {
            const value = strengthInput.value;
            const level = value ? Math.max(1, score(value)) : 0;
            strength.dataset.level = String(level);
            text.textContent = labels[level];
        };

        strengthInput.addEventListener('input', update);
        update();
    }

    /* ===================================================== 时钟 */

    const clock = document.querySelector('[data-clock]');
    if (clock) {
        const tick = () => {
            const d = new Date();
            const p = (n) => String(n).padStart(2, '0');
            clock.textContent = `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
        };
        tick();
        setInterval(tick, 1000);
    }

    /* ===================================================== 编辑即清除错误态 */

    document.querySelectorAll('.fld.is-invalid .fld__in, .agree.is-invalid input').forEach((el) => {
        const clear = () => el.closest('.fld, .agree')?.classList.remove('is-invalid');
        el.addEventListener('input', clear);
        el.addEventListener('change', clear);
    });
})();
