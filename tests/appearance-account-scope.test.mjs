import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const template = readFileSync('resources/views/layouts/partials/appearance-preferences.blade.php', 'utf8');
function boot(scope, storage) {
    const classes = new Set();
    const root = { dataset: {}, style: {}, classList: {
        contains: name => classes.has(name),
        toggle: (name, active) => active ? classes.add(name) : classes.delete(name),
    } };
    const events = {};
    const window = { matchMedia: () => ({ matches: false, addEventListener() {} }),
        addEventListener: (name, handler) => { events[name] = handler; }, dispatchEvent() {} };
    const script = template.match(/<script>([\s\S]*)<\/script>/)[1]
        .replace('@js($appearanceScope)', JSON.stringify(scope))
        .replace('@js((bool) ($publicAppearance ?? false))', scope === 'guest' ? 'true' : 'false');
    runInNewContext(script, { document: { documentElement: root }, window,
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        CustomEvent: class {}, });
    return { root, appearance: window.TruthGuardAppearance, events };
}

test('account and guest preferences stay isolated across login and logout', () => {
    const storage = new Map([['truthguard-display-mode', 'dark']]);
    const userA = boot('user:1', storage);
    userA.appearance.set({ mode: 'dark', textSize: 'large' });
    const userB = boot('user:2', storage);
    assert.equal(userB.appearance.preferences.mode, 'system');
    assert.equal(userB.root.classList.contains('dark'), false);
    userB.events.storage({ key: 'truthguard-appearance:user:1:mode' });
    assert.equal(userB.appearance.preferences.mode, 'system');
    const guest = boot('guest', storage);
    assert.equal(guest.root.classList.contains('dark'), false);
    guest.appearance.set({ mode: 'light' });
    assert.equal(boot('user:1', storage).root.classList.contains('dark'), true);
    assert.equal(boot('user:2', storage).root.classList.contains('dark'), false);
});
