(() => {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ===================================================== 滚动 reveal */

    const items = document.querySelectorAll('[data-reveal]');
    if (!items.length) {
        return;
    }

    if (reduceMotion || !('IntersectionObserver' in window)) {
        items.forEach((el) => el.classList.add('is-in'));
        return;
    }

    const io = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            }
        }
    }, { threshold: 0.15, rootMargin: '0px 0px -6% 0px' });

    items.forEach((el) => io.observe(el));
})();
