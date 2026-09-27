@if ($user->needsPasswordSetup())
    <div
        x-data="{ open: true, showPassword: false, showPasswordConfirmation: false }"
        x-show="open"
        x-cloak
        x-transition.opacity.duration.180ms
        class="fixed inset-0 z-[99996] flex items-start justify-center overflow-y-auto bg-slate-950/60 px-4 pb-6 pt-24 backdrop-blur-sm sm:pt-28 lg:pt-32"
        role="dialog"
        aria-modal="true"
        aria-labelledby="google-account-setup-title"
    >
        <div class="w-full max-w-xl overflow-hidden rounded-[28px] border border-blue-100 bg-white shadow-[0_34px_100px_rgba(15,23,42,0.36)] ring-1 ring-white/80">
            <div class="border-b border-blue-100 bg-gradient-to-br from-blue-100 via-white to-cyan-50 p-6">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-600">Social sign-in setup</p>
                <h2 id="google-account-setup-title" class="mt-2 text-2xl font-black tracking-tight text-slate-950">Finish securing your TruthGuard account</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">Create a TruthGuard password so you can also sign in with email, manage security settings, and recover access if social sign-in is unavailable.</p>
            </div>

            <form method="POST" action="{{ route('profile.password.setup') }}" class="space-y-4 p-6">
                @csrf
                <div>
                    <label for="google_setup_password" class="block text-sm font-bold text-slate-700">New TruthGuard password</label>
                    <div class="relative mt-2">
                        <input
                            id="google_setup_password"
                            name="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            class="w-full rounded-2xl border-slate-300 py-3 pl-4 pr-12 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center rounded-r-2xl text-slate-500 transition hover:text-blue-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-500"
                            @click="showPassword = ! showPassword"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :title="showPassword ? 'Hide password' : 'Show password'"
                        >
                            <svg x-show="! showPassword" class="h-5 w-5" x-cloak fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="showPassword" class="h-5 w-5" x-cloak fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 3.5l17 17"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.58 5.44A9.79 9.79 0 0 1 12 5.25c6 0 9.75 6.75 9.75 6.75a17.3 17.3 0 0 1-2.79 3.54M6.11 6.89C3.73 8.59 2.25 12 2.25 12s3.75 6.75 9.75 6.75a9.51 9.51 0 0 0 4.14-.95"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 9.88a2.75 2.75 0 0 0 3.89 3.89"></path>
                            </svg>
                            <span class="sr-only" x-text="showPassword ? 'Hide password' : 'Show password'"></span>
                        </button>
                    </div>
                    @error('password')
                        <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="google_setup_password_confirmation" class="block text-sm font-bold text-slate-700">Confirm password</label>
                    <div class="relative mt-2">
                        <input
                            id="google_setup_password_confirmation"
                            name="password_confirmation"
                            :type="showPasswordConfirmation ? 'text' : 'password'"
                            required
                            autocomplete="new-password"
                            class="w-full rounded-2xl border-slate-300 py-3 pl-4 pr-12 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        >
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 inline-flex w-12 items-center justify-center rounded-r-2xl text-slate-500 transition hover:text-blue-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-500"
                            @click="showPasswordConfirmation = ! showPasswordConfirmation"
                            :aria-label="showPasswordConfirmation ? 'Hide confirm password' : 'Show confirm password'"
                            :title="showPasswordConfirmation ? 'Hide confirm password' : 'Show confirm password'"
                        >
                            <svg x-show="! showPasswordConfirmation" class="h-5 w-5" x-cloak fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"></path>
                                <circle cx="12" cy="12" r="2.75"></circle>
                            </svg>
                            <svg x-show="showPasswordConfirmation" class="h-5 w-5" x-cloak fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.5 3.5l17 17"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.58 5.44A9.79 9.79 0 0 1 12 5.25c6 0 9.75 6.75 9.75 6.75a17.3 17.3 0 0 1-2.79 3.54M6.11 6.89C3.73 8.59 2.25 12 2.25 12s3.75 6.75 9.75 6.75a9.51 9.51 0 0 0 4.14-.95"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 9.88a2.75 2.75 0 0 0 3.89 3.89"></path>
                            </svg>
                            <span class="sr-only" x-text="showPasswordConfirmation ? 'Hide confirm password' : 'Show confirm password'"></span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" @click="open = false" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">Later</button>
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700">Set password</button>
                </div>
            </form>
        </div>
    </div>
@endif
