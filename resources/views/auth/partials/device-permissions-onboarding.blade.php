@auth
<div
    x-data="{
        open: false,
        working: false,
        message: '',
        microphoneEnabled: true,
        locationEnabled: true,
        filesEnabled: true,
        microphone: 'Not requested',
        location: 'Not requested',
        files: 'Ready when you upload',
        init() {
            if (window.localStorage.getItem('truthguard-device-permissions-complete') !== '1') {
                window.setTimeout(() => { this.open = true; }, 450);
            }
        },
        async togglePermission(type) {
            if (type === 'files') {
                this.filesEnabled = !this.filesEnabled;
                this.files = this.filesEnabled ? 'Ready when you upload' : 'Off';
                return;
            }

            const enabledKey = `${type}Enabled`;
            this[enabledKey] = !this[enabledKey];

            if (! this[enabledKey]) {
                this[type] = 'Off';
                return;
            }

            await this.requestPermission(type);
        },
        async requestPermission(type) {
            this.working = true;
            this.message = 'Allow the browser permission when prompted.';

            if (type === 'microphone') {
                if (navigator.mediaDevices?.getUserMedia) {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        stream.getTracks().forEach((track) => track.stop());
                        this.microphone = 'Allowed';
                    } catch (error) {
                        this.microphone = error?.name === 'NotAllowedError' ? 'Blocked' : 'Unavailable';
                        this.microphoneEnabled = false;
                    }
                } else {
                    this.microphone = 'Unsupported';
                    this.microphoneEnabled = false;
                }
            }

            if (type === 'location') {
                if (navigator.geolocation) {
                    await new Promise((resolve) => {
                        navigator.geolocation.getCurrentPosition(
                            () => { this.location = 'Allowed'; resolve(); },
                            () => { this.location = 'Blocked'; this.locationEnabled = false; resolve(); },
                            { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
                        );
                    });
                } else {
                    this.location = 'Unsupported';
                    this.locationEnabled = false;
                }
            }

            this.working = false;
            this.message = 'You can change these permissions anytime in your browser settings.';
        },
        async allowAll() {
            if (this.working) return;
            if (this.microphoneEnabled) await this.requestPermission('microphone');
            if (this.locationEnabled) await this.requestPermission('location');
            this.files = this.filesEnabled ? 'Ready when you upload' : 'Off';
        },
        finish() {
            window.localStorage.setItem('truthguard-device-permissions-complete', '1');
            this.open = false;
        }
    }"
    x-show="open"
    x-cloak
    x-transition.opacity
    class="fixed inset-0 z-[100000] flex items-end justify-center bg-slate-950/45 p-3 backdrop-blur-sm sm:items-center sm:p-6"
    role="dialog"
    aria-modal="true"
    aria-labelledby="truthguard-permissions-title"
>
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-y-4 opacity-0 sm:scale-95"
        x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
        class="w-full max-w-lg overflow-hidden rounded-[28px] border border-white/70 bg-white shadow-2xl shadow-slate-950/25"
        @click.outside="finish()"
    >
        <div class="bg-gradient-to-br from-slate-950 via-blue-950 to-violet-950 px-5 py-5 text-white sm:px-7 sm:py-6">
            <p class="text-[10px] font-black uppercase tracking-[0.2em] text-blue-200">One-time setup</p>
            <h2 id="truthguard-permissions-title" class="mt-2 text-xl font-black sm:text-2xl">Set up your TruthGuard workspace</h2>
            <p class="mt-2 text-sm leading-6 text-blue-100">Allow the tools you want to use for voice input, location-based checks, and evidence uploads.</p>
        </div>

        <div class="space-y-3 p-5 sm:p-7">
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-blue-100 bg-gradient-to-r from-blue-50/80 to-white p-3.5">
                <div><p class="text-sm font-black text-slate-900">Microphone</p><p class="mt-0.5 text-xs text-slate-500" x-text="microphone"></p></div>
                <button type="button" role="switch" :aria-checked="microphoneEnabled" @click="togglePermission('microphone')" class="relative h-7 w-12 shrink-0 rounded-full p-1 transition" :class="microphoneEnabled ? 'bg-blue-600 shadow-lg shadow-blue-500/25' : 'bg-slate-300'" aria-label="Toggle microphone permission"><span class="block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="microphoneEnabled ? 'translate-x-5' : 'translate-x-0'"></span></button>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-cyan-100 bg-gradient-to-r from-cyan-50/80 to-white p-3.5">
                <div><p class="text-sm font-black text-slate-900">Location</p><p class="mt-0.5 text-xs text-slate-500" x-text="location"></p></div>
                <button type="button" role="switch" :aria-checked="locationEnabled" @click="togglePermission('location')" class="relative h-7 w-12 shrink-0 rounded-full p-1 transition" :class="locationEnabled ? 'bg-cyan-600 shadow-lg shadow-cyan-500/25' : 'bg-slate-300'" aria-label="Toggle location permission"><span class="block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="locationEnabled ? 'translate-x-5' : 'translate-x-0'"></span></button>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-2xl border border-violet-100 bg-gradient-to-r from-violet-50/80 to-white p-3.5">
                <div><p class="text-sm font-black text-slate-900">Evidence files</p><p class="mt-0.5 text-xs text-slate-500" x-text="files"></p></div>
                <button type="button" role="switch" :aria-checked="filesEnabled" @click="togglePermission('files')" class="relative h-7 w-12 shrink-0 rounded-full p-1 transition" :class="filesEnabled ? 'bg-violet-600 shadow-lg shadow-violet-500/25' : 'bg-slate-300'" aria-label="Toggle evidence file access"><span class="block h-5 w-5 rounded-full bg-white shadow-sm transition" :class="filesEnabled ? 'translate-x-5' : 'translate-x-0'"></span></button>
            </div>

            <p x-show="message" x-text="message" class="pt-1 text-xs leading-5 text-slate-500"></p>

            <div class="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button type="button" @click="finish()" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-black text-slate-600 hover:bg-slate-50">Do this later</button>
                <button type="button" @click="allowAll()" :disabled="working" class="rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-blue-500/20 disabled:opacity-60" x-text="working ? 'Requesting…' : 'Allow selected'"></button>
            </div>
        </div>
    </div>
</div>
@endauth
