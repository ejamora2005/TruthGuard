<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;

new class extends Component
{
    public function removeProfilePhoto(): void
    {
        $user = Auth::user();
        $currentAvatarPath = $user->profile_photo_path;

        if ($currentAvatarPath) {
            Storage::disk('public')->delete($currentAvatarPath);
        }

        $user->syncProfilePreferences(null, (string) $user->theme_preference);

        $freshUser = $user->fresh();

        $this->dispatchProfileState($freshUser);
    }

    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    private function dispatchProfileState(User $user): void
    {
        $this->dispatch('truthguard-profile-updated',
            name: (string) $user->name,
            email: (string) $user->email,
            initials: $user->profile_initials,
            avatarUrl: $user->display_profile_avatar_url,
            usesSampleProfile: $user->uses_starter_profile_avatar,
            theme: (string) ($user->theme_preference ?: 'ocean'),
        );

        $this->dispatch('truthguard-theme-updated', theme: (string) ($user->theme_preference ?: 'ocean'));
    }
}; ?>

@php
    $themeOptions = [
        [
            'key' => 'ocean',
            'label' => 'Ocean Blue',
            'description' => 'Clean TruthGuard blue surfaces.',
            'accent' => 'bg-blue-600',
            'ring' => 'ring-blue-100',
            'preview' => 'from-blue-50 via-white to-sky-100',
        ],
        [
            'key' => 'forest',
            'label' => 'Emerald Trust',
            'description' => 'Calm verification and review accents.',
            'accent' => 'bg-emerald-500',
            'ring' => 'ring-emerald-100',
            'preview' => 'from-emerald-50 via-white to-teal-100',
        ],
        [
            'key' => 'sunset',
            'label' => 'Amber Review',
            'description' => 'Warm highlights for editorial workflows.',
            'accent' => 'bg-amber-500',
            'ring' => 'ring-amber-100',
            'preview' => 'from-amber-50 via-white to-orange-100',
        ],
    ];

    $displayModes = [
        ['key' => 'light', 'label' => 'Light', 'description' => 'Bright workspace surfaces.'],
        ['key' => 'dark', 'label' => 'Dark', 'description' => 'Deep preview for low-light work.'],
        ['key' => 'system', 'label' => 'System', 'description' => 'Follow device preference.'],
    ];

    $accentOptions = [
        ['key' => 'blue', 'label' => 'Blue', 'class' => 'bg-blue-600'],
        ['key' => 'violet', 'label' => 'Violet', 'class' => 'bg-violet-600'],
        ['key' => 'emerald', 'label' => 'Emerald', 'class' => 'bg-emerald-500'],
        ['key' => 'slate', 'label' => 'Slate', 'class' => 'bg-slate-800'],
    ];

    $authUser = auth()->user();
    $formName = old('name', (string) ($authUser?->name ?? ''));
    $nameParts = preg_split('/\s+/', trim($formName)) ?: [];
    $formFirstName = old('first_name', $nameParts[0] ?? '');
    $formLastName = old('last_name', count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '');
    $formEmail = old('email', (string) ($authUser?->email ?? ''));
    $formUsername = old('username', (string) ($authUser?->username ?? ''));
    $formThemePreference = old('theme_preference', (string) ($authUser?->theme_preference ?: 'ocean'));
    $formEmailUpdatesEnabled = (bool) old('email_updates_enabled', (bool) ($authUser?->email_updates_enabled ?? false));
    $currentTheme = collect($themeOptions)->firstWhere('key', $formThemePreference) ?? $themeOptions[0];
    $starterAvatarUrls = collect(['ocean', 'forest', 'sunset'])->mapWithKeys(
        fn (string $theme) => [$theme => asset("images/avatars/default-{$theme}.svg")]
    )->all();
    $storedAvatarUrl = $authUser?->profile_photo_url ?: $authUser?->google_avatar_url;
    $currentAvatarUrl = $authUser?->display_profile_avatar_url ?? ($starterAvatarUrls[$formThemePreference] ?? $starterAvatarUrls['ocean']);
    $hasUploadedAvatar = filled($authUser?->profile_photo_path);
    $emailVerified = filled($authUser?->email_verified_at);
    $roleLabel = $authUser?->isAdmin() ? 'Administrator' : 'User';
    $statusLabel = ucfirst((string) ($authUser?->subscription_status ?: 'active'));
    $avatarUploadMaxMb = (int) config('truthguard.uploads.avatar_max_mb', 5);
    $avatarUploadMaxBytes = (int) config('truthguard.uploads.avatar_max_bytes', $avatarUploadMaxMb * 1024 * 1024);
