@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : null;
@endphp

<div class="relative flex h-dvh w-full items-center justify-center overflow-hidden bg-slate-100 px-4 sm:px-6 lg:px-8">
    <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,#f8fbff_0%,#eef4ff_52%,#e8f1ff_100%)]"></div>
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_15%,rgba(14,165,233,0.26)_0%,transparent_34%),radial-gradient(circle_at_85%_80%,rgba(59,130,246,0.22)_0%,transparent_38%)]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[26rem] w-[26rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-200/45 [animation:spin_40s_linear_infinite]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[20rem] w-[20rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-cyan-200/35 [animation:spin_28s_linear_infinite_reverse]"></div>
    @include('partials.floating-social-icons')

    <div class="relative z-10 w-full max-w-md">
        <div
            x-data="{ authLoading: null }"
            class="rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-2xl shadow-slate-400/40 ring-2 ring-slate-200/70 backdrop-blur-xl sm:p-6"
        >
            <a href="{{ route('home') }}" class="mb-4 inline-flex w-full flex-col items-center justify-center gap-2">
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

            <div class="mb-4 text-center">
                <h1 class="text-2xl font-bold text-slate-900">Welcome Back</h1>
                <p class="mt-1 text-sm text-slate-600">Sign in to your TruthGuard account</p>
            </div>

            @if (session('auth_error'))
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ session('auth_error') }}
                </div>
            @endif

            @if (session('status'))
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                    {{ session('status') }}
                </div>
            @endif

            <form
                method="POST"
                action="{{ route('login') }}"
                class="space-y-3"
                x-on:submit="authLoading = 'password'"
                x-bind:aria-busy="authLoading === 'password'"
            >
                @csrf
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
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="username"
                            x-bind:readonly="authLoading !== null"
                            class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                            placeholder="you@example.com"
                        >
                    </div>
                    @error('email')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="mb-1 block text-sm font-semibold text-slate-700">Password</label>
                    <div
                        x-data="{ showPassword: false, hasValue: false }"
                        x-init="$nextTick(() => { hasValue = $refs.passwordInput.value.length > 0; if (hasValue) { $refs.passwordInput.dispatchEvent(new Event('input', { bubbles: true })); } })"
                        class="relative"
                    >
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-slate-400">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="h-4 w-4">
                                <rect x="5" y="11" width="14" height="9" rx="2" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V8a4 4 0 0 1 8 0v3" />
                            </svg>
                        </span>
                        <input
                            x-ref="passwordInput"
                            id="password"
                            name="password"
                            x-bind:type="showPassword ? 'text' : 'password'"
                            x-on:input="hasValue = $refs.passwordInput.value.length > 0"
                            autocomplete="current-password"
                            x-bind:readonly="authLoading !== null"
                            class="w-full rounded-xl border border-slate-300 bg-white py-2.5 pl-10 pr-12 text-sm text-slate-900 placeholder:text-slate-400 focus:border-cyan-500 focus:outline-none focus:ring-4 focus:ring-cyan-100"
                            placeholder="Enter your password"
                        >
                        <button
                            type="button"
                            style="display:none;"
                            x-show="hasValue"
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
                    @error('password')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end text-sm">
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="font-medium text-cyan-700 transition hover:text-cyan-600">Forgot password?</a>
                    @endif
                </div>

                <button
                    type="submit"
                    x-bind:disabled="authLoading !== null"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-900/25 transition duration-300 hover:-translate-y-0.5 hover:shadow-cyan-900/35 disabled:cursor-not-allowed disabled:opacity-70"
                >
                    <svg
                        x-show="authLoading === 'password'"
                        style="display:none;"
                        class="h-4 w-4 animate-spin"
                        viewBox="0 0 24 24"
                        fill="none"
                        aria-hidden="true"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                        <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path>
                    </svg>
                    <span x-show="authLoading !== 'password'">Login</span>
                    <span x-show="authLoading === 'password'" style="display:none;">Signing in...</span>
                </button>
            </form>

            <div class="my-4 flex items-center gap-3 text-xs text-slate-500">
                <div class="h-px flex-1 bg-slate-200"></div>
                <span>Or continue with</span>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>

            <div class="flex items-center justify-center gap-3">
                <a
                    href="{{ route('google.redirect') }}"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50"
                    aria-label="Continue with Google"
                    x-on:click="if (authLoading) { $event.preventDefault(); return; } authLoading = 'google'"
                    x-bind:aria-busy="authLoading === 'google'"
                    x-bind:aria-disabled="authLoading ? 'true' : 'false'"
                    x-bind:tabindex="authLoading ? -1 : 0"
                    x-bind:class="{ 'pointer-events-none cursor-wait opacity-70': authLoading === 'google', 'pointer-events-none opacity-70': authLoading && authLoading !== 'google' }"
                >
                    <svg x-show="authLoading !== 'google'" class="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill="#EA4335" d="M12 10.2v3.9h5.5c-.2 1.3-1.6 3.8-5.5 3.8-3.3 0-6-2.7-6-6s2.7-6 6-6c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5A9.5 9.5 0 1 0 12 21.5c5.5 0 9.1-3.9 9.1-9.3 0-.6-.1-1.1-.2-1.6H12Z"/>
                        <path fill="#34A853" d="M3.6 7.3 6.8 9.6a6 6 0 0 1 5.2-3.8c1.9 0 3.1.8 3.8 1.5l2.6-2.5C16.8 3.4 14.6 2.5 12 2.5 8.3 2.5 5 4.7 3.6 7.3Z"/>
                        <path fill="#FBBC05" d="M12 21.5c2.5 0 4.7-.8 6.3-2.3l-2.9-2.3c-.8.6-1.9 1-3.4 1-2.6 0-4.8-1.7-5.6-4.1l-3.2 2.5A9.5 9.5 0 0 0 12 21.5Z"/>
                        <path fill="#4285F4" d="M21.1 12.2c0-.6-.1-1.1-.2-1.6H12v3.9h5.5c-.3 1.2-1.1 2.1-2.1 2.8l2.9 2.3c1.7-1.5 2.8-3.9 2.8-7.4Z"/>
                    </svg>
                    <svg x-show="authLoading === 'google'" style="display:none;" class="h-4 w-4 animate-spin text-cyan-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                        <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path>
                    </svg>
                    <span x-show="authLoading === 'google'" style="display:none;" class="sr-only">Signing in with Google</span>
                </a>

                <a
                    href="{{ route('facebook.redirect') }}"
                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:border-slate-400 hover:bg-slate-50"
                    aria-label="Continue with Facebook"
                    x-on:click="if (authLoading) { $event.preventDefault(); return; } authLoading = 'facebook'"
                    x-bind:aria-busy="authLoading === 'facebook'"
                    x-bind:aria-disabled="authLoading ? 'true' : 'false'"
                    x-bind:tabindex="authLoading ? -1 : 0"
                    x-bind:class="{ 'pointer-events-none cursor-wait opacity-70': authLoading === 'facebook', 'pointer-events-none opacity-70': authLoading && authLoading !== 'facebook' }"
                >
                    <svg x-show="authLoading !== 'facebook'" class="h-4 w-4 text-[#1877F2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M24 12.1C24 5.4 18.6 0 12 0S0 5.4 0 12.1c0 6 4.4 11 10.1 12v-8.5H7.1v-3.5h3V9.4c0-3 1.8-4.8 4.6-4.8 1.3 0 2.7.2 2.7.2v3h-1.5c-1.5 0-2 .9-2 1.9v2.4h3.4l-.5 3.5h-2.9v8.5c5.7-1 10.1-6 10.1-12Z"/>
                    </svg>
                    <svg x-show="authLoading === 'facebook'" style="display:none;" class="h-4 w-4 animate-spin text-[#1877F2]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3"></circle>
                        <path class="opacity-90" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6h3Z"></path>
                    </svg>
                    <span x-show="authLoading === 'facebook'" style="display:none;" class="sr-only">Signing in with Facebook</span>
                </a>
            </div>

            <p class="mt-4 text-center text-sm text-slate-600">
                Don't have an account?
                <a href="{{ route('register') }}" class="font-semibold text-cyan-700 transition hover:text-cyan-600">Sign up</a>
            </p>
        </div>
    </div>
</div>
