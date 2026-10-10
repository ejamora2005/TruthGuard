import { getTruthGuardWorker } from './service-worker';

// Runtime configuration only: server credentials never enter the frontend bundle.
let settings, messaging, sdk, registrationPending, initializationPending;
const boundPanels = new WeakSet();
const deviceDisabled = () => { try { return localStorage.getItem('truthguard:push-disabled') === '1'; } catch { return false; } };
const setDeviceDisabled = (disabled) => { try { localStorage.setItem('truthguard:push-disabled', disabled ? '1' : '0'); } catch { /* Storage unavailable. */ } };
const panels = () => [...document.querySelectorAll('[data-push-settings]')];
const status = (text) => panels().forEach(panel => {
    panel.querySelectorAll('[data-push-status]').forEach(element => { element.textContent = text; });
});
const supported = () => window.isSecureContext && typeof Notification !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window;
// This diagnostic must remain usable even if settings/Firebase/queue requests fail.
const bindDeviceTests = () => panels().forEach(panel => {
    const button = panel.querySelector('[data-push-device-test]');
    const feedback = panel.querySelector('[data-push-device-status]');
    if (!button || !feedback || button.dataset.bound) return;
    button.dataset.bound = 'true';
    button.disabled = false;
    button.addEventListener('click', async () => {
        feedback.hidden = false;
        button.disabled = true;
        button.textContent = 'Testing device...';
        feedback.textContent = 'Checking notification permission on this device...';
        try {
            if (!supported()) throw new Error('Device alerts require HTTPS and a browser that supports notifications. Open TruthGuard in Chrome.');
            if (Notification.permission === 'denied') throw new Error('Chrome has blocked notifications for TruthGuard. Allow them in Chrome site settings, then try again.');
            if (Notification.permission !== 'granted') throw new Error('Notification permission has not been granted. Tap Enable Notifications above and choose Allow, then try this device test again.');
            const worker = await getTruthGuardWorker();
            await worker.showNotification('TruthGuard device test', {
                body: 'This alert came directly from your device. Server push delivery still needs a separate test.',
                icon: '/pwa/icon-192.png', badge: '/pwa/icon-32.png',
                tag: 'truthguard-device-test', renotify: true,
                data: { url: '/notifications' },
            });
            feedback.textContent = 'Chrome accepted the device alert. Check your notification tray as well as the popup. If it appears, test server delivery with Send Test Notification. If it is missing, check Android notification settings for TruthGuard/Chrome and Do Not Disturb. This does not verify background push delivery.';
        } catch (error) {
            feedback.textContent = error.message || 'The device alert failed. Reopen TruthGuard and try again.';
        } finally {
            button.disabled = false;
            button.textContent = 'Test device alert';
        }
    });
});
const request = async (path, method = 'GET', body) => {
    const response = await fetch(path, {
        method, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(30000),
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) {
        throw new Error(result.message || 'Unable to load notifications. Refresh the page or sign in again.');
    }
    return result;
};
const getMessaging = async () => {
    if (messaging) return messaging;
    const [{ initializeApp, getApps }, messagingSdk] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
    sdk = messagingSdk;
    if (!await sdk.isSupported()) throw new Error('Push notifications are not supported by this browser.');
    const app = getApps().find(app => app.name === 'truthguard-push') || initializeApp(settings.firebase, 'truthguard-push');
    messaging = sdk.getMessaging(app);
    return messaging;
};
const subscribe = async () => {
    if (!supported() || Notification.permission !== 'granted' || !settings.enabled) throw new Error('Enable browser notification permission and configure push first.');
    const instance = await getMessaging();
    const registration = await getTruthGuardWorker();
    let timer;
    const token = await Promise.race([
        sdk.getToken(instance, { vapidKey: settings.vapidKey, serviceWorkerRegistration: registration }),
        new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Push subscription timed out. Check connectivity and try again.')), 30000); }),
    ]).finally(() => clearTimeout(timer));
    if (!token) throw new Error('Firebase did not provide a subscription. Please try again.');
    await request('/push/subscriptions', 'POST', { token });
    settings.subscribed = true;
};
const register = () => {
    if (!registrationPending) registrationPending = subscribe().finally(() => { registrationPending = null; });
    return registrationPending;
};
const render = () => panels().forEach(panel => {
    panel.querySelectorAll('[data-preference]').forEach(input => { input.checked = !!settings.preferences[input.dataset.preference]; });
    const enable = panel.querySelector('[data-push-enable]');
    enable.disabled = !settings.enabled || !supported() || Notification.permission === 'denied';
    enable.textContent = settings.subscribed && !settings.deviceDisabled && settings.preferences.push_enabled ? 'Refresh Notifications' : 'Enable Notifications';
    panel.querySelector('[data-push-test]').hidden = !settings.canTest;
});
const automaticRegistration = () => settings.enabled && supported() && Notification.permission === 'granted'
    && settings.preferences.push_enabled && !settings.deviceDisabled && !deviceDisabled();
async function initialize() {
    bindDeviceTests();
    if (!document.querySelector('meta[name="truthguard-push-settings"]')) return;
    try {
        settings = await request('/push/settings');
        render();
        if (!settings.enabled) status('Push notifications are not configured correctly on the server. In-app notifications remain available.');
        else if (!supported()) status('Push notifications require HTTPS and a supported browser/device.');
        else if (Notification.permission === 'denied') {
            status('Notifications are blocked. Change this site notification permission in your browser settings to enable them.');
            await request('/push/subscriptions', 'DELETE');
            settings.subscribed = false;
        } else status('Enable notifications when you are ready.');
        panels().forEach(panel => {
            if (boundPanels.has(panel)) return;
            boundPanels.add(panel);
            const run = async (action) => {
                const controls = panel.querySelectorAll('button, input');
                controls.forEach(control => { control.disabled = true; });
                try { await action(); } catch (error) { status(error.message); }
                finally { controls.forEach(control => { control.disabled = false; }); render(); }
            };
            panel.querySelector('[data-push-enable]').addEventListener('click', () => {
                if (!supported() || !settings.enabled) return;
                // Keep the permission prompt inside the explicit Enable gesture.
                const permission = Notification.requestPermission();
                run(async () => {
                    status('Enabling notifications on this device...');
                    if (await permission !== 'granted') { status('Notifications were not enabled. Check browser site permissions.'); return; }
                    await register();
                    setDeviceDisabled(false);
                    settings.deviceDisabled = false;
                    settings.preferences = (await request('/push/preferences', 'PATCH', { push_enabled: true })).preferences;
                    status('Push notifications are enabled on this device.');
                });
            });
            panel.querySelector('[data-push-later]').addEventListener('click', () => status('No problem. You can enable notifications here anytime.'));
            panel.querySelector('[data-push-disable]').addEventListener('click', () => run(async () => {
                // Wait for any in-flight registration before deleting it.
                if (registrationPending) await registrationPending.catch(() => {});
                await request('/push/subscriptions', 'DELETE');
                setDeviceDisabled(true);
                settings.deviceDisabled = true;
                settings.subscribed = false;
                if (supported() && settings.enabled && Notification.permission === 'granted') {
                    try { const instance = await getMessaging(); await sdk.deleteToken(instance); } catch { /* Server revocation is authoritative. */ }
                }
                status('Notifications are disabled on this device. Other devices keep their settings.');
            }));
            panel.querySelector('[data-push-save]').addEventListener('click', () => run(async () => {
                const preferences = Object.fromEntries([...panel.querySelectorAll('[data-preference]')].map(input => [input.dataset.preference, input.checked]));
                settings.preferences = (await request('/push/preferences', 'PATCH', preferences)).preferences;
                if (automaticRegistration()) await register();
                status(preferences.push_enabled && !settings.subscribed ? 'Preferences saved. Click Enable Notifications to subscribe this device.' : 'Notification preferences saved for your account.');
            }));
            panel.querySelector('[data-push-test]').addEventListener('click', () => run(async () => {
                const button = panel.querySelector('[data-push-test]');
                const feedback = panel.querySelector('[data-push-test-feedback]');
                if (feedback) feedback.hidden = false;
                button.textContent = 'Sending test...';
                status('Sending a test notification to this device...');
                try {
                    const delay = panel.querySelector('[data-push-test-delay]')?.checked ? 15 : 0;
                    const result = await request('/push/test', 'POST', { delay_seconds: delay });
                    status(delay ? 'Test scheduled for 15 seconds from now. You can close the PWA window to test background delivery.' : result.message);
                    if (!result.statusUrl) return;
                    button.textContent = 'Checking delivery...';
                    for (let attempt = 0; attempt < 15 && panel.isConnected; attempt++) {
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        const delivery = await request(result.statusUrl);
                        status(delivery.message);
                        if (['sent', 'failed', 'unsubscribed'].includes(delivery.status)) break;
                    }
                } finally {
                    button.textContent = 'Send Test Notification';
                }
            }));
        });
        // Bind controls first so slow/failed registration never leaves dead buttons.
        if (automaticRegistration()) {
            try { await register(); status('Push notifications are enabled on this device.'); }
            catch (error) { status(error.message); }
            render();
        }
    } catch (error) { status(error.message); }
}
const initializeOnce = () => {
    if (!initializationPending) initializationPending = initialize().finally(() => { initializationPending = null; });
    return initializationPending;
};
// Alpine x-teleport and Livewire can insert the dialog after initial page setup.
// Observe panel insertions only; status text changes must never restart setup.
let panelInitializationQueued = false;
const initializeInsertedPanels = () => {
    if (panelInitializationQueued || !panels().some(panel => !boundPanels.has(panel))) return;
    panelInitializationQueued = true;
    const pending = initializationPending || Promise.resolve();
    pending.finally(() => {
        panelInitializationQueued = false;
        if (panels().some(panel => !boundPanels.has(panel))) initializeOnce();
    });
};
new MutationObserver(records => {
    if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1
        && (node.matches('[data-push-settings]') || node.querySelector('[data-push-settings]'))))) {
        bindDeviceTests();
        initializeInsertedPanels();
    }
}).observe(document.documentElement, { childList: true, subtree: true });
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeOnce, { once: true });
else initializeOnce();
document.addEventListener('livewire:navigated', () => {
    // A navigation may replace settings panels while token registration is pending.
    if (initializationPending) initializationPending.finally(initializeOnce);
    else initializeOnce();
});
navigator.serviceWorker?.addEventListener('message', event => {
    if (event.data?.type === 'TRUTHGUARD_NOTIFICATION') window.dispatchEvent(new Event('truthguard:notification'));
});
