@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : null;
@endphp

<div class="relative flex h-dvh w-full items-center justify-center overflow-hidden bg-slate-100 px-4 sm:px-6 lg:px-8">
    <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,#f8fbff_0%,#eef4ff_52%,#e8f1ff_100%)]"></div>
    <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_15%_15%,rgba(14,165,233,0.26)_0%,transparent_34%),radial-gradient(circle_at_85%_80%,rgba(59,130,246,0.22)_0%,transparent_38%)]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[26rem] w-[26rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-sky-200/45 [animation:spin_40s_linear_infinite]"></div>
    <div class="pointer-events-none absolute left-1/2 top-1/2 h-[20rem] w-[20rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-cyan-200/35 [animation:spin_28s_linear_infinite_reverse]"></div>

    <div class="relative z-10 w-full max-w-md">
        <div class="rounded-2xl border-2 border-slate-300 bg-white p-5 shadow-2xl shadow-slate-400/40 ring-2 ring-slate-200/70 backdrop-blur-xl sm:p-6">
            <a href="{{ route('home', absolute: false) }}" wire:navigate class="mb-4 inline-flex w-full flex-col items-center justify-center gap-2">
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-16 w-16 object-contain">
                @else
                    <span class="inline-flex h-16 w-16 items-center justify-center text-xl font-bold text-cyan-700">TG</span>
                @endif
                <div class="text-center">
                    <p class="truthguard-auth-brand text-base font-bold text-slate-900">TruthGuard</p>
                    <p class="text-xs text-slate-500">AI-Powered Media Verification</p>
                </div>
            </a>

            <div class="mb-5 text-center">
                <div class="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="5" y="11" width="14" height="9" rx="2"></rect>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-900">Forgot Password?</h1>
                <p class="mt-1 text-sm text-slate-600">Enter your email and we&rsquo;ll send you a reset link.</p>
            </div>

            @if (session('auth_error'))
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {{ session('auth_error') }}
                </div>
            @endif

            <form wire:submit="sendResetLink" class="space-y-3">
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

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="sendResetLink"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-cyan-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-cyan-900/25 transition duration-300 hover:-translate-y-0.5 hover:shadow-cyan-900/35 disabled:cursor-not-allowed disabled:opacity-70"
                >
                    <svg wire:loading wire:target="sendResetLink" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                    </svg>
                    <span wire:loading.remove wire:target="sendResetLink">Send Reset Link</span>
                    <span wire:loading wire:target="sendResetLink">Sending...</span>
                </button>
            </form>

            <p class="mt-4 text-center text-sm text-slate-600">
                Remember your password?
                <a href="{{ route('login', absolute: false) }}" wire:navigate class="font-semibold text-cyan-700 transition hover:text-cyan-600">Back to Login</a>
            </p>
        </div>
    </div>
</div>
