<template x-teleport="body">
    <dialog
        x-ref="voiceDialog"
        class="tg-voice-dialog"
        aria-label="Voice input"
        aria-describedby="truthguard-voice-hint"
        @cancel.prevent="cancelVoiceInput()"
        @close="if (voiceModalOpen && !$el.open) cancelVoiceInput()"
        @click="if ($event.target === $el) { const box = $el.getBoundingClientRect(); if ($event.clientX < box.left || $event.clientX > box.right || $event.clientY < box.top || $event.clientY > box.bottom) cancelVoiceInput(); }"
    >
        <div class="tg-voice-content">
            <button type="button" class="tg-voice-close" aria-label="Cancel voice input" @click="cancelVoiceInput()" autofocus>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="m6 6 12 12M6 18 18 6" /></svg>
            </button>
            <p class="tg-voice-eyebrow">TruthGuard voice input</p>
            <div class="tg-voice-mic" :class="{ 'is-listening': voiceListening }" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="8" y="3" width="8" height="12" rx="4" />
                    <path stroke-linecap="round" d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3M8 22h8" />
                </svg>
            </div>
            <h2 class="tg-voice-heading" role="status" aria-live="polite" x-text="voiceError ? 'Let’s try that again' : (voiceStarting ? 'Starting microphone…' : (voiceFinishing ? 'Finishing…' : 'Listening…'))"></h2>
            <p id="truthguard-voice-hint" class="tg-voice-hint" x-text="voiceError ? 'You can retry or cancel and type your question.' : (voiceStarting ? 'Allow microphone access if your browser asks.' : (voiceFinishing ? 'Adding your words to the input.' : 'Speak naturally. Tap Stop when you’re done.'))"></p>
            <div class="tg-voice-preview" x-show="!voiceError">
                <span class="tg-voice-placeholder" x-show="!voiceFinalText && !voiceInterimText">Your words will appear here.</span>
                <span x-text="voiceFinalText"></span>
                <span class="tg-voice-interim" x-text="voiceInterimText ? ' ' + voiceInterimText : ''"></span>
            </div>
            <p class="tg-voice-error" x-show="!!voiceError" x-cloak role="alert" x-text="voiceError"></p>
            <p class="tg-voice-note">Your question is added when listening finishes.</p>
        </div>
        <div class="tg-voice-actions">
            <button type="button" class="tg-voice-cancel" @click="cancelVoiceInput()">Cancel</button>
            <button
                type="button"
                class="tg-voice-stop"
                :disabled="voiceStarting || voiceFinishing"
                @click="voiceError ? startVoiceInput() : stopVoiceInput()"
                x-text="voiceError ? 'Try again' : (voiceFinishing ? 'Finishing…' : 'Stop listening')"
            ></button>
        </div>
    </dialog>
</template>
