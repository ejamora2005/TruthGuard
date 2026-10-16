import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const login = readFileSync('resources/views/livewire/auth/login.blade.php', 'utf8');
const options = readFileSync('resources/js/login-options.js', 'utf8');
const startup = readFileSync('resources/views/layouts/partials/pwa.blade.php', 'utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const labels = [...login.matchAll(/<label x-show="!?isPwa"[\s\S]*?<\/label>/g)].map(m => m[0]).join('');
const controls = login.match(/<input type="hidden" name="(?:pwa|remember)"[^>]+>/g).join('');
const state = login.match(/x-data="(truthguardLoginOptions\([^"]+)"/)[1].replace('@js((bool) old(\'remember\', true))', 'true');
const submit = login.match(/x-on:submit="([^"]+)"/)[1];
const error = login.match(/<p x-show="authError"[^>]+><\/p>/)[0];
const html = `<style>[x-cloak]{display:none!important}</style><script>${startup}${options}</script>
<div x-data="${state}"><form method="POST" action="/login" x-on:submit="${submit}">
${controls}${error}<input name="email" autocomplete="username" value="fixture@example.test">
<input name="password" type="password" autocomplete="current-password" value="test-password">
${labels}<button type="submit">Login</button></form></div><script>${alpine}</script>`;

test('web password saving happens only after successful verification and never requests a remembered session', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const scenario of ['save', 'declined', 'unchecked', 'unsupported', 'pwa', 'invalid', 'expired', 'offline']) {
            const page = await browser.newPage();
            const browserErrors = [];
            page.on('pageerror', e => browserErrors.push(e.message));
            const saved = [];
            const requests = [];
            await page.exposeFunction('recordPasswordSave', credential => saved.push(credential));
            await page.addInitScript(scenario => {
                window.matchMedia = query => ({ matches: scenario === 'pwa' && query === '(display-mode: standalone)' });
                Object.defineProperty(window, 'PasswordCredential', {
                    value: scenario === 'unsupported' ? undefined : class { constructor(value) { Object.assign(this, value); } }, configurable: true,
                });
                Object.defineProperty(navigator, 'credentials', { value: { store: async credential => {
                    await window.recordPasswordSave({ id: credential.id, password: credential.password });
                    if (scenario === 'declined') throw new Error('User declined password saving');
                } } });
            }, scenario);
            await page.route('http://localhost:9876/**', async route => {
                if (route.request().method() === 'POST') {
                    assert.equal(saved.length, 0, 'Must not save an unverified password');
                    const request = route.request();
                    requests.push(request);
                    if (scenario === 'offline') return route.abort('failed');
                    if (scenario === 'invalid') return route.fulfill({ status: 422, json: { errors: { email: ['Invalid login details.'] } } });
                    if (scenario === 'expired') return route.fulfill({ status: 419, json: { message: 'CSRF token mismatch.' } });
                    return request.headers().accept === 'application/json'
                        ? route.fulfill({ json: { authenticated: true, redirect: '/dashboard' } })
                        : route.fulfill({ contentType: 'text/html', body: '<h1>Dashboard</h1>' }); // Native POST; Laravel redirect is covered by PHP tests.
                }
                return route.fulfill({ contentType: 'text/html', body: route.request().url().endsWith('/dashboard') ? '<h1>Dashboard</h1>' : html });
            });
            await page.goto('http://localhost:9876/login');
            await page.waitForFunction(() => document.querySelector('[x-data]')._x_dataStack);
            if (!['unchecked', 'pwa'].includes(scenario)) await page.locator('[data-login-option="web"] input').check();
            await page.getByRole('button', { name: 'Login', exact: true }).click();
            const failure = ['invalid', 'expired', 'offline'].includes(scenario);
            if (failure) {
                await page.getByRole('alert').waitFor();
                assert.equal(await page.evaluate(() => document.querySelector('[x-data]')._x_dataStack[0].authLoading), null);
                assert.equal(new URL(page.url()).pathname, '/login');
            } else if (['unchecked', 'unsupported', 'pwa'].includes(scenario)) {
                await page.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
            } else {
                await page.waitForURL('**/dashboard', { timeout: 7000 }).catch(async () => {
                    throw new Error(`${scenario}: ${await page.evaluate(() => document.querySelector('[role="alert"]')?.textContent)}; URL=${page.url()}; POSTs=${requests.length}; saved=${saved.length}; JS=${browserErrors.join(';')}`);
                });
            }
            assert.equal(requests.length, 1);
            assert.equal(saved.length, ['save', 'declined'].includes(scenario) ? 1 : 0, scenario);
            if (saved.length) assert.deepEqual(saved[0], { id: 'fixture@example.test', password: 'test-password' });
            const body = requests[0].postData();
            if (['unchecked', 'unsupported', 'pwa'].includes(scenario)) {
                const form = new URLSearchParams(body);
                assert.equal(form.get('pwa'), scenario === 'pwa' ? '1' : '0');
                assert.equal(form.getAll('remember').at(-1), scenario === 'pwa' ? '1' : '0');
            } else {
                assert.match(body, /name="pwa"\r\n\r\n0/);
                assert.match(body, /name="remember"\r\n\r\n0/);
            }
            assert.equal(await page.evaluate(() => localStorage.length + sessionStorage.length), 0);
            assert.deepEqual(browserErrors, []);
            await page.close();
        }
    } finally { await browser.close(); }
});

test('moving between app and browser modes resets the incompatible option', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        await page.addInitScript(() => {
            window.testMode = 'browser';
            window.modeCallbacks = [];
            window.matchMedia = query => ({
                get matches() { return query === `(display-mode: ${window.testMode})`; },
                addEventListener: (_, callback) => window.modeCallbacks.push(callback), removeEventListener() {},
            });
        });
        await page.route('http://localhost:9876/**', route => route.fulfill({ contentType: 'text/html', body: html }));
        await page.goto('http://localhost:9876/login');
        const web = page.locator('[data-login-option="web"] input');
        const app = page.locator('[data-login-option="pwa"] input');
        await web.check();
        for (const mode of ['standalone', 'fullscreen', 'browser']) {
            await page.evaluate(mode => { window.testMode = mode; window.modeCallbacks.forEach(callback => callback()); }, mode);
            await page.waitForFunction(mode => document.querySelector('[data-login-option="pwa"]').style.display === (mode === 'browser' ? 'none' : ''), mode);
            assert.equal(await app.isVisible(), mode !== 'browser');
            assert.equal(await web.isVisible(), mode === 'browser');
        }
        assert.equal(await app.isChecked(), false);
        assert.equal(await web.isChecked(), false);
    } finally { await browser.close(); }
});
