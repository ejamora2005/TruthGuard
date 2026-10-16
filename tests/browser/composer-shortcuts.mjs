import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {chromium} from 'playwright';

// Render the real Blade fixture first with render-create-workspace.php.
// Scripts and remote requests are disabled; the test invokes the rendered
// Alpine handler directly against the real form controls.
const fixture = readFileSync('storage/tmp-create-browser/create.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const browser = await chromium.launch({channel: 'chrome', headless: true});

try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.fulfill({status: 204}));
    await page.setContent(fixture, {waitUntil: 'domcontentloaded'});
    const result = await page.evaluate(() => {
        const shell = document.querySelector('.truthguard-detection-shell');
        const form = document.querySelector('#truthguard-composer');
        const caption = form.querySelector('[name="caption_text"]');
        const task = form.querySelector('[name="analysis_task"]');
        const guides = [...form.querySelectorAll('.truthguard-guided-prompt-card')];
        const state = new Function(`return (${shell.getAttribute('x-data')})`)();
        state.$refs = {analysisTask: task, composerInput: caption};
        state.$nextTick = callback => callback();
        state.resizeComposer = () => {};

        const choose = guide => {
            const handler = guide.getAttribute('@click');
            new Function('$el', `with (this) { ${handler}; }`).call(state, guide);
        };

        caption.value = 'The Riverside bridge reopened in May 2026.';
        state.composerValue = caption.value;
        state.selectedInputType = 'link';
        state.menuOpen = true;
        choose(guides[1]);
        const withDraft = {
            task: task.value,
            caption: caption.value,
            composerValue: state.composerValue,
            selectedInputType: state.selectedInputType,
            menuOpen: state.menuOpen,
        };

        caption.value = '';
        state.composerValue = '';
        state.selectedInputType = null;
        state.hasFile = true;
        choose(guides[0]);
        const withUpload = {
            task: task.value,
            caption: caption.value,
            composerValue: state.composerValue,
            canAnalyze: state.canAnalyze(),
        };
        choose(guides[1]);
        const factCheckUpload = {
            task: task.value,
            caption: caption.value,
            composerValue: state.composerValue,
            canAnalyze: state.canAnalyze(),
        };

        return {withDraft, withUpload, factCheckUpload, promptAttributes: guides.map(guide => guide.hasAttribute('data-prompt'))};
    });

    assert.deepEqual(result.withDraft, {
        task: 'claim',
        caption: 'Is the claim in this post true?\n\nThe Riverside bridge reopened in May 2026.',
        composerValue: 'Is the claim in this post true?\n\nThe Riverside bridge reopened in May 2026.',
        selectedInputType: 'link',
        menuOpen: false,
    });
    assert.deepEqual(result.withUpload, {
        task: 'media',
        caption: 'Is this image or video AI-generated or manipulated?',
        composerValue: 'Is this image or video AI-generated or manipulated?',
        canAnalyze: true,
    });
    assert.deepEqual(result.factCheckUpload, {
        task: 'claim',
        caption: 'Is the claim in this post true?',
        composerValue: 'Is the claim in this post true?',
        canAnalyze: true,
    });
    assert.deepEqual(result.promptAttributes, [false, false, false]);
    console.log('Composer guides insert ready-to-send questions, replace the previous preset, and retain the user draft.');
} finally {
    await browser.close();
}
