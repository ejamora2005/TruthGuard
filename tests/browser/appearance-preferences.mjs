// Offline UI check. First render fixtures with TRUTHGUARD_APPEARANCE_BROWSER_FIXTURE=1.
import { chromium } from 'playwright';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import assert from 'node:assert/strict';

const root = resolve('public');
const appCss = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'))['resources/css/app.css'].file;
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [];
const prepare = (html) => html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, (script) => script.includes('const allowedModes') ? script : '')
    .replace(/assets\/app-[\w-]+\.css/g, appCss);
try {
    for (const layout of ['user', 'admin']) {
        const html = prepare(readFileSync(`storage/tmp-appearance-${layout}.html`, 'utf8'));
        const history = prepare(readFileSync('storage/tmp-history-timezone.html', 'utf8'));
        for (const width of [1440, 1024, 390, 320]) {
            const context = await browser.newContext({ viewport: { width, height: 900 }, serviceWorkers: 'block', reducedMotion: 'reduce', colorScheme: 'light' });
            const errors = [];
            await context.route('**/*', async (route) => {
                const url = new URL(route.request().url());
                if (url.origin === 'http://127.0.0.1:8000' && url.pathname === '/profile') return route.fulfill({ contentType: 'text/html', body: html });
                if (url.origin === 'http://127.0.0.1:8000' && url.pathname === '/history') return route.fulfill({ contentType: 'text/html', body: history });
                const file = resolve(root, '.' + url.pathname);
                if (url.origin === 'http://127.0.0.1:8000' && file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) {
                    return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png', body: readFileSync(file) });
                }
                return route.fulfill({ status: 204 });
            });
            const page = await context.newPage();
            page.on('pageerror', (error) => errors.push(error.message));
            const mount = async () => {
                await page.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
                await page.addScriptTag({ content: alpine });
                await page.getByRole('group', { name: 'Text size', exact: true }).waitFor();
            };
            await page.goto('http://127.0.0.1:8000/profile?section=appearance');
            await mount();
            const mode = page.getByRole('group', { name: 'Display mode', exact: true });
            const size = page.getByRole('group', { name: 'Text size', exact: true });
            const topbarToggle = page.getByRole('switch', { name: 'Dark mode', exact: true });
            if (await topbarToggle.isVisible()) {
                await topbarToggle.focus(); await page.keyboard.press('Space');
                await page.waitForFunction(() => document.documentElement.classList.contains('dark'));
                assert.equal(await topbarToggle.getAttribute('aria-checked'), 'true');
                assert.equal(await mode.getByRole('button', { name: 'Dark', exact: true }).getAttribute('aria-pressed'), 'true');
                assert.equal(await page.evaluate(() => localStorage.getItem('truthguard-display-mode')), 'dark');
                await page.reload(); await mount();
                assert.equal(await topbarToggle.getAttribute('aria-checked'), 'true');
                await topbarToggle.focus(); await page.keyboard.press('Enter');
                await page.waitForFunction(() => !document.documentElement.classList.contains('dark'));
                assert.equal(await mode.getByRole('button', { name: 'Light', exact: true }).getAttribute('aria-pressed'), 'true');
                if (layout === 'user') assert.equal(await page.evaluate(() => Alpine.$data(document.body).themeKey), 'ocean');
            }
            const sampleSize = () => page.locator('.tg-reading-sample').evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
            await size.getByRole('button', { name: 'Standard', exact: true }).click();
            const initialSize = await sampleSize();
            await mode.getByRole('button', { name: 'Dark', exact: true }).click();
            if (await topbarToggle.isVisible()) assert.equal(await topbarToggle.getAttribute('aria-checked'), 'true');
            assert.equal(await page.locator('html').getAttribute('class'), 'dark');
            assert.equal(await mode.getByRole('button', { name: 'Dark', exact: true }).getAttribute('aria-pressed'), 'true');
            assert.equal(await page.locator('.truthguard-settings-modal-dialog').evaluate((el) => getComputedStyle(el).backgroundColor), 'rgb(24, 34, 53)');
            assert.equal(await page.locator('.tg-reading-preview > div').first().evaluate((el) => getComputedStyle(el).backgroundColor), 'rgb(24, 34, 53)');
            await size.getByRole('button', { name: 'Large', exact: true }).click();
            assert.equal(await sampleSize(), initialSize * 1.125);
            await size.getByRole('button', { name: 'Extra large', exact: true }).click();
            assert.equal(await sampleSize(), initialSize * 1.25);
            const overflow = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, elements: [...document.querySelectorAll('body *')].filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.right > innerWidth + 1 && r.left >= 0 && getComputedStyle(el).position !== 'fixed'; }).slice(0, 12).map(el => ({ tag: el.tagName, className: el.className, right: el.getBoundingClientRect().right })) }));
            await page.locator('.truthguard-settings-modal-body').evaluate((el) => el.scrollTop = 0);
            await page.screenshot({ path: `storage/tmp-appearance-${layout}-${width}-dark.png` });
            assert.ok(overflow.scrollWidth <= width + 1, JSON.stringify({ layout, ...overflow }));
            await page.reload(); await mount();
            assert.equal(await page.locator('html').getAttribute('data-display-mode'), 'dark');
            assert.equal(await page.locator('html').getAttribute('data-text-size'), 'extra-large');
            await mode.getByRole('button', { name: 'System', exact: true }).click();
            await page.emulateMedia({ colorScheme: 'dark' });
            await page.waitForFunction(() => document.documentElement.classList.contains('dark'));
            await page.emulateMedia({ colorScheme: 'light' });
            await page.waitForFunction(() => !document.documentElement.classList.contains('dark'));
            await mode.getByRole('button', { name: 'Light', exact: true }).click();
            await page.emulateMedia({ colorScheme: 'dark' });
            assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), false);
            await size.getByRole('button', { name: 'Standard', exact: true }).click();
            await page.locator('.truthguard-settings-modal-body').evaluate((el) => el.scrollTop = 0);
            await page.screenshot({ path: `storage/tmp-appearance-${layout}-${width}-light.png` });
            // Another page/tab sees the saved choice, and storage events keep it current.
            const other = await context.newPage();
            await other.goto('http://127.0.0.1:8000/history');
            await mode.getByRole('button', { name: 'Dark', exact: true }).click();
            await size.getByRole('button', { name: 'Extra large', exact: true }).click();
            await other.waitForFunction(() => document.documentElement.classList.contains('dark') && document.documentElement.dataset.textSize === 'extra-large');
            await other.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
            await other.screenshot({ path: `storage/tmp-appearance-history-${width}-dark.png` });
            assert.deepEqual(errors, []);
            checks.push({ layout, width, modes: true, textSizes: true, reload: true, systemChanges: true, crossTab: true, noPageOverflow: true });
            await context.close();
        }
    }
    console.log(JSON.stringify(checks));
} finally { await browser.close(); }
