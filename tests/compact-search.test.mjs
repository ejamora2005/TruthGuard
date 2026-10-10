import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

test('mobile search collapses, keeps query values, submits filters, and expands on desktop', async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        await page.route('http://localhost:9876/**', route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/compact-search.js') return route.fulfill({ contentType: 'text/javascript', body: readFileSync('resources/js/compact-search.js', 'utf8') });
            return route.fulfill({ contentType: 'text/html', body: `<meta name="viewport" content="width=device-width,initial-scale=1"><header><h1>History</h1><span id="search-action"></span></header><form data-compact-search-target="search-action" data-compact-search="Search history" action="/history"><input type="hidden" name="status" value="real"><input type="search" name="search" aria-label="Search history" value="${url.searchParams.has('search') ? 'test' : ''}"><button>Apply</button></form><script type="module" src="/compact-search.js"></script>` });
        });
        await page.goto('http://localhost:9876/history');
        const button = page.locator('.truthguard-compact-search-toggle');
        await button.waitFor();
        assert.equal(await page.locator('#search-action > button').count(), 1);
        assert.equal(await button.getAttribute('aria-label'), 'Search history');
        assert.equal(await button.locator('svg').count(), 1);
        assert.equal(await button.textContent(), '');
        assert.equal(await page.locator('form').isVisible(), false);
        await button.click();
        await page.getByRole('searchbox').fill('test');
        await page.getByRole('searchbox').press('Escape');
        assert.equal(await page.locator('form').isVisible(), false);
        await button.click();
        assert.equal(await page.getByRole('searchbox').inputValue(), 'test');
        await page.getByRole('searchbox').press('Enter');
        await page.waitForURL('**/history?status=real&search=test');
        assert.equal(await page.locator('form').isVisible(), true);
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.waitForFunction(() => document.querySelector('.truthguard-compact-search-toggle').hidden);
        assert.equal(await page.locator('form').isVisible(), true);
        await page.evaluate(() => document.dispatchEvent(new Event('livewire:navigated')));
        assert.equal(await button.count(), 1);
    } finally { await browser.close(); }
});
