import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

test('composer elapsed time advances between polls and catches up after a background pause', () => {
    const source = readFileSync('resources/views/livewire/detections/ai-composer.blade.php', 'utf8');
    const method = source.slice(source.indexOf('            trackPendingResult(url) {'), source.indexOf('            async refreshPendingResult()'));
    let now = 100000;
    let tick;
    const state = vm.runInNewContext(`({ ${method} refreshPendingResult() {} })`, {
        Date: { now: () => now },
        window: { clearTimeout() {}, clearInterval() {}, setInterval(fn) { tick = fn; return 1; } },
    });
    state.trackPendingResult('/detections/1/result');
    now += 1000;
    tick();
    assert.equal(state.progressElapsed, 1);
    now += 29000;
    tick();
    assert.equal(state.progressElapsed, 30);
    state.trackPendingResult('/detections/2/result');
    assert.equal(state.progressElapsed, 0);
});
