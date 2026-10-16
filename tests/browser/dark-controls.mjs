// Exercise rendered dark controls offline, including states hidden by default.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFileSync, writeFileSync, existsSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';

const root = resolve('public');
const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
const css = manifest['resources/css/app.css'].file;
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const width = Number(process.env.CONTROL_WIDTH || 1440);
const names = (process.env.CONTROL_PAGES || 'dashboard,create,result,history,inbox,notice,article,settings-personal,settings-appearance,settings-photo,settings-security,settings-notifications,settings-account').split(',');
const states = (process.env.CONTROL_STATES || 'normal,hover,focus,active').split(',');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];

async function contrast(page, selector) {
    return page.locator(selector).evaluate(control => {
        const rgba = value => {
            const numbers = value?.match(/[\d.]+/g)?.map(Number);
            return numbers?.length >= 3 ? [...numbers.slice(0, 3).map(n => value.startsWith('color(srgb') ? n * 255 : n), numbers[3] ?? 1] : [0, 0, 0, 0];
        };
        const over = (a, b) => {
            const alpha = a[3] + b[3] * (1 - a[3]);
            return [...a.slice(0, 3).map((n, i) => (n * a[3] + b[i] * b[3] * (1 - a[3])) / (alpha || 1)), alpha];
        };
        const background = element => {
            let result = [0, 0, 0, 0];
            for (let node = element; node; node = node.parentElement) {
                const style = getComputedStyle(node);
                let layer = rgba(style.backgroundColor);
                const gradient = style.backgroundImage.match(/rgba?\([^)]*\)/);
                if (style.backgroundImage.includes('gradient') && gradient) layer = over(rgba(gradient[0]), layer);
                result = over(result, layer);
                if (result[3] > .99) break;
            }
            return over(result, [15, 23, 42, 1]);
        };
        const luminance = color => color.slice(0, 3).map(n => {
            n /= 255;
            return n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4;
        }).reduce((sum, n, i) => sum + n * [.2126, .7152, .0722][i], 0);
        const failures = [];
        for (const node of [control, ...control.querySelectorAll('*')]) {
            if (node.closest('[aria-hidden="true"], [inert]') && !node.closest('svg')) continue;
            if (!node.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
            const style = getComputedStyle(node);
            const ownText = [...node.childNodes].filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join('').trim();
            const icon = node.tagName.toLowerCase() === 'svg' && (
                (!control.textContent.trim() && !control.querySelector('img')) ||
                node.closest('.truthguard-profile-action-icon, .truthguard-recent-open')
            );
            const input = node === control && ['INPUT', 'TEXTAREA', 'SELECT'].includes(node.tagName) && !['checkbox', 'radio', 'file'].includes(node.type);
            if (!ownText && !icon && !input) continue;
            const bg = background(node);
            let paint = style.color;
            if (icon) {
                const paints = new Set([...node.querySelectorAll('path,line,polyline,circle,rect')].flatMap(shape => {
                    const style = getComputedStyle(shape);
                    return [style.stroke, style.fill].filter(value => value !== 'none' && rgba(value)[3] > 0);
                }));
                if (paints.size > 1) continue; // Multicolor branding/flags are not monochrome action icons.
                if (paints.size === 1) paint = [...paints][0];
            }
            const fg = over(rgba(paint), bg);
            const ratio = (Math.max(luminance(fg), luminance(bg)) + .05) / (Math.min(luminance(fg), luminance(bg)) + .05);
            const large = parseFloat(style.fontSize) >= 24 || (parseFloat(style.fontSize) >= 18.66 && Number(style.fontWeight) >= 700);
            const minimum = control.disabled || control.getAttribute('aria-disabled') === 'true' || icon || large ? 3 : 4.5;
            if (ratio < minimum) failures.push({ text: ownText.slice(0, 70) || control.getAttribute('aria-label') || control.placeholder || node.tagName,
                ratio: Number(ratio.toFixed(2)), minimum, fg: paint, bg: bg.slice(0, 3).map(Math.round),
                control: control.className, child: node.className?.baseVal ?? node.className });
        }
        return failures;
    });
}

