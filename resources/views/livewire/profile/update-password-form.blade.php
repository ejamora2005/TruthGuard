<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

@php
    $authUser = auth()->user();
    $lastLogin = $authUser?->last_login_at?->format('M j, Y g:i A') ?? 'Not recorded';
    $createdDate = $authUser?->created_at?->format('M j, Y') ?? 'Not recorded';
@endphp

<section
    x-show="activeSection === 'security'"
    x-cloak
    x-transition.opacity.duration.180ms
    role="tabpanel"
    class="p-4 sm:p-6"
    x-data="{
        saved: false,
        timer: null,
        showCurrent: false,
        showNew: false,
        showConfirm: false,
        passwordValue: '',
        strengthScore() {
            let score = 0;
            const value = this.passwordValue || '';

            if (value.length >= 8) score += 25;
            if (value.length >= 12) score += 20;
            if (/[A-Z]/.test(value)) score += 15;
            if (/[0-9]/.test(value)) score += 15;
            if (/[^A-Za-z0-9]/.test(value)) score += 25;

            return Math.min(score, 100);
        },
        strengthLabel() {
            const score = this.strengthScore();

            if (score >= 80) return 'Strong';
            if (score >= 55) return 'Good';
            if (score >= 30) return 'Fair';
            return 'Weak';
        },
        strengthClass() {
            const score = this.strengthScore();

            if (score >= 80) return 'bg-emerald-500';
            if (score >= 55) return 'bg-blue-600';
            if (score >= 30) return 'bg-amber-500';
            return 'bg-rose-500';
        },
    }"
    x-on:password-updated.window="saved = true; passwordValue = ''; clearTimeout(timer); timer = setTimeout(() => saved = false, 2600)"
