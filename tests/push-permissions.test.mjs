import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const source = readFileSync('resources/js/push-notifications.js', 'utf8');
const markup = `<!doctype html><html><head><meta name="csrf-token" content="test-csrf"><meta name="truthguard-push-settings" content="/push/settings"></head><body>
<section data-push-settings><p data-push-status role="status"></p>
<button data-push-enable disabled>Enable Notifications</button><button data-push-later>Not Now</button>
<button data-push-disable>Disable on this device</button><button data-push-save>Save notification preferences</button>
<button data-push-test hidden>Send Test Notification</button>
<label><input type="checkbox" data-preference="push_enabled">Push Notifications</label>
<label><input type="checkbox" data-preference="analysis_results">Analysis Results</label></section>
<script type="module" src="/push-notifications.js"></script></body></html>`;

for (const permission of ['default', 'denied', 'unsupported']) {
    test(`permission flow: ${permission}`, async () => {
        const browser = await chromium.launch({ headless: true });
        try {
            const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
            const requests = [];
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.addInitScript((value) => {
                window.permissionRequests = 0;
                if (value === 'unsupported') Object.defineProperty(window, 'Notification', { value: undefined });
                else Object.defineProperty(window, 'Notification', { value: { permission: value, requestPermission: async () => { window.permissionRequests++; return 'default'; } } });
                if (value === 'unsupported') delete window.Notification;
            }, permission);
            await page.route('http://localhost:9876/**', async (route) => {
                const path = new URL(route.request().url()).pathname;
                if (path === '/push-notifications.js') return route.fulfill({ contentType: 'text/javascript', body: source });
                if (path.startsWith('/push/')) {
                    requests.push({ path, method: route.request().method(), headers: route.request().headers(), body: route.request().postDataJSON() });
                    const preferences = { push_enabled: false, analysis_results: true };
                    return route.fulfill({ json: path === '/push/settings' ? { enabled: true, firebase: {}, preferences, canTest: false } : { ok: true, preferences } });
                }
                return route.fulfill({ contentType: 'text/html', body: markup });
            });
            await page.goto('http://localhost:9876/profile');
            await page.waitForFunction(() => document.querySelector('[data-push-status]').textContent.length > 0);
            assert.equal(await page.evaluate(() => window.permissionRequests), 0);
            if (permission === 'default') {
                await page.click('[data-push-later]');
                assert.equal(await page.evaluate(() => window.permissionRequests), 0);
                await page.click('[data-push-enable]');
                await page.waitForFunction(() => window.permissionRequests === 1);
                assert.equal(requests.some((request) => request.path === '/push/subscriptions'), false);
                await page.click('[data-push-save]');
                await page.waitForResponse((response) => response.url().endsWith('/push/preferences'));
                const save = requests.find((request) => request.path === '/push/preferences');
                assert.equal(save.method, 'PATCH');
                assert.equal(save.headers['x-csrf-token'], 'test-csrf');
            } else assert.equal(await page.locator('[data-push-enable]').isDisabled(), true);
            assert.deepEqual(errors, []);
        } finally { await browser.close(); }
    });
}

test('granted permission registers the existing root worker and device disable persists', async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        let deviceDisabled = false;
        let preferences = { push_enabled: true, analysis_results: true };
        const requests = [];
        await page.addInitScript(() => {
            window.permissionRequests = 0;
            window.registeredWorkers = [];
            Object.defineProperty(window, 'Notification', { value: { permission: 'granted', requestPermission: async () => { window.permissionRequests++; return 'granted'; } } });
            Object.defineProperty(navigator, 'serviceWorker', { value: {
                ready: Promise.resolve(), addEventListener() {},
                register: async (url, options) => { window.registeredWorkers.push([url, options.scope]); return { scope: 'http://localhost:9876/' }; },
            } });
        });
        await page.route('http://localhost:9876/**', async (route) => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/push-notifications.js') return route.fulfill({ contentType: 'text/javascript', body: source });
            if (path === '/mock-app.js') return route.fulfill({ contentType: 'text/javascript', body: 'export const getApps = () => []; export const initializeApp = () => ({});' });
            if (path === '/mock-messaging.js') return route.fulfill({ contentType: 'text/javascript', body: `export const isSupported = async () => true; export const getMessaging = () => ({}); export const deleteToken = async () => true; export const getToken = async (instance, options) => { if (options.serviceWorkerRegistration.scope !== 'http://localhost:9876/') throw Error('Wrong worker'); return 'example-token'; };` });
            if (path.startsWith('/push/')) {
                requests.push([path, route.request().method()]);
                if (path === '/push/subscriptions' && route.request().method() === 'DELETE') deviceDisabled = true;
                if (path === '/push/preferences') preferences = { ...preferences, ...route.request().postDataJSON() };
                return route.fulfill({ json: path === '/push/settings' ? { enabled: true, firebase: {}, vapidKey: 'test-vapid', preferences, deviceDisabled } : { ok: true, preferences } });
            }
            return route.fulfill({ contentType: 'text/html', body: markup.replace('<script type="module"', '<script type="importmap">{"imports":{"firebase/app":"/mock-app.js","firebase/messaging":"/mock-messaging.js"}}</script><script type="module"') });
        });
        await page.goto('http://localhost:9876/profile');
        await page.waitForFunction(() => document.querySelector('[data-push-status]').textContent.includes('enabled on this device'));
        assert.deepEqual(await page.evaluate(() => window.registeredWorkers), [['/sw.js', '/']]);
        assert.equal(await page.evaluate(() => window.permissionRequests), 0);
        await page.click('[data-push-disable]');
        await page.waitForFunction(() => document.querySelector('[data-push-status]').textContent.includes('disabled on this device'));
        await page.reload();
        await page.waitForFunction(() => document.querySelector('[data-push-status]').textContent.includes('when you are ready'));
        assert.equal(requests.filter(([path, method]) => path === '/push/subscriptions' && method === 'POST').length, 1);
    } finally { await browser.close(); }
});
