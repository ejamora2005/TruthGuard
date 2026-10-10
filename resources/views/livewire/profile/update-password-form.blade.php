<?php

use App\Models\User;
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

        $updates = [
            'password' => Hash::make($validated['password']),
        ];

        if (User::hasPasswordSetAtColumn()) {
            $updates['password_set_at'] = now();
        }

        Auth::user()->update($updates);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>



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
    <div class="truthguard-security-layout grid gap-6">


        <form id="truthguard-password-form" wire:submit="updatePassword" class="truthguard-settings-primary-form overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
            <div class="flex items-center gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.875a4.5 4.5 0 0 0-9 0V10.5M6.75 10.5h10.5A1.5 1.5 0 0 1 18.75 12v6.75a1.5 1.5 0 0 1-1.5 1.5H6.75a1.5 1.5 0 0 1-1.5-1.5V12a1.5 1.5 0 0 1 1.5-1.5Z"></path>
                    </svg>
                </span>
                <div>
                    <h2 class="text-base font-black text-slate-950">Update your password</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">Choose a strong password to keep your account secure.</p>
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

                <div class="truthguard-password-strength-card rounded-[24px] border border-slate-200/80 bg-gradient-to-br from-slate-50/90 via-white/90 to-blue-50/70 p-4 shadow-inner">
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

            <div class="truthguard-settings-action-footer flex flex-col gap-3 border-t border-slate-200/70 bg-white/65 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p x-show="saved" x-cloak class="text-sm font-semibold text-emerald-600">Password updated.</p>


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
