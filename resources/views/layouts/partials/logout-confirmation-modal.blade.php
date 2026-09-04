@php
    $logoutScope = $logoutScope ?? 'current';
    $logoutMessage = $logoutScope === 'admin'
        ? 'Your admin session will end.'
        : 'Your current session will end.';
@endphp

<div
    x-show="logoutConfirmOpen"
    x-cloak
    class="fixed inset-0"
    style="display: none; z-index: 2147483647;"
    role="dialog"
    aria-modal="true"
    aria-labelledby="truthguard-logout-title"
    @keydown.escape.window="if (!logoutSubmitting) logoutConfirmOpen = false"
>
    <div
        x-show="logoutConfirmOpen"
        x-transition.opacity
        class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"
        @click="if (!logoutSubmitting) logoutConfirmOpen = false"
    ></div>

    <div
        x-show="logoutConfirmOpen"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:scale-95"
        class="fixed overflow-hidden rounded-[24px] border border-blue-100 bg-white shadow-[0_30px_90px_rgba(15,23,42,0.24)]"
        style="left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(24rem, calc(100vw - 2rem));"
    >
        <div class="relative overflow-hidden">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_15%_0%,rgba(37,99,235,0.16),transparent_32%),radial-gradient(circle_at_90%_10%,rgba(14,165,233,0.16),transparent_34%),linear-gradient(180deg,#ffffff,#f8fbff)]"></div>
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-blue-600 via-cyan-400 to-violet-500"></div>

            <div class="relative space-y-4 p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl border border-blue-100 bg-white shadow-[0_14px_30px_rgba(37,99,235,0.16)]">
                            @if ($logoUrl !== '')
                                <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-10 w-10 object-contain">
                            @else
                                <span class="text-sm font-black text-blue-700">TG</span>
                            @endif
                        </span>
                        <span>
                            <span class="block text-xs font-black uppercase tracking-[0.18em] text-blue-600">TruthGuard</span>
                            <span class="block text-sm font-semibold text-slate-500">Session</span>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-400 transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="logoutConfirmOpen = false"
                        :disabled="logoutSubmitting"
                        aria-label="Close logout confirmation"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div>
                    <h3 id="truthguard-logout-title" class="text-2xl font-black tracking-tight text-slate-950">Log out?</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $logoutMessage }}</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white/86 p-3 shadow-sm">
                    <div class="flex items-center gap-3">
                        <template x-if="profileAvatarUrl">
                            <img :src="profileAvatarUrl" alt="Signed-in profile" class="h-10 w-10 rounded-full object-cover ring-2 ring-blue-50">
                        </template>
                        <template x-if="!profileAvatarUrl">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-cyan-500 text-sm font-black text-white ring-2 ring-blue-50" x-text="profileInitials"></span>
                        </template>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-black text-slate-950" x-text="profileName"></p>
                            <p class="mt-0.5 truncate text-xs font-semibold text-slate-500" x-text="profileEmail"></p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 border-t border-slate-200/80 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-full border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="logoutConfirmOpen = false"
                        :disabled="logoutSubmitting"
                    >
                        Stay signed in
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-rose-600 to-red-500 px-5 py-3 text-sm font-black text-white shadow-lg shadow-rose-900/20 transition hover:-translate-y-0.5 hover:shadow-rose-900/30 disabled:cursor-wait disabled:translate-y-0 disabled:opacity-80"
                        @click="submitLogout()"
                        :disabled="logoutSubmitting"
                    >
                        <svg x-show="logoutSubmitting" x-cloak class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                        </svg>
                        <span x-text="logoutSubmitting ? 'Signing out...' : 'Log out'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
