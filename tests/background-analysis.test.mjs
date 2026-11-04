import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('resources/views/layouts/partials/background-analysis.blade.php', 'utf8');
const expression = source.split('x-data="')[1].split('\n    }"')[0] + '\n}';
function setup(status) {
    const intervals = new Map();
    const timeouts = new Map();
    let id = 0;
    const window = {
        setInterval(fn) { intervals.set(++id, fn); return id; },
        clearInterval(key) { intervals.delete(key); },
        setTimeout(fn) { timeouts.set(++id, fn); return id; },
        clearTimeout(key) { timeouts.delete(key); },
    };
    const state = vm.runInNewContext(`(${expression.replace(/@js\([^\n]*\)/g, "'test'")})`, {
        window, AbortController,
        fetch: async () => ({ ok: true, status: 200, json: async () => ({ processing_status: status, elapsed_seconds: 20 }) }),
    });
    state.status = 'processing';
    return { state, intervals, timeouts };
}

test('finished check keeps its open-result prompt until acknowledged', async () => {
    const { state, intervals, timeouts } = setup('completed');
    state.startPolling();
    await state.refresh();
    assert.equal(state.showPrompt, true);
    assert.equal(intervals.size, 0);
    assert.equal(timeouts.size, 0);
    assert.equal(state.visible, true);
    state.dismiss();
    assert.equal(state.visible, false);
});

test('closing a terminal report hides the indicator; pending checks stay accessible', () => {
    const { state } = setup('processing');
    state.dismiss();
    assert.equal(state.visible, true);
    state.status = 'failed';
    state.dismiss();
    assert.equal(state.visible, false);
});

test('successful manual reconnect resumes automatic polling', async () => {
    const { state, intervals } = setup('processing');
    state.connectionError = true;
    await state.refresh();
    assert.equal(state.connectionError, false);
    assert.equal(intervals.size, 1);
    state.destroy();
    assert.equal(intervals.size, 0);
});
