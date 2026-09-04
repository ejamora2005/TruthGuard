@php
    $showOnboardingTour = (bool) ($showOnboardingTour ?? false);
    $tourSteps = [
        [
            'title' => 'Dashboard',
            'targets' => ['dashboard'],
            'text' => 'Start here to see the latest public claim reviews, recent activity, and your TruthGuard overview.',
        ],
        [
            'title' => 'Fact Check',
            'targets' => ['fact-check'],
            'text' => 'Upload an image, video, PDF, or paste a source link to generate an AI-assisted fact-check report.',
        ],
        [
            'title' => 'History',
            'targets' => ['history'],
            'text' => 'Review your recent fact-check records. TruthGuard keeps only the latest 7 days of user fact-check history.',
        ],
        [
            'title' => 'Notifications',
            'targets' => ['notifications-bell', 'notifications'],
            'text' => 'Get updates when fact-check results are ready, when public claim reviews are added, and when important account alerts arrive.',
        ],
        [
            'title' => 'Result Reports',
            'targets' => ['result-reports'],
            'text' => 'Each report includes an AI answer, risk score, basis used by AI, verification sources, and recommendations.',
        ],
        [
            'title' => 'Profile and Settings',
            'targets' => ['profile', 'settings'],
            'text' => 'Manage your profile, security, appearance, notification preferences, and privacy settings here.',
        ],
        [
            'title' => 'You are ready',
            'targets' => [],
            'text' => 'You are ready to use TruthGuard. Start by scanning a claim or reviewing the latest public fact checks.',
        ],
    ];
@endphp

