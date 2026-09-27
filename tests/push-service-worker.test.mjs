import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';

function worker(windows = []) {
    const listeners = {};
    const shown = [];
    const opened = [];
    const self = {
        location: { origin: 'https://truthguard.online' },
        addEventListener: (name, callback) => { listeners[name] = callback; },
        registration: { showNotification: async (...args) => shown.push(args) },
        clients: { matchAll: async () => windows, openWindow: async (url) => opened.push(url) },
    };
    const context = vm.createContext({ self, URL, Date, Number });
    vm.runInContext(readFileSync('public/sw.js', 'utf8'), context);
    return { listeners, shown, opened, context };
}

test('FCM data displays one system notification and refreshes open clients', async () => {
    const messages = [];
    const w = worker([{ postMessage: (message) => messages.push(message) }]);
    let done;
    w.listeners.push({ data: { json: () => ({ data: { notification_id: 'abc', title: 'TruthGuard', body: 'Analysis complete.', url: '/detections/42/result', timestamp: '12345' } }) }, waitUntil: (promise) => { done = promise; } });
    await done;
    assert.equal(w.shown.length, 1);
    assert.equal(w.shown[0][1].data.url, 'https://truthguard.online/detections/42/result');
    assert.equal(w.shown[0][1].tag, 'truthguard-abc');
    assert.equal(messages.length, 1);
});

test('click reuses an existing app window', async () => {
    let navigated, focused = false, done;
    const client = { url: 'https://truthguard.online/dashboard', navigate: async (url) => { navigated = url; return client; }, focus: async () => { focused = true; } };
    const w = worker([client]);
    w.listeners.notificationclick({ notification: { close() {}, data: { url: '/detections/42/result' } }, waitUntil: (promise) => { done = promise; } });
    await done;
    assert.equal(navigated, 'https://truthguard.online/detections/42/result');
    assert.equal(focused, true);
    assert.equal(w.opened.length, 0);
});

test('external and unsafe notification URLs fall back to the app center', async () => {
    for (const url of ['https://evil.example', '//evil.example', 'javascript:alert(1)', '/logout', '/notifications?next=https://evil.example']) {
        const w = worker();
        let done;
        w.listeners.notificationclick({ notification: { close() {}, data: { url } }, waitUntil: (promise) => { done = promise; } });
        await done;
        assert.equal(w.opened[0], 'https://truthguard.online/notifications');
    }
});

test('existing PWA caching handlers remain registered and push endpoints stay private', () => {
    const w = worker();
    for (const event of ['install', 'activate', 'fetch', 'message']) assert.equal(typeof w.listeners[event], 'function');
    assert.equal(vm.runInContext("isPrivatePath('/push/settings')", w.context), true);
});
