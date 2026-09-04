<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

@php
    $authUser = auth()->user();
    $createdDate = $authUser?->created_at?->format('M j, Y') ?? 'Not recorded';
    $lastLogin = $authUser?->last_login_at?->format('M j, Y g:i A') ?? 'Not recorded';
    $emailStatus = filled($authUser?->email_verified_at) ? 'Verified' : 'Pending verification';
    $accountStatus = ucfirst((string) ($authUser?->subscription_status ?: 'active'));
    $privacyItems = [
        [
            'title' => 'Profile visibility',
            'value' => 'Private workspace profile',
            'badge' => 'Private',
            'icon' => 'M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
            'tone' => 'bg-blue-50 text-blue-600 ring-blue-100',
        ],
        [
            'title' => 'Activity history',
            'value' => 'Fact-check records retained by policy',
            'badge' => 'Policy',
            'icon' => 'M12 6v6l3.75 2.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
            'tone' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        ],
        [
            'title' => 'Connected apps',
            'value' => filled($authUser?->google_id) ? 'Google connected' : 'No connected apps',
            'badge' => filled($authUser?->google_id) ? 'Connected' : 'None',
            'icon' => 'M13.5 6.75 15 5.25a4.5 4.5 0 1 1 6.36 6.36l-2.12 2.12M10.5 17.25 9 18.75a4.5 4.5 0 1 1-6.36-6.36l2.12-2.12M8.25 15.75l7.5-7.5',
            'tone' => 'bg-sky-50 text-sky-600 ring-sky-100',
        ],
        [
            'title' => 'API access',
            'value' => 'No active personal API tokens',
            'badge' => 'Inactive',
            'icon' => 'M15.75 7.5a3.75 3.75 0 1 1-3.04 3.68L5.25 18.75H3v-2.25h2.25v-2.25H7.5l4.57-4.57A3.75 3.75 0 0 1 15.75 7.5Z',
            'tone' => 'bg-violet-50 text-violet-600 ring-violet-100',
        ],
    ];
@endphp

<section
    x-show="activeSection === 'account'"
    x-cloak
    x-transition.opacity.duration.180ms
    role="tabpanel"
    class="p-4 sm:p-6"
    x-data="{ dangerOpen: @js($errors->has('password')), openDeletionModal: @js($errors->has('password')) }"
    x-on:open-modal.window="if ($event.detail === 'confirm-user-deletion') { activeSection = 'account'; dangerOpen = true; openDeletionModal = true }"
    @keydown.escape.window="openDeletionModal = false"
