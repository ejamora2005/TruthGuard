// Inlined in the shared head, independent of Vite and nonessential assets.
(() => {
    if (window.TruthGuardLoader) return;
    const root = document.documentElement;
    const pending = new Set();
    const inertElements = new Map();
    let showTimer, blinkTimer, blinkEye, blinkEnd, finishTimer, fadeFrame, watchdog, navigation, previousFocus;
    let failed = false;
    const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
    const overlay = () => document.querySelector('[data-truthguard-splash]');
    const observer = new MutationObserver(() => lock());
    const restore = () => {
        inertElements.forEach((value, element) => { element.inert = value; });
        inertElements.clear();
        if (previousFocus?.isConnected && [overlay(), document.body].includes(document.activeElement)) {
            previousFocus.focus?.({ preventScroll: true });
        }
        previousFocus = null;
    };
    const lock = () => {
        if (!root.classList.contains('tg-loading')) return;
        const loader = overlay();
        loader?.setAttribute('aria-hidden', 'false');
        if (loader && document.activeElement !== loader && !loader.contains(document.activeElement)) {
            previousFocus ||= document.activeElement;
        }
        for (const child of document.body?.children || []) {
            if (child === overlay() || ['SCRIPT', 'STYLE'].includes(child.tagName)) continue;
            if (!inertElements.has(child)) inertElements.set(child, child.inert);
            child.inert = true;
        }
        if (loader && document.activeElement !== loader && !loader.contains(document.activeElement)) {
            loader.focus({ preventScroll: true });
        }
        // Livewire can replace body; teleported controls can be appended during a request.
        observer.observe(root, { childList: true });
        if (document.body) observer.observe(document.body, { childList: true });
    };
    const cancelBlink = () => {
        clearTimeout(blinkTimer);
        blinkEye?.removeEventListener('animationend', blinkEnd);
        blinkEye = blinkEnd = null;
    };
    const reset = () => {
        clearTimeout(showTimer);
        cancelBlink();
        clearTimeout(finishTimer);
        cancelAnimationFrame(fadeFrame);
        clearTimeout(watchdog);
        pending.clear();
        navigation = null;
        observer.disconnect();
        root.classList.remove('tg-loading', 'tg-blinking', 'tg-fading');
        root.removeAttribute('data-tg-loader-initial');
        restore();
        overlay()?.setAttribute('aria-hidden', 'true');
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
            cancelBlink();
            clearTimeout(finishTimer);
            cancelAnimationFrame(fadeFrame);
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
            // Let the eye complete its blink while the overlay is fully visible.
            // Keep interaction blocked until the subsequent fade has finished.
            const fade = () => {
                fadeFrame = requestAnimationFrame(() => {
                    root.classList.add('tg-fading');
                    clearTimeout(watchdog);
                    finishTimer = setTimeout(reset, 240);
                });
            };
            if (failed) fade();
            else {
                blinkEye = overlay()?.querySelector('.tg-loader-eye');
                if (!blinkEye) { fade(); return; }
                blinkEnd = event => {
                    if (event.target !== blinkEye || event.animationName !== 'tg-loader-blink') return;
                    cancelBlink();
                    fade();
                };
                blinkEye.addEventListener('animationend', blinkEnd);
                root.classList.add('tg-blinking');
                // Browsers can suppress animation events in background tabs.
                blinkTimer = setTimeout(() => { cancelBlink(); fade(); }, 1200);
            }
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
    root.setAttribute('data-tg-loader-initial', '');
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
    window.addEventListener('pagehide', () => {
        // Leave the outgoing page/backdrop painted until the browser replaces it.
        // BFCache restoration resets this state in pageshow below.
        clearTimeout(showTimer);
        cancelBlink();
        clearTimeout(finishTimer);
        cancelAnimationFrame(fadeFrame);
        clearTimeout(watchdog);
        observer.disconnect();
    });
    window.addEventListener('pageshow', event => { if (event.persisted) reset(); });
    window.addEventListener('offline', reset);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') reset(); });
    // Lock scrolling without removing the scrollbar or changing the page's layout/position.
    const blockScroll = event => {
        if (root.classList.contains('tg-loading') && event.cancelable) event.preventDefault();
    };
    document.addEventListener('wheel', blockScroll, { passive: false, capture: true });
    document.addEventListener('touchmove', blockScroll, { passive: false, capture: true });
    document.addEventListener('keydown', event => {
        if ([' ', 'PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) blockScroll(event);
    }, { capture: true });
})();
