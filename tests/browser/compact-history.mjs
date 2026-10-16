// Offline browser coverage; render CompactHistoryTest's optional fixture first.
import { chromium } from 'playwright';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import assert from 'node:assert/strict';
const root = resolve('public');
const css = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'))['resources/css/app.css'].file;
const html = readFileSync('storage/tmp-history-compact.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, script => script.includes('const allowedModes') ? script : '')
    .replace(/assets\/app-[\w-]+\.css/g, css);
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [];
try {
    for (const width of [1920, 1440, 1024, 768, 390, 320]) {
        for (const [mode, textSize] of [['light', 'standard'], ['dark', 'extra-large']]) {
            const page = await browser.newPage({ viewport: { width, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
            const errors = [];
            const mutations = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(({ mode, textSize }) => {
                localStorage.setItem('truthguard-display-mode', mode);
                localStorage.setItem('truthguard-text-size', textSize);
            }, { mode, textSize });
            await page.route('**/*', async route => {
                const request = route.request();
                const url = new URL(request.url());
                if (url.origin === 'http://127.0.0.1:8000' && /^\/history\/\d+\/archive$/.test(url.pathname)) {
                    mutations.push({ path: url.pathname, data: Object.fromEntries(new URLSearchParams(request.postData())) });
                    return route.fulfill({ contentType: 'text/html', body: '<h1>Archived</h1>' });
                }
                if (url.origin === 'http://127.0.0.1:8000' && url.pathname === '/history') return route.fulfill({ contentType: 'text/html', body: html });
                const file = resolve(root, '.' + url.pathname);
                if (url.origin === 'http://127.0.0.1:8000' && file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png', body: readFileSync(file) });
                return route.fulfill({ status: 204 });
            });
            const mount = async () => {
                await page.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
                await page.addScriptTag({ content: alpine });
                await page.locator('.tg-history').waitFor();
                await page.evaluate(() => {
                    const bell = document.querySelector('[x-ref="notificationBell"]');
                    if (bell) Alpine.$data(bell.closest('[x-data]')).toast = null;
                });
            };
            await page.goto('http://127.0.0.1:8000/history'); await mount();
            const scope = page.locator(width >= 1440 ? '.tg-history-desktop' : '.tg-history-mobile-list');
            assert.equal(await scope.isVisible(), true);
            assert.equal(await scope.locator('[role="progressbar"]').count(), 0);
            assert.match(await scope.innerText(), /TG-03/);
            assert.match(await scope.innerText(), /Uncalibrated model output/);
            const dimensions = await scope.locator('.tg-history-title').first().evaluate(el => ({ height: el.getBoundingClientRect().height, line: parseFloat(getComputedStyle(el).lineHeight) }));
            assert.ok(dimensions.height <= dimensions.line * 2 + 1);
            const open = scope.getByRole('link', { name: 'Open result', exact: false }).first();
            assert.equal(await open.evaluate(el => getComputedStyle(el).whiteSpace), 'nowrap');
            const unavailable = scope.getByRole('button', { name: /Not available/ });
            await unavailable.focus();
            await scope.getByRole('tooltip').filter({ visible: true }).waitFor();
            await page.keyboard.press('Escape');
            await scope.getByRole('tooltip').filter({ visible: true }).waitFor({ state: 'hidden' });
            assert.equal(await scope.getByRole('tooltip').filter({ visible: true }).count(), 0);
            const more = scope.getByRole('button', { name: 'More actions for TG-03', exact: true });
            await more.focus(); await page.keyboard.press('Enter');
            await scope.getByRole('button', { name: 'Archive report', exact: true }).filter({ visible: true }).waitFor();
            assert.equal(mutations.length, 0);
            await page.keyboard.press('Escape');
            assert.equal(await more.getAttribute('aria-expanded'), 'false');
            const overflow = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, history: document.querySelector('.tg-history').getBoundingClientRect().right }));
            assert.ok(overflow.document <= width + 1 && overflow.history <= width + 1, JSON.stringify({ width, mode, textSize, overflow }));
            await page.screenshot({ path: `storage/tmp-history-compact-${width}-${mode}.png` });
            await page.locator('.tg-history-date-picker summary').click();
            await page.locator('[name="date_from"]').filter({ visible: true }).fill('2026-10-12');
            await page.locator('[name="date_to"]').filter({ visible: true }).fill('2026-10-16');
            await page.getByRole('button', { name: 'Apply dates', exact: true }).click();
            await page.waitForURL(url => url.searchParams.get('date_from') === '2026-10-12' && url.searchParams.get('date_to') === '2026-10-16');
            await mount();
            await page.getByRole('searchbox', { name: 'Search history' }).fill('original photo');
            await page.getByRole('combobox', { name: 'Sort history' }).selectOption('oldest');
            await page.waitForURL(url => url.searchParams.get('sort') === 'oldest' && url.searchParams.get('search') === 'original photo');
            await mount();
            await scope.getByRole('button', { name: 'More actions for TG-03', exact: true }).click();
            await scope.getByRole('button', { name: 'Archive report', exact: true }).filter({ visible: true }).click();
            await page.getByRole('heading', { name: 'Archived', exact: true }).waitFor();
            assert.equal(mutations.length, 1);
            assert.equal(mutations[0].data._method, 'PATCH');
            assert.ok(mutations[0].data._token.length > 20);
            assert.deepEqual(errors, []);
            checks.push({ width, mode, textSize, noOverflow: true, tooltip: true, keyboardMenu: true, dateForm: true, searchSort: true, archiveForm: true });
            await page.close();
        }
    }
    console.log(JSON.stringify(checks));
} finally { await browser.close(); }
