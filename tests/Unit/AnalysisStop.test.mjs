import test from 'node:test';
import assert from 'node:assert/strict';
import { stopAnalysis } from '../../resources/js/analysis-stop.js';

function fixture(confirm = async () => true) {
    const requests = [];
    return { requests, environment: {
        location: { href: 'https://truthguard.test/history', origin: 'https://truthguard.test' },
        document: { querySelector: () => ({ content: 'csrf-fixture' }) },
        TruthGuardDialog: { confirm }, setTimeout, clearTimeout,
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: true, json: async () => ({ cancelled: true, processing_status: 'failed' }) };
        },
    } };
}

test('dismissing the confirmation leaves the check running and sends no stop request', async () => {
    const { environment, requests } = fixture(async () => false);
    let stopped = false;
    assert.equal(await stopAnalysis('/detections/108/stop', { onConfirmed: () => { stopped = true; } }, environment), null);
    assert.equal(stopped, false);
    assert.equal(requests.length, 0);
});

test('opening Stop waits for confirmation and does not stop automatically', async () => {
    let choose;
    let confirmed = false;
    const { environment, requests } = fixture(() => new Promise(resolve => { choose = resolve; }));
    const pending = stopAnalysis('/detections/108/stop', { onConfirmed: () => { confirmed = true; } }, environment);
    assert.equal(requests.length, 0);
    assert.equal(confirmed, false);
    choose(true);
    assert.equal((await pending).cancelled, true);
    assert.equal(confirmed, true);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, 'https://truthguard.test/detections/108/stop');
    assert.equal(requests[0].options.method, 'POST');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'csrf-fixture');
    assert.deepEqual(JSON.parse(requests[0].options.body), { confirmed: true });
});

test('a failed confirmation UI or a foreign URL cannot silently stop a check', async () => {
    const { environment, requests } = fixture();
    await assert.rejects(stopAnalysis('https://other.test/stop', {}, environment));
    delete environment.TruthGuardDialog;
    await assert.rejects(stopAnalysis('/detections/108/stop', {}, environment));
    assert.equal(requests.length, 0);
});

test('server rejection is not reported as a successful stop and is never retried', async () => {
    const { environment, requests } = fixture();
    environment.fetch = async () => { requests.push({}); return { ok: false, status: 419 }; };
    await assert.rejects(stopAnalysis('/detections/108/stop', {}, environment), /could not be confirmed/);
    assert.equal(requests.length, 1);
});
