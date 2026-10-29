// Render with TRUTHGUARD_PAGINATION_BROWSER_FIXTURE=1 and CompactPaginationTest.
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { chromium } from 'playwright';

const root = resolve('public');
const styledSelect = readFileSync('resources/js/styled-select.js', 'utf8');
const css = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'))['resources/css/app.css'].file;
const origin = 'http://127.0.0.1:8000';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const directory = 'output/validation/pagination';
mkdirSync(directory, { recursive: true });
try {
    for (const [view, path, parameter, filters] of [
        ['history', '/history', 'page', { search: 'Pagination', sort: 'oldest', status: 'all' }],
        ['dashboard', '/dashboard', 'fact_page', { review_search: 'Pagination', review_category: 'fake-news' }],
        ['public', '/claim-reviews', 'page', { review_search: 'Pagination', review_category: 'fake-news' }],
    ]) {
        for (const width of [320, 390, 1440]) for (const dark of [false, true]) {
            const page = await browser.newPage({ viewport: { width, height: 844 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.origin === origin && url.pathname === path) {
                    const number = Number(url.searchParams.get(parameter) || 1);
                    const html = readFileSync(`storage/tmp-pagination/${view}-${number}.html`, 'utf8')
                        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
                        .replace(/assets\/app-[\w-]+\.css/g, css);
                    return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
                }
                const file = resolve(root, '.' + url.pathname);
                if (url.origin === origin && file.startsWith(root + sep) && existsSync(file) && statSync(file).isFile()) {
                    return route.fulfill({ body: readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'application/octet-stream' });
                }
                return route.fulfill({ status: 204 });
            });
            await page.goto(`${origin}${path}?${new URLSearchParams({ ...filters, [parameter]: 2 })}`);
            await page.locator('select.tg-pagination-select').evaluate(select => {
                select.dataset.tgSelect = '';
                select.dataset.tgSelectTriggerClass = 'tg-pagination-select';
            });
            await page.addScriptTag({ content: `{${styledSelect}}` });
            await page.evaluate(dark => {
                document.documentElement.classList.remove('tg-loading'); document.body.classList.remove('tg-loading');
                document.documentElement.dataset.tgWorkspace = '';
                document.documentElement.classList.toggle('dark', dark);
            }, dark);
            const pager = page.locator('.tg-pagination');
            await pager.scrollIntoViewIfNeeded();
            const metrics = await pager.evaluate(element => {
                const bounds = element.getBoundingClientRect(), controls = element.querySelector('nav').getBoundingClientRect();
                const select = element.querySelector('.tg-select-trigger');
                return { height: bounds.height, controlsWidth: controls.width, inside: controls.left >= bounds.left && controls.right <= bounds.right,
                    overflow: document.documentElement.scrollWidth - innerWidth, selectBackground: getComputedStyle(select).backgroundColor,
                    selectColor: getComputedStyle(select).color, links: [...element.querySelectorAll('a')].map(link => link.getBoundingClientRect().height) };
            });
            assert.ok(metrics.height <= 72 && metrics.controlsWidth <= 210 && metrics.inside && metrics.overflow <= 1, JSON.stringify({ view, width, dark, ...metrics }));
            if (width <= 640) assert.ok(metrics.links.every(height => height >= 44));
            if (dark) {
                assert.equal(metrics.selectBackground, 'rgb(32, 56, 88)');
                assert.equal(metrics.selectColor, 'rgb(191, 219, 254)');
            }
            await pager.screenshot({ path: `${directory}/${view}-${width}-${dark ? 'dark' : 'light'}.png` });
            const select = page.getByRole('button', { name: 'Go to page', exact: true });
            assert.equal(await select.textContent(), '2 / 3');
            await select.click();
            await Promise.all([page.waitForURL(url => url.searchParams.get(parameter) === '3'), page.getByRole('option', { name: '3 / 3', exact: true }).click()]);
            assert.equal(await page.getByRole('link', { name: 'Next page', exact: true }).count(), 0);
            const previous = page.getByRole('link', { name: 'Previous page', exact: true });
            await previous.focus();
            await Promise.all([page.waitForURL(url => url.searchParams.get(parameter) === '2'), page.keyboard.press('Enter')]);
            await Promise.all([page.waitForURL(url => Number(url.searchParams.get(parameter) || 1) === 1), page.getByRole('link', { name: 'Previous page', exact: true }).click()]);
            assert.equal(await page.getByRole('link', { name: 'Previous page', exact: true }).count(), 0);
            const current = new URL(page.url());
            for (const [key, value] of Object.entries(filters)) assert.equal(current.searchParams.get(key), value);
            assert.deepEqual(errors, []);
            console.log(JSON.stringify({ view, width, dark, height: metrics.height, directJump: true, keyboard: true, filtersPreserved: true }));
            await page.close();
        }
    }
} finally { await browser.close(); }
