import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const partial = readFileSync('resources/views/layouts/partials/pwa.blade.php', 'utf8');
const startup = partial.match(/<script>([\s\S]*?)<\/script>/)[1];
const login = readFileSync('resources/views/livewire/auth/login.blade.php', 'utf8');
const loginOptions = readFileSync('resources/js/login-options.js', 'utf8');
const loginState = login.match(/x-data="(truthguardLoginOptions\([^"]+)"/)[1]
    .replace('@js((bool) old(\'remember\', true))', 'true');
const modes = ['browser', 'fullscreen', 'standalone', 'minimal-ui', 'window-controls-overlay', 'ios'];
const appMode = mode => !['browser', 'fullscreen'].includes(mode);

test('app launches dashboard and automatically selects remembered login only in app windows', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const mode of modes) {
            const context = await browser.newContext();
            await context.addInitScript(mode => {
                const original = window.matchMedia.bind(window);
                window.matchMedia = query => query.startsWith('(display-mode:')
                    ? { matches: query === `(display-mode: ${mode})` } : original(query);
                Object.defineProperty(navigator, 'standalone', { value: mode === 'ios' });
            }, mode);
            const page = await context.newPage();
            await page.route('http://localhost:9876/**', route => route.fulfill({
                contentType: 'text/html', body: `<script>${startup}${loginOptions}</script><h1>TruthGuard</h1>`,
            }));
            await page.goto('http://localhost:9876/?source=pwa');
            if (appMode(mode)) await page.waitForURL('**/dashboard?source=pwa');
            assert.equal(new URL(page.url()).pathname, appMode(mode) ? '/dashboard' : '/');
            await page.goto('http://localhost:9876/login');
            assert.equal(await page.evaluate(expression => Function(`return (${expression})`)().rememberDevice, loginState), appMode(mode));
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

test('thirty-day option and password/OAuth remember requests are available only in PWA windows', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const controls = login.match(/<input type="hidden" name="(?:pwa|remember)"[^>]+>/g).join('\n');
    const label = login.match(/<label x-show="isPwa"[\s\S]*?<\/label>/)[0];
    const webLabel = login.match(/<label x-show="!isPwa"[\s\S]*?<\/label>/)[0];
    const oauthLinks = [...login.matchAll(/x-bind:href="(@js\(route\('(google|facebook)\.redirect'\)\)[^"]+)"/g)]
        .map(([, expression, provider]) => `<a id="${provider}" x-bind:href="${expression.replace(`@js(route('${provider}.redirect'))`, `'/auth/${provider}/redirect'`)}">${provider}</a>`).join('\n');
    const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
    try {
        for (const mode of modes) {
            const page = await browser.newPage();
            await page.addInitScript(mode => {
                window.matchMedia = query => ({ matches: query === `(display-mode: ${mode})` });
                Object.defineProperty(navigator, 'standalone', { value: mode === 'ios' });
                localStorage.setItem('truthguard:pwa-installed', 'true');
            }, mode);
            await page.route('http://localhost:9876/**', route => route.fulfill({
                contentType: 'text/html',
                body: `<style>[x-cloak]{display:none!important}</style><script>${startup}${loginOptions}</script>
                    <div x-data="${loginState}"><form>${controls}${label}${webLabel}</form>${oauthLinks}</div><script>${alpine}</script>`,
            }));
            await page.goto('http://localhost:9876/login?source=pwa');
            await page.waitForFunction(() => document.querySelector('[x-data]')._x_dataStack);
            const isPwa = appMode(mode);
            const checkbox = page.locator('input[type=checkbox][name=remember]');
            assert.equal(await checkbox.isVisible(), isPwa);
            assert.equal(await checkbox.isEnabled(), isPwa);
            assert.equal(await checkbox.isChecked(), isPwa);
            const webCheckbox = page.locator('[data-login-option="web"] input');
            assert.equal(await webCheckbox.isVisible(), !isPwa);
            assert.equal(await webCheckbox.isEnabled(), !isPwa);
            if (!isPwa) await webCheckbox.check();
            const readForm = () => page.evaluate(() => {
                const data = new FormData(document.querySelector('form'));
                return { pwa: data.get('pwa'), remember: data.getAll('remember').at(-1) };
            });
            assert.deepEqual(await readForm(), { pwa: isPwa ? '1' : '0', remember: isPwa ? '1' : '0' });
            for (const provider of ['google', 'facebook']) {
                const url = new URL(await page.locator(`#${provider}`).getAttribute('href'), page.url());
                assert.equal(url.searchParams.get('pwa'), isPwa ? '1' : '0');
                assert.equal(url.searchParams.get('remember'), isPwa ? '1' : '0');
            }
            if (isPwa) {
                await checkbox.uncheck();
                assert.deepEqual(await readForm(), { pwa: '1', remember: '0' });
                for (const provider of ['google', 'facebook']) {
                    assert.match(await page.locator(`#${provider}`).getAttribute('href'), /remember=0&pwa=1$/);
                }
            }
            await page.close();
        }
    } finally { await browser.close(); }
});
