@auth
    @php
        $truthguardSessionTimeout = app(\App\Services\Auth\SessionTimeoutManager::class)->payload(request());
    @endphp

    <style>
        .truthguard-session-timeout-backdrop[hidden] {
            display: none !important;
        }

        .truthguard-session-timeout-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2147483600;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, 0.52);
            backdrop-filter: blur(10px);
        }

        .truthguard-session-timeout-dialog {
            width: min(28rem, calc(100vw - 2rem));
            overflow: hidden;
            border: 1px solid rgba(191, 219, 254, 0.82);
            border-radius: 1.5rem;
            background:
                radial-gradient(circle at 12% 0%, rgba(255, 255, 255, 0.98), transparent 36%),
                linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.92));
            box-shadow:
                0 28px 70px rgba(15, 23, 42, 0.24),
                inset 0 1px 0 rgba(255, 255, 255, 0.92);
        }

        .truthguard-session-timeout-icon {
            display: inline-flex;
            height: 2.9rem;
            width: 2.9rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: 1rem;
            color: #2563eb;
            background:
                linear-gradient(135deg, rgba(219, 234, 254, 0.96), rgba(224, 242, 254, 0.82));
            box-shadow:
                0 12px 26px rgba(37, 99, 235, 0.16),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }
    </style>

    <script type="application/json" id="truthguard-session-timeout-config">@json($truthguardSessionTimeout)</script>

    <div
        id="truthguard-session-timeout-modal"
        class="truthguard-session-timeout-backdrop"
        role="dialog"
        aria-modal="true"
        aria-labelledby="truthguard-session-timeout-title"
        aria-describedby="truthguard-session-timeout-description"
        hidden
    >
        <div class="truthguard-session-timeout-dialog">
            <div class="space-y-5 p-5 sm:p-6">
                <div class="flex items-start gap-4">
                    <span class="truthguard-session-timeout-icon" aria-hidden="true">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.5 2"></path>
                            <circle cx="12" cy="12" r="8.5"></circle>
                        </svg>
                    </span>

                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">Session expiring</p>
                        <h2 id="truthguard-session-timeout-title" class="mt-2 text-xl font-bold text-slate-950">Still working in TruthGuard?</h2>
                        <p id="truthguard-session-timeout-description" class="mt-2 text-sm leading-6 text-slate-600">
                            For your security, this session will expire in
                            <span id="truthguard-session-countdown" class="font-bold text-slate-950">60 seconds</span>.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-blue-100 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        type="button"
                        id="truthguard-session-logout-button"
                        class="inline-flex min-h-11 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-bold text-slate-700 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600"
                    >
                        Log Out
                    </button>

                    <button
                        type="button"
                        id="truthguard-session-stay-button"
                        class="inline-flex min-h-11 items-center justify-center rounded-2xl bg-blue-600 px-5 text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700 disabled:cursor-wait disabled:opacity-70"
                    >
                        Stay Signed In
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form id="truthguard-session-timeout-logout-form" method="POST" action="{{ route('logout', absolute: false) }}" class="hidden">
        @csrf
    </form>

    <form id="truthguard-session-timeout-expire-form" method="POST" action="{{ route('auth.session.expire', absolute: false) }}" class="hidden">
        @csrf
    </form>

    <script>
        (() => {
            if (window.TruthGuardSessionTimeoutBooted) {
                return;
            }

            const configElement = document.getElementById('truthguard-session-timeout-config');
            const modal = document.getElementById('truthguard-session-timeout-modal');
            const countdown = document.getElementById('truthguard-session-countdown');
            const stayButton = document.getElementById('truthguard-session-stay-button');
            const logoutButton = document.getElementById('truthguard-session-logout-button');
            const logoutForm = document.getElementById('truthguard-session-timeout-logout-form');
            const expireForm = document.getElementById('truthguard-session-timeout-expire-form');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (!configElement || !modal || !countdown || !stayButton || !logoutButton || !expireForm) {
                return;
            }

            let config = JSON.parse(configElement.textContent || '{}');
            let expiresAtMs = Number(config.expiresAt || 0) * 1000;
            let warningMs = Number(config.warningSeconds || 60) * 1000;
            let expirationSubmitted = false;
            let tickTimer = null;

            const formatSeconds = (seconds) => `${seconds} ${seconds === 1 ? 'second' : 'seconds'}`;

            const secondsRemaining = () => Math.max(0, Math.ceil((expiresAtMs - Date.now()) / 1000));

            const showModal = () => {
                modal.hidden = false;
                stayButton.focus({ preventScroll: true });
            };

            const hideModal = () => {
                modal.hidden = true;
            };

            const scheduleTick = () => {
                window.clearTimeout(tickTimer);
                tickTimer = window.setTimeout(tick, 1000);
            };

            const submitExpiration = () => {
                if (expirationSubmitted) {
                    return;
                }

                expirationSubmitted = true;
                expireForm.submit();
            };

            const tick = () => {
                const remaining = secondsRemaining();
                countdown.textContent = formatSeconds(remaining);

                if (remaining <= 0) {
                    submitExpiration();
                    return;
                }

                if ((remaining * 1000) <= warningMs) {
                    showModal();
                } else {
                    hideModal();
                }

                scheduleTick();
            };

            stayButton.addEventListener('click', async () => {
                stayButton.disabled = true;

                try {
                    const response = await fetch(config.keepAliveUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        try {
                            const payload = await response.json();
                            window.location.assign(payload.redirect_url || config.loginUrl);
                        } catch (error) {
                            window.location.assign(config.loginUrl);
                        }

                        return;
                    }

                    const payload = await response.json();
                    config = { ...config, ...payload };
                    expiresAtMs = Number(config.expiresAt || 0) * 1000;
                    warningMs = Number(config.warningSeconds || 60) * 1000;
                    expirationSubmitted = false;
                    hideModal();
                    tick();
                } catch (error) {
                    submitExpiration();
                } finally {
                    stayButton.disabled = false;
                }
            });

            logoutButton.addEventListener('click', () => {
                if (logoutForm) {
                    logoutForm.submit();
                    return;
                }

                submitExpiration();
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    tick();
                }
            });

            window.TruthGuardSessionTimeoutBooted = true;
            tick();
        })();
    </script>
@endauth
