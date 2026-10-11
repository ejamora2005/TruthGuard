import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const partial = readFileSync('resources/views/layouts/partials/pwa.blade.php', 'utf8');
const startup = partial.match(/<script>([\s\S]*?)<\/script>/)[1];
const login = readFileSync('resources/views/livewire/auth/login.blade.php', 'utf8');
const loginState = login.match(/x-data="(\{ authLoading: null,[^"]+)"/)[1]
    .replace('@js((bool) old(\'remember\', false))', 'false');

test('app launches dashboard and automatically selects remembered login only in app windows', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const mode of ['browser', 'standalone', 'minimal-ui', 'ios']) {
            const context = await browser.newContext();
            await context.addInitScript(mode => {
                const original = window.matchMedia.bind(window);
                window.matchMedia = query => query.startsWith('(display-mode:')
                    ? { matches: query === `(display-mode: ${mode})` } : original(query);
                Object.defineProperty(navigator, 'standalone', { value: mode === 'ios' });
            }, mode);
            const page = await context.newPage();
            await page.route('http://localhost:9876/**', route => route.fulfill({
                contentType: 'text/html', body: `<script>${startup}</script><h1>TruthGuard</h1>`,
            }));
            await page.goto('http://localhost:9876/?source=pwa');
            if (mode !== 'browser') await page.waitForURL('**/dashboard?source=pwa');
            assert.equal(new URL(page.url()).pathname, mode === 'browser' ? '/' : '/dashboard');
            await page.goto('http://localhost:9876/login');
            assert.equal(await page.evaluate(expression => Function(`return (${expression})`)().rememberDevice, loginState), mode !== 'browser');
            // Deep links must remain intact, including notification destinations.
            await page.goto('http://localhost:9876/notifications/example');
            assert.equal(new URL(page.url()).pathname, '/notifications/example');
            await context.close();
        }
        const manifest = JSON.parse(readFileSync('public/manifest.webmanifest', 'utf8'));
        assert.equal(manifest.id, '/?source=pwa'); // Preserve existing app identity.
        assert.equal(manifest.start_url, '/dashboard?source=pwa');
    } finally { await browser.close(); }
});
