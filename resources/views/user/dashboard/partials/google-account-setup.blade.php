@if ($user->needsPasswordSetup())
    <div
        x-data="{ open: true }"
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
                    <input
                        id="google_setup_password"
                        name="password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    >
                    @error('password')
                        <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="google_setup_password_confirmation" class="block text-sm font-bold text-slate-700">Confirm password</label>
                    <input
                        id="google_setup_password_confirmation"
                        name="password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="mt-2 w-full rounded-2xl border-slate-300 px-4 py-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"
                    >
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                    After saving, open notification settings from <strong>Settings modules → Notifications</strong> to enable browser push alerts on this device.
                </div>

                <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
                    <button type="button" @click="open = false" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">Later</button>
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <a href="{{ route('profile', ['section' => 'notifications']) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-blue-200 bg-blue-50 px-5 text-sm font-bold text-blue-700 hover:bg-blue-100">Notification settings</a>
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-blue-600 px-5 text-sm font-black text-white shadow-lg shadow-blue-600/20 hover:bg-blue-700">Set password</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endif
