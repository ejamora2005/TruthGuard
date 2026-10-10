// Inlined in the shared head, independent of Vite and nonessential assets.
(() => {
    if (window.TruthGuardLoader) return;
    const root = document.documentElement;
    const pending = new Set();
    const inertElements = new Map();
    let showTimer, finishTimer, watchdog, navigation;
    let failed = false;
    const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
    const overlay = () => document.querySelector('[data-truthguard-splash]');
    const restore = () => {
        inertElements.forEach((value, element) => { element.inert = value; });
        inertElements.clear();
    };
    const lock = () => {
        if (!root.classList.contains('tg-loading')) return;
        overlay()?.setAttribute('aria-hidden', 'false');
        for (const child of document.body?.children || []) {
            if (child === overlay() || ['SCRIPT', 'STYLE'].includes(child.tagName)) continue;
            if (!inertElements.has(child)) inertElements.set(child, child.inert);
            child.inert = true;
        }
    };
    const reset = () => {
        clearTimeout(showTimer);
        clearTimeout(finishTimer);
        clearTimeout(watchdog);
        pending.clear();
        navigation = null;
        root.classList.remove('tg-loading', 'tg-blinking', 'tg-fading');
        overlay()?.setAttribute('aria-hidden', 'true');
        restore();
        failed = false;
    };
    const show = () => {
        root.classList.add('tg-loading');
        lock();
    };
    // Explicit essential-data API: const done = TruthGuardLoader.begin();
    // Call done() after success, done(false) on failure or component cleanup.
    // Calls are idempotent and concurrent work shares the same overlay.
    function begin({ immediate = false } = {}) {
        const token = Symbol('loading');
        if (!pending.size) {
            clearTimeout(finishTimer);
            root.classList.remove('tg-blinking', 'tg-fading');
            failed = false;
            // Canceled navigation or a broken bundle must never trap the page.
            clearTimeout(watchdog);
            watchdog = setTimeout(reset, 15000);
            if (immediate || root.classList.contains('tg-loading')) show();
            else showTimer = setTimeout(show, 120);
        }
        pending.add(token);
        let ended = false;
        return (success = true) => {
            if (ended || !pending.has(token)) return;
            ended = true;
            pending.delete(token);
            failed ||= !success;
            if (pending.size) return;
            clearTimeout(showTimer);
            if (!root.classList.contains('tg-loading') || reduced()) { reset(); return; }
            const fade = () => {
                root.classList.add('tg-fading');
                restore();
                finishTimer = setTimeout(reset, 250);
            };
            if (failed) { fade(); return; }
            root.classList.add('tg-blinking');
            finishTimer = setTimeout(fade, 500);
        };
    }
    const startNavigation = () => { navigation ||= begin(); };
    const ready = () => {
        lock();
        initialDone();
        navigation?.();
        navigation = null;
    };
    window.TruthGuardLoader = { begin, reset, ready, startNavigation };
    const initialDone = begin({ immediate: true });
    document.addEventListener('DOMContentLoaded', () => {
        lock();
        if (!document.getElementById('admin-root')) ready();
    }, { once: true });
    // Observe native actions after other handlers can cancel them. Never intercept.
    document.addEventListener('click', event => {
        const link = event.target.closest?.('a[href]');
        if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.hasAttribute('download') || link.closest('[data-no-global-loader]')
            || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, location.href);
        if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)
            || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
        setTimeout(() => { if (!event.defaultPrevented) startNavigation(); }, 0);
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        const target = event.submitter?.formTarget || form.target;
        if (form.closest('[data-no-global-loader]') || (target && target !== '_self') || form.method === 'dialog') return;
        setTimeout(() => { if (!event.defaultPrevented) startNavigation(); }, 0);
    });
    document.addEventListener('livewire:navigate', event => {
        queueMicrotask(() => { if (!event.defaultPrevented) startNavigation(); });
    });
    document.addEventListener('livewire:navigated', ready);
    window.addEventListener('pagehide', reset);
    window.addEventListener('pageshow', event => { if (event.persisted) reset(); });
    window.addEventListener('offline', reset);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') reset(); });
})();
