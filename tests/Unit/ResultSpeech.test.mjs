import test from 'node:test';
import assert from 'node:assert/strict';
import { selectResultVoice, toggleResultSpeech, stopResultSpeech } from '../../resources/js/result-speech.js';

const voice = (name, lang, extra = {}) => ({ name, lang, ...extra });
function fixture(voices = []) {
    class Synth extends EventTarget {
        voices = voices;
        spoken = [];
        getVoices() { return this.voices; }
        cancel() {}
        speak(utterance) { this.spoken.push(utterance); }
    }
    return {
        state: { speaking: false, talking: false, speechRequest: 0 },
        environment: { speechSynthesis: new Synth(), SpeechSynthesisUtterance: class { constructor(text) { this.text = text; } } },
    };
}

test('prefers feminine natural voices and Philippine English without choosing another language', () => {
    const rosa = voice('Microsoft Rosa Online (Natural)', 'en-PH');
    assert.equal(selectResultVoice([
        voice('Microsoft David', 'en-US', { default: true }), voice('Microsoft Zira', 'en-US'),
        voice('Microsoft Aria Online (Natural)', 'en-US'), voice('Samantha', 'fr-FR'), rosa,
    ], 'en'), rosa);
    const aria = voice('Microsoft Aria Online (Natural)', 'en-US');
    assert.equal(selectResultVoice([voice('Microsoft Zira', 'en-US'), aria], 'en'), aria);
});

test('respects Filipino/Tagalog language metadata and falls back safely when names are unknown', () => {
    const blessica = voice('Microsoft Blessica Online (Natural)', 'fil-PH');
    assert.equal(selectResultVoice([voice('Aria', 'en-US'), voice('Angelo', 'fil-PH'), blessica], 'tl-PH'), blessica);
    const english = voice('Device default', 'en-US');
    assert.equal(selectResultVoice([voice('Samantha', 'fr-FR'), english], 'en-PH'), english);
    assert.equal(selectResultVoice([], 'en-PH'), null);
});

test('uses a normal pitch, a slightly slower pace, and actual speaking events for the indicator', async () => {
    const zira = voice('Microsoft Zira', 'en-US');
    const { state, environment } = fixture([zira]);
    await toggleResultSpeech(state, 'AI-generated. Read the recorded finding.', 'en', environment);
    const utterance = environment.speechSynthesis.spoken[0];
    assert.equal(utterance.voice, zira);
    assert.equal(utterance.lang, 'en-US');
    assert.equal(utterance.text, 'A I-generated. Read the recorded finding.');
    assert.equal(utterance.rate, .95);
    assert.equal(utterance.pitch, 1);
    assert.equal(state.speaking, true);
    assert.equal(state.talking, false);
    utterance.onstart(); assert.equal(state.talking, true);
    utterance.onpause(); assert.equal(state.talking, false);
    utterance.onresume(); assert.equal(state.talking, true);
    utterance.onend(); assert.equal(state.speaking, false); assert.equal(state.talking, false);
});

test('waits for late voice lists before selecting the preferred voice', async () => {
    const { state, environment } = fixture();
    const pending = toggleResultSpeech(state, 'Recorded finding.', 'en', environment);
    const rosa = voice('Rosa', 'en-PH');
    environment.speechSynthesis.voices = [rosa];
    environment.speechSynthesis.dispatchEvent(new Event('voiceschanged'));
    await pending;
    assert.equal(environment.speechSynthesis.spoken[0].voice, rosa);
});

test('stopping during voice loading prevents delayed speech after cancellation', async () => {
    const { state, environment } = fixture();
    const pending = toggleResultSpeech(state, 'Recorded finding.', 'en', environment);
    stopResultSpeech(state, environment);
    environment.speechSynthesis.voices = [voice('Rosa', 'en-PH')];
    environment.speechSynthesis.dispatchEvent(new Event('voiceschanged'));
    await pending;
    assert.deepEqual(environment.speechSynthesis.spoken, []);
    assert.equal(state.speaking, false);
});

test('stale playback events cannot clear a later reading and errors reset the indicator', async () => {
    const { state, environment } = fixture([voice('Zira', 'en-US')]);
    await toggleResultSpeech(state, 'First finding.', 'en', environment);
    const first = environment.speechSynthesis.spoken[0];
    await toggleResultSpeech(state, 'First finding.', 'en', environment);
    await toggleResultSpeech(state, 'Second finding.', 'en', environment);
    first.onend(); assert.equal(state.speaking, true);
    environment.speechSynthesis.spoken[1].onerror(); assert.equal(state.speaking, false);
});

test('empty voice lists use the platform language fallback without inventing a voice', async () => {
    const { state, environment } = fixture();
    environment.speechSynthesis.addEventListener = null;
    await toggleResultSpeech(state, 'Recorded finding.', 'en', environment);
    const utterance = environment.speechSynthesis.spoken[0];
    assert.equal(utterance.voice, undefined);
    assert.equal(utterance.lang, 'en-PH');
});
