// Run: node tests/browser/global-loader.mjs (installed Google Chrome).
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { createServer } from 'node:http';
import assert from 'node:assert/strict';

const js = readFileSync('resources/js/global-loader.js', 'utf8');
const css = readFileSync('resources/css/global-loader.css', 'utf8');
const markup = readFileSync('resources/views/layouts/partials/app-splash.blade.php', 'utf8')
    .replace(/@once|@endonce/g, '').replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/\{\{ asset\('[^']+'\) \}\}/g, '/logo.png');
const server = createServer((req, res) => {
    if (req.url === '/logo.png') {
        res.setHeader('Content-Type', 'image/png');
        res.end(readFileSync('public/images/truthguard-logo-transparent.png'));
        return;
    }
    const render = () => {
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><script>${js}</script></head><body>${markup}
            <main><h1>Usable page</h1><a id="next" href="/slow">Next</a><a id="hash" href="#section">Section</a>
            <a id="cancel" href="/slow" onclick="event.preventDefault()">Canceled</a>
            <form action="/slow"><button>Sign in</button></form><div id="section">Section</div></main>
            <script>window.ends = []; window.blinks = 0;
            document.addEventListener('animationstart', e => { if (e.animationName === 'tg-loader-blink') window.blinks++; });
            ${req.url === '/hold' ? 'window.ends.push(TruthGuardLoader.begin());' : ''}</script></body></html>`);
    };
    if (req.url === '/slow') setTimeout(render, 500); else render();
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
const hidden = () => page.waitForFunction(() => !document.documentElement.classList.contains('tg-loading'));
const visible = () => page.waitForFunction(() => document.documentElement.classList.contains('tg-loading'));
try {
    await page.goto(base + '/hold');
    await visible();
    assert.equal(await page.locator('main').evaluate(el => el.inert), true);
    await page.evaluate(() => ends.pop()());
    await hidden();
    assert.equal(await page.evaluate(() => blinks), 1);
    assert.equal(await page.locator('main').evaluate(el => el.inert), false);
    await page.reload();
    await page.evaluate(() => ends.pop()());
    await hidden();
    assert.equal(await page.evaluate(() => blinks), 1);

    // Fast operations and canceled/hash navigation never flash a full-screen overlay.
    await page.evaluate(() => TruthGuardLoader.begin()());
    await page.locator('#hash').click();
    await page.locator('#cancel').click();
    await page.waitForTimeout(180);
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('tg-loading')), false);

    // Concurrent operations: no completion until all are done; failure never blinks.
    await page.evaluate(() => { ends.push(TruthGuardLoader.begin(), TruthGuardLoader.begin()); });
    await visible();
    await page.evaluate(() => ends.pop()());
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('tg-blinking')), false);
    await page.evaluate(() => ends.pop()(false));
    await hidden();
    assert.equal(await page.evaluate(() => blinks), 1);

    // Livewire events, including duplicate completion and canceled navigation.
    await page.evaluate(() => document.dispatchEvent(new CustomEvent('livewire:navigate')));
    await visible();
    await page.evaluate(() => {
        document.dispatchEvent(new CustomEvent('livewire:navigated'));
        document.dispatchEvent(new CustomEvent('livewire:navigated'));
    });
    await hidden();
    assert.equal(await page.evaluate(() => blinks), 2);

    await page.locator('#next').click({ noWaitAfter: true });
    await visible();
    await page.waitForURL(base + '/slow');
    await hidden();
    await page.goBack();
    await page.evaluate(() => ends.pop()?.());
    await hidden();
    await page.goForward();
    await hidden();
    await page.evaluate(() => {
        TruthGuardLoader.begin({ immediate: true });
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await hidden();
    assert.equal(await page.locator('main').evaluate(el => el.inert), false);

    await page.locator('button').click({ noWaitAfter: true });
    await visible();
    await page.waitForURL(/\/slow\?/);
    await hidden();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => TruthGuardLoader.begin({ immediate: true }));
    mkdirSync('storage/app/loader-qa', { recursive: true });
    await page.screenshot({ path: 'storage/app/loader-qa/mobile.png' });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.keyboard.press('Escape');
    await hidden();
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.evaluate(() => TruthGuardLoader.begin({ immediate: true })());
    await hidden();

    // Lost requests: deterministic clock verifies the fail-open watchdog.
    await page.clock.install();
    await page.evaluate(() => TruthGuardLoader.begin({ immediate: true }));
    await page.clock.fastForward(15001);
    await hidden();
    const noJs = await browser.newPage({ javaScriptEnabled: false });
    await noJs.goto(base);
    assert.equal(await noJs.locator('[data-truthguard-splash]').isVisible(), false);
    assert.equal(await noJs.locator('button').isEnabled(), true);
    console.log('PASS: Chrome initial/refresh, one blink, concurrency, failure, fast/canceled actions, Livewire lifecycle, native navigation/forms, history/BFCache restoration, mobile, reduced motion, watchdog, no JavaScript.');
} finally {
    await browser.close();
    server.close();
}
