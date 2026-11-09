import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { chromium } from 'playwright';

// Real rendered composer and Alpine. All submissions/results are synthetic;
// no user report or external provider is accessed.
const fixture = readFileSync('storage/tmp-create-browser/create.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace('</body>', `<script>
        document.querySelectorAll('style').forEach(style => document.head.append(style.cloneNode(true)));
        document.body.innerHTML = document.querySelector('.truthguard-detection-theme').outerHTML;
        document.documentElement.classList.remove('tg-loading');
        document.body.classList.remove('tg-loading');
    </script><script src="/fixture/alpine.js"></script></body>`);
const root = resolve('public');
const pixel = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
mkdirSync('output/validation/composer-reset', { recursive: true });
try {
    for (const width of [1366, 390]) {
        for (const mode of ['queued', 'redirect', 'cached', 'rejected', 'network']) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(() => {
                window.resetFixture = { mode: 'queued', complete: false, posts: [] };
                window.fetch = async (input, options = {}) => {
                    if (options.method === 'POST') {
                        resetFixture.posts.push({ caption: options.body.get('caption_text'), task: options.body.get('analysis_task'), file: options.body.get('media_file')?.name });
                        if (resetFixture.mode === 'network') throw new TypeError('Synthetic connection failure');
                        if (resetFixture.mode === 'rejected') return { ok: false, redirected: false, status: 422,
                            headers: new Headers({ 'content-type': 'application/json' }), json: async () => ({ errors: { caption_text: ['Synthetic validation failure'] } }) };
                        if (resetFixture.mode === 'redirect') return { redirected: true, url: 'http://127.0.0.1:8000/detections/999999/result' };
                        if (resetFixture.mode === 'cached') return { ok: true, redirected: false, status: 200,
                            headers: new Headers({ 'content-type': 'application/json' }),
                            json: async () => ({ processing_status: 'completed', result_url: '/detections/999999/result', reused: true }) };
                        return { ok: true, redirected: false, status: 202, json: async () => ({ result_url: '/detections/999999/result' }) };
                    }
                    return { ok: true, status: 200, json: async () => ({ processing_status: resetFixture.complete ? 'completed' : 'processing', stage: 'Synthetic evidence review' }) };
                };
            });
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/fixture/create') return route.fulfill({ contentType: 'text/html', body: fixture });
                if (url.pathname === '/fixture/alpine.js') return route.fulfill({ contentType: 'text/javascript', body: readFileSync('node_modules/alpinejs/dist/cdn.js') });
                if (url.pathname === '/detections/999999/result') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Synthetic result</title><p>Completed synthetic report</p>' });
                const local = resolve(root, '.' + url.pathname);
                if (url.origin === 'http://127.0.0.1:8000' && local.startsWith(root + sep) && existsSync(local) && statSync(local).isFile()) {
                    return route.fulfill({ body: readFileSync(local), contentType: local.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
                }
                return route.fulfill({ status: 204 });
            });
            await page.goto('http://127.0.0.1:8000/fixture/create');
            await page.waitForFunction(() => !!document.querySelector('.truthguard-detection-shell')._x_dataStack);
            await page.evaluate(mode => { resetFixture.mode = mode; }, mode);
            const field = page.locator('#truthguard-message');
            await field.fill('Synthetic draft that should clear after submission.');
            await page.locator('.truthguard-guided-prompt-card').nth(1).dispatchEvent('click');
            await page.locator('[name=media_file]').setInputFiles({ name: 'fixture.png', mimeType: 'image/png', buffer: pixel });
            const original = await field.inputValue();
            await page.locator('.truthguard-detection-composer button[aria-label="Check claim"]:visible').dispatchEvent('click');

            if (mode === 'rejected' || mode === 'network') {
                await page.waitForFunction(() => !!Alpine.$data(document.querySelector('.truthguard-detection-shell')).composerError);
                assert.equal(await field.inputValue(), original);
                assert.equal(await page.evaluate(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).hasFile), true);
                await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
                assert.equal(await field.inputValue(), original, 'Unsubmitted drafts survive restoration');
            } else {
                if (mode === 'queued') {
                    await page.waitForFunction(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).submissionAccepted);
                    assert.equal(await field.inputValue(), '');
                    const submitted = await page.evaluate(() => resetFixture.posts[0]);
                    assert.equal(submitted.caption, original, 'Clear only after the original data was sent');
                    assert.equal(submitted.file, 'fixture.png');
                    await page.evaluate(() => { resetFixture.complete = true; Alpine.$data(document.querySelector('.truthguard-detection-shell')).refreshPendingResult(); });
                }
                await page.waitForURL('**/detections/999999/result');
                await page.goBack({ waitUntil: 'domcontentloaded' });
                await page.waitForFunction(() => !!document.querySelector('.truthguard-detection-shell')._x_dataStack);
                assert.equal(await field.inputValue(), '', 'Browser Back opens a blank composer');
                const restored = await page.evaluate(() => {
                    const state = Alpine.$data(document.querySelector('.truthguard-detection-shell'));
                    return { value: state.composerValue, guide: state.selectedGuide, task: state.analysisTask, file: state.hasFile, submitting: state.submitting, canAnalyze: state.canAnalyze() };
                });
                assert.deepEqual(restored, { value: '', guide: null, task: 'auto', file: false, submitting: false, canAnalyze: false });
                await field.fill('A new unsent draft should stay.');
                await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
                assert.equal(await field.inputValue(), 'A new unsent draft should stay.');
                if (mode === 'queued') {
                    await field.fill('');
                    await page.screenshot({ path: `output/validation/composer-reset/blank-${width}.png` });
                }
            }
            assert.deepEqual(errors, []);
            console.log(`Composer reset passed: ${mode}, ${width}px.`);
            await page.close();
        }
    }
} finally {
    await browser.close();
}
