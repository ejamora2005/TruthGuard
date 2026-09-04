const INSTALL_DISMISSED_KEY = 'truthguard:pwa-install-dismissed';
const INSTALL_UNAVAILABLE_MESSAGE =
    'Install prompt needs HTTPS. You can still use your browser menu to add TruthGuard to your home screen.';

const boundInstallButtons = new WeakSet();

const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

const isMobileViewport = () => window.matchMedia('(max-width: 767px)').matches;

const shouldShowLaunchSplash = () => isStandalone() && isMobileViewport();

const onReady = (callback) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
        return;
    }

    callback();
};

const getInstallButtons = () => Array.from(document.querySelectorAll('.js-install-app'));

const getInstallHelpElements = () =>
    Array.from(new Set([...document.querySelectorAll('#install-help'), ...document.querySelectorAll('[data-pwa-install-help]')]));

const setButtonLabel = (button, label) => {
    const labelTarget = button.querySelector('[data-pwa-install-label]');

    if (labelTarget) {
        labelTarget.textContent = label;
    } else {
        button.textContent = label;
    }

    button.setAttribute('aria-label', label);
};

const setInstallHelp = (message = '') => {
    getInstallHelpElements().forEach((element) => {
        element.textContent = message;
        element.hidden = message.length === 0;
        element.classList.toggle('hidden', message.length === 0);
    });
};

const setInstallControls = ({ label = 'Install App', disabled = false, help = '' } = {}) => {
    getInstallButtons().forEach((button) => {
        setButtonLabel(button, label);
        button.disabled = disabled;
        button.classList.toggle('opacity-60', disabled);
        button.classList.toggle('cursor-not-allowed', disabled);
    });

    setInstallHelp(help);
};

const isLikelyIos = () => /iphone|ipad|ipod/i.test(window.navigator.userAgent);

const isLikelyAndroid = () => /android/i.test(window.navigator.userAgent);

