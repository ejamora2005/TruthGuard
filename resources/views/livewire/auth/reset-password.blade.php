@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : null;
@endphp

<div class="relative flex min-h-dvh w-full items-start justify-center overflow-y-auto bg-slate-100 px-4 py-4 sm:items-center sm:px-6 lg:px-8">
    <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,#f8fbff_0%,#eef4ff_52%,#e8f1ff_100%)]"></div>
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_15%,rgba(14,165,233,0.26)_0%,transparent_34%),radial-gradient(circle_at_85%_80%,rgba(59,130,246,0.22)_0%,transparent_38%)]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[26rem] w-[26rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-200/45 [animation:spin_40s_linear_infinite]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[20rem] w-[20rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-cyan-200/35 [animation:spin_28s_linear_infinite_reverse]"></div>

    <div class="relative z-10 w-full max-w-md">
        <div class="rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-2xl shadow-slate-400/40 ring-2 ring-slate-200/70 backdrop-blur-xl sm:p-6">
            <a href="{{ route('home') }}" wire:navigate class="mb-4 inline-flex w-full flex-col items-center justify-center gap-2">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-16 w-16 object-contain">
                @else
                    <span class="inline-flex h-16 w-16 items-center justify-center text-xl font-bold text-cyan-700">TG</span>
                @endif
                <div class="text-center">
                    <p class="text-base font-bold text-slate-900">TruthGuard</p>
                    <p class="text-xs text-slate-500">AI-Powered Media Verification</p>
                </div>
            </a>

            @if ($passwordResetComplete)
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                    <div class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-white text-emerald-700 ring-1 ring-emerald-100">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.8 12.2 2.2 2.2 4.2-4.2" />
                        </svg>
                    </div>
                    <h1 class="text-xl font-bold text-slate-900">Password Updated</h1>
                    <p class="mt-1 text-sm text-slate-600">Password updated successfully. You can now sign in.</p>

                    <a
                        href="{{ route('login') }}"
                        wire:navigate
                        class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-900/25 transition duration-300 hover:-translate-y-0.5 hover:shadow-cyan-900/35"
                    >
                        Back to Login
                    </a>
                </div>
            @else
                <div class="mb-5 text-center">
                    <div class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 6.5h14M7 6.5V5a5 5 0 1 1 10 0v1.5" />
                            <rect x="5" y="6.5" width="14" height="12.5" rx="2"></rect>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.5v4" />
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900">Create New Password</h1>
                    <p class="mt-1 text-sm text-slate-600">Enter your new password below.</p>
                </div>

                <form wire:submit="resetPassword" class="space-y-3">
                    <div>
                        <label for="email" class="mb-1 block text-sm font-semibold text-slate-700">Email</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5h16v11H4z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6" />
                                </svg>
                            </span>
                            <input
                                id="email"
                                type="email"
                                wire:model.live.debounce.400ms="email"
                                autocomplete="username"
                                class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                                placeholder="you@example.com"
                            >
                        </div>
                        @error('email')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div
                        x-data="{
                            showPassword: false,
                            strength: 0,
                            strengthLabel() {
                                if (this.strength === 0) return 'Use at least 8 characters.';
                                if (this.strength <= 1) return 'Weak password';
                                if (this.strength === 2) return 'Fair password';
                                if (this.strength === 3) return 'Good password';
                                return 'Strong password';
                            },
                            strengthBarClass() {
                                if (this.strength <= 1) return 'bg-rose-500';
                                if (this.strength === 2) return 'bg-amber-500';
                                if (this.strength === 3) return 'bg-cyan-500';
                                return 'bg-emerald-500';
                            },
                            evaluate(value) {
                                let score = 0;
                                if (value.length >= 8) score++;
                                if (/[A-Z]/.test(value)) score++;
                                if (/[0-9]/.test(value)) score++;
                                if (/[^A-Za-z0-9]/.test(value)) score++;
                                return score;
                            }
                        }"
                    >
                        <label for="password" class="mb-1 block text-sm font-semibold text-slate-700">New Password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4">
                                    <rect x="5" y="11" width="14" height="9" rx="2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                                </svg>
                            </span>
                            <input
                                id="password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                x-on:input="strength = evaluate($event.target.value)"
                                wire:model.live.debounce.250ms="password"
                                autocomplete="new-password"
                                class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-12 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                                placeholder="At least 8 characters"
                            >
                            <button
                                type="button"
                                x-on:click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-2 my-1 inline-flex w-8 items-center justify-center rounded-lg text-cyan-700 transition hover:bg-cyan-50"
                                aria-label="Toggle password visibility"
                            >
                                <svg x-show="!showPassword" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M1 12c1.45-3.6 5.5-7.1 11-7.1S21.55 8.4 23 12c-1.45 3.6-5.5 7.1-11 7.1S2.45 15.6 1 12Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg x-show="showPassword" style="display:none;" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.58 10.58A3 3 0 0 0 12 15a3 3 0 0 0 2.42-4.42" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 5.09A10.94 10.94 0 0 1 12 4.9c5.5 0 9.55 3.5 11 7.1a11.9 11.9 0 0 1-3.28 4.64" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.61 6.6A11.74 11.74 0 0 0 1 12c1.45 3.6 5.5 7.1 11 7.1 1.63 0 3.14-.3 4.48-.83" />
                                </svg>
                            </button>
                        </div>
                        <div class="mt-2">
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    x-bind:class="strengthBarClass()"
                                    x-bind:style="`width: ${strength * 25}%`"
                                ></div>
                            </div>
                            <p class="mt-1 text-xs text-slate-500" x-text="strengthLabel()"></p>
                        </div>
                        @error('password')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div x-data="{ showPassword: false }">
                        <label for="password_confirmation" class="mb-1 block text-sm font-semibold text-slate-700">Confirm Password</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4">
                                    <rect x="5" y="11" width="14" height="9" rx="2" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                                </svg>
                            </span>
                            <input
                                id="password_confirmation"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                wire:model="password_confirmation"
                                autocomplete="new-password"
                                class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-12 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                                placeholder="Re-enter your new password"
                            >
                            <button
                                type="button"
                                x-on:click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-2 my-1 inline-flex w-8 items-center justify-center rounded-lg text-cyan-700 transition hover:bg-cyan-50"
                                aria-label="Toggle password confirmation visibility"
                            >
                                <svg x-show="!showPassword" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M1 12c1.45-3.6 5.5-7.1 11-7.1S21.55 8.4 23 12c-1.45 3.6-5.5 7.1-11 7.1S2.45 15.6 1 12Z" />
                                    <circle cx="12" cy="12" r="3" />
                                </svg>
                                <svg x-show="showPassword" style="display:none;" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.58 10.58A3 3 0 0 0 12 15a3 3 0 0 0 2.42-4.42" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 5.09A10.94 10.94 0 0 1 12 4.9c5.5 0 9.55 3.5 11 7.1a11.9 11.9 0 0 1-3.28 4.64" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.61 6.6A11.74 11.74 0 0 0 1 12c1.45 3.6 5.5 7.1 11 7.1 1.63 0 3.14-.3 4.48-.83" />
                                </svg>
                            </button>
                        </div>
                        @error('password_confirmation')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="resetPassword"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-900/25 transition duration-300 hover:-translate-y-0.5 hover:shadow-cyan-900/35 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        <svg wire:loading wire:target="resetPassword" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                        </svg>
                        <span wire:loading.remove wire:target="resetPassword">Reset Password</span>
                        <span wire:loading wire:target="resetPassword">Resetting...</span>
                    </button>
                </form>

                <p class="mt-4 text-center text-sm text-slate-600">
                    <a href="{{ route('login') }}" wire:navigate class="font-semibold text-cyan-700 transition hover:text-cyan-600">Back to Login</a>
                </p>
            @endif
        </div>
    </div>
</div>