@endphp

<div
    x-data="{
        profileFirstName: @js($formFirstName),
        profileLastName: @js($formLastName),
        profileName: @js($formName),
        profileEmail: @js($formEmail),
        profileUsername: @js($formUsername),
        emailUpdatesEnabled: @js($formEmailUpdatesEnabled),
        profileBio: '',
        profileTheme: @js($formThemePreference),
        storedAvatarUrl: @js($storedAvatarUrl),
        starterAvatarUrls: @js($starterAvatarUrls),
        previewUrl: null,
        photoFileName: '',
        avatarUploadMaxMb: @js($avatarUploadMaxMb),
        avatarUploadMaxBytes: @js($avatarUploadMaxBytes),
        avatarUploadError: '',
        displayMode: 'system',
        accentColor: 'blue',
        init() {
            try {
                this.profileBio = localStorage.getItem('truthguard-profile-bio') || '';
                this.displayMode = localStorage.getItem('truthguard-display-mode') || 'system';
                this.accentColor = localStorage.getItem('truthguard-accent-color') || 'blue';
            } catch (error) {}

            window.addEventListener('truthguard-profile-updated', (event) => {
                const detail = event.detail || {};

                if (detail.theme) {
                    this.profileTheme = detail.theme;
                }

                if (detail.name) {
                    this.profileName = detail.name;
                    const parts = detail.name.trim().split(/\s+/);
                    this.profileFirstName = parts.shift() || '';
                    this.profileLastName = parts.join(' ');
                }

                if (detail.email) {
                    this.profileEmail = detail.email;
                }

                if (Object.prototype.hasOwnProperty.call(detail, 'usesSampleProfile')) {
                    this.storedAvatarUrl = detail.usesSampleProfile ? null : (detail.avatarUrl || null);
                } else if (detail.avatarUrl) {
                    this.storedAvatarUrl = detail.avatarUrl;
                }
            });
        },
        composeDisplayName() {
            const full = [this.profileFirstName, this.profileLastName].map((part) => part.trim()).filter(Boolean).join(' ');

            if (full !== '') {
                this.profileName = full;
            }
        },
        avatarPreviewUrl() {
            return this.previewUrl || this.storedAvatarUrl || this.starterAvatarUrls[this.profileTheme] || this.starterAvatarUrls.ocean;
        },
        handleAvatarChange(event) {
            const file = event.target.files?.[0] || null;
            this.avatarUploadError = '';

            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = null;
            this.photoFileName = '';

            if (! file) {
                return;
            }

            if (file.size > this.avatarUploadMaxBytes) {
                const fileSizeMb = (file.size / (1024 * 1024)).toFixed(2);
                this.avatarUploadError = `${file.name || 'Profile photo'} (${fileSizeMb}MB) exceeds the ${this.avatarUploadMaxMb}MB limit. Choose a smaller image.`;

                if (this.$refs.avatarInput) {
                    this.$refs.avatarInput.value = '';
                }

                window.alert(this.avatarUploadError);
                return;
            }

            this.previewUrl = URL.createObjectURL(file);
            this.photoFileName = file.name || '';
        },
        clearSelectedAvatar() {
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }

            this.previewUrl = null;
            this.photoFileName = '';
            this.avatarUploadError = '';

            if (this.$refs.avatarInput) {
                this.$refs.avatarInput.value = '';
            }
        },
        saveLocalPreferences() {
            localStorage.setItem('truthguard-profile-bio', this.profileBio || '');
            localStorage.setItem('truthguard-display-mode', this.displayMode);
            localStorage.setItem('truthguard-accent-color', this.accentColor);
        },
    }"