const isLocalHttp = () =>
    window.location.protocol === 'http:' &&
    !['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname);

const installInstructionSteps = () => {
    if (isLikelyIos()) {
        return [
            'Open this page in Safari.',
            'Tap the Share button.',
            'Choose Add to Home Screen, then tap Add.'
        ];
    }

    if (isLikelyAndroid()) {
        return [
            'Open this page in Chrome.',
            'Tap the three-dot menu.',
            'Choose Install app or Add to Home screen, then confirm.'
        ];
    }

    return [
        'Open this page in Chrome or Edge.',
        'Use the install icon in the address bar or browser menu.',
        'Confirm Install app.'
    ];
};

const createInstallGuide = () => {
    const existingGuide = document.getElementById('truthguard-install-guide');

    if (existingGuide) {
        return existingGuide;
    }

    const guide = document.createElement('div');
    guide.id = 'truthguard-install-guide';
    guide.className = 'truthguard-install-guide-backdrop';
    guide.hidden = true;
    guide.innerHTML = `
        <section class="truthguard-install-guide" role="dialog" aria-modal="true" aria-labelledby="truthguard-install-guide-title">
            <button type="button" class="truthguard-install-guide-close" data-truthguard-install-close aria-label="Close install guide">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
            <div class="truthguard-install-guide-mark" aria-hidden="true">
                <img src="/pwa/icon-192.png" alt="">
            </div>
            <div>
                <p class="truthguard-install-guide-kicker">TruthGuard App</p>
                <h2 id="truthguard-install-guide-title">Install from your phone browser</h2>
                <p class="truthguard-install-guide-copy">The automatic install prompt is not available in this browser session.</p>
            </div>
            <ol class="truthguard-install-guide-steps"></ol>
            <p class="truthguard-install-guide-note"></p>
            <button type="button" class="truthguard-install-guide-action" data-truthguard-install-close>Got it</button>
        </section>
    `;

    guide.addEventListener('click', (event) => {
        if (event.target === guide || event.target.closest('[data-truthguard-install-close]')) {
            hideInstallGuide();
        }
    });

    document.body.appendChild(guide);

    return guide;
};

const showInstallGuide = () => {
    const guide = createInstallGuide();
    const steps = installInstructionSteps();
    const stepsList = guide.querySelector('.truthguard-install-guide-steps');
    const copy = guide.querySelector('.truthguard-install-guide-copy');
    const note = guide.querySelector('.truthguard-install-guide-note');

    if (stepsList) {
        stepsList.innerHTML = steps.map((step) => `<li>${step}</li>`).join('');
    }

    if (copy) {
        copy.textContent = isLocalHttp()
            ? 'Your local Wi-Fi link uses HTTP, so the native PWA install prompt is blocked.'
            : 'This browser did not expose the native install prompt yet.';
    }

    if (note) {
        note.textContent = isLocalHttp()
            ? 'For a full PWA install, open TruthGuard through an HTTPS tunnel such as ngrok or deploy it with HTTPS.'
            : 'If the app is already installed, open it from your home screen or apps list.';
    }

    guide.hidden = false;
    window.requestAnimationFrame(() => guide.classList.add('is-visible'));
    guide.querySelector('[data-truthguard-install-close]')?.focus();
};

const hideInstallGuide = () => {
    const guide = document.getElementById('truthguard-install-guide');

    if (!guide) {
        return;
    }

    guide.classList.remove('is-visible');
    window.setTimeout(() => {
        guide.hidden = true;
    }, 180);
};

const hideFloatingInstallButton = () => {
    const button = document.getElementById('truthguard-pwa-install');

    if (button) {
        button.hidden = true;
    }
};

const handleInstallClick = async (event) => {
    event.preventDefault();

    if (isStandalone()) {
        setInstallControls({
            label: 'App Installed',
            disabled: true,
            help: 'TruthGuard is already running as an installed app.'
        });
        hideFloatingInstallButton();
        return;
    }

    if (!deferredInstallPrompt) {
        setInstallControls({
            label: 'Install App',
            disabled: false,
            help: INSTALL_UNAVAILABLE_MESSAGE
        });
        showInstallGuide();
        return;
    }

    setInstallControls({ label: 'Installing...', disabled: true });
    deferredInstallPrompt.prompt();

    try {
        const choice = await deferredInstallPrompt.userChoice;

        if (choice?.outcome === 'accepted') {
            setInstallControls({
                label: 'Installing...',
                disabled: true,
                help: 'Installation started. You can launch TruthGuard from your apps list after it finishes.'
            });
        } else {
            sessionStorage.setItem(INSTALL_DISMISSED_KEY, '1');
            setInstallControls({
                label: 'Install App',
                disabled: false,
                help: INSTALL_UNAVAILABLE_MESSAGE
            });
        }
    } finally {
        deferredInstallPrompt = null;
        hideFloatingInstallButton();
    }
};

const bindInstallControls = () => {
    getInstallButtons().forEach((button) => {
        if (boundInstallButtons.has(button)) {
            return;
        }

        boundInstallButtons.add(button);
        button.addEventListener('click', handleInstallClick);
    });
};

const createInstallButton = () => {
    const existingButton = document.getElementById('truthguard-pwa-install');

    if (existingButton) {
        return existingButton;
    }

    const button = document.createElement('button');
    button.id = 'truthguard-pwa-install';
    button.type = 'button';
    button.className = 'truthguard-pwa-install-button js-install-app';
    button.textContent = 'Install app';
    button.dataset.pwaFloating = 'true';
    button.hidden = true;
    document.body.appendChild(button);

    return button;
};

const createOfflinePill = () => {
    const existingPill = document.getElementById('truthguard-offline-pill');

    if (existingPill) {
        return existingPill;
    }

    const pill = document.createElement('div');
    pill.id = 'truthguard-offline-pill';
    pill.className = 'truthguard-offline-pill';
    pill.setAttribute('role', 'status');
    pill.setAttribute('aria-live', 'polite');
    pill.textContent = 'Offline. Reconnect to continue live checks.';
    pill.hidden = true;
    document.body.appendChild(pill);

    return pill;
};

const updateOfflineState = (isOffline) => {
    onReady(() => {
        const pill = createOfflinePill();
        pill.hidden = !isOffline;
    });
};

const initLaunchSplash = () => {
    const splash = document.querySelector('[data-truthguard-splash]');

    if (!splash) {
        return;
    }

    if (!shouldShowLaunchSplash()) {
        splash.remove();
        document.documentElement.classList.remove('truthguard-splash-lock');
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const minimumDuration = prefersReducedMotion ? 120 : Number(splash.dataset.minDuration || 900);
    const startedAt = performance.now();
    let dismissed = false;

    splash.classList.add('is-visible');
    document.documentElement.classList.add('truthguard-splash-lock');

    const dismiss = () => {
        if (dismissed) {
            return;
        }

        dismissed = true;
        const remaining = Math.max(0, minimumDuration - (performance.now() - startedAt));

        window.setTimeout(() => {
            splash.classList.add('is-leaving');
            splash.setAttribute('aria-hidden', 'true');
            document.documentElement.classList.remove('truthguard-splash-lock');

            window.setTimeout(() => {
                splash.remove();
            }, prefersReducedMotion ? 0 : 380);
        }, remaining);
    };

    if (document.readyState === 'complete') {
        dismiss();
    } else {
        window.addEventListener('load', dismiss, { once: true });
        window.setTimeout(dismiss, 2600);
    }
};

onReady(initLaunchSplash);

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                if (registration.waiting) {
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }

                registration.addEventListener('updatefound', () => {
                    const installingWorker = registration.installing;

                    if (!installingWorker) {
                        return;
                    }

                    installingWorker.addEventListener('statechange', () => {
                        if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            installingWorker.postMessage({ type: 'SKIP_WAITING' });
                        }
                    });
                });
            })
            .catch(() => undefined);
    });
}

