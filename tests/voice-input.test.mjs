import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const source = readFileSync('resources/views/livewire/detections/ai-composer.blade.php', 'utf8').replace(/\r\n/g, '\n');
const start = source.lastIndexOf('<section', source.indexOf('class="truthguard-detection-shell'));
const values = new Map([
    ['$mediaUploadMaxMb', '20'], ['$mediaUploadMaxBytes', '20971520'],
    ['$initialUploadError ?: null', 'null'], ['$composerDraft', "''"],
    ['$claimMaxCharacters', '4000'], ['$initialComposerError ?: null', 'null'],
    ["old('analysis_task', 'auto')", "'auto'"], ['$shortcutPrompts', '[]'],
    ["in_array(old('analysis_task'), ['media', 'claim'], true) ? old('analysis_task') : null", 'null'],
    ["$oldSourceUrl !== '' ? 'link' : null", 'null'],
    ['$googleDrivePicker', '{ enabled: false }'], ['(bool) $activeDetection', 'false'],
    ['$pendingResultUrl', 'null'], ['$pendingMediaType', 'null'],
]);
const opening = source.slice(start, source.indexOf('\n        <div', start))
    .replace(/@js\((.*)\),/g, (_, expression) => {
        assert.ok(values.has(expression), `Missing fixture for ${expression}`);
        return values.get(expression) + ',';
    });
const textarea = source.match(/<textarea\s+id="truthguard-message"[\s\S]*?<\/textarea>/)[0]
    .replace('{{ $composerDraft }}', '');
