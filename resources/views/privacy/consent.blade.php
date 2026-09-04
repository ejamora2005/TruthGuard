@extends('layouts.auth-modern')

@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : null;
    $serverConsentError = $errors->first('privacy_policy_acceptance');
    $acceptedByOldInput = old('privacy_policy_acceptance') === '1';
@endphp

@section('content')
    <main class="relative min-h-dvh overflow-x-hidden bg-slate-50 px-4 py-5 text-slate-900 sm:px-6 lg:px-8">
        <style>
            [x-cloak] {
                display: none !important;
            }

            .tg-consent-bg {
                position: absolute;
                inset: 0;
                overflow: hidden;
                pointer-events: none;
            }

            .tg-consent-bg::before {
                content: '';
                position: absolute;
                inset: -3rem;
                opacity: 0.42;
                filter: blur(10px);
                background:
                    linear-gradient(90deg, rgba(37, 99, 235, 0.06) 1px, transparent 1px),
                    linear-gradient(180deg, rgba(37, 99, 235, 0.06) 1px, transparent 1px),
                    linear-gradient(180deg, #f8fbff 0%, #eef5ff 100%);
                background-size: 32px 32px, 32px 32px, auto;
                transform: scale(1.02);
            }

            .tg-consent-bg-card {
                position: absolute;
                border: 1px solid rgba(191, 219, 254, 0.7);
                border-radius: 24px;
                background: rgba(255, 255, 255, 0.74);
                box-shadow: 0 26px 70px rgba(15, 23, 42, 0.08);
                opacity: 0.52;
                filter: blur(6px);
            }

            .tg-consent-shell {
                border: 1px solid rgba(203, 213, 225, 0.9);
                background: rgba(255, 255, 255, 0.96);
                box-shadow:
                    0 24px 70px rgba(15, 23, 42, 0.12),
                    inset 0 1px 0 rgba(255, 255, 255, 0.96);
            }

            .tg-consent-policy {
                scrollbar-width: thin;
                scrollbar-color: #94a3b8 #f1f5f9;
            }

            .tg-consent-policy::-webkit-scrollbar {
                width: 0.65rem;
            }

            .tg-consent-policy::-webkit-scrollbar-track {
                background: #f1f5f9;
                border-radius: 999px;
            }

            .tg-consent-policy::-webkit-scrollbar-thumb {
                background: #94a3b8;
                border: 3px solid #f8fafc;
                border-radius: 999px;
            }

            .tg-formal-alert {
                border: 1px solid rgba(225, 29, 72, 0.22);
                background: #fff7f8;
                box-shadow: 0 12px 30px rgba(225, 29, 72, 0.08);
            }
        </style>

        <div class="tg-consent-bg" aria-hidden="true">
            <div class="tg-consent-bg-card left-[7%] top-[14%] h-28 w-72"></div>
            <div class="tg-consent-bg-card right-[8%] top-[9%] h-24 w-96"></div>
            <div class="tg-consent-bg-card bottom-[12%] left-[18%] h-40 w-[32rem]"></div>
            <div class="tg-consent-bg-card bottom-[18%] right-[13%] h-32 w-80"></div>
        </div>
        <div class="pointer-events-none absolute inset-0 bg-white/64"></div>

        <div class="relative z-10 mx-auto w-full max-w-6xl">
            <section
                class="tg-consent-shell overflow-hidden rounded-[24px]"
                x-data="{
                    accepted: @js($acceptedByOldInput),
                    consentAlert: @js($serverConsentError !== ''),
                    submitting: false,
                    submitConsent(event) {
                        if (!this.accepted) {
                            this.consentAlert = true;
                            this.$nextTick(() => this.$refs.consentAlert?.focus());
                            return;
                        }

                        this.submitting = true;
                        event.currentTarget.submit();
                    },
                    focusConsent() {
                        this.$refs.consentCheckbox?.focus();
                    },
                }"
            >
                <header class="border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-10 w-10 object-contain">
                            @else
                                <span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-sm font-black text-white">TG</span>
                            @endif
                            <span>
                                <span class="block text-lg font-black text-slate-950">TruthGuard</span>
                                <span class="block text-xs font-semibold text-slate-500">Privacy and AI processing</span>
                            </span>
                        </a>

                        <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                            <span class="rounded-full border border-blue-100 bg-blue-50 px-3 py-1.5 text-blue-700">Policy {{ $policyVersion }}</span>
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-slate-600">All accounts</span>
                        </div>
                    </div>
                </header>

                <div class="grid lg:grid-cols-[minmax(0,1fr)_23rem]">
                    <section class="min-w-0 p-5 sm:p-6 lg:p-8">
                        <div class="max-w-3xl">
                            <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Before you continue</p>
                            <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">Review your privacy notice</h1>
                            <p class="mt-3 text-sm leading-7 text-slate-600 sm:text-base">
                                Please read this notice once before entering your workspace. It explains how TruthGuard
                                processes account data and submitted evidence for AI-assisted fact-checking, notifications,
                                security, and platform operations.
                            </p>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="text-[11px] font-black uppercase tracking-[0.14em] text-slate-500">Purpose</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">Fact-checking service</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="text-[11px] font-black uppercase tracking-[0.14em] text-slate-500">Security</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">Protected sessions</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="text-[11px] font-black uppercase tracking-[0.14em] text-slate-500">AI use</p>
                                <p class="mt-1 text-sm font-bold text-slate-950">Assisted analysis</p>
                            </div>
                        </div>

                        <div class="tg-consent-policy mt-5 max-h-[58dvh] overflow-y-auto rounded-[20px] border border-slate-200 bg-white p-5 sm:p-6">
                            @include('privacy.partials.policy-content', ['policyVersion' => $policyVersion])
                        </div>
                    </section>

                    <aside class="border-t border-slate-200 bg-slate-50/90 p-5 sm:p-6 lg:border-l lg:border-t-0 lg:p-6">
                        <div class="lg:sticky lg:top-6">
                            <div class="rounded-[20px] border border-slate-200 bg-white p-5 shadow-sm">
                                <div class="flex items-start gap-3">
                                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 12.25 1.75 1.75 3.5-4" />
                                        </svg>
                                    </span>
                                    <div>
                                        <h2 class="text-base font-black text-slate-950">Consent required</h2>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Confirm the acknowledgement below to continue to your TruthGuard workspace.
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-5 space-y-3 text-sm text-slate-600">
                                    <div class="flex gap-2">
                                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600"></span>
                                        <p>Submitted evidence may be processed to generate AI-assisted reports.</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600"></span>
                                        <p>Important details should still be verified before sharing.</p>
                                    </div>
                                    <div class="flex gap-2">
                                        <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-blue-600"></span>
                                        <p>Your consent is saved to your account for this policy version.</p>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" action="{{ route('privacy.accept') }}" class="mt-4 space-y-4" novalidate @submit.prevent="submitConsent($event)">
                                @csrf

                                <div
                                    x-show="consentAlert"
                                    x-cloak
                                    x-transition.opacity.duration.160ms
                                    x-ref="consentAlert"
                                    tabindex="-1"
                                    role="alert"
                                    class="tg-formal-alert rounded-[18px] p-4 outline-none"
                                >
                                    <div class="flex gap-3">
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600 ring-1 ring-rose-100">
                                            <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 4.5h3.4l7.05 12.2a2.2 2.2 0 0 1-1.9 3.3H5.15a2.2 2.2 0 0 1-1.9-3.3L10.3 4.5Z" />
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-black text-slate-950">Acknowledgement required</h3>
                                            <p class="mt-1 text-sm leading-6 text-slate-600">
                                                {{ $serverConsentError ?: 'Please check the acknowledgement box before continuing.' }}
                                            </p>
                                            <button
                                                type="button"
                                                class="mt-3 text-xs font-black text-rose-700 underline-offset-4 transition hover:underline focus:outline-none focus:ring-4 focus:ring-rose-100"
                                                @click="focusConsent()"
                                            >
                                                Go to acknowledgement
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <label
                                    class="flex cursor-pointer items-start gap-3 rounded-[18px] border bg-white p-4 shadow-sm transition hover:border-blue-200 hover:bg-blue-50/40"
                                    :class="consentAlert && !accepted ? 'border-rose-200 ring-4 ring-rose-100/70' : 'border-slate-200'"
                                >
                                    <input
                                        x-ref="consentCheckbox"
                                        x-model="accepted"
                                        @change="if (accepted) consentAlert = false"
                                        type="checkbox"
                                        name="privacy_policy_acceptance"
                                        value="1"
                                        class="mt-1 h-5 w-5 cursor-pointer rounded border-slate-300 text-blue-600 focus:ring-4 focus:ring-blue-100"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-black text-slate-950">I have read and agree</span>
                                        <span class="mt-1 block text-sm leading-6 text-slate-600">
                                            I agree to the Privacy Policy and understand that TruthGuard may process my
                                            submitted content to provide AI-assisted fact-checking results.
                                        </span>
                                    </span>
                                </label>

                                <button
                                    type="submit"
                                    class="inline-flex w-full items-center justify-center rounded-full bg-blue-600 px-5 py-3 text-sm font-black text-white shadow-lg shadow-blue-600/18 transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100 disabled:cursor-wait disabled:opacity-75"
                                    :disabled="submitting"
                                >
                                    <span x-show="!submitting">Agree and continue</span>
                                    <span x-show="submitting" x-cloak>Saving consent...</span>
                                </button>

                                <a href="{{ route('privacy.policy') }}" target="_blank" rel="noreferrer" class="inline-flex w-full items-center justify-center rounded-full border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100">
                                    Open full policy
                                </a>
                            </form>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </main>
@endsection