>
    <div class="grid gap-6 xl:grid-cols-[292px_minmax(0,1fr)]">
        <aside class="space-y-4">
            <div class="overflow-hidden rounded-[28px] border border-white/80 bg-gradient-to-br from-blue-600 via-sky-500 to-emerald-400 text-white shadow-[0_24px_70px_rgba(37,99,235,0.18)]">
                <div class="px-5 py-5">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-50/80">Account & Privacy</p>
                    <h2 class="mt-2 text-lg font-black">Account record</h2>
                    <span class="mt-4 inline-flex w-fit items-center gap-1.5 rounded-full bg-white/20 px-3 py-1.5 text-xs font-bold text-white ring-1 ring-white/25">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                        {{ $accountStatus }}
                    </span>
                </div>

                <div class="space-y-3 border-t border-white/20 px-5 py-4">
                    <div class="rounded-2xl border border-white/20 bg-white/15 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-50/70">Created</p>
                        <p class="mt-2 text-sm font-semibold text-white">{{ $createdDate }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/20 bg-white/15 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-50/70">Last login</p>
                        <p class="mt-2 text-sm font-semibold leading-6 text-white">{{ $lastLogin }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/20 bg-white/15 px-4 py-3">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-50/70">Email status</p>
                        <p class="mt-2 text-sm font-semibold text-white">{{ $emailStatus }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <div class="space-y-5">
            <div class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-base font-black text-slate-950">Privacy controls</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-500">Review account state, privacy surfaces, and irreversible actions.</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 p-5">
                    @foreach ($privacyItems as $item)
                        <div class="flex flex-col gap-3 rounded-[22px] border border-slate-200/70 bg-white/80 px-4 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-start gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl {{ $item['tone'] }} ring-1">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"></path>
                                    </svg>
                                </span>
                                <div>
                                    <p class="text-sm font-black text-slate-950">{{ $item['title'] }}</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ $item['value'] }}</p>
                                </div>
                            </div>
                            <span class="inline-flex w-fit rounded-full border border-slate-200/80 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 shadow-sm">
                                {{ $item['badge'] }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="overflow-hidden rounded-[28px] border border-rose-200/80 bg-gradient-to-br from-rose-50 via-white to-orange-50 shadow-[0_20px_58px_rgba(244,63,94,0.10)]">
                <button
                    type="button"
                    @click="dangerOpen = !dangerOpen"
                    class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left transition hover:bg-rose-100/50 focus:outline-none focus:ring-4 focus:ring-rose-100"
                    :aria-expanded="dangerOpen"
                >
                    <span class="flex items-center gap-3">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-rose-600 shadow-sm ring-1 ring-rose-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            </svg>
                        </span>
                        <span>
                            <span class="block text-sm font-black text-rose-700">Danger Zone</span>
                            <span class="mt-0.5 block text-sm text-rose-600/80">Permanent account removal requires password confirmation.</span>
                        </span>
                    </span>

                    <svg class="h-5 w-5 shrink-0 text-rose-500 transition" :class="dangerOpen ? 'rotate-180' : 'rotate-0'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"></path>
                    </svg>
                </button>

                <div
                    x-show="dangerOpen"
                    x-transition.opacity.duration.180ms
                    class="border-t border-rose-200/80 bg-white/80 px-5 py-5"
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="text-base font-bold text-slate-950">Delete account</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                                This removes your account, access, and saved profile settings permanently.
                            </p>
                        </div>

                        <button
                            type="button"
                            @click="openDeletionModal = true"
                            class="inline-flex items-center justify-center rounded-2xl bg-rose-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-rose-500/20 transition hover:-translate-y-0.5 hover:bg-rose-500 focus:outline-none focus:ring-4 focus:ring-rose-100"
                        >
                            Delete Account
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <template x-teleport="body">
        <div
            x-show="openDeletionModal"
            x-cloak
            class="fixed inset-0"
            style="display: none; z-index: 2147483647;"
        >
            <div
                x-show="openDeletionModal"
                x-transition.opacity
                class="absolute inset-0"
                @click="openDeletionModal = false"
            >
                <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm"></div>
            </div>

            <div
                x-show="openDeletionModal"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-3 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-3 sm:scale-95"
                class="truthguard-account-delete-dialog fixed overflow-y-auto rounded-[28px] border border-white/80 bg-white/95 shadow-[0_30px_80px_rgba(15,23,42,0.22)] backdrop-blur-xl"
                style="left: 50%; top: 50%; transform: translate(-50%, -50%); width: max-content; min-width: min(24rem, calc(100vw - 2rem)); max-width: min(32rem, calc(100vw - 2rem)); max-height: calc(100vh - 2rem);"
            >
                <form wire:submit="deleteUser" class="space-y-5 p-6 sm:p-7">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 ring-1 ring-rose-100">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                </svg>
                            </span>

                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-rose-500">Final confirmation</p>
                                <h3 class="mt-2 text-xl font-black text-slate-900">Delete your account?</h3>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="openDeletionModal = false"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200/80 bg-white text-slate-500 transition hover:border-rose-200 hover:text-rose-600"
                            aria-label="Close deletion modal"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <p class="text-sm leading-6 text-slate-600">
                        Once confirmed, the account is permanently removed. Enter your password to continue.
                    </p>

                    <div>
                        <label for="password" class="text-sm font-semibold text-slate-700">{{ __('Password') }}</label>
                        <input
                            wire:model="password"
                            id="password"
                            name="password"
                            type="password"
                            placeholder="{{ __('Password') }}"
                            class="mt-2 block w-full rounded-2xl border border-slate-200/80 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 shadow-sm transition focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                        >

                        @if ($errors->has('password'))
                            <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                @foreach ($errors->get('password') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm text-slate-500">This action cannot be undone.</p>

                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                @click="openDeletionModal = false"
                                class="inline-flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-2xl bg-rose-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-rose-500/20 transition hover:bg-rose-500 focus:outline-none focus:ring-4 focus:ring-rose-100 disabled:cursor-wait disabled:opacity-70"
                            >
                                Delete Account
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </template>
</section>
