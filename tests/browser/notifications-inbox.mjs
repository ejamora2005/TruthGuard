// Render NotificationInboxBehaviorTest with TRUTHGUARD_NOTIFICATION_BROWSER_FIXTURE=1 first.
// Requests stay offline; exercise the actual Blade/Alpine UI with simulated API failures.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { readFileSync, existsSync, statSync, mkdirSync } from 'node:fs';
import { resolve, sep } from 'node:path';

const root = resolve('public');
const css = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'))['resources/css/app.css'].file;
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
const output = 'output/validation/notifications';
mkdirSync(output, { recursive: true });
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const checks = [];
try {
    for (const layout of ['user', 'admin']) {
        const html = readFileSync(`storage/tmp-notification-${layout}.html`, 'utf8')
            .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, s => s.includes('const allowedModes') ? s : '')
            .replace(/assets\/app-[\w-]+\.css/g, css);
        for (const width of layout === 'user' ? [320, 390, 768, 1024, 1440] : [390, 1440]) {
            for (const mode of ['light', 'dark']) {
                const page = await browser.newPage({ viewport: { width, height: 960 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
                const errors = [];
                const requests = [];
                let status = 200;
                page.on('pageerror', error => errors.push(error.message));
                await page.addInitScript(({ mode, width }) => {
                    localStorage.setItem('truthguard-display-mode', mode);
                    localStorage.setItem('truthguard-text-size', width === 320 ? 'extra-large' : 'standard');
                }, { mode, width });
                await page.route('**/*', async route => {
                    const request = route.request();
                    const url = new URL(request.url());
                    if (url.origin === 'http://127.0.0.1:8000') {
                        if (request.method() !== 'GET') {
                            requests.push({ path: url.pathname, method: request.method(), token: request.headers()['x-csrf-token'] });
                            return route.fulfill({ status, json: { ok: status === 200 } });
                        }
                        if (url.pathname === '/notifications') return route.fulfill({ contentType: 'text/html', body: html });
                        if (url.pathname === '/notifications/peek') return route.fulfill({ json: { notifications: [], unreadCount: 0, latestId: null } });
                        if (/^\/detections\/\d+\/result$/.test(url.pathname)) return route.fulfill({ contentType: 'text/html', body: '<h1>Saved result</h1>' });
                        const file = resolve(root, '.' + url.pathname);
                        if (file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) return route.fulfill({ contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'image/png', body: readFileSync(file) });
                    }
                    return route.fulfill({ status: 204 });
                });
                await page.goto('http://127.0.0.1:8000/notifications');
                await page.evaluate(() => document.querySelector('#truthguard-app-splash')?.remove());
                await page.addScriptTag({ content: alpine });
                const inbox = page.locator('.tg-inbox');
                await inbox.locator('.tg-inbox-row').first().waitFor();
                await page.evaluate(() => {
                    const bell = document.querySelector('[x-ref="notificationBell"]');
                    if (bell) Alpine.$data(bell.closest('[x-data]')).toast = null;
                    // Varied, explicitly synthetic display records; no database writes.
                    const state = Alpine.$data(document.querySelector('.tg-inbox'));
                    state.notifications = [
                        ...state.notifications.slice(0, 2),
                        { id: 'news-fixture', category: 'news', title: 'A new public claim review is available', message: 'Read the published review and compare the original claim with its supporting sources.', createdAt: '2 hours ago', unread: true, imageUrl: '/images/truthguard-logo.png', actionUrl: '/notifications/news-fixture', actionLabel: 'Read notification' },
                        { id: 'security-fixture', category: 'security', title: 'Your password was updated', message: 'Your account password has been changed. If this was not you, review your account settings.', createdAt: 'Yesterday', unread: false, imageUrl: '', actionUrl: '/notifications/security-fixture', actionLabel: 'Read notification' },
                        { id: 'system-fixture', category: 'system', title: 'Account preferences saved', message: 'Your notification preferences are up to date.', createdAt: '3 days ago', unread: false, imageUrl: '', actionUrl: '/notifications/system-fixture', actionLabel: 'Read notification' },
                    ];
                });
                await page.addStyleTag({ content: '*,*::before,*::after{transition-duration:0s!important;animation-duration:0s!important;}' });
                await page.waitForTimeout(100);
                const rows = inbox.locator('.tg-inbox-row');
                assert.equal(await rows.count(), 5);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
                assert.equal(overflow, false, `${layout} ${width} ${mode} overflow`);
                assert.match(await inbox.getByRole('link', { name: 'Notification settings', exact: true }).getAttribute('href'), /profile\?section=notifications/);
                await page.screenshot({ path: `${output}/${layout}-${width}-${mode}.png` });

                const filter = name => inbox.getByRole('navigation', { name: 'Filter notifications' }).getByRole('button', { name: new RegExp('^' + name + '\\s') });
                await filter('News').click();
                assert.equal(await rows.count(), 1);
                assert.match(await rows.innerText(), /published review/);
                await filter('System').click();
                assert.equal(await rows.count(), 2); // System also includes the welcome notice.
                await filter('All').click();
                // Keyboard filtering remains usable when tabs scroll horizontally.
                await filter('Unread').focus();
                await page.keyboard.press('Enter');
                const unreadCount = await rows.count();
                assert.ok(unreadCount > 1);
                status = 419;
                await rows.first().getByRole('button', { name: /^Mark as read:/ }).click();
                await inbox.getByRole('alert').waitFor({ state: 'visible' });
                assert.equal(await rows.count(), unreadCount);
                status = 200;
                await rows.first().getByRole('button', { name: /^Mark as read:/ }).click();
                await page.waitForFunction(n => document.querySelectorAll('.tg-inbox-row').length === n, unreadCount - 1);
                status = 500;
                await inbox.getByRole('button', { name: 'Mark all as read', exact: true }).click();
                await inbox.getByRole('alert').waitFor({ state: 'visible' });
                assert.equal(await rows.count(), unreadCount - 1);
                status = 200;
                await inbox.getByRole('button', { name: 'Mark all as read', exact: true }).click();
                await inbox.getByRole('heading', { name: 'You’re all caught up' }).waitFor();
                assert.equal(await inbox.getByRole('button', { name: 'Mark all as read', exact: true }).isDisabled(), true);
                await inbox.getByRole('button', { name: 'View all notifications', exact: true }).click();
                assert.equal(await rows.count(), 5);
                status = 500;
                await rows.last().getByRole('button', { name: /^Archive / }).click();
                await inbox.getByRole('alert').waitFor({ state: 'visible' });
                assert.equal(await rows.count(), 5);
                status = 200;
                await rows.last().getByRole('button', { name: /^Archive / }).click();
                await page.waitForFunction(() => document.querySelectorAll('.tg-inbox-row').length === 4);
                const result = inbox.locator('.tg-inbox-open').filter({ hasText: 'View result' }).first();
                assert.match(await result.getAttribute('href'), /^\/detections\/\d+\/result$/);
                await result.click();
                await page.getByRole('heading', { name: 'Saved result' }).waitFor();
                assert.equal(requests.length, 6);
                assert.ok(requests.every(request => request.token?.length > 20));
                assert.deepEqual(errors, []);
                checks.push({ layout, width, mode, filters: true, readAndArchive: true, failuresPreserveState: true, directResult: true, noOverflow: true });
                await page.close();
            }
        }
    }
    console.log(JSON.stringify(checks));
} finally { await browser.close(); }