let deferredInstallPrompt = null;

window.addEventListener('beforeinstallprompt', (event) => {
    if (isStandalone()) {
        return;
    }

    event.preventDefault();
    deferredInstallPrompt = event;

    onReady(() => {
        if (getInstallButtons().length === 0 && sessionStorage.getItem(INSTALL_DISMISSED_KEY) !== '1') {
            createInstallButton().hidden = false;
        }

        bindInstallControls();
        setInstallControls({
            label: 'Install App',
            disabled: false,
            help: 'Click Install App to add TruthGuard to your device.'
        });
    });
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    sessionStorage.removeItem(INSTALL_DISMISSED_KEY);
    hideFloatingInstallButton();
    setInstallControls({
        label: 'App Installed',
        disabled: true,
        help: 'Installation complete. You can launch TruthGuard from your apps list.'
    });
});

window.addEventListener('offline', () => updateOfflineState(true));
window.addEventListener('online', () => updateOfflineState(false));
window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        hideInstallGuide();
    }
});

onReady(() => updateOfflineState(!navigator.onLine));

onReady(() => {
    bindInstallControls();

    if (isStandalone()) {
        setInstallControls({
            label: 'App Installed',
            disabled: true,
            help: 'TruthGuard is already running as an installed app.'
        });
    } else {
        setInstallControls({ label: 'Install App', disabled: false });
    }

    if ('MutationObserver' in window) {
        new MutationObserver(() => bindInstallControls()).observe(document.body, {
            childList: true,
            subtree: true
        });
    }
});

if ('fetch' in window) {
    const nativeFetch = window.fetch.bind(window);

    window.fetch = (...args) =>
        nativeFetch(...args).catch((error) => {
            if (!navigator.onLine) {
                updateOfflineState(true);
            }

            throw error;
        });
}
