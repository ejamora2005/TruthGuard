// Render AppearancePreferencesTest with TRUTHGUARD_DARK_MODE_BROWSER_FIXTURE=1 first.
// These checks use local assets and rendered fixtures; no live APIs or saved account changes.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFileSync, existsSync, statSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, sep } from 'node:path';

const root = resolve('public'), origin = 'http://127.0.0.1:8000';
const css = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'))['resources/css/app.css'].file;
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const compactSearch = readFileSync('resources/js/compact-search.js', 'utf8');
const directory = 'output/validation/workspace-refresh';
mkdirSync(directory, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [];
try {
    for (const [name, path] of Object.entries({ dashboard: '/dashboard', create: '/detections/create',
        history: '/history', result: '/detections/1/result', inbox: '/notifications',
        'settings-personal': '/profile', notice: '/notifications/fixture', article: '/dashboard/fact-checks/sample-review' })) {
        const html = readFileSync(`storage/tmp-dark-mode-${name}.html`, 'utf8')
            .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, script => script.includes('const allowedModes') ? script : '')
            .replace(/assets\/app-[\w-]+\.css/g, css);
        for (const width of [320, 390, 768, 1024, 1440, 1920]) for (const mode of ['light', 'dark']) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(({ mode, width }) => {
                localStorage.setItem('truthguard-display-mode', mode);
                localStorage.setItem('truthguard-text-size', width === 320 ? 'extra-large' : 'standard');
            }, { mode, width });
            await page.route('**/*', route => {
                const url = new URL(route.request().url()), file = resolve(root, '.' + url.pathname);
                if (route.request().isNavigationRequest()) return route.fulfill({ contentType: 'text/html', body: html });
                if (url.origin === origin && file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) {
                    return route.fulfill({ body: readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'application/octet-stream' });
                }
                return route.fulfill({ status: 204 });
            });
            await page.goto(origin + path);
            await page.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
            await page.addScriptTag({ content: alpine });
            await page.addScriptTag({ type: 'module', content: compactSearch });
            await page.addStyleTag({ content: '*,*::before,*::after { transition-duration:0s!important;animation-duration:0s!important; }' });
            await page.evaluate(() => {
                const bell = document.querySelector('[x-ref="notificationBell"]');
                if (bell) Alpine.$data(bell.closest('[x-data]')).toast = null;
            });
            const later = page.getByRole('button', { name: 'Do this later', exact: true });
            if (await later.isVisible()) await later.click();
            if (name.startsWith('settings')) {
                const close = page.getByRole('button', { name: 'Close settings', exact: true });
                if (await close.isVisible()) await close.click();
                const options = page.locator('.tg-settings-option');
                assert.equal(await options.count(), 6);
                for (const option of await options.all()) {
                    await option.focus(); await page.keyboard.press('Enter');
                    await page.getByRole('dialog').filter({ visible: true }).waitFor();
                    await page.keyboard.press('Escape');
                    await page.getByRole('dialog').filter({ visible: true }).waitFor({ state: 'hidden' });
                    assert.equal(await option.evaluate(el => el === document.activeElement), true);
                }
            }
            const toggle = page.locator('.truthguard-topbar .truthguard-mode-toggle').filter({ visible: true });
            assert.equal(await toggle.count(), 1, `${name}: exactly one visible theme switch`);
            await toggle.click();
            assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark')), mode !== 'dark');
            await toggle.click();
            const metrics = await page.evaluate(() => {
                const main = document.querySelector('.truthguard-user-main').getBoundingClientRect();
                const bar = document.querySelector('.truthguard-topbar').getBoundingClientRect();
                const nav = document.querySelector('.truthguard-mobile-tabbar');
                return { overflow: document.documentElement.scrollWidth - innerWidth,
                    mainInside: main.left >= 0 && main.right <= innerWidth + 1,
                    topbarInside: bar.left >= 0 && bar.right <= innerWidth + 1,
                    nav: nav && getComputedStyle(nav).display !== 'none' ? [...nav.querySelectorAll('a')].map(el => {
                        const r = el.getBoundingClientRect(); return { width: r.width, height: r.height, inside: r.left >= 0 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1 };
                    }) : [] };
            });
            assert.ok(metrics.overflow <= 1 && metrics.mainInside && metrics.topbarInside, JSON.stringify({ name, width, mode, metrics }));
            assert.ok(metrics.nav.every(link => link.width >= 44 && link.height >= 44 && link.inside), JSON.stringify({ name, width, mode, nav: metrics.nav }));
            if (width >= 1024 && await page.getByRole('button', { name: 'Toggle sidebar', exact: true }).isVisible()) {
                await page.getByRole('button', { name: 'Toggle sidebar', exact: true }).click();
                assert.ok(await page.locator('.truthguard-sidebar').evaluate(el => el.getBoundingClientRect().width < 100));
                await page.getByRole('button', { name: 'Toggle sidebar', exact: true }).click();
            }
            await page.evaluate(() => window.scrollTo(0, 0));
            if ([390, 1440].includes(width)) await page.screenshot({ path: `${directory}/${name}-${width}-${mode}.png`, fullPage: name === 'settings-personal' });
            assert.deepEqual(errors, [], `${name} ${width} ${mode}`);
            checks.push({ page: name, width, mode, fits: true, themeToggle: true, keyboard: true });
            await page.close();
        }
    }
    writeFileSync(`${directory}/checks.json`, JSON.stringify(checks, null, 2));
    console.log(`${checks.length} user workspace layouts passed: no overflow, working theme controls, navigation, and settings keyboard actions.`);
} finally { await browser.close(); }
