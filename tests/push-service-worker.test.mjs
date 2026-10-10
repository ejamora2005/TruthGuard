import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { test } from 'node:test';
const id = '12345678-1234-1234-1234-123456789abc';
const data = { notification_id: id, subscription_id: '1', title: 'TruthGuard', body: 'Analysis complete.', url: `/notifications/${id}`, timestamp: '12345' };
function worker(windows = [], authorize = async () => data, receipts = new Map()) {
    const listeners = {}, shown = [], opened = [];
    const self = {
        location: { origin: 'https://truthguard.online' },
        addEventListener: (name, callback) => { listeners[name] = callback; },
        registration: { showNotification: async (...args) => shown.push(args) },
        clients: { matchAll: async () => windows, openWindow: async url => opened.push(url) },
    };
    const caches = { open: async () => ({
        match: async key => receipts.get(key), put: async (key, value) => receipts.set(key, value),
        keys: async () => [...receipts.keys()], delete: async key => receipts.delete(key),
    }) };
    const context = vm.createContext({ self, caches, URL, Date, Number, Response, AbortSignal, console,
        fetch: async () => { const result = await authorize(); return { ok: !!result, json: async () => result }; } });
    vm.runInContext(readFileSync('public/sw.js', 'utf8'), context);
    const push = async (incoming = data) => {
        let done;
        listeners.push({ data: { json: () => ({ data: incoming }) }, waitUntil: promise => { done = promise; } });
        await done;
    };
    return { listeners, shown, opened, context, push };
}
test('data-only push displays once and updates open clients', async () => {
    const messages = [];
    const w = worker([{ postMessage: message => messages.push(message) }]);
    await w.push();
    assert.equal(w.shown.length, 1);
    assert.equal(w.shown[0][1].data.url, `https://truthguard.online/notifications/${id}`);
    assert.equal(messages.length, 1);
});
test('background push works without an open page and retries stay deduplicated across restarts', async () => {
    const receipts = new Map();
    const first = worker([], async () => data, receipts);
    await Promise.all([first.push(), first.push()]);
    assert.equal(first.shown.length, 1);
    const restarted = worker([], async () => data, receipts);
    await restarted.push();
    assert.equal(restarted.shown.length, 0);
});
test('revoked, wrong-device, and unavailable authorization never display stale account messages', async () => {
    for (const authorize of [async () => null, async () => ({ ...data, subscription_id: '2' }), async () => { throw Error('Offline'); }]) {
        const w = worker([], authorize);
        await w.push();
        assert.equal(w.shown.length, 0);
    }
});
test('click validates ownership again and focuses the correct page', async () => {
    let navigated, focused = false, done;
    const client = { url: 'https://truthguard.online/dashboard', navigate: async url => { navigated = url; return client; }, focus: async () => { focused = true; } };
    const w = worker([client]);
    w.listeners.notificationclick({ notification: { close() {}, data: { notificationId: id } }, waitUntil: promise => { done = promise; } });
    await done;
    assert.equal(navigated, `https://truthguard.online/notifications/${id}`);
    assert.equal(focused, true);
    assert.equal(w.opened.length, 0);
});
test('unsafe and revoked notification clicks fall back to the protected center', async () => {
    for (const url of ['https://evil.example', '//evil.example', 'javascript:alert(1)', '/logout', '/notifications?next=https://evil.example', null]) {
        const w = worker([], async () => url ? { ...data, url } : null);
        let done;
        w.listeners.notificationclick({ notification: { close() {}, data: { notificationId: id } }, waitUntil: promise => { done = promise; } });
        await done;
        assert.equal(w.opened[0], 'https://truthguard.online/notifications');
    }
});
test('offline caching handlers remain registered and push endpoints stay private', () => {
    const w = worker();
    for (const event of ['install', 'activate', 'fetch', 'message']) assert.equal(typeof w.listeners[event], 'function');
    assert.equal(vm.runInContext("isPrivatePath('/push/settings')", w.context), true);
    assert.equal(vm.runInContext("isPrivatePath('/push/deliveries/test')", w.context), true);
});
