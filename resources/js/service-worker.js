let pending;

// Shared by install/offline support and push; wait for this registration, not an
// unrelated navigator.serviceWorker.ready registration that can wait forever.
export function getTruthGuardWorker() {
    if (pending) return pending;
    pending = new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('The TruthGuard service worker did not become ready. Reload and try again.')), 15000);
        const finish = (registration) => { clearTimeout(timer); resolve(registration); };
        navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).then((registration) => {
            if (registration.scope !== new URL('/', location.href).href) throw new Error('Unexpected TruthGuard service worker scope.');
            if (registration.active) { finish(registration); return; }
            const worker = registration.installing || registration.waiting;
            if (!worker) throw new Error('TruthGuard service worker installation failed.');
            const changed = () => {
                if (worker.state === 'activated') { worker.removeEventListener('statechange', changed); finish(registration); }
                else if (worker.state === 'redundant') {
                    worker.removeEventListener('statechange', changed);
                    clearTimeout(timer);
                    reject(new Error('TruthGuard service worker installation failed. Reload and try again.'));
                }
            };
            worker.addEventListener('statechange', changed);
            changed();
        }).catch((error) => { clearTimeout(timer); reject(error); });
    }).catch((error) => { pending = null; throw error; });
    return pending;
}
