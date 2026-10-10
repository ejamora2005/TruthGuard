import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function helper(register) {
    const timers = new Map();
    let sequence = 0;
    const context = vm.createContext({
        URL, location: { href: 'https://truthguard.online/profile' },
        navigator: { serviceWorker: { register } },
        setTimeout: callback => { timers.set(++sequence, callback); return sequence; },
        clearTimeout: id => timers.delete(id),
    });
    vm.runInContext(readFileSync('resources/js/service-worker.js', 'utf8').replace('export function', 'function'), context);
    return { get: () => vm.runInContext('getTruthGuardWorker()', context), timers };
}

test('PWA and push callers share one root registration without global ready', async () => {
    const calls = [];
    const registration = { scope: 'https://truthguard.online/', active: {} };
    const h = helper(async (...args) => { calls.push(args); return registration; });
    const first = h.get();
    assert.equal(first, h.get());
    assert.equal(await first, registration);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][0], '/sw.js');
    assert.equal(calls[0][1].updateViaCache, 'none');
    assert.equal(h.timers.size, 0);
});

test('failed installation is actionable and can retry', async () => {
    let calls = 0;
    const h = helper(async () => {
        if (++calls === 1) throw Error('Installation failed');
        return { scope: 'https://truthguard.online/', active: {} };
    });
    await assert.rejects(h.get(), /Installation failed/);
    await h.get();
    assert.equal(calls, 2);
});

test('hung registration times out instead of leaving Enable pending forever', async () => {
    const h = helper(() => new Promise(() => {}));
    const result = assert.rejects(h.get(), /did not become ready/);
    for (const callback of h.timers.values()) callback();
    await result;
});

test('nested scope cannot silently replace the root push worker', async () => {
    const h = helper(async () => ({ scope: 'https://truthguard.online/profile/', active: {} }));
    await assert.rejects(h.get(), /Unexpected.*scope/);
});
