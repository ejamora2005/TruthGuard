// Configuration is fetched at runtime so Dokploy secrets are never bundled.
let settings;
let messaging;
let sdk;
let initialized = false;
const boundPanels = new WeakSet();
const deviceDisabled = () => { try { return localStorage.getItem('truthguard:push-disabled') === '1'; } catch { return false; } };
const setDeviceDisabled = (disabled) => { try { localStorage.setItem('truthguard:push-disabled', disabled ? '1' : '0'); } catch { /* Browser storage unavailable. */ } };
const panels = () => [...document.querySelectorAll('[data-push-settings]')];
const status = (text) => panels().forEach((panel) => { panel.querySelector('[data-push-status]').textContent = text; });
const supported = () => window.isSecureContext && 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
const request = async (path, method = 'GET', body) => {
    const response = await fetch(path, {
        method, credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || 'Unable to save notifications. Reconnect or sign in again.');
    return result;
};
const getMessaging = async () => {
    if (messaging) return messaging;
    const [{ initializeApp, getApps }, messagingSdk] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
    sdk = messagingSdk;
    if (!await sdk.isSupported()) throw new Error('Push notifications are not supported by this browser.');
    const app = getApps().find((app) => app.name === 'truthguard-push') || initializeApp(settings.firebase, 'truthguard-push');
    messaging = sdk.getMessaging(app);
    return messaging;
};
const register = async () => {
    if (!supported() || Notification.permission !== 'granted' || !settings.enabled) return;
    const instance = await getMessaging();
    // Reuse the root PWA registration. Never register firebase-messaging-sw.js.
    const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
    await navigator.serviceWorker.ready;
    const token = await sdk.getToken(instance, { vapidKey: settings.vapidKey, serviceWorkerRegistration: registration });
    if (!token) throw new Error('Firebase did not provide a subscription. Please try again.');
    await request('/push/subscriptions', 'POST', { token });
};
const render = () => {
    panels().forEach((panel) => {
        panel.querySelectorAll('[data-preference]').forEach((input) => { input.checked = !!settings.preferences[input.dataset.preference]; });
        const enable = panel.querySelector('[data-push-enable]');
        enable.disabled = !settings.enabled || !supported() || Notification.permission === 'denied';
        panel.querySelector('[data-push-test]').hidden = !settings.canTest;
    });
};
async function initialize() {
    if (initialized || !document.querySelector('meta[name="truthguard-push-settings"]')) return;
    initialized = true;
    try {
        settings = await request('/push/settings');
        render();
        if (!settings.enabled) status('Push notifications are awaiting Firebase configuration. In-app notifications remain available.');
        else if (!supported()) status('Push notifications require HTTPS and a supported browser/device.');
        else if (Notification.permission === 'denied') {
            status('Notifications are blocked. Change this site notification permission in your browser settings to enable them.');
            await request('/push/subscriptions', 'DELETE');
        } else if (Notification.permission === 'granted' && settings.preferences.push_enabled && !settings.deviceDisabled && !deviceDisabled()) {
            try {
                await register();
                status('Push notifications are enabled on this device.');
            } catch (error) { status(error.message); }
        } else status('Enable notifications when you are ready.');
        panels().forEach((panel) => {
            if (boundPanels.has(panel)) return;
            boundPanels.add(panel);
            const run = async (action) => {
                const buttons = panel.querySelectorAll('button, input');
                buttons.forEach((button) => { button.disabled = true; });
                try { await action(); } catch (error) { status(error.message); }
                finally { buttons.forEach((button) => { button.disabled = false; }); render(); }
            };
            panel.querySelector('[data-push-enable]').addEventListener('click', () => {
                // Request immediately in the user gesture, before network or SDK loading.
                const permission = Notification.requestPermission();
                run(async () => {
                    if (await permission !== 'granted') { status('Notifications were not enabled. You can change permission in browser settings.'); return; }
                    await register();
                    setDeviceDisabled(false);
                    settings.deviceDisabled = false;
                    settings.preferences = (await request('/push/preferences', 'PATCH', { push_enabled: true })).preferences;
                    status('Push notifications are enabled on this device.');
                });
            });
            panel.querySelector('[data-push-later]').addEventListener('click', () => { status('No problem. You can enable notifications here anytime.'); });
            panel.querySelector('[data-push-disable]').addEventListener('click', () => run(async () => {
                // Server removal first, even if Firebase/browser storage is unavailable.
                await request('/push/subscriptions', 'DELETE');
                setDeviceDisabled(true);
                settings.deviceDisabled = true;
                if (supported() && settings.enabled && Notification.permission === 'granted') {
                    const instance = await getMessaging();
                    await sdk.deleteToken(instance);
                }
                status('Notifications are disabled on this device. Other devices keep their settings.');
            }));
            panel.querySelector('[data-push-save]').addEventListener('click', () => run(async () => {
                const preferences = Object.fromEntries([...panel.querySelectorAll('[data-preference]')].map((input) => [input.dataset.preference, input.checked]));
                settings.preferences = (await request('/push/preferences', 'PATCH', preferences)).preferences;
                status('Notification preferences saved for your account.');
            }));
            panel.querySelector('[data-push-test]').addEventListener('click', () => run(async () => { status((await request('/push/test', 'POST')).message); }));
        });
    } catch (error) { status(error.message); initialized = false; }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
else initialize();
document.addEventListener('livewire:navigated', () => { initialized = false; initialize(); });
navigator.serviceWorker?.addEventListener('message', (event) => {
    if (event.data?.type === 'TRUTHGUARD_NOTIFICATION') window.dispatchEvent(new Event('truthguard:notification'));
});
