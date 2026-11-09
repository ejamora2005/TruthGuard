import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { chromium } from 'playwright';

// Render with render-create-workspace.php first. Exercise the real Blade,
// Alpine and stop client using a synthetic check; no user check is cancelled.
const fixture = readFileSync('storage/tmp-create-browser/create.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const stopClient = readFileSync('resources/js/analysis-stop.js', 'utf8').replace('export async function', 'async function');
const root = resolve('public');
const screenshots = 'output/validation/composer-stop';
mkdirSync(screenshots, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
try {
    for (const width of [1366, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.origin === 'http://127.0.0.1:8000' && url.pathname === '/fixture/create') {
                return route.fulfill({ contentType: 'text/html', body: fixture });
            }
            const local = resolve(root, '.' + url.pathname);
            if (url.origin === 'http://127.0.0.1:8000' && local.startsWith(root + sep) && existsSync(local) && statSync(local).isFile()) {
                return route.fulfill({ body: readFileSync(local), contentType: local.endsWith('.css') ? 'text/css' : 'application/octet-stream' });
            }
            return route.fulfill({ status: 204 });
        });
        await page.goto('http://127.0.0.1:8000/fixture/create');
        await page.evaluate(() => {
            document.querySelectorAll('style').forEach(style => document.head.append(style.cloneNode(true)));
            document.body.innerHTML = document.querySelector('.truthguard-detection-theme').outerHTML;
            document.documentElement.classList.remove('tg-loading');
            document.body.classList.remove('tg-loading');
        });
        await page.addScriptTag({ content: stopClient });
        await page.addScriptTag({ path: 'node_modules/alpinejs/dist/cdn.js' });
        await page.waitForFunction(() => !!document.querySelector('.truthguard-detection-shell')._x_dataStack);
        const button = page.locator('.truthguard-detection-composer button[aria-label="Stop fact check"]:visible');
        await page.evaluate(() => {
            const state = Alpine.$data(document.querySelector('.truthguard-detection-shell'));
            state.composerValue = 'A synthetic claim for the stop control.';
            state.$refs.composerInput.value = state.composerValue;
            state.submitting = true;
            state.progressHidden = true;
            window.stopRequests = [];
            window.TruthGuardDialog = { confirm: () => new Promise(resolve => { window.answerStop = resolve; }) };
            window.fetch = (url, options) => {
                window.stopRequests.push({ url, options });
                return new Promise(resolve => { window.finishStop = () => resolve({ ok: true, json: async () => ({ cancelled: true }) }); });
            };
        });
        await button.waitFor({ state: 'visible' });
        assert.equal(await button.isDisabled(), true, 'Wait for a saved check before enabling Stop');
        await page.evaluate(() => { Alpine.$data(document.querySelector('.truthguard-detection-shell')).pendingResultUrl = '/detections/999999/result'; });
        await page.waitForFunction(() => {
            const buttons = [...document.querySelectorAll('.truthguard-composer-stop')];
            return buttons.length === 2 && buttons.every(button => !button.disabled);
        }, null, { timeout: 5000 });
        assert.equal(await button.count(), 1, 'One visible composer Stop per viewport');
        await page.waitForFunction(() => [...document.querySelectorAll('.truthguard-composer-stop')]
            .filter(element => element.getClientRects().length > 0)
            .every(element => getComputedStyle(element).color === 'rgb(190, 18, 60)'), null, { timeout: 5000 });
        assert.deepEqual(await button.evaluate(element => ({ color: getComputedStyle(element).color, background: getComputedStyle(element).backgroundColor })),
            { color: 'rgb(190, 18, 60)', background: 'rgb(255, 241, 242)' }, 'Stop must have a visible red icon on a pale red button');
        assert.equal(await button.evaluate(element => !!element.closest('[inert]')), false);
        assert.equal(await page.locator('#truthguard-message').evaluate(element => !!element.closest('[inert]')), true);
        await page.screenshot({ path: `${screenshots}/loading-${width}.png` });

        // The visible Stop opens confirmation, with no request before acceptance.
        await button.dispatchEvent('click');
        await page.waitForFunction(() => typeof window.answerStop === 'function');
        assert.equal(await page.evaluate(() => window.stopRequests.length), 0);
        await page.evaluate(() => window.answerStop(false));
        await page.waitForFunction(() => !Alpine.$data(document.querySelector('.truthguard-detection-shell')).stopPromptOpen);
        assert.equal(await button.isDisabled(), false);
        assert.equal(await page.evaluate(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).submitting), true);

        await button.dispatchEvent('click');
        await page.evaluate(() => window.answerStop(true));
        await page.waitForFunction(() => window.stopRequests.length === 1);
        const stopping = page.locator('.truthguard-detection-composer button[aria-label="Stopping fact check"]:visible');
        assert.equal(await stopping.isDisabled(), true);
        assert.equal(await stopping.getAttribute('aria-busy'), 'true');
        await page.evaluate(() => window.finishStop());
        await page.waitForFunction(() => !Alpine.$data(document.querySelector('.truthguard-detection-shell')).submitting);
        const request = await page.evaluate(() => window.stopRequests[0]);
        assert.equal(request.url, 'http://127.0.0.1:8000/detections/999999/stop');
        assert.equal(request.options.method, 'POST');
        assert.equal(request.options.body, JSON.stringify({ confirmed: true }));
        assert.equal(await page.locator('.truthguard-detection-composer button[aria-label="Check claim"]:visible').isEnabled(), true);
        assert.equal(await page.locator('#truthguard-message').evaluate(element => !!element.closest('[inert]')), false);
        assert.deepEqual(errors, []);
        console.log(`Composer Stop passed at ${width}px: loading, confirmation dismissal, cancellation and restored input.`);
        await page.close();
    }
} finally {
    await browser.close();
}