const button = source.match(/<button\s+type="button"\s+class="truthguard-voice-button[\s\S]*?<\/button>/)[0];
const status = source.match(/<div\s+x-show="!!voiceError && !voiceModalOpen"[\s\S]*?<\/div>/)[0];
const modal = readFileSync('resources/views/detections/partials/voice-input-dialog.blade.php', 'utf8');
const modalCss = readFileSync('resources/css/voice-input.css', 'utf8');
// Include the real global mobile text rules, which can override dialog text.
const workspaceCss = readFileSync('resources/css/app.css', 'utf8').replace(/^@(?:import|tailwind).*;\r?$/gm, '');
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
let browser;

before(async () => { browser = await chromium.launch({ channel: 'chrome', headless: true }); });
after(async () => { await browser?.close(); });

async function createVoicePage(prefixed = false, { mobile = false, dark = false, delayedStart = false, throwOnStart = false, unsupported = false, reducedMotion = false } = {}) {
    const page = await browser.newPage({
        viewport: mobile ? { width: 320, height: 680 } : { width: 1280, height: 800 },
        isMobile: mobile,
        hasTouch: mobile,
        reducedMotion: reducedMotion ? 'reduce' : 'no-preference',
    });
    const errors = [];
    page.on('pageerror', error => errors.push(error.stack || error.message));
    await page.addInitScript(({ prefixed, delayedStart, throwOnStart, unsupported }) => {
        window.recognizers = [];
        class Recognition {
            processLocally = false;
            phrases = [];
            starts = 0;
            stops = 0;
            aborts = 0;
            constructor() { window.recognizers.push(this); }
            start() {
                this.starts += 1;
                if (throwOnStart) throw new DOMException('Microphone unavailable', 'NotAllowedError');
                // Match Chrome: API availability does not imply service support.
                if (this.phrases.length) {
                    this.onerror?.({ error: 'phrases-not-supported' });
                    this.onend?.();
                } else if (!delayedStart) {
                    this.onstart?.();
                }
            }
            stop() { this.stops += 1; }
            abort() { this.aborts += 1; this.onerror?.({ error: 'aborted' }); this.onend?.(); }
        }
        window.SpeechRecognition = !unsupported && !prefixed ? Recognition : undefined;
        window.webkitSpeechRecognition = !unsupported && prefixed ? Recognition : undefined;
        window.SpeechRecognitionPhrase = class {
            constructor(phrase, boost) { this.phrase = phrase; this.boost = boost; }
        };
    }, { prefixed, delayedStart, throwOnStart, unsupported });
    await page.route('http://localhost:9876/**', route => route.fulfill({
        contentType: 'text/html',
        body: `<html lang="en" data-tg-workspace class="${dark ? 'dark' : ''}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:${dark ? '#0f172a' : '#f5f8ff'}}[x-cloak]{display:none!important}${modalCss}${workspaceCss}</style></head>
            <body>${opening}${textarea}${button}${status}${modal}</section><script>${alpine}</script></body></html>`,
    }));
    await page.goto('http://localhost:9876/voice-test');
    await page.waitForFunction(() => document.querySelector('[x-data]')._x_dataStack);
    return { page, errors };
}

for (const prefixed of [false, true]) {
    test(`voice input starts without unsupported hints (${prefixed ? 'prefixed' : 'standard'} API) and inserts speech`, async () => {
        const { page, errors } = await createVoicePage(prefixed);
        try {
            assert.equal(await page.evaluate(() => window.recognizers.length), 0);
            assert.ok(!(await page.locator('body').innerText()).includes('phraseLength'));
            await page.locator('.truthguard-voice-button').click();
            const dialog = page.getByRole('dialog', { name: 'Voice input' });
            await dialog.waitFor({ state: 'visible' });
            assert.equal((await dialog.getByRole('status').innerText()).trim(), 'Listening…');
            assert.equal(await page.evaluate(() => window.recognizers[0].phrases.length), 0);
            await page.evaluate(() => {
                const result = [{ transcript: 'check check this claim' }];
                result.isFinal = true;
                window.recognizers[0].onresult({ resultIndex: 0, results: [result] });
            });
            assert.equal(await page.locator('#truthguard-message').inputValue(), '');
            assert.ok((await dialog.locator('.tg-voice-preview').innerText()).includes('check this claim'));
            await dialog.getByRole('button', { name: 'Stop listening', exact: true }).click();
            assert.equal(await dialog.getByRole('status').innerText(), 'Finishing…');
            // Recognition can deliver its last final result after stop().
            await page.evaluate(() => {
                const result = [{ transcript: 'using reliable sources' }];
                result.isFinal = true;
                window.recognizers[0].onresult({ resultIndex: 0, results: [result] });
                window.recognizers[0].onend();
            });
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'check this claim using reliable sources');
            assert.equal(await page.evaluate(() => window.recognizers[0].stops), 1);
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });
}

test('voice errors explain the cause and allow another user-initiated attempt', async () => {
    const { page, errors } = await createVoicePage();
    const messages = new Map([
        ['network', 'internet connection'], ['audio-capture', 'No working microphone'],
        ['not-allowed', 'browser site settings'], ['service-not-allowed', 'blocked its speech recognition service'],
        ['language-not-supported', 'selected language'], ['no-speech', 'No speech was detected'],
        ['phrases-not-supported', 'rejected the voice hints'], ['unknown', 'Speech recognition is unavailable'],
    ]);
    try {
        await page.locator('#truthguard-message').fill('Keep my existing claim');
        await page.locator('.truthguard-voice-button').click();
        const dialog = page.getByRole('dialog', { name: 'Voice input' });
        for (const [error, message] of messages) {
            await page.evaluate(error => {
                window.recognizers.at(-1).onerror({ error });
                window.recognizers.at(-1).onend();
            }, error);
            assert.ok((await dialog.getByRole('alert').innerText()).includes(message));
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'Keep my existing claim');
            await dialog.getByRole('button', { name: 'Try again', exact: true }).click();
        }
        assert.equal((await dialog.getByRole('status').innerText()).trim(), 'Listening…');
        await page.evaluate(() => {
            window.recognizers.at(-1).onerror({ error: 'aborted' });
            window.recognizers.at(-1).onend();
        });
        await dialog.waitFor({ state: 'hidden' });
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});

async function speak(page, text, { final = true, index = -1 } = {}) {
    await page.evaluate(({ text, final, index }) => {
        const result = [{ transcript: text }];
        result.isFinal = final;
        window.recognizers.at(index).onresult({ resultIndex: 0, results: [result] });
    }, { text, final, index });
}

test('live interim words stay in the modal; natural completion appends final text once', async () => {
    const { page, errors } = await createVoicePage();
    try {
        await page.locator('#truthguard-message').fill('Existing question.');
        await page.locator('.truthguard-voice-button').click();
        await speak(page, 'Is this true', { final: false });
        assert.ok((await page.locator('.tg-voice-interim').innerText()).includes('Is this true'));
        await speak(page, 'Is this claim true', { final: false });
        assert.ok(!(await page.locator('.tg-voice-preview').innerText()).includes('Is this true'));
        assert.equal(await page.locator('#truthguard-message').inputValue(), 'Existing question.');
        await speak(page, 'Is this claim true?');
        assert.equal(await page.locator('.tg-voice-interim').innerText(), '');
        await page.evaluate(() => { window.recognizers.at(-1).onend(); window.recognizers.at(-1).onend(); });
        await page.getByRole('dialog').waitFor({ state: 'hidden' });
        assert.equal(await page.locator('#truthguard-message').inputValue(), 'Existing question. Is this claim true?');
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});

for (const action of ['Cancel', 'Escape', 'close button', 'backdrop', 'pagehide']) {
    test(`${action} aborts speech without changing the existing draft or accepting late results`, async () => {
        const { page, errors } = await createVoicePage();
        try {
            await page.locator('#truthguard-message').fill('Keep my original question.');
            await page.locator('.truthguard-voice-button').click();
            await speak(page, 'Discard this recording');
            if (action === 'Cancel') await page.getByRole('button', { name: 'Cancel', exact: true }).click();
            if (action === 'Escape') await page.keyboard.press('Escape');
            if (action === 'close button') await page.getByRole('button', { name: 'Cancel voice input', exact: true }).click();
            if (action === 'backdrop') await page.mouse.click(4, 4);
            if (action === 'pagehide') await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
            await page.getByRole('dialog').waitFor({ state: 'hidden' });
            assert.equal(await page.evaluate(() => window.recognizers[0].aborts), 1);
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'Keep my original question.');
            if (action !== 'pagehide') assert.ok(await page.locator('.truthguard-voice-button').evaluate(el => document.activeElement === el));
            await page.locator('.truthguard-voice-button').click();
            await speak(page, 'Old session must be ignored', { index: 0 });
            await page.evaluate(() => { window.recognizers[0].onstart(); window.recognizers[0].onend(); });
            assert.ok(await page.getByRole('dialog').isVisible());
            await speak(page, 'New recording.');
            await page.evaluate(() => window.recognizers.at(-1).onend());
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'Keep my original question. New recording.');
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });
}

test('the permission-waiting modal opens immediately and can be cancelled', async () => {
    const { page, errors } = await createVoicePage(false, { delayedStart: true });
    try {
        await page.locator('.truthguard-voice-button').click();
        const dialog = page.getByRole('dialog');
        assert.ok(await dialog.isVisible());
        assert.equal(await dialog.getByRole('status').innerText(), 'Starting microphone…');
        assert.ok(await dialog.getByRole('button', { name: 'Stop listening' }).isDisabled());
        assert.ok(await page.locator('.tg-voice-mic').evaluate(el => !el.classList.contains('is-listening')));
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await page.evaluate(() => window.recognizers[0].onstart());
        assert.ok(!(await dialog.isVisible()));
        assert.equal(await page.evaluate(() => window.recognizers[0].aborts), 1);
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});

test('an empty recognition result offers retry; synchronous startup failure remains dismissible', async () => {
    for (const throwOnStart of [false, true]) {
        const { page, errors } = await createVoicePage(false, { throwOnStart });
        try {
            await page.locator('.truthguard-voice-button').click();
            if (!throwOnStart) await page.evaluate(() => window.recognizers[0].onend());
            const dialog = page.getByRole('dialog');
            assert.ok((await dialog.getByRole('alert').innerText()).includes(throwOnStart ? 'could not start' : 'No speech'));
            assert.ok(!(await dialog.getByRole('button', { name: 'Try again' }).isDisabled()));
            await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
            assert.ok(!(await dialog.isVisible()));
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    }
});

test('unsupported browsers keep the microphone disabled', async () => {
    const { page, errors } = await createVoicePage(false, { unsupported: true });
    try {
        assert.ok(await page.locator('.truthguard-voice-button').isDisabled());
        assert.equal(await page.evaluate(() => window.recognizers.length), 0);
        assert.ok(!(await page.getByRole('dialog').isVisible()));
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});

for (const dark of [false, true]) {
    test(`mobile modal is centered, readable and keyboard-contained in ${dark ? 'dark' : 'light'} mode`, async () => {
        const { page, errors } = await createVoicePage(false, { mobile: true, dark, reducedMotion: true });
        try {
            await page.locator('.truthguard-voice-button').click();
            await speak(page, 'Is the claim in this post true?', { final: false });
            const dialog = page.getByRole('dialog');
            const metrics = await dialog.evaluate(el => {
                const box = el.getBoundingClientRect();
                return { x: box.x, y: box.y, right: box.right, bottom: box.bottom, centerX: box.x + box.width / 2, centerY: box.y + box.height / 2, width: innerWidth, height: innerHeight, ink: getComputedStyle(el).color, background: getComputedStyle(el).backgroundColor, animation: getComputedStyle(el.querySelector('.tg-voice-mic'), '::before').animationName };
            });
            assert.ok(metrics.x >= 15 && metrics.right <= metrics.width - 15);
            assert.ok(metrics.y >= 15 && metrics.bottom <= metrics.height - 15);
            assert.ok(Math.abs(metrics.centerX - metrics.width / 2) < 1);
            assert.ok(Math.abs(metrics.centerY - metrics.height / 2) < 1);
            assert.equal(metrics.background, dark ? 'rgb(24, 34, 53)' : 'rgb(255, 255, 255)');
            assert.equal(metrics.ink, dark ? 'rgb(241, 245, 249)' : 'rgb(23, 37, 84)');
            assert.equal(metrics.animation, 'none');
            assert.equal(await dialog.locator('h2').evaluate(el => getComputedStyle(el).fontSize), '26px');
            assert.equal(await dialog.locator('.tg-voice-interim').evaluate(el => getComputedStyle(el).fontSize), '16px');
            for (let i = 0; i < 6; i++) {
                await page.keyboard.press('Tab');
                // Native dialogs can return focus to browser chrome at the end
                // of a tab cycle, but never to controls in the inert background.
                assert.ok(await dialog.evaluate(el => el.contains(document.activeElement) || document.activeElement === document.body));
            }
            await page.locator('#truthguard-message').evaluate(el => el.focus());
            assert.ok(!(await page.locator('#truthguard-message').evaluate(el => document.activeElement === el)));
            mkdirSync('output/validation/voice-modal', { recursive: true });
            await page.screenshot({ path: `output/validation/voice-modal/mobile-${dark ? 'dark' : 'light'}.png` });
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });
}