>
    <div class="grid gap-6 xl:grid-cols-[292px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <div class="overflow-hidden rounded-[28px] border border-white/80 bg-gradient-to-br from-blue-600 via-sky-500 to-emerald-400 text-white shadow-[0_24px_70px_rgba(37,99,235,0.18)]">
                <div class="px-5 py-5">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-50/80">Security</p>
                    <div class="mt-4 flex items-end justify-between gap-4">
                        <div>
                            <p class="text-4xl font-black tracking-tight">85%</p>
                            <p class="mt-1 text-sm font-medium text-blue-50">Security score</p>
                        </div>
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-white/20 bg-white/20 text-white">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="border-t border-white/20 px-5 py-4">
                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/20 bg-white/15 px-4 py-3">
                        <span class="text-sm font-medium text-blue-50">Password</span>
                        <span class="rounded-full bg-white/20 px-3 py-1.5 text-xs font-bold text-white ring-1 ring-white/25">Protected</span>
                    </div>
                </div>
            </div>

            <div class="rounded-[22px] border border-white/80 bg-white/85 p-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l3.75 2.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"></path>
                        </svg>
                    </span>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Last login</p>
                        <p class="mt-2 text-sm font-semibold leading-6 text-slate-900">{{ $lastLogin }}</p>
                    </div>
                </div>
            </div>

            <div class="rounded-[22px] border border-white/80 bg-white/85 p-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3.75v2.5M17.25 3.75v2.5M3.75 9.75h16.5M5.25 5.25h13.5a1.5 1.5 0 0 1 1.5 1.5v12a1.5 1.5 0 0 1-1.5 1.5H5.25a1.5 1.5 0 0 1-1.5-1.5v-12a1.5 1.5 0 0 1 1.5-1.5Z"></path>
                        </svg>
                    </span>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Account created</p>
                        <p class="mt-2 text-sm font-semibold text-slate-900">{{ $createdDate }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <form id="truthguard-password-form" wire:submit="updatePassword" class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.875a4.5 4.5 0 0 0-9 0V10.5M6.75 10.5h10.5A1.5 1.5 0 0 1 18.75 12v6.75a1.5 1.5 0 0 1-1.5 1.5H6.75a1.5 1.5 0 0 1-1.5-1.5V12a1.5 1.5 0 0 1 1.5-1.5Z"></path>
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-black text-slate-950">Password and activity</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Update credentials and review recent account security context.</p>
                </div>
            </div>

            <div class="space-y-5 p-5">
                <div>
                    <label for="update_password_current_password" class="text-sm font-semibold text-slate-700">{{ __('Current Password') }}</label>
                    <div class="mt-2 flex rounded-2xl border border-slate-200/80 bg-white/90 shadow-sm transition focus-within:border-blue-400 focus-within:ring-4 focus-within:ring-blue-100">
                        <input
                            wire:model="current_password"
                            id="update_password_current_password"
                            name="current_password"
                            x-bind:type="showCurrent ? 'text' : 'password'"
                            autocomplete="current-password"
                            class="block w-full rounded-l-2xl border-0 bg-transparent px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                        >
                        <button
                            type="button"
                            @click="showCurrent = !showCurrent"
                            class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-r-2xl text-slate-500 transition hover:bg-blue-50 hover:text-blue-600"
                            :title="showCurrent ? 'Hide password' : 'Show password'"
                            :aria-label="showCurrent ? 'Hide current password' : 'Show current password'"
                        >
                            <svg x-show="!showCurrent" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path>
                            </svg>
                            <svg x-show="showCurrent" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 5.54A9.74 9.74 0 0 1 12 5.25c6 0 9.75 6.75 9.75 6.75a18.1 18.1 0 0 1-3.1 3.72"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.61 6.61A18.64 18.64 0 0 0 2.25 12S6 18.75 12 18.75a9.7 9.7 0 0 0 4.03-.88"></path>
                            </svg>
                        </button>
                    </div>
                    @if ($errors->has('current_password'))
                        <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                            @foreach ($errors->get('current_password') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <label for="update_password_password" class="text-sm font-semibold text-slate-700">{{ __('New Password') }}</label>
                        <div class="mt-2 flex rounded-2xl border border-slate-200/80 bg-white/90 shadow-sm transition focus-within:border-blue-400 focus-within:ring-4 focus-within:ring-blue-100">
                            <input
                                wire:model="password"
                                x-model="passwordValue"
                                id="update_password_password"
                                name="password"
                                x-bind:type="showNew ? 'text' : 'password'"
                                autocomplete="new-password"
                                class="block w-full rounded-l-2xl border-0 bg-transparent px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                            >
                            <button
                                type="button"
                                @click="showNew = !showNew"
                                class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-r-2xl text-slate-500 transition hover:bg-blue-50 hover:text-blue-600"
                                :title="showNew ? 'Hide password' : 'Show password'"
                                :aria-label="showNew ? 'Hide new password' : 'Show new password'"
                            >
                                <svg x-show="!showNew" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path>
                                </svg>
                                <svg x-show="showNew" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 5.54A9.74 9.74 0 0 1 12 5.25c6 0 9.75 6.75 9.75 6.75a18.1 18.1 0 0 1-3.1 3.72"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.61 6.61A18.64 18.64 0 0 0 2.25 12S6 18.75 12 18.75a9.7 9.7 0 0 0 4.03-.88"></path>
                                </svg>
                            </button>
                        </div>
                        @if ($errors->has('password'))
                            <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                @foreach ($errors->get('password') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div>
                        <label for="update_password_password_confirmation" class="text-sm font-semibold text-slate-700">{{ __('Confirm Password') }}</label>
                        <div class="mt-2 flex rounded-2xl border border-slate-200/80 bg-white/90 shadow-sm transition focus-within:border-blue-400 focus-within:ring-4 focus-within:ring-blue-100">
                            <input
                                wire:model="password_confirmation"
                                id="update_password_password_confirmation"
                                name="password_confirmation"
                                x-bind:type="showConfirm ? 'text' : 'password'"
                                autocomplete="new-password"
                                class="block w-full rounded-l-2xl border-0 bg-transparent px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"
                            >
                            <button
                                type="button"
                                @click="showConfirm = !showConfirm"
                                class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-r-2xl text-slate-500 transition hover:bg-blue-50 hover:text-blue-600"
                                :title="showConfirm ? 'Hide password' : 'Show password'"
                                :aria-label="showConfirm ? 'Hide confirmation password' : 'Show confirmation password'"
                            >
                                <svg x-show="!showConfirm" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"></path>
                                </svg>
                                <svg x-show="showConfirm" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 5.54A9.74 9.74 0 0 1 12 5.25c6 0 9.75 6.75 9.75 6.75a18.1 18.1 0 0 1-3.1 3.72"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.61 6.61A18.64 18.64 0 0 0 2.25 12S6 18.75 12 18.75a9.7 9.7 0 0 0 4.03-.88"></path>
                                </svg>
                            </button>
                        </div>
                        @if ($errors->has('password_confirmation'))
                            <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                @foreach ($errors->get('password_confirmation') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="rounded-[24px] border border-slate-200/80 bg-gradient-to-br from-slate-50/90 via-white/90 to-blue-50/70 p-4 shadow-inner">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-700">Password strength</span>
                        <span class="text-sm font-bold text-slate-900" x-text="strengthLabel()">Weak</span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full transition-all duration-300" :class="strengthClass()" :style="`width: ${strengthScore()}%`"></div>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <span class="text-xs font-semibold" :class="passwordValue.length >= 8 ? 'text-emerald-600' : 'text-slate-500'">8+ characters</span>
                        <span class="text-xs font-semibold" :class="/[A-Z]/.test(passwordValue) ? 'text-emerald-600' : 'text-slate-500'">Uppercase letter</span>
                        <span class="text-xs font-semibold" :class="/[0-9]/.test(passwordValue) ? 'text-emerald-600' : 'text-slate-500'">Number</span>
                        <span class="text-xs font-semibold" :class="/[^A-Za-z0-9]/.test(passwordValue) ? 'text-emerald-600' : 'text-slate-500'">Special character</span>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-3 border-t border-slate-200/70 bg-white/65 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p x-show="saved" x-cloak class="text-sm font-semibold text-emerald-600">Password updated.</p>
                <p x-show="!saved" class="text-sm text-slate-500">Use a unique password for your TruthGuard account.</p>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100 disabled:cursor-wait disabled:opacity-70"
                >
                    <svg wire:loading class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                    </svg>
                    Update Password
                </button>
            </div>
        </form>
    </div>
</section>