>
    <section
        x-show="activeSection === 'personal'"
        x-cloak
        x-transition.opacity.duration.180ms
        role="tabpanel"
        class="p-4 sm:p-6"
    >
        <div class="grid gap-6 xl:grid-cols-[292px_minmax(0,1fr)]">
            <aside class="space-y-4">
                <div class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.09)] backdrop-blur-xl">
                    <div class="bg-gradient-to-br from-blue-600 via-sky-500 to-emerald-400 px-5 py-5 text-white">
                        <div class="flex items-start justify-between gap-4">
                            <img
                                src="{{ $currentAvatarUrl }}"
                                x-bind:src="avatarPreviewUrl()"
                                alt="Profile avatar"
                                class="h-20 w-20 rounded-[24px] object-cover shadow-[0_18px_38px_rgba(15,23,42,0.2)] ring-4 ring-white/30"
                            >
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-3 py-1.5 text-xs font-bold text-white ring-1 ring-white/25">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-200"></span>
                                Live
                            </span>
                        </div>
                        <h2 class="mt-4 truncate text-xl font-black tracking-tight" x-text="profileName || 'TruthGuard User'">{{ $formName }}</h2>
                        <p class="mt-1 truncate text-sm font-medium text-blue-50" x-text="profileEmail">{{ $formEmail }}</p>
                    </div>

                    <div class="grid gap-3 p-4">
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-600">Role</span>
                            <span class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 ring-1 ring-blue-100">{{ $roleLabel }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-600">Status</span>
                            <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">{{ $statusLabel }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3">
                            <span class="text-sm font-semibold text-slate-600">Email</span>
                            <span @class([
                                'rounded-full px-3 py-1.5 text-xs font-bold ring-1',
                                $emailVerified ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-amber-50 text-amber-700 ring-amber-100',
                            ])>
                                {{ $emailVerified ? 'Verified' : 'Pending' }}
                            </span>
                        </div>
                    </div>
                </div>

                @if (session('status') === 'profile-updated')
                    <p class="flex items-center gap-2 rounded-[22px] border border-emerald-200/80 bg-emerald-50/90 px-4 py-3 text-sm font-bold text-emerald-700 shadow-sm">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                        </svg>
                        Profile saved
                    </p>
                @endif
            </aside>

            <form
                id="truthguard-personal-form"
                method="POST"
                action="{{ route('profile.update') }}"
                enctype="multipart/form-data"
                class="space-y-5"
                @submit="saveLocalPreferences()"
            >
                @csrf
                @method('patch')
                <input type="hidden" name="theme_preference" x-model="profileTheme">
                <input type="hidden" name="return_section" value="personal">

                <div class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0"></path>
                            </svg>
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">Personal Information</p>
                            <h3 class="mt-1 text-base font-black text-slate-950">Identity profile</h3>
                        </div>
                    </div>

                    <div class="space-y-5 p-5">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label for="first_name" class="text-sm font-semibold text-slate-700">First name</label>
                                <input
                                    id="first_name"
                                    name="first_name"
                                    type="text"
                                    x-model="profileFirstName"
                                    @input="composeDisplayName()"
                                    class="mt-2 block w-full rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-sm transition placeholder:text-slate-400 hover:border-blue-200 focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                                >
                            </div>

                            <div>
                                <label for="last_name" class="text-sm font-semibold text-slate-700">Last name</label>
                                <input
                                    id="last_name"
                                    name="last_name"
                                    type="text"
                                    x-model="profileLastName"
                                    @input="composeDisplayName()"
                                    class="mt-2 block w-full rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-sm transition placeholder:text-slate-400 hover:border-blue-200 focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                                >
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label for="name" class="text-sm font-semibold text-slate-700">Display name</label>
                                <input
                                    id="name"
                                    name="name"
                                    type="text"
                                    required
                                    autocomplete="name"
                                    x-model="profileName"
                                    class="mt-2 block w-full rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-sm transition placeholder:text-slate-400 hover:border-blue-200 focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                                >
                                @if ($errors->has('name'))
                                    <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                        @foreach ($errors->get('name') as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            <div>
                                <label for="username" class="text-sm font-semibold text-slate-700">Username</label>
                                <div class="mt-2 flex rounded-2xl border border-slate-200/80 bg-white/90 shadow-sm transition focus-within:border-blue-400 focus-within:ring-4 focus-within:ring-blue-100">
                                    <span class="inline-flex items-center border-r border-slate-200/80 px-4 text-sm font-semibold text-slate-400">@</span>
                                    <input
                                        id="username"
                                        name="username"
                                        type="text"
                                        autocomplete="username"
                                        x-model="profileUsername"
                                        class="block w-full rounded-r-2xl border-0 bg-transparent px-4 py-3 text-sm text-slate-900 shadow-none placeholder:text-slate-400 focus:outline-none focus:ring-0"
                                    >
                                </div>
                                @if ($errors->has('username'))
                                    <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                        @foreach ($errors->get('username') as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>

                        <div>
                            <label for="email" class="text-sm font-semibold text-slate-700">Email</label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                required
                                autocomplete="email"
                                x-model="profileEmail"
                                class="mt-2 block w-full rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm text-slate-900 shadow-sm transition placeholder:text-slate-400 hover:border-blue-200 focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                            >
                            @if ($errors->has('email'))
                                <ul class="mt-2 space-y-1 text-sm font-medium text-rose-500">
                                    @foreach ($errors->get('email') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div>
                            <label for="profile_bio" class="text-sm font-semibold text-slate-700">Bio</label>
                            <textarea
                                id="profile_bio"
                                name="bio"
                                rows="4"
                                x-model="profileBio"
                                class="mt-2 block w-full resize-none rounded-2xl border border-slate-200/80 bg-white/90 px-4 py-3 text-sm leading-6 text-slate-900 shadow-sm transition placeholder:text-slate-400 hover:border-blue-200 focus:border-blue-400 focus:outline-none focus:ring-4 focus:ring-blue-100"
                                placeholder="Cybersecurity analyst, fact-check reviewer, or newsroom editor."
                            ></textarea>
                        </div>

                        <div class="rounded-[24px] border border-blue-100 bg-gradient-to-br from-blue-50/80 via-white to-slate-50 p-4">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex gap-3">
                                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white text-blue-700 shadow-sm ring-1 ring-blue-100" aria-hidden="true">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5h16v11H4z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6"></path>
                                        </svg>
                                    </span>
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[0.16em] text-blue-600">Email permission</p>
                                        <h4 class="mt-1 text-sm font-black text-slate-950">Optional product and fact-check updates</h4>
                                        <p class="mt-1 text-sm leading-6 text-slate-600">
                                            Turn this on only if you want TruthGuard to send public claim-review alerts, feature announcements, and non-critical system updates.
                                            Password reset, verification, and security emails may still be sent when needed.
                                        </p>
                                    </div>
                                </div>

                                <label class="inline-flex w-full shrink-0 cursor-pointer items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm sm:w-auto">
                                    <input type="hidden" name="email_updates_enabled" value="0">
                                    <input
                                        type="checkbox"
                                        name="email_updates_enabled"
                                        value="1"
                                        x-model="emailUpdatesEnabled"
                                        class="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                    >
                                    <span class="text-sm font-bold text-slate-800" x-text="emailUpdatesEnabled ? 'Allowed' : 'Not allowed'"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                    <div class="rounded-[24px] border border-amber-200/80 bg-gradient-to-r from-amber-50 via-white to-orange-50 px-4 py-4 shadow-sm">
                        <p class="flex flex-col gap-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between">
                            <span>{{ __('Your email address is unverified.') }}</span>
                            <button type="button" wire:click.prevent="sendVerification" class="inline-flex w-fit items-center justify-center rounded-full bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-100">
                                {{ __('Resend verification') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 text-sm font-medium text-emerald-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif

                <div class="flex flex-col gap-3 rounded-[24px] border border-white/80 bg-white/75 px-5 py-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500">Identity changes update your TruthGuard profile and workspace menus.</p>

                    <button
                        type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                        </svg>
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section
        x-show="activeSection === 'photo'"
        x-cloak
        x-transition.opacity.duration.180ms
        role="tabpanel"
        class="p-4 sm:p-6"
    >
        <form
            id="truthguard-photo-form"
            method="POST"
            action="{{ route('profile.update') }}"
            enctype="multipart/form-data"
            class="grid gap-6 xl:grid-cols-[292px_minmax(0,1fr)]"
        >
            @csrf
            @method('patch')
            <input type="hidden" name="name" x-model="profileName">
            <input type="hidden" name="email" x-model="profileEmail">
            <input type="hidden" name="username" x-model="profileUsername">
            <input type="hidden" name="theme_preference" x-model="profileTheme">
            <input type="hidden" name="email_updates_enabled" x-bind:value="emailUpdatesEnabled ? '1' : '0'">
            <input type="hidden" name="return_section" value="photo">

            <aside class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.09)] backdrop-blur-xl">
                <div class="bg-gradient-to-br from-indigo-500 via-blue-500 to-sky-400 px-5 py-5 text-white">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-50/80">Profile Photo</p>
                            <h2 class="mt-2 text-lg font-black">Avatar preview</h2>
                        </div>
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-white/20 text-white ring-1 ring-white/25">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 7.5A2.25 2.25 0 0 1 6.75 5.25h10.5A2.25 2.25 0 0 1 19.5 7.5v9A2.25 2.25 0 0 1 17.25 18.75H6.75A2.25 2.25 0 0 1 4.5 16.5v-9ZM8.25 9h.01M4.5 15l3.3-3.3a1.5 1.5 0 0 1 2.12 0l1.33 1.33 2.58-2.58a1.5 1.5 0 0 1 2.12 0L19.5 14"></path>
                            </svg>
                        </span>
                    </div>
                </div>

                <div class="px-5 py-6">
                    <div class="mx-auto h-44 w-44 overflow-hidden rounded-[32px] bg-gradient-to-br from-slate-100 via-white to-blue-50 p-1 shadow-[0_24px_50px_rgba(37,99,235,0.16)] ring-1 ring-white">
                        <img
                            src="{{ $currentAvatarUrl }}"
                            x-bind:src="avatarPreviewUrl()"
                            alt="Large avatar preview"
                            class="h-full w-full rounded-[28px] object-cover"
                        >
                    </div>

                    <div class="mt-5 text-center">
                        <p class="truncate text-base font-semibold text-slate-950" x-text="profileName || 'TruthGuard User'">{{ $formName }}</p>
                        <p class="mt-1 truncate text-sm text-slate-500" x-text="photoFileName || '{{ $hasUploadedAvatar ? 'Uploaded profile photo' : 'Starter or connected account image' }}'"></p>
                    </div>
                </div>

                <div class="border-t border-slate-200/70 bg-white/60 px-5 py-4">
                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3">
                        <span class="text-sm font-semibold text-slate-600">Current source</span>
                        <span class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 ring-1 ring-blue-100">
                            {{ $hasUploadedAvatar ? 'Uploaded' : 'Starter' }}
                        </span>
                    </div>
                </div>
            </aside>

            <div class="space-y-4">
                <div class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
                    <div class="flex items-center gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-600 ring-1 ring-sky-100">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5v-9m0 0-3.75 3.75M12 7.5l3.75 3.75"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 17.25v.75A2.25 2.25 0 0 0 6.75 20.25h10.5A2.25 2.25 0 0 0 19.5 18v-.75"></path>
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-base font-black text-slate-950">Upload new photo</h3>
                            <p class="mt-1 text-sm text-slate-500">PNG, JPG, WEBP, or GIF up to {{ $avatarUploadMaxMb }}MB.</p>
                        </div>
                    </div>

                    <input
                        x-ref="avatarInput"
                        id="profile_avatar"
                        type="file"
                        name="avatar"
                        accept=".jpg,.jpeg,.png,.webp,.gif,image/*"
                        class="hidden"
                        @change="handleAvatarChange($event)"
                    >

                    <div class="p-5">
                        <label for="profile_avatar" class="group flex cursor-pointer flex-col items-center justify-center rounded-[26px] border-2 border-dashed border-blue-200/80 bg-gradient-to-br from-blue-50/80 via-white/80 to-emerald-50/70 px-5 py-10 text-center shadow-inner transition hover:border-blue-300 hover:from-blue-50 hover:to-emerald-50 focus-within:ring-4 focus-within:ring-blue-100">
                            <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl border border-blue-100 bg-white text-blue-600 shadow-sm transition group-hover:scale-105">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5v-9m0 0-3.75 3.75M12 7.5l3.75 3.75"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 17.25v.75A2.25 2.25 0 0 0 6.75 20.25h10.5A2.25 2.25 0 0 0 19.5 18v-.75"></path>
                                </svg>
                            </span>
                            <span class="mt-4 text-sm font-bold text-slate-950">Choose image</span>
                            <span class="mt-1 max-w-sm text-sm leading-6 text-slate-500">Your selected image appears in the preview before it is saved.</span>
                            <span x-show="photoFileName" x-cloak class="mt-3 max-w-full truncate rounded-full bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm ring-1 ring-blue-100" x-text="photoFileName"></span>
                        </label>

                        <p
                            x-show="avatarUploadError"
                            role="alert"
                            x-cloak
                            class="mt-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-600"
                            x-text="avatarUploadError"
                        ></p>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Format</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">JPG, PNG, WEBP, GIF</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200/70 bg-white/80 px-4 py-3 shadow-sm">
                                <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">Limit</p>
                                <p class="mt-1 text-sm font-semibold text-slate-800">{{ $avatarUploadMaxMb }}MB maximum</p>
                            </div>
                        </div>
                    </div>

                    @if ($errors->has('avatar'))
                        <ul class="px-5 pb-5 text-sm font-medium text-rose-500">
                            @foreach ($errors->get('avatar') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="flex flex-col gap-3 rounded-[24px] border border-white/80 bg-white/75 px-5 py-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl sm:flex-row sm:items-center">
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                        </svg>
                        Save Photo
                    </button>

                    <button
                        type="button"
                        x-show="previewUrl"
                        x-cloak
                        @click="clearSelectedAvatar()"
                        class="inline-flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white/90 px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50/70"
                    >
                        Clear Preview
                    </button>

                    @if ($hasUploadedAvatar)
                        <button
                            type="button"
                            wire:click="removeProfilePhoto"
                            class="inline-flex items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-bold text-rose-600 transition hover:border-rose-300 hover:bg-rose-100"
                        >
                            Remove Photo
                        </button>
                    @endif
                </div>
            </div>
        </form>
    </section>

    <section
        x-show="activeSection === 'appearance'"
        x-cloak
        x-transition.opacity.duration.180ms
        role="tabpanel"
        class="p-4 sm:p-6"
    >
        <form
            id="truthguard-appearance-form"
            method="POST"
            action="{{ route('profile.update') }}"
            enctype="multipart/form-data"
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_292px]"
            @submit="saveLocalPreferences()"
        >
            @csrf
            @method('patch')
            <input type="hidden" name="name" x-model="profileName">
            <input type="hidden" name="email" x-model="profileEmail">
            <input type="hidden" name="username" x-model="profileUsername">
            <input type="hidden" name="theme_preference" x-model="profileTheme">
            <input type="hidden" name="email_updates_enabled" x-bind:value="emailUpdatesEnabled ? '1' : '0'">
            <input type="hidden" name="return_section" value="appearance">

            <div class="space-y-5">
                <div class="overflow-hidden rounded-[28px] border border-white/80 bg-white/85 shadow-[0_24px_70px_rgba(15,23,42,0.08)] backdrop-blur-xl">
                    <div class="flex flex-col gap-3 border-b border-slate-200/70 bg-white/65 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-violet-50 text-violet-600 ring-1 ring-violet-100">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25M12 18.75V21M4.64 4.64l1.59 1.59M17.77 17.77l1.59 1.59M3 12h2.25M18.75 12H21M4.64 19.36l1.59-1.59M17.77 6.23l1.59-1.59M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"></path>
                                </svg>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">Appearance</p>
                                <h3 class="mt-1 text-base font-black text-slate-950">TruthGuard theme</h3>
                            </div>
                        </div>
                        <span
                            class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 ring-1 ring-blue-100"
                            x-text="{ ocean: 'Ocean Blue', forest: 'Emerald Trust', sunset: 'Amber Review' }[profileTheme] || '{{ $currentTheme['label'] }}'"
                        >
                            {{ $currentTheme['label'] }}
                        </span>
                    </div>

                    <div class="grid gap-4 p-5 lg:grid-cols-3">
                        @foreach ($themeOptions as $option)
                            <button
                                type="button"
                                @click="profileTheme = '{{ $option['key'] }}'"
                                class="group rounded-[24px] border bg-white/85 p-3 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-[0_18px_44px_rgba(15,23,42,0.09)] focus:outline-none focus:ring-4 focus:ring-blue-100"
                                :class="profileTheme === '{{ $option['key'] }}' ? 'border-blue-300 bg-blue-50/40 ring-4 ring-blue-100/70' : 'border-slate-200/80 hover:border-blue-200'"
                            >
                                <div class="rounded-[20px] bg-gradient-to-br {{ $option['preview'] }} p-3">
                                    <div class="h-20 rounded-2xl border border-white/80 bg-white/90 p-3 shadow-sm">
                                        <div class="h-2 w-16 rounded-full {{ $option['accent'] }}"></div>
                                        <div class="mt-3 h-2 w-24 rounded-full bg-slate-200"></div>
                                        <div class="mt-2 h-2 w-14 rounded-full bg-slate-100"></div>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-black text-slate-950">{{ $option['label'] }}</p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $option['description'] }}</p>
                                    </div>

                                    <span
                                        x-show="profileTheme === '{{ $option['key'] }}'"
                                        x-cloak
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-blue-600 text-white shadow-lg shadow-blue-500/25"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                                        </svg>
                                    </span>
                                </div>
                            </button>
                        @endforeach
                    </div>

                    @if ($errors->has('theme_preference'))
                        <ul class="px-5 pb-5 text-sm font-medium text-rose-500">
                            @foreach ($errors->get('theme_preference') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="rounded-[24px] border border-white/80 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl">
                        <h3 class="text-base font-black text-slate-950">Display mode</h3>
                        <div class="mt-4 grid gap-1 rounded-2xl border border-slate-200/80 bg-slate-100/80 p-1 sm:grid-cols-3">
                            @foreach ($displayModes as $mode)
                                <button
                                    type="button"
                                    @click="displayMode = '{{ $mode['key'] }}'; saveLocalPreferences()"
                                    class="rounded-xl px-3 py-2.5 text-left text-sm transition focus:outline-none focus:ring-4 focus:ring-blue-100"
                                    :class="displayMode === '{{ $mode['key'] }}' ? 'bg-white font-black text-slate-950 shadow-sm' : 'font-semibold text-slate-500 hover:text-slate-900'"
                                >
                                    <span class="block">{{ $mode['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-[24px] border border-white/80 bg-white/85 p-5 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl">
                        <h3 class="text-base font-black text-slate-950">Accent color</h3>
                        <div class="mt-4 flex flex-wrap gap-3">
                            @foreach ($accentOptions as $accent)
                                <button
                                    type="button"
                                    @click="accentColor = '{{ $accent['key'] }}'; saveLocalPreferences()"
                                    class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border transition focus:outline-none focus:ring-4 focus:ring-blue-100"
                                    :class="accentColor === '{{ $accent['key'] }}' ? 'border-blue-400 bg-blue-50 shadow-sm ring-4 ring-blue-100/70' : 'border-slate-200/80 bg-white hover:border-blue-200'"
                                    title="{{ $accent['label'] }}"
                                    aria-label="{{ $accent['label'] }} accent"
                                >
                                    <span class="h-5 w-5 rounded-full {{ $accent['class'] }}"></span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex justify-end rounded-[24px] border border-white/80 bg-white/75 px-5 py-4 shadow-[0_18px_48px_rgba(15,23,42,0.07)] backdrop-blur-xl">
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25 transition hover:-translate-y-0.5 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                        </svg>
                        Save Appearance
                    </button>
                </div>
            </div>

            <aside class="overflow-hidden rounded-[28px] border border-white/80 bg-gradient-to-br from-blue-600 via-sky-500 to-emerald-400 text-white shadow-[0_24px_70px_rgba(37,99,235,0.18)] xl:sticky xl:top-28 xl:self-start">
                <div class="border-b border-white/20 px-5 py-4">
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-50/80">Preview</p>
                    <h3 class="mt-1 text-base font-black">Workspace sample</h3>
                </div>

                <div class="p-5">
                    <div
                        class="rounded-[24px] bg-gradient-to-br p-4 transition ring-1 ring-white/20"
                        :class="{
                            'from-blue-400/30 via-white/15 to-sky-300/20': profileTheme === 'ocean',
                            'from-emerald-300/30 via-white/15 to-teal-300/20': profileTheme === 'forest',
                            'from-amber-300/30 via-white/15 to-orange-300/20': profileTheme === 'sunset'
                        }"
                    >
                        <div class="rounded-[22px] border border-white/50 bg-white/95 p-4 text-slate-950 shadow-xl">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-400">TruthGuard</p>
                                    <p class="mt-1 text-sm font-black">Dashboard chrome</p>
                                </div>
                                <span
                                    class="h-8 w-8 rounded-2xl shadow-sm"
                                    :class="{
                                        'bg-blue-600': accentColor === 'blue',
                                        'bg-violet-600': accentColor === 'violet',
                                        'bg-emerald-500': accentColor === 'emerald',
                                        'bg-slate-800': accentColor === 'slate'
                                    }"
                                ></span>
                            </div>
                            <div class="mt-5 space-y-2">
                                <div class="h-2 w-3/4 rounded-full bg-slate-200"></div>
                                <div class="h-2 w-1/2 rounded-full bg-slate-100"></div>
                            </div>
                            <div class="mt-5 grid grid-cols-3 gap-2">
                                <div class="h-12 rounded-2xl bg-slate-100"></div>
                                <div class="h-12 rounded-2xl bg-slate-100"></div>
                                <div class="h-12 rounded-2xl bg-slate-100"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/20 bg-white/15 px-3 py-3">
                            <span class="text-sm text-blue-50">Theme</span>
                            <span class="text-sm font-semibold" x-text="{ ocean: 'Ocean', forest: 'Forest', sunset: 'Sunset' }[profileTheme] || 'Ocean'"></span>
                        </div>
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/20 bg-white/15 px-3 py-3">
                            <span class="text-sm text-blue-50">Display</span>
                            <span class="text-sm font-semibold capitalize" x-text="displayMode"></span>
                        </div>
                    </div>
                </div>
            </aside>
        </form>
    </section>
</div>
