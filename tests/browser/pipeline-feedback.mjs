// Developer-rendered fixtures only; no user session or feedback writes.
import {chromium} from 'playwright';
import {readFileSync, existsSync, statSync, mkdirSync} from 'node:fs';
import {resolve, sep} from 'node:path';
import assert from 'node:assert/strict';

const browser = await chromium.launch({channel: 'chrome', headless: true});
const page = await browser.newPage({reducedMotion: 'reduce', serviceWorkers: 'block'});
await page.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/fixture/')) {
        const variant = url.pathname.split('/').at(-1);
        const body = readFileSync(`storage/tmp-assessment-browser/${variant}.html`, 'utf8')
            .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
        return route.fulfill({contentType: 'text/html', body});
    }
    const file = resolve('public', '.' + url.pathname);
    if (file.startsWith(resolve('public') + sep) && existsSync(file) && statSync(file).isFile()) {
        return route.fulfill({contentType: file.endsWith('.css') ? 'text/css' : 'application/octet-stream', body: readFileSync(file)});
    }
    return route.fulfill({status: 204});
});
mkdirSync('storage/tmp-assessment-browser/screenshots', {recursive: true});
try {
    for (const variant of ['factual', 'media']) {
        for (const width of [1366, 390]) {
            await page.setViewportSize({width, height: 900});
            await page.goto(`http://127.0.0.1:8000/fixture/${variant}`);
            await page.evaluate(() => document.documentElement.classList.remove('tg-loading'));
            const section = page.locator('section[aria-labelledby="result-feedback-title"]');
            assert.equal(await section.locator('form').count(), 1);
            assert.equal(await section.locator('button[name="category"]').count(), variant === 'media' ? 8 : 6);
            const payloads = await section.evaluate(el => {
                const form = el.querySelector('form');
                form.elements.note.value = 'Please inspect the original evidence.';
                return [...form.querySelectorAll('button[name="category"]')].map(button => ({
                    category: new FormData(form, button).get('category'),
                    note: new FormData(form, button).get('note'),
                    expected: button.value,
                }));
            });
            for (const row of payloads) {
                assert.equal(row.category, row.expected);
                assert.equal(row.note, 'Please inspect the original evidence.');
            }
            assert.ok(await section.evaluate(el => el.scrollWidth <= el.clientWidth + 1), `${variant} ${width}: feedback overflow`);
            await section.screenshot({path: `storage/tmp-assessment-browser/screenshots/feedback-${variant}-${width}.png`});
            console.log(`${variant} ${width}: feedback form and category payloads passed`);
        }
    }
} finally {
    await browser.close();
}
