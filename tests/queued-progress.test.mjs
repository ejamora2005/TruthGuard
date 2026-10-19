import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

// Exercise the actual Alpine submission methods and existing loading controls.
const source = readFileSync('resources/views/livewire/detections/ai-composer.blade.php', 'utf8').replace(/\r\n/g, '\n');
const start = source.lastIndexOf('<section', source.indexOf('class="truthguard-detection-shell'));
const template = source.slice(start, source.indexOf('\n        <div', start));
const modalStart = source.indexOf('<template x-teleport="body">', source.indexOf('@submit="handleSubmit($event)"'));
const modal = source.slice(modalStart, source.indexOf('<input type="hidden" name="weather_consent"', modalStart))
    .replaceAll('{{ $truthguardLogoUrl }}', 'data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27/%3E');
const restore = source.match(/<button x-show="submitting && \(progressHidden \|\| progressMinimized\)"[^>]*>[\s\S]*?<\/button>/)[0];
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const origin = 'http://localhost:9876';
const resultUrl = `${origin}/detections/24/result`;
let browser;
before(async () => { browser = await chromium.launch({ channel: 'chrome', headless: true }); });
after(async () => { await browser?.close(); });

async function setup({ resume = false, mobile = false } = {}) {
    const values = new Map([
        ['$mediaUploadMaxMb', '20'], ['$mediaUploadMaxBytes', '20971520'],
        ['$initialUploadError ?: null', 'null'], ['$composerDraft', "'The city cancelled classes today.'"],
        ['$claimMaxCharacters', '4000'], ['$initialComposerError ?: null', 'null'],
        ["old('analysis_task', 'auto')", "'auto'"], ['$shortcutPrompts', '[]'],
        ["in_array(old('analysis_task'), ['media', 'claim'], true) ? old('analysis_task') : null", 'null'],
        ["$oldSourceUrl !== '' ? 'link' : null", 'null'],
        ['$googleDrivePicker', '{ enabled: false }'], ['(bool) $activeDetection', 'false'],
        ['$pendingResultUrl', resume ? `'${resultUrl}'` : 'null'], ['$pendingMediaType', "'image'"],
    ]);
    const opening = template.replace(/@js\((.*)\),/g, (_, expression) => {
        assert.ok(values.has(expression), `Missing fixture for ${expression}`);
        return values.get(expression) + ',';
    });
    const page = await browser.newPage({ viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 800 } });
    const state = { posts: 0, polls: 0, navigations: 0, status: 'queued', transientFailure: false, errors: [] };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route(`${origin}/**`, async route => {
        const request = route.request();
        if (request.method() === 'POST') {
            state.posts++;
            return route.fulfill({ status: 202, json: { processing_status: 'queued', result_url: resultUrl } });
        }
        if (request.url() === resultUrl) {
            if (request.isNavigationRequest()) {
                state.navigations++;
                return route.fulfill({ contentType: 'text/html', body: '<h1>Completed result</h1>' });
            }
            state.polls++;
            if (state.transientFailure) return route.fulfill({ status: 503, body: 'Temporarily unavailable' });
            return route.fulfill({ json: state.status === 'completed'
                ? { verdict: 'LIKELY_AI_GENERATED' }
                : { processing_status: state.status, stage: 'Analyzing image' } });
        }
        return route.fulfill({ contentType: 'text/html', body: `<html><head><style>
            [x-cloak]{display:none!important}.truthguard-inline-progress{position:fixed;inset:10%;background:white;border:1px solid #999}
            .truthguard-verify-loader{display:none}.truthguard-progress-control{min-width:44px;min-height:44px}
            </style></head><body>${opening}
            <form action="${origin}/detections" @submit="handleSubmit($event)">
            <input name="caption_text" value="The city cancelled classes today.">
            <button type="submit" :disabled="submitting">Check claim</button>
            ${modal}${restore}<p role="alert" x-text="composerError"></p>
            </form></section><script>${alpine}</script></body></html>` });
    });
    await page.goto(`${origin}/detections/create${resume ? '?pending=24' : ''}`);
    return { page, state };
}

async function refresh(page, state) {
    const previous = state.polls;
    await page.evaluate(() => {
        const data = document.querySelector('.truthguard-detection-shell')._x_dataStack[0];
        window.clearTimeout(data.progressTimer);
        return data.refreshPendingResult();
    });
    assert.equal(state.polls, previous + 1);
}

for (const mobile of [false, true]) {
    test(`queued submission stays in the closable loader until ready (${mobile ? 'mobile' : 'desktop'})`, async () => {
        const { page, state } = await setup({ mobile });
        try {
            await page.getByRole('button', { name: 'Check claim', exact: true }).click();
            await page.locator('.truthguard-verify-copy').filter({ hasText: 'Your analysis is starting' }).waitFor();
            assert.equal(state.posts, 1);
            assert.equal(state.navigations, 0);
            assert.equal(page.url(), `${origin}/detections/create`);
            await page.getByRole('button', { name: 'Hide progress', exact: true }).click();
            await page.locator('.truthguard-inline-progress').waitFor({ state: 'hidden' });
            state.status = 'processing';
            await refresh(page, state);
            assert.equal(state.posts, 1);
            await page.locator('.truthguard-progress-restore').click();
            await page.locator('.truthguard-inline-progress').waitFor({ state: 'visible' });
            await page.getByRole('button', { name: 'Minimize progress', exact: true }).click();
            await page.locator('.truthguard-inline-progress').waitFor({ state: 'hidden' });
            state.status = 'completed';
            await refresh(page, state);
            await page.waitForURL(resultUrl);
            assert.equal(state.navigations, 1);
            assert.equal(state.posts, 1);
            assert.deepEqual(state.errors, []);
        } finally { await page.close(); }
    });
}

test('opening an unfinished report resumes polling without resubmission', async () => {
    const { page, state } = await setup({ resume: true });
    try {
        await page.locator('.truthguard-inline-progress').waitFor({ state: 'visible' });
        await page.locator('.truthguard-verify-copy').filter({ hasText: 'Your analysis is starting' }).waitFor();
        assert.equal(state.polls, 1); // Alpine auto-init plus x-init must not start two polls.
        assert.equal(state.posts, 0);
        state.status = 'completed';
        await refresh(page, state);
        await page.waitForURL(resultUrl);
        assert.deepEqual(state.errors, []);
    } finally { await page.close(); }
});

test('temporary status failures reconnect; a failed analysis stops the loader without opening a result', async () => {
    const { page, state } = await setup({ resume: true });
    try {
        await page.locator('.truthguard-verify-copy').filter({ hasText: 'Your analysis is starting' }).waitFor();
        state.transientFailure = true;
        await refresh(page, state);
        assert.match(await page.locator('.truthguard-verify-copy').innerText(), /Reconnecting/);
        state.transientFailure = false;
        state.status = 'failed';
        await refresh(page, state);
        await page.locator('.truthguard-inline-progress').waitFor({ state: 'hidden' });
        assert.match(await page.getByRole('alert').innerText(), /No verdict was produced/);
        assert.equal(await page.getByRole('button', { name: 'Check claim', exact: true }).isEnabled(), true);
        assert.equal(state.posts, 0);
        assert.equal(state.navigations, 0);
        assert.deepEqual(state.errors, []);
    } finally { await page.close(); }
});