@if ($showOnboardingTour)
    @once
        <style>
            .truthguard-tour-layer {
                position: fixed;
                inset: 0;
                z-index: 100000;
                pointer-events: auto;
                isolation: isolate;
            }

            .truthguard-tour-scrim {
                position: absolute;
                inset: 0;
                background: rgba(15, 23, 42, 0.58);
                animation: truthguard-tour-fade-in 160ms ease-out both;
            }

            .truthguard-tour-highlight {
                position: fixed;
                z-index: 100001;
                border: 2px solid rgba(255, 255, 255, 0.95);
                border-radius: 18px;
                background: rgba(255, 255, 255, 0.08);
                box-shadow:
                    0 0 0 4px rgba(37, 99, 235, 0.38),
                    0 18px 45px rgba(15, 23, 42, 0.26);
                pointer-events: none;
                transition:
                    left 220ms ease,
                    top 220ms ease,
                    width 220ms ease,
                    height 220ms ease,
                    opacity 160ms ease;
            }

            .truthguard-tour-card {
                position: fixed;
                z-index: 100002;
                width: min(22.5rem, calc(100vw - 2rem));
                overflow: hidden;
                border: 1px solid #dbe4f0;
                border-radius: 18px;
                background: #ffffff;
                box-shadow: 0 24px 70px rgba(15, 23, 42, 0.26);
                outline: none;
                color: #0f172a;
                -webkit-font-smoothing: antialiased;
                text-rendering: optimizeLegibility;
                animation: truthguard-tour-card-in 180ms ease-out both;
                transition:
                    left 220ms ease,
                    top 220ms ease,
                    opacity 160ms ease;
            }

            .truthguard-tour-body {
                opacity: 1;
                transform: translateY(0);
                transition: opacity 130ms ease, transform 130ms ease;
            }

            .truthguard-tour-body.is-transitioning {
                opacity: 0;
                transform: translateY(4px);
            }

            .truthguard-tour-icon {
                background: #eff6ff;
                color: #2563eb;
                border: 1px solid #bfdbfe;
            }

            .truthguard-tour-kicker {
                color: #2563eb;
                letter-spacing: 0.14em;
            }

            .truthguard-tour-copy {
                color: #475569;
            }

            .truthguard-tour-progress {
                height: 4px;
                overflow: hidden;
                border-radius: 999px;
                background: #e2e8f0;
            }

            .truthguard-tour-progress span {
                display: block;
                height: 100%;
                border-radius: inherit;
                background: #2563eb;
                transition: width 220ms ease;
            }

            .truthguard-tour-dot {
                height: 6px;
                width: 6px;
                border-radius: 999px;
                background: #cbd5e1;
                transition: width 160ms ease, background-color 160ms ease;
            }

            .truthguard-tour-dot.is-active {
                width: 18px;
                background: #2563eb;
            }

            @keyframes truthguard-tour-fade-in {
                from {
                    opacity: 0;
                }
                to {
                    opacity: 1;
                }
            }

            @keyframes truthguard-tour-card-in {
                from {
                    opacity: 0;
                    transform: translateY(8px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            @media (max-width: 640px) {
                .truthguard-tour-card {
                    bottom: 1rem !important;
                    left: 1rem !important;
                    top: auto !important;
                    width: calc(100vw - 2rem);
                }
            }

            @media (prefers-reduced-motion: reduce) {
                .truthguard-tour-scrim,
                .truthguard-tour-card {
                    animation: none;
                }

                .truthguard-tour-card,
                .truthguard-tour-highlight,
                .truthguard-tour-body,
                .truthguard-tour-progress span,
                .truthguard-tour-dot {
                    transition: none;
                }
            }
        </style>

        <script>
            window.truthguardOnboardingTour = function (config) {
                return {
                    active: true,
                    currentIndex: 0,
                    saving: false,
                    transitioning: false,
                    error: '',
                    highlights: [],
                    panelStyle: 'left: 16px; top: 16px;',
                    resizeHandler: null,
                    scrollHandler: null,
                    steps: config.steps || [],
                    csrfToken: config.csrfToken,
                    completeUrl: config.completeUrl,
                    skipUrl: config.skipUrl,
                    get currentStep() {
                        return this.steps[this.currentIndex] || {};
                    },
                    get isFirst() {
                        return this.currentIndex === 0;
                    },
                    get isLast() {
                        return this.currentIndex >= this.steps.length - 1;
                    },
                    get progressLabel() {
                        return `${this.currentIndex + 1} of ${this.steps.length}`;
                    },
                    get progressPercent() {
                        return `${((this.currentIndex + 1) / Math.max(this.steps.length, 1)) * 100}%`;
                    },
                    init() {
                        document.documentElement.classList.add('overflow-hidden');
                        this.resizeHandler = () => this.positionTour();
                        this.scrollHandler = () => this.positionTour();
                        window.addEventListener('resize', this.resizeHandler);
                        window.addEventListener('scroll', this.scrollHandler, true);
                        this.$nextTick(() => {
                            this.positionTour();
                            this.$refs.panel?.focus();
                        });
                    },
                    destroy() {
                        document.documentElement.classList.remove('overflow-hidden');
                        if (this.resizeHandler) window.removeEventListener('resize', this.resizeHandler);
                        if (this.scrollHandler) window.removeEventListener('scroll', this.scrollHandler, true);
                    },
                    targetElement() {
                        const targets = this.currentStep.targets || [];

                        for (const target of targets) {
                            const elements = Array.from(document.querySelectorAll(`[data-tour="${target}"]`));
                            const visible = elements.find((element) => {
                                const rect = element.getBoundingClientRect();

                                return rect.width > 0
                                    && rect.height > 0
                                    && rect.bottom > 0
                                    && rect.right > 0
                                    && rect.top < window.innerHeight
                                    && rect.left < window.innerWidth;
                            });

                            if (visible) return visible;
                        }

                        return null;
                    },
                    centerPanel() {
                        const panel = this.$refs.panel;
                        const panelWidth = Math.min(panel?.offsetWidth || 360, window.innerWidth - 32);
                        const panelHeight = Math.min(panel?.offsetHeight || 260, window.innerHeight - 32);
                        const left = Math.max(16, Math.round((window.innerWidth - panelWidth) / 2));
                        const top = Math.max(16, Math.round((window.innerHeight - panelHeight) / 2));

                        this.panelStyle = `left: ${left}px; top: ${top}px;`;
                    },
                    positionTour() {
                        const target = this.targetElement();
                        const padding = 10;

                        if (!target) {
                            this.highlights = [];
                            this.centerPanel();
                            return;
                        }

                        const rect = target.getBoundingClientRect();
                        const highlight = {
                            left: Math.max(8, rect.left - padding),
                            top: Math.max(8, rect.top - padding),
                            width: Math.min(window.innerWidth - 16, rect.width + (padding * 2)),
                            height: Math.min(window.innerHeight - 16, rect.height + (padding * 2)),
                        };

                        this.highlights = [highlight];

                        const panel = this.$refs.panel;
                        const panelWidth = Math.min(panel?.offsetWidth || 360, window.innerWidth - 32);
                        const panelHeight = Math.min(panel?.offsetHeight || 260, window.innerHeight - 32);
                        const gutter = 16;
                        let left;
                        let top = highlight.top + (highlight.height / 2) - (panelHeight / 2);

                        if (highlight.left + highlight.width + gutter + panelWidth <= window.innerWidth - 16) {
                            left = highlight.left + highlight.width + gutter;
                        } else if (highlight.left - gutter - panelWidth >= 16) {
                            left = highlight.left - gutter - panelWidth;
                        } else {
                            left = Math.max(16, Math.round((window.innerWidth - panelWidth) / 2));
                            top = highlight.top + highlight.height + gutter;
                        }

                        if (top + panelHeight > window.innerHeight - 16) {
                            top = window.innerHeight - panelHeight - 16;
                        }

                        if (top < 16) {
                            top = 16;
                        }

                        this.panelStyle = `left: ${Math.round(left)}px; top: ${Math.round(top)}px;`;
                    },
                    changeStep(callback) {
                        if (this.transitioning || this.saving) return;

                        this.transitioning = true;
                        window.setTimeout(() => {
                            callback();
                            this.error = '';
                            this.$nextTick(() => {
                                this.positionTour();
                                this.$refs.panel?.focus();
                                window.setTimeout(() => this.transitioning = false, 30);
                            });
                        }, 90);
                    },
                    goNext() {
                        if (this.isLast) {
                            this.finish();
                            return;
                        }

                        this.changeStep(() => this.currentIndex += 1);
                    },
                    goBack() {
                        if (this.isFirst) return;

                        this.changeStep(() => this.currentIndex -= 1);
                    },
                    async persist(url) {
                        if (this.saving) return;

                        this.saving = true;
                        this.error = '';

                        try {
                            const response = await fetch(url, {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': this.csrfToken,
                                },
                                body: JSON.stringify({ version: config.version }),
                            });

                            if (!response.ok) {
                                throw new Error('Unable to save onboarding status.');
                            }

                            this.active = false;
                            this.destroy();
                        } catch (error) {
                            this.error = 'TruthGuard could not save this yet. Please try again.';
                        } finally {
                            this.saving = false;
                        }
                    },
                    finish() {
                        this.persist(this.completeUrl);
                    },
                    skip() {
                        this.persist(this.skipUrl);
                    },
                    trapFocus(event) {
                        const focusable = Array.from(this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'))
                            .filter((element) => !element.hasAttribute('disabled') && element.offsetParent !== null);

                        if (!focusable.length) return;

                        const first = focusable[0];
                        const last = focusable[focusable.length - 1];

                        if (event.shiftKey && document.activeElement === first) {
                            event.preventDefault();
                            last.focus();
                        } else if (!event.shiftKey && document.activeElement === last) {
                            event.preventDefault();
                            first.focus();
                        }
                    },
                };
            };
        </script>
    @endonce

    <div
        x-data="truthguardOnboardingTour({
            steps: @js($tourSteps),
            csrfToken: @js(csrf_token()),
            completeUrl: @js(route('onboarding.complete', absolute: false)),
            skipUrl: @js(route('onboarding.skip', absolute: false)),
            version: @js(config('app.onboarding_version', '2026-07-28')),
        })"
        x-init="init()"
        x-show="active"
        x-cloak
        class="truthguard-tour-layer"
        role="presentation"
        @keydown.escape.window.prevent.stop="skip()"
    >
        <div class="truthguard-tour-scrim" aria-hidden="true"></div>

        <template x-for="(rect, index) in highlights" :key="index">
            <div
                class="truthguard-tour-highlight"
                :style="`left: ${rect.left}px; top: ${rect.top}px; width: ${rect.width}px; height: ${rect.height}px;`"
                aria-hidden="true"
            ></div>
        </template>

        <section
            x-ref="panel"
            class="truthguard-tour-card"
            :style="panelStyle"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            aria-labelledby="truthguard-tour-title"
            aria-describedby="truthguard-tour-description"
            @keydown.tab="trapFocus($event)"
        >
            <div class="truthguard-tour-body p-5" :class="transitioning ? 'is-transitioning' : ''">
                <div class="flex items-start gap-3">
                    <span class="truthguard-tour-icon inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 12.25 1.75 1.75 3.5-4" />
                        </svg>
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truthguard-tour-kicker text-[11px] font-black uppercase">TruthGuard Tour</p>
                                <h2 id="truthguard-tour-title" class="mt-1 text-xl font-black tracking-tight text-slate-950" x-text="currentStep.title"></h2>
                            </div>

                            <button
                                type="button"
                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                @click="skip()"
                                :disabled="saving"
                                aria-label="Skip onboarding tour"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <p id="truthguard-tour-description" class="truthguard-tour-copy mt-3 text-sm font-medium leading-6" x-text="currentStep.text"></p>
                    </div>
                </div>

                <div class="truthguard-tour-progress mt-5" aria-hidden="true">
                    <span :style="`width: ${progressPercent};`"></span>
                </div>

                <div class="mt-4 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-1.5" aria-hidden="true">
                        <template x-for="(step, index) in steps" :key="step.title">
                            <span class="truthguard-tour-dot" :class="index === currentIndex ? 'is-active' : ''"></span>
                        </template>
                    </div>

                    <span class="text-xs font-bold text-slate-500" x-text="progressLabel"></span>
                </div>

                <p x-show="error" x-cloak class="mt-4 rounded-xl border border-rose-100 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700" x-text="error"></p>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-full px-3.5 py-2 text-sm font-bold text-slate-600 transition hover:bg-slate-200/70 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    @click="skip()"
                    :disabled="saving"
                >
                    Skip
                </button>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="goBack()"
                        :disabled="isFirst || saving"
                    >
                        Back
                    </button>

                    <button
                        type="button"
                        class="inline-flex min-w-[5.75rem] items-center justify-center rounded-full bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-70"
                        @click="goNext()"
                        :disabled="saving"
                    >
                        <span x-show="!saving" x-text="isLast ? 'Finish' : 'Next'"></span>
                        <span x-show="saving" x-cloak>Saving...</span>
                    </button>
                </div>
            </div>
        </section>
    </div>
@endif
