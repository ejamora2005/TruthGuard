// Password saving belongs to the browser's credential manager, never web storage.
window.truthguardLoginOptions = (rememberInApp = true) => ({
    authLoading: null,
    authError: '',
    isPwa: window.truthguardStandalone === true,
    rememberDevice: window.truthguardStandalone === true && rememberInApp,
    rememberPassword: false,
    modeListeners: [],

    init() {
        this.syncMode();
        for (const mode of ['standalone', 'minimal-ui', 'window-controls-overlay', 'fullscreen']) {
            const query = window.matchMedia(`(display-mode: ${mode})`);
            const listener = () => this.syncMode();
            query.addEventListener?.('change', listener);
            this.modeListeners.push(() => query.removeEventListener?.('change', listener));
        }
    },

    destroy() {
        this.modeListeners.forEach(remove => remove());
    },

    syncMode() {
        // Fullscreen in a normal browser does not turn that window into a PWA.
        const standalone = window.navigator.standalone === true
            || ['standalone', 'minimal-ui', 'window-controls-overlay'].some(mode => window.matchMedia(`(display-mode: ${mode})`).matches);
        const next = standalone || (this.isPwa && window.matchMedia('(display-mode: fullscreen)').matches);
        if (this.isPwa !== next) {
            this.isPwa = next;
            this.rememberDevice = next && rememberInApp;
            this.rememberPassword = false;
        }
        if (!this.isPwa) this.rememberDevice = false;
    },

    async submitLogin(event) {
        if (this.authLoading !== null) {
            event.preventDefault();
            return;
        }
        this.syncMode();
        const form = event.target;
        form.elements.pwa.value = this.isPwa ? '1' : '0';
        const appRemember = form.querySelector('input[type="checkbox"][name="remember"]');
        appRemember.disabled = !this.isPwa;
        appRemember.checked = this.isPwa && this.rememberDevice;
        this.authError = '';
        this.authLoading = 'password';
        const supportsPasswordSave = window.isSecureContext
            && typeof window.PasswordCredential === 'function'
            && typeof navigator.credentials?.store === 'function';
        // Keep normal form submission, autofill and browser saving as the fallback.
        if (this.isPwa || !this.rememberPassword || !supportsPasswordSave) return;

        event.preventDefault();
        const data = new FormData(form);
        data.set('pwa', '0');
        data.set('remember', '0');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.authenticated !== true || typeof result.redirect !== 'string') {
                this.authError = response.status === 419 ? 'Your sign-in page expired. Refresh the page and try again.'
                    : Object.values(result.errors || {}).flat()[0] || 'Sign-in could not be completed. Please try again.';
                this.authLoading = null;
                return;
            }
            const destination = new URL(result.redirect, window.location.href);
            if (destination.origin !== window.location.origin) throw new Error('Invalid sign-in destination');

            // Offer saving only after the server has verified this exact password.
            try {
                await navigator.credentials.store(new PasswordCredential({
                    id: String(data.get('email')), password: String(data.get('password')),
                }));
            } catch {
                // A declined or unavailable password manager must not undo a login.
            }
            window.location.assign(destination.href);
        } catch {
            this.authError = 'Could not confirm sign-in. Check your connection and try again.';
            this.authLoading = null;
        } finally {
            data.delete('password');
        }
    },
});
