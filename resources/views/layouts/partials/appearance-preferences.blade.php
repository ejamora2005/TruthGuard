<script>
    // Apply before paint so saved reading preferences survive every page load.
    (() => {
        const root = document.documentElement;
        const system = window.matchMedia('(prefers-color-scheme: dark)');
        const allowedModes = ['light', 'dark', 'system'];
        const allowedSizes = ['standard', 'large', 'extra-large'];
        const read = (key, allowed, fallback) => {
            try {
                const value = localStorage.getItem(key);
                return allowed.includes(value) ? value : fallback;
            } catch { return fallback; }
        };
        let mode = read('truthguard-display-mode', allowedModes, 'system');
        let textSize = read('truthguard-text-size', allowedSizes, 'standard');
        root.dataset.tgWorkspace = '';
        const apply = () => {
            const dark = mode === 'dark' || (mode === 'system' && system.matches);
            root.classList.toggle('dark', dark);
            root.dataset.displayMode = mode;
            root.dataset.textSize = textSize;
            root.style.colorScheme = dark ? 'dark' : 'light';
            window.dispatchEvent(new CustomEvent('truthguard-appearance-updated', { detail: { mode, textSize } }));
        };
        window.TruthGuardAppearance = {
            get preferences() { return { mode, textSize }; },
            toggle() {
                this.set({ mode: root.classList.contains('dark') ? 'light' : 'dark' });
            },
            set(preferences) {
                if (allowedModes.includes(preferences.mode)) mode = preferences.mode;
                if (allowedSizes.includes(preferences.textSize)) textSize = preferences.textSize;
                try {
                    localStorage.setItem('truthguard-display-mode', mode);
                    localStorage.setItem('truthguard-text-size', textSize);
                } catch {}
                apply();
            },
        };
        system.addEventListener('change', () => { if (mode === 'system') apply(); });
        window.addEventListener('storage', (event) => {
            if (event.key !== null && !['truthguard-display-mode', 'truthguard-text-size'].includes(event.key)) return;
            mode = read('truthguard-display-mode', allowedModes, 'system');
            textSize = read('truthguard-text-size', allowedSizes, 'standard');
            apply();
        });
        apply();
    })();
</script>
