import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test('late PWA script startup and blocked storage do not break installation', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(() => {
            for (const name of ['sessionStorage', 'localStorage']) {
                Object.defineProperty(window, name, { get() { throw new DOMException('Blocked', 'SecurityError'); } });
            }
        });
        await page.route('http://localhost:9876/**', route => {
            const path = new URL(route.request().url()).pathname;
            if (path === '/pwa.js') return route.fulfill({ contentType: 'text/javascript', body: readFileSync('resources/js/pwa.js', 'utf8') });
            if (path === '/service-worker') return route.fulfill({ contentType: 'text/javascript', body: 'export async function getTruthGuardWorker(){ window.workerStarted = true; return { addEventListener(){} }; }' });
            return route.fulfill({ contentType: 'text/html', body: '<!doctype html><html><body><h1>TruthGuard</h1></body></html>' });
        });
        await page.goto('http://localhost:9876');
        // Import after the window load event has already fired.
        await page.evaluate(() => import('/pwa.js'));
        assert.equal(await page.evaluate(() => window.workerStarted), true);
        await page.evaluate(() => {
            const prompt = new Event('beforeinstallprompt', { cancelable: true });
            prompt.prompt = async () => {};
            prompt.userChoice = Promise.resolve({ outcome: 'accepted' });
            window.dispatchEvent(prompt);
        });
        assert.equal(await page.locator('#truthguard-pwa-install').isVisible(), true);
        await page.evaluate(() => window.dispatchEvent(new Event('appinstalled')));
        assert.equal(await page.locator('#truthguard-pwa-install').isVisible(), false);
        assert.deepEqual(errors, []);
    } finally { await browser.close(); }
});
