import assert from 'node:assert/strict';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { resolve, sep } from 'node:path';
import { chromium } from 'playwright';

// Real rendered Blade + real Alpine click handlers. Only local assets are served;
// selecting a guide or a file must never submit a form or call a provider.
const fixture = readFileSync('storage/tmp-create-browser/create.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const root = resolve('public');
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
try {
    for (const width of [1366, 390]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 }, reducedMotion: 'reduce', serviceWorkers: 'block' });
        const errors = [];
        const unexpected = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.origin === 'http://127.0.0.1:8000' && url.pathname === '/fixture/create') return route.fulfill({ contentType: 'text/html', body: fixture });
            const local = resolve(root, '.' + url.pathname);
            if (url.origin === 'http://127.0.0.1:8000' && local.startsWith(root + sep) && existsSync(local) && statSync(local).isFile()) {
                return route.fulfill({ body: readFileSync(local), contentType: local.endsWith('.css') ? 'text/css' : local.endsWith('.svg') ? 'image/svg+xml' : local.endsWith('.png') ? 'image/png' : 'application/octet-stream' });
            }
            if (!['fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname)) unexpected.push(route.request().method() + ' ' + url.origin + url.pathname);
            return route.fulfill({ status: 204 });
        });
        await page.goto('http://127.0.0.1:8000/fixture/create');
        await page.evaluate(() => {
            document.querySelectorAll('style').forEach(style => document.head.append(style.cloneNode(true)));
            document.body.innerHTML = document.querySelector('.truthguard-detection-theme').outerHTML;
            document.documentElement.classList.remove('tg-loading');
            document.body.classList.remove('tg-loading');
            window.guideFormSubmissions = 0;
            document.addEventListener('submit', event => { event.preventDefault(); window.guideFormSubmissions++; }, true);
        });
        await page.addScriptTag({ path: 'node_modules/alpinejs/dist/cdn.js' });
        const field = page.locator('[name=caption_text]');
        const task = page.locator('[name=analysis_task]');
        const guides = page.locator('.truthguard-guided-prompt-card');
        await page.waitForFunction(() => !!window.Alpine && !!document.querySelector('.truthguard-detection-shell')._x_dataStack);
        assert.equal(await page.getByRole('button', { name: 'Clear guide', exact: true }).count(), 0);
        await field.fill('The Riverside bridge reopened in May 2026.');
        await page.getByRole('button', { name: /Fact-check this claim/ }).click();
        await page.waitForFunction(() => document.querySelector('[name=caption_text]').value.startsWith('Is the claim in this post true?'));
        await page.waitForFunction(() => document.activeElement.id === 'truthguard-message');
        assert.equal(await task.inputValue(), 'claim');
        assert.equal(await field.inputValue(), 'Is the claim in this post true?\n\nThe Riverside bridge reopened in May 2026.');
        assert.equal(await guides.nth(1).getAttribute('aria-pressed'), 'true');
        assert.equal(await field.evaluate(element => element === document.activeElement), true);

        // Keyboard selection is distinct even though both guides use factual verification.
        await guides.nth(2).focus();
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelector('.truthguard-guided-prompt-card[data-guide=sources]').getAttribute('aria-pressed') === 'true');
        assert.equal(await guides.nth(1).getAttribute('aria-pressed'), 'false');
        assert.equal(await task.inputValue(), 'claim');
        assert.equal(await field.inputValue(), 'Has this claim already been fact-checked, and what ratings did trusted sources give it?\n\nThe Riverside bridge reopened in May 2026.');
        await guides.nth(2).click();
        assert.equal(await field.inputValue(), 'Has this claim already been fact-checked, and what ratings did trusted sources give it?\n\nThe Riverside bridge reopened in May 2026.');
        assert.equal(await page.getByRole('button', { name: 'Clear guide', exact: true }).count(), 0);
        assert.equal(await field.getAttribute('placeholder'), 'Ask or paste a link');

        await field.fill('');
        await guides.nth(0).click();
        assert.equal(await task.inputValue(), 'media');
        assert.equal(await field.inputValue(), 'Is this image or video AI-generated or manipulated?');
        assert.equal(await page.evaluate(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).canAnalyze()), false);
        await page.locator('[name=media_file]').setInputFiles({ name: 'guide-fixture.png', mimeType: 'image/png', buffer: image });
        await page.waitForFunction(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).hasFile);
        assert.equal(await page.evaluate(() => Alpine.$data(document.querySelector('.truthguard-detection-shell')).canAnalyze()), true);
        await field.fill('Is this image or video AI-generated or manipulated?\n\nPlease check the attached photo.');
        await guides.nth(1).click();
        const form = await page.evaluate(() => {
            const data = new FormData(document.querySelector('#truthguard-composer'));
            return { task: data.get('analysis_task'), caption: data.get('caption_text'), filename: data.get('media_file').name, submissions: window.guideFormSubmissions };
        });
        assert.deepEqual(form, { task: 'claim', caption: 'Is the claim in this post true?\n\nPlease check the attached photo.', filename: 'guide-fixture.png', submissions: 0 });
        // Changing a guide while processing must not alter the pending request.
        await page.evaluate(() => { Alpine.$data(document.querySelector('.truthguard-detection-shell')).submitting = true; });
        await page.waitForFunction(() => document.querySelector('.truthguard-guided-prompt-card').disabled);
        assert.equal(await guides.nth(0).isDisabled(), true);
        assert.equal(await task.inputValue(), 'claim');
        assert.deepEqual(errors, []);
        assert.deepEqual(unexpected, []);
        results.push({ width, questionInserted: true, keyboardSelection: true, previousPresetReplaced: true, draftAndUploadPreserved: true, formTaskCorrect: true, providerRequests: 0 });
        await page.close();
    }
    console.log(JSON.stringify(results));
} finally { await browser.close(); }