try {
    for (const name of names) {
        const admin = name.startsWith('admin-');
        const raw = readFileSync(`storage/tmp-dark-mode-${admin ? 'admin' : name}.html`, 'utf8');
        let html = raw.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, script =>
            script.includes('const allowedModes') || (admin && (script.includes('window.TruthGuardAdmin') || script.includes('type="module"'))) ? script : '')
            .replace(/assets\/app-[\w-]+\.css/g, css);
        if (admin) {
            html = html.replace(/assets\/admin-[\w-]+\.js/g, manifest['resources/js/admin.jsx'].file)
                .replace(/assets\/admin-[\w-]+\.css/g, manifest['resources/js/admin.jsx'].css.find(path => path.includes('/admin-')));
        }
        const page = await browser.newPage({ viewport: { width, height: 1000 }, serviceWorkers: 'block', reducedMotion: 'reduce' });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.addInitScript(() => localStorage.setItem('truthguard-display-mode', 'dark'));
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (route.request().isNavigationRequest()) return route.fulfill({ contentType: 'text/html', body: html });
            if (url.pathname.startsWith('/admin/api/')) return route.fulfill({ status: 503, contentType: 'application/json', body: '{}' });
            const file = resolve(root, '.' + url.pathname);
            if (url.origin === 'http://127.0.0.1:8000' && file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) {
                return route.fulfill({ contentType: file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png', body: readFileSync(file) });
            }
            return route.fulfill({ contentType: 'application/json', body: '{}' });
        });
        const path = admin ? '/admin/' + name.slice(6) : name.startsWith('settings-') ? '/profile?section=' + name.slice(9) :
            ({ dashboard: '/dashboard', create: '/detections/create', result: '/detections/1/result', history: '/history', inbox: '/notifications', notice: '/notifications/fixture', article: '/dashboard/fact-checks/sample-review' })[name];
        await page.goto('http://127.0.0.1:8000' + path);
        await page.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
        if (admin) await page.locator('.truthguard-admin-shell').waitFor();
        else await page.addScriptTag({ content: alpine });
        await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important;scroll-behavior:auto!important;}' });
        const later = page.getByRole('button', { name: 'Do this later', exact: true });
        if (await later.isVisible()) await later.click();
        const failures = [];
        async function inspect(panel) {
            const scope = ({ 'profile-menu': '.truthguard-admin-profile-popover', 'user-profile': '.truthguard-profile-popover', 'logout-dialog': '.truthguard-admin-logout-card', 'user-logout': 'dialog.tg-dialog[open]', notifications: '.truthguard-notification-popover' })[panel];
            // Capture before outside-click checks can dismiss a popover.
            if (scope && await page.locator(scope).isVisible()) {
                await page.screenshot({ path: `storage/tmp-dark-controls-${name}-${panel}-${width}.png` });
            }
            const controls = await page.evaluate(scope => {
                document.querySelectorAll('[data-dark-control]').forEach(el => delete el.dataset.darkControl);
                const seen = new Set();
                const container = scope ? document.querySelector(scope) : document;
                if (!container) return [];
                return [...container.querySelectorAll('button,a[href],summary,input:not([type="hidden"]),textarea,select')]
                    .filter(el => el.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true }) && !el.closest('[inert], [aria-hidden="true"]'))
                    .filter(el => { const rect = el.getBoundingClientRect(); return rect.width >= 4 && rect.right > 0 && rect.left < innerWidth; })
                    .filter(el => {
                        const key = el.tagName + el.className + el.getAttribute('aria-pressed') + el.disabled;
                        if (seen.has(key)) return false;
                        seen.add(key);
                        return true;
                    }).map((el, index) => { el.dataset.darkControl = String(index); return { index, disabled: el.disabled, label: el.textContent.trim().slice(0, 45) || el.getAttribute('aria-label') || el.tagName }; });
            }, scope);
            for (const control of controls) {
                const selector = `[data-dark-control="${control.index}"]`;
                const locator = page.locator(selector);
                for (const state of states) {
                    if (panel === 'user-profile' && !(await locator.isVisible())) {
                        await page.getByRole('button', { name: 'Open profile menu', exact: true }).click();
                        await page.waitForFunction(() => getComputedStyle(document.querySelector('.truthguard-profile-popover')).opacity === '1');
                    }
                    if (!(await locator.isVisible())) continue;
                    if (state !== 'normal' && control.disabled) continue;
                    try { await locator.evaluate(el => el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'instant' }), undefined, { timeout: 1500 }); }
                    catch (error) { if (!(await locator.isVisible())) continue; throw error; }
                    const box = await locator.boundingBox();
                    if (!box) continue;
                    const pointer = () => page.mouse.move(Math.max(1, Math.min(width - 1, box.x + box.width / 2)), Math.max(1, Math.min(999, box.y + box.height / 2)));
                    if (state === 'hover') await pointer();
                    if (state === 'focus') {
                        await page.mouse.move(0, 0);
                        await page.keyboard.press('Tab');
                        await locator.focus();
                    }
                    if (state === 'active') { await pointer(); await page.mouse.down(); }
                    for (const failure of await contrast(page, selector)) failures.push({ panel, state, label: control.label, ...failure });
                    if (state === 'active') {
                        await page.mouse.move(0, 0); await page.mouse.up();
                        if (panel === 'user-profile') {
                            await page.keyboard.press('Escape');
                            await page.locator(scope).waitFor({ state: 'hidden' });
                        }
                    }
                }
            }
            await page.evaluate(() => window.getSelection()?.removeAllRanges());
        }
        await inspect('page');
        if (name === 'create') {
            assert.ok(await page.locator('.truthguard-recent-open').count(), 'The fixture must exercise recent-card arrow icons.');
            const profile = page.getByRole('button', { name: 'Open profile menu', exact: true });
            await profile.click();
            await page.locator('.truthguard-profile-popover').waitFor({ state: 'visible' });
            const pointer = await page.locator('.truthguard-profile-popover').evaluate(el => getComputedStyle(el, '::before').backgroundColor);
            assert.equal(pointer, 'rgb(24, 34, 53)', 'The profile pointer should match the dark menu.');
            await inspect('user-profile');
            const logout = page.getByRole('button', { name: 'Sign out', exact: true });
            if (!(await logout.isVisible())) await profile.click();
            await logout.click();
            const dialog = page.locator('dialog.tg-dialog');
            await dialog.waitFor({ state: 'visible' });
            await inspect('user-logout');
            await page.getByRole('button', { name: 'Stay signed in', exact: true }).click();
            await dialog.waitFor({ state: 'hidden' });
            await page.evaluate(() => window.TruthGuardAppearance.set({ mode: 'light' }));
            await profile.click();
            const lightSurface = await logout.evaluate(el => {
                const style = getComputedStyle(el);
                return { color: style.backgroundColor, image: style.backgroundImage };
            });
            assert.notEqual(lightSurface.color, 'rgb(24, 34, 53)', 'Light mode must not retain the dark action surface.');
            assert.ok(lightSurface.color === 'rgb(255, 255, 255)' || lightSurface.image.includes('gradient'),
                'Light mode keeps its original white surface or light gradient.');
            await page.keyboard.press('Escape');
            await page.evaluate(() => window.TruthGuardAppearance.set({ mode: 'dark' }));
        }
        if (admin) {
            const profile = page.getByRole('button', { name: 'Open profile menu', exact: true });
            await profile.click();
            await inspect('profile-menu');
            const logout = page.getByRole('button', { name: 'Sign out', exact: true });
            if (!(await logout.isVisible())) await profile.click();
            await logout.click();
            await page.locator('.truthguard-admin-logout-modal').waitFor({ state: 'visible' });
            await inspect('logout-dialog');
            await page.getByRole('button', { name: 'Stay signed in', exact: true }).click();
            assert.equal(await page.locator('.truthguard-admin-logout-modal').count(), 0);
        }
        if (name === 'dashboard' && width >= 1024) {
            const bell = page.locator('[x-ref="notificationBell"]');
            if (await bell.isVisible()) { await bell.click(); await inspect('notifications'); await page.keyboard.press('Escape'); }
        }
        if (name === 'dashboard') {
            await page.evaluate(() => Alpine.$data(document.body).requestLogout());
            const dialog = page.locator('dialog.tg-dialog');
            await dialog.waitFor({ state: 'visible' });
            await inspect('user-logout');
            await page.getByRole('button', { name: 'Stay signed in', exact: true }).click();
            await dialog.waitFor({ state: 'hidden' });
        }
        if (name === 'result') {
            const details = page.locator('details');
            for (let i = 0; i < await details.count(); i++) await details.nth(i).evaluate(el => { el.open = true; });
            await inspect('expanded-details');
        }
        if (name === 'settings-security') {
            const show = page.getByRole('button', { name: 'Show current password', exact: true });
            if (await show.isVisible()) {
                await show.click();
                await page.getByRole('button', { name: 'Hide current password', exact: true }).waitFor();
                await page.getByRole('button', { name: 'Hide current password', exact: true }).click();
                await show.waitFor();
            }
        }
        await page.evaluate(() => window.getSelection()?.removeAllRanges());
        await page.screenshot({ path: `storage/tmp-dark-controls-${name}-${width}.png`, fullPage: !name.startsWith('settings-') });
        if (name === 'create') {
            // A recent review still opens the recorded destination after the theme change.
            const href = await page.locator('.truthguard-recent-card').first().getAttribute('href');
            await page.locator('.truthguard-recent-card').first().click();
            await page.waitForURL(new URL(href, 'http://127.0.0.1:8000').href);
        }
        results.push({ name, width, failures, errors });
        console.log(JSON.stringify({ page: name, width, failures: failures.length }));
        await page.close();
    }
    writeFileSync(`storage/tmp-dark-controls-${width}.json`, JSON.stringify(results, null, 2));
    const failures = results.flatMap(result => result.failures.map(failure => ({ page: result.name, ...failure })));
    console.log(JSON.stringify({ pages: results.length, width, failedStates: failures.length, failures: failures.slice(0, 20) }));
    for (const result of results) assert.deepEqual(result.errors, [], result.name);
    assert.equal(failures.length, 0, `See storage/tmp-dark-controls-${width}.json`);
} finally { await browser.close(); }
