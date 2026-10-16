const feminineNames = /\b(?:Rosa|Aria|Jenny|Sonia|Zira|Samantha|Karen|Moira|Tessa|Blessica|Hazel|Susan|Salli|Joanna)\b/i;
const normalize = (locale) => String(locale || '').replaceAll('_', '-').toLowerCase();
const family = (locale) => /^(?:fil|tl)(?:-|$)/.test(normalize(locale)) ? 'fil' : normalize(locale).split('-')[0];
const readingLocale = (locale) => normalize(locale) === 'en' ? 'en-PH' : locale || 'en-PH';

// The browser exposes no gender field. These are voice-name preferences,
// applied only among voices compatible with the requested language.
export function selectResultVoice(voices, locale = 'en-PH') {
    const target = readingLocale(locale);
    const matching = voices.filter((voice) => family(voice.lang) === family(target));
    const candidates = matching.length ? matching : voices.filter((voice) => family(voice.lang) === 'en');
    const score = (voice) => (feminineNames.test(voice.name) ? 1000 : 0)
        + (/natural|neural/i.test(voice.name) ? 150 : 0)
        + (normalize(voice.lang) === normalize(target) ? 100 : 0)
        + (normalize(voice.lang) === 'en-us' ? 20 : 0)
        + (voice.default ? 5 : 0);

    return [...candidates].sort((a, b) => score(b) - score(a))[0] ?? null;
}

function availableVoices(synthesis) {
    const voices = synthesis.getVoices?.() ?? [];
    if (voices.length || !synthesis.addEventListener) return Promise.resolve(voices);

    // Chrome can populate its list after the page has loaded. Wait briefly,
    // then allow the platform fallback rather than blocking the reader.
    return new Promise((resolve) => {
        let timer;
        const finish = () => {
            clearTimeout(timer);
            synthesis.removeEventListener('voiceschanged', changed);
            resolve(synthesis.getVoices?.() ?? []);
        };
        const changed = () => { if (synthesis.getVoices?.().length) finish(); };
        synthesis.addEventListener('voiceschanged', changed);
        timer = setTimeout(finish, 600);
        changed();
    });
}

export function stopResultSpeech(state, environment = window) {
    state.speechRequest = (Number(state.speechRequest) || 0) + 1;
    state.speaking = false;
    state.talking = false;
    environment.speechSynthesis?.cancel();
}

export async function toggleResultSpeech(state, text, locale = 'en-PH', environment = window) {
    if (state.speaking) {
        stopResultSpeech(state, environment);
        return;
    }
    const synthesis = environment.speechSynthesis;
    if (!synthesis || !environment.SpeechSynthesisUtterance) return;
    stopResultSpeech(state, environment);
    const request = state.speechRequest;
    state.speaking = true;
    try {
        const voices = await availableVoices(synthesis);
        if (request !== state.speechRequest) return;
        const utterance = new environment.SpeechSynthesisUtterance(String(text).replace(/\bAI\b/g, 'A I'));
        const voice = selectResultVoice(voices, locale);
        if (voice) utterance.voice = voice;
        utterance.lang = voice?.lang || readingLocale(locale);
        utterance.rate = 0.95;
        utterance.pitch = 1;
        const current = (callback) => () => { if (request === state.speechRequest) callback(); };
        utterance.onstart = current(() => { state.talking = true; });
        utterance.onpause = current(() => { state.talking = false; });
        utterance.onresume = current(() => { state.talking = true; });
        utterance.onend = utterance.onerror = current(() => { state.speaking = false; state.talking = false; });
        if (synthesis.paused) synthesis.resume();
        synthesis.speak(utterance);
    } catch {
        if (request === state.speechRequest) { state.speaking = false; state.talking = false; }
    }
}

if (typeof window !== 'undefined') {
    window.TruthGuardSpeech = { toggle: toggleResultSpeech, stop: stopResultSpeech };
    window.speechSynthesis?.getVoices?.();
}
