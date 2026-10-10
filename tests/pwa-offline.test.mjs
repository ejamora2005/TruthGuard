import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function worker(caches, fetch) {
    const handlers = {};
    const self = { location: { origin: 'https://truthguard.online' }, addEventListener: (name, handler) => { handlers[name] = handler; }, skipWaiting() {} };
    vm.runInNewContext(readFileSync('public/sw.js', 'utf8'), { self, caches, fetch, URL, Response, Promise, console });
    return handlers;
}

test('one unavailable icon does not prevent offline document installation', async () => {
    const saved = [];
    const handlers = worker({ open: async () => ({ add: async url => {
        if (url === '/favicon.ico') throw Error('404');
        saved.push(url);
    } }) });
    let pending;
    handlers.install({ waitUntil: value => { pending = value; } });
    await pending;
    assert.ok(saved.includes('/offline.html'));
    assert.ok(saved.includes('/images/truthguard-logo-transparent.png'));
});

test('cached assets refresh within the fetch event lifetime', async () => {
    const writes = [];
    const handlers = worker({ open: async () => ({ match: async () => new Response('cached'), put: async (key, response) => writes.push(await response.text()) }) }, async () => {
        const response = new Response('fresh');
        Object.defineProperty(response, 'type', { value: 'basic' });
        return response;
    });
    let response, pending;
    handlers.fetch({ request: new Request('https://truthguard.online/build/app.js'), respondWith: value => { response = value; }, waitUntil: value => { pending = value; } });
    assert.ok(pending);
    assert.equal(await (await response).text(), 'cached');
    await pending;
    assert.deepEqual(writes, ['fresh']);
});

test('unavailable cache storage still serves network assets and an offline fallback', async () => {
    const caches = { open: async () => { throw Error('Storage unavailable'); }, match: async () => { throw Error('Storage unavailable'); } };
    let offline = false;
    const handlers = worker(caches, async () => { if (offline) throw Error('Offline'); return new Response('network'); });
    let response, pending;
    handlers.fetch({ request: new Request('https://truthguard.online/build/app.js'), respondWith: value => { response = value; }, waitUntil: value => { pending = value; } });
    assert.equal(await (await response).text(), 'network');
    await pending;
    offline = true;
    handlers.fetch({ request: { method: 'GET', url: 'https://truthguard.online/profile', mode: 'navigate' }, respondWith: value => { response = value; } });
    assert.equal((await response).status, 503);
    assert.match(await (await response).text(), /Reconnect/);
});
