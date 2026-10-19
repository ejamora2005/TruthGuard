import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
const status = source.match(/<div\s+x-show="voiceListening \|\| voiceError"[\s\S]*?<\/div>/)[0];
const alpine = readFileSync('node_modules/alpinejs/dist/cdn.min.js', 'utf8');
let browser;

before(async () => { browser = await chromium.launch({ channel: 'chrome', headless: true }); });
after(async () => { await browser?.close(); });

async function createVoicePage(prefixed = false) {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(prefixed => {
        window.recognizers = [];
        class Recognition {
            processLocally = false;
            phrases = [];
            starts = 0;
            constructor() { window.recognizers.push(this); }
            start() {
                this.starts += 1;
                // Match Chrome: API availability does not imply service support.
                if (this.phrases.length) {
                    this.onerror?.({ error: 'phrases-not-supported' });
                    this.onend?.();
                } else {
                    this.onstart?.();
                }
            }
            stop() { this.onend?.(); }
        }
        window.SpeechRecognition = prefixed ? undefined : Recognition;
        window.webkitSpeechRecognition = prefixed ? Recognition : undefined;
        window.SpeechRecognitionPhrase = class {
            constructor(phrase, boost) { this.phrase = phrase; this.boost = boost; }
        };
    }, prefixed);
    await page.route('http://localhost:9876/**', route => route.fulfill({
        contentType: 'text/html',
        body: `<html lang="en"><head><style>[x-cloak]{display:none!important}</style></head>
            <body>${opening}${textarea}${button}${status}</section><script>${alpine}</script></body></html>`,
    }));
    await page.goto('http://localhost:9876/voice-test');
    await page.waitForFunction(() => document.querySelector('[x-data]')._x_dataStack);
    return { page, errors };
}

for (const prefixed of [false, true]) {
    test(`voice input starts without unsupported hints (${prefixed ? 'prefixed' : 'standard'} API) and inserts speech`, async () => {
        const { page, errors } = await createVoicePage(prefixed);
        try {
            assert.equal(await page.evaluate(() => window.recognizers.length), 1);
            assert.ok(!(await page.locator('body').innerText()).includes('phraseLength'));
            await page.locator('.truthguard-voice-button').click();
            await page.locator('[role=status]').waitFor({ state: 'visible' });
            assert.equal((await page.locator('[role=status]').innerText()).trim(), 'Listening... speak now');
            await page.evaluate(() => {
                const result = [{ transcript: 'check check this claim' }];
                result.isFinal = true;
                window.recognizers[0].onresult({ resultIndex: 0, results: [result] });
            });
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'check this claim');
            await page.locator('.truthguard-voice-button').click();
            await page.locator('[role=status]').waitFor({ state: 'hidden' });
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
        for (const [error, message] of messages) {
            await page.locator('.truthguard-voice-button').click();
            await page.evaluate(error => {
                window.recognizers[0].onerror({ error });
                window.recognizers[0].onend();
            }, error);
            assert.ok((await page.locator('[role=status]').innerText()).includes(message));
            assert.equal(await page.locator('#truthguard-message').inputValue(), 'Keep my existing claim');
        }
        await page.locator('.truthguard-voice-button').click();
        assert.equal((await page.locator('[role=status]').innerText()).trim(), 'Listening... speak now');
        await page.evaluate(() => {
            window.recognizers[0].onerror({ error: 'aborted' });
            window.recognizers[0].onend();
        });
        await page.locator('[role=status]').waitFor({ state: 'hidden' });
        assert.deepEqual(errors, []);
    } finally { await page.close(); }
});
