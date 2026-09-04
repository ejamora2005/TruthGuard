@php
    $layout = auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.user';
    $user = auth()->user();
    $fullName = trim((string) ($user?->name ?? 'TruthGuard User'));
    $nameParts = preg_split('/\s+/', $fullName) ?: [];
    $firstName = old('first_name', $nameParts[0] ?? '');
    $lastName = old('last_name', count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '');
    $profileAvatarUrl = $user?->display_profile_avatar_url ?? asset('images/avatars/default-ocean.svg');
    $themeKey = in_array((string) $user?->theme_preference, ['ocean', 'forest', 'sunset'], true)
        ? (string) $user?->theme_preference
        : 'ocean';
    $themeLabels = [
        'ocean' => 'Ocean Blue',
        'forest' => 'Forest Green',
        'sunset' => 'Sunset Amber',
    ];
    $roleLabel = $user?->isAdmin() ? 'Administrator' : 'User';
    $statusLabel = ucfirst((string) ($user?->subscription_status ?: 'active'));
    $emailVerified = filled($user?->email_verified_at);
    $createdDate = $user?->created_at?->format('M j, Y') ?? 'Not recorded';
    $lastLogin = $user?->last_login_at?->format('M j, Y g:i A') ?? 'Not recorded';
    $completionItems = [
        filled($user?->name),
        filled($user?->email),
        filled($user?->username),
        filled($user?->email_verified_at),
        ! (bool) ($user?->uses_starter_profile_avatar ?? true),
    ];
    $profileCompletion = (int) round((collect($completionItems)->filter()->count() / count($completionItems)) * 100);
    $validSections = ['personal', 'photo', 'appearance', 'security', 'account'];
    $requestedSection = (string) request()->query('section', 'personal');
    $initialSection = in_array($requestedSection, $validSections, true) ? $requestedSection : 'personal';

    if ($errors->has('avatar')) {
        $initialSection = 'photo';
    } elseif ($errors->has('theme_preference')) {
        $initialSection = 'appearance';
    } elseif ($errors->hasAny(['current_password', 'password', 'password_confirmation'])) {
        $initialSection = 'security';
    } elseif ($errors->hasAny(['name', 'email', 'username'])) {
        $initialSection = 'personal';
    }

    $settingsSections = [
        [
            'id' => 'personal',
            'label' => 'Personal Information',
            'description' => 'Name, email, identity',
            'status' => 'Core',
            'icon' => 'M15.75 7.5a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0',
        ],
        [
            'id' => 'photo',
            'label' => 'Profile Photo',
            'description' => 'Avatar and preview',
            'status' => 'Media',
            'icon' => 'M4.5 7.5A2.25 2.25 0 0 1 6.75 5.25h10.5A2.25 2.25 0 0 1 19.5 7.5v9A2.25 2.25 0 0 1 17.25 18.75H6.75A2.25 2.25 0 0 1 4.5 16.5v-9ZM8.25 9h.01M4.5 15l3.3-3.3a1.5 1.5 0 0 1 2.12 0l1.33 1.33 2.58-2.58a1.5 1.5 0 0 1 2.12 0L19.5 14',
        ],
        [
            'id' => 'appearance',
            'label' => 'Appearance',
            'description' => 'Theme and accent',
            'status' => 'Visual',
            'icon' => 'M12 3v2.25M12 18.75V21M4.64 4.64l1.59 1.59M17.77 17.77l1.59 1.59M3 12h2.25M18.75 12H21M4.64 19.36l1.59-1.59M17.77 6.23l1.59-1.59M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
        ],
        [
            'id' => 'security',
            'label' => 'Security',
            'description' => 'Password and sessions',
            'status' => 'Protected',
            'icon' => 'M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z',
        ],
        [
            'id' => 'account',
            'label' => 'Account & Privacy',
            'description' => 'Data and deletion',
            'status' => 'Sensitive',
            'icon' => 'M12 12.75c2.9 0 5.25-2.35 5.25-5.25S14.9 2.25 12 2.25 6.75 4.6 6.75 7.5 9.1 12.75 12 12.75ZM4.5 21a7.5 7.5 0 0 1 15 0M16.5 18.75l1.5 1.5 3-3',
        ],
    ];
@endphp

@extends($layout)

@section('title', 'Account Settings')
@section('page_title', 'Settings')
@section('page_subtitle', 'Account, security, and workspace preferences')

@section('content')
    <div
        class="truthguard-mobile-page truthguard-mobile-settings truthguard-settings-page mx-auto w-full max-w-[1380px] space-y-5 pb-2"
        x-data="{
            activeSection: @js($initialSection),
            settingsModalOpen: @js(request()->has('section') || $errors->any()),
            profileName: @js($fullName),
            profileEmail: @js((string) ($user?->email ?? '')),
            profileAvatarUrl: @js($profileAvatarUrl),
            profileThemeKey: @js($themeKey),
            profileCompletion: @js($profileCompletion),
            sectionQuery: '',
            sections: @js(collect($settingsSections)->mapWithKeys(fn ($section) => [$section['id'] => [
                'label' => $section['label'],
                'description' => $section['description'],
                'status' => $section['status'],
            ]])->all()),
            init() {
                if (this.settingsModalOpen) {
                    document.body.classList.add('truthguard-settings-modal-open');
                }

                window.addEventListener('truthguard-profile-updated', (event) => {
                    const detail = event.detail || {};

                    if (detail.name) {
                        this.profileName = detail.name;
                    }

                    if (detail.email) {
                        this.profileEmail = detail.email;
                    }

                    if (detail.avatarUrl) {
                        this.profileAvatarUrl = detail.avatarUrl;
                    }

                    if (detail.theme) {
                        this.profileThemeKey = detail.theme;
                    }
                });
            },
            destroy() {
                document.body.classList.remove('truthguard-settings-modal-open');
            },
            setSection(section) {
                this.activeSection = section;

                const url = new URL(window.location.href);
                url.searchParams.set('section', section);
                window.history.replaceState({}, '', url.toString());
            },
            openSettings(section) {
                this.setSection(section);
                this.settingsModalOpen = true;
                document.body.classList.add('truthguard-settings-modal-open');

                this.$nextTick(() => this.$refs.settingsDialog?.focus());
            },
            closeSettingsModal() {
                this.settingsModalOpen = false;
                document.body.classList.remove('truthguard-settings-modal-open');

                const url = new URL(window.location.href);
                url.searchParams.delete('section');
                window.history.replaceState({}, '', url.toString());
            },
            submitActiveForm() {
                const forms = {
                    personal: 'truthguard-personal-form',
                    photo: 'truthguard-photo-form',
                    appearance: 'truthguard-appearance-form',
                    security: 'truthguard-password-form',
                };
                const form = document.getElementById(forms[this.activeSection]);

                if (form) {
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            },
            canSaveSection() {
                return ['personal', 'photo', 'appearance', 'security'].includes(this.activeSection);
            },
            activeSectionLabel() {
                return this.sections[this.activeSection]?.label || 'Settings';
            },
            activeSectionDescription() {
                return this.sections[this.activeSection]?.description || 'Account preferences';
            },
            sectionMatches(text) {
                const query = this.sectionQuery.trim().toLowerCase();

                return query === '' || text.toLowerCase().includes(query);
            },
        }"
        @keydown.escape.window="if (settingsModalOpen) closeSettingsModal()"
    >
        <style>
            .truthguard-settings-page {
                --tg-settings-ink: #071426;
                --tg-settings-muted: #64748b;
                --tg-settings-line: rgba(148, 163, 184, 0.2);
                --tg-settings-blue: #2563eb;
                --tg-settings-cyan: #06b6d4;
                position: relative;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: var(--tg-settings-ink);
            }

            .truthguard-settings-page::before {
                content: '';
                position: absolute;
                inset: -1.25rem -1.25rem auto;
                z-index: -1;
                height: 32rem;
                background:
                    radial-gradient(circle at 14% 10%, rgba(34, 211, 238, 0.12), transparent 30%),
                    radial-gradient(circle at 86% 4%, rgba(99, 102, 241, 0.12), transparent 28%);
                pointer-events: none;
            }

            .truthguard-settings-hero {
                position: relative;
                isolation: isolate;
                border-color: rgba(51, 65, 85, 0.82) !important;
                background:
                    radial-gradient(circle at 82% 8%, rgba(56, 189, 248, 0.25), transparent 26%),
                    radial-gradient(circle at 12% 92%, rgba(37, 99, 235, 0.3), transparent 30%),
                    linear-gradient(135deg, #071426 0%, #0b1d36 54%, #102b4c 100%) !important;
                color: white;
                box-shadow: 0 30px 80px rgba(2, 8, 23, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.08) !important;
                backdrop-filter: none !important;
            }

            .truthguard-settings-hero::before {
                content: '';
                position: absolute;
                inset: 0;
                z-index: -1;
                background-image:
                    linear-gradient(rgba(148, 163, 184, 0.055) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(148, 163, 184, 0.055) 1px, transparent 1px);
                background-size: 28px 28px;
                mask-image: linear-gradient(90deg, #000, transparent 92%);
                -webkit-mask-image: linear-gradient(90deg, #000, transparent 92%);
                pointer-events: none;
            }

            .truthguard-settings-hero::after {
                content: '';
                position: absolute;
                top: -5rem;
                right: 12%;
                z-index: -1;
                width: 15rem;
                height: 15rem;
                border: 1px solid rgba(125, 211, 252, 0.13);
                border-radius: 9999px;
                box-shadow: 0 0 0 2.4rem rgba(125, 211, 252, 0.025), 0 0 0 5rem rgba(99, 102, 241, 0.02);
                pointer-events: none;
            }

            .truthguard-settings-eyebrow {
                display: inline-flex;
                align-items: center;
                gap: 0.55rem;
                border: 1px solid rgba(125, 211, 252, 0.24);
                border-radius: 9999px;
                background: rgba(15, 23, 42, 0.44);
                padding: 0.42rem 0.75rem;
                color: #bae6fd;
                font-size: 0.69rem;
                font-weight: 800;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
            }

            .truthguard-settings-eyebrow > span:first-child {
                width: 0.45rem;
                height: 0.45rem;
                border-radius: 9999px;
                background: #22d3ee;
                box-shadow: 0 0 0 4px rgba(34, 211, 238, 0.12), 0 0 16px rgba(34, 211, 238, 0.65);
            }

            .truthguard-settings-avatar-shell {
                position: relative;
                display: grid;
                width: 5.5rem;
                height: 5.5rem;
                flex: 0 0 5.5rem;
                place-items: center;
                border: 1px solid rgba(125, 211, 252, 0.22);
                border-radius: 1.65rem;
                background: linear-gradient(145deg, rgba(255, 255, 255, 0.15), rgba(125, 211, 252, 0.05));
                box-shadow: 0 18px 42px rgba(2, 8, 23, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.14);
            }

            .truthguard-settings-avatar-shell img {
                width: 4.55rem !important;
                height: 4.55rem !important;
                border-radius: 1.25rem !important;
                box-shadow: none !important;
                --tw-ring-shadow: 0 0 0 3px rgba(255, 255, 255, 0.12) !important;
            }

            .truthguard-settings-avatar-shell .truthguard-settings-presence {
                position: absolute;
                right: -0.15rem;
                bottom: -0.15rem;
                width: 1.2rem;
                height: 1.2rem;
                border: 4px solid #0b1d36;
                border-radius: 9999px;
                background: #34d399;
                box-shadow: 0 0 16px rgba(52, 211, 153, 0.6);
            }

            .truthguard-settings-hero h1 {
                color: #fff !important;
                font-size: clamp(2rem, 3vw, 3rem) !important;
                letter-spacing: -0.045em !important;
                line-height: 1.02;
            }

            .truthguard-settings-hero-copy {
                color: #a9bdd5 !important;
            }

            .truthguard-settings-status-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                border: 1px solid rgba(148, 163, 184, 0.16) !important;
                border-radius: 9999px;
                background: rgba(15, 23, 42, 0.45) !important;
                padding: 0.42rem 0.72rem;
                color: #dbeafe !important;
                font-size: 0.7rem;
                font-weight: 750;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
            }

            .truthguard-settings-status-chip.is-success {
                border-color: rgba(52, 211, 153, 0.22) !important;
                color: #a7f3d0 !important;
            }

            .truthguard-settings-hero-summary {
                border-color: rgba(125, 211, 252, 0.18) !important;
                background: linear-gradient(145deg, rgba(255, 255, 255, 0.11), rgba(15, 23, 42, 0.24)) !important;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1), 0 22px 50px rgba(2, 8, 23, 0.2) !important;
                backdrop-filter: blur(18px) !important;
            }

            .truthguard-settings-readiness-ring {
                --completion: 0;
                display: grid;
                width: 5.25rem;
                height: 5.25rem;
                flex: 0 0 5.25rem;
                place-items: center;
                border-radius: 9999px;
                background: conic-gradient(#22d3ee calc(var(--completion) * 1%), rgba(148, 163, 184, 0.16) 0);
                box-shadow: 0 0 32px rgba(34, 211, 238, 0.12);
            }

            .truthguard-settings-readiness-ring > span {
                display: grid;
                width: 4.25rem;
                height: 4.25rem;
                place-items: center;
                border-radius: inherit;
                background: #0c1e36;
                color: #fff;
                font-size: 1.2rem;
                font-weight: 900;
            }

            .truthguard-settings-save-button {
                border: 1px solid rgba(255, 255, 255, 0.18);
                background: linear-gradient(135deg, #38bdf8, #2563eb 58%, #4f46e5) !important;
                color: #fff !important;
                box-shadow: 0 14px 30px rgba(37, 99, 235, 0.28), inset 0 1px 0 rgba(255, 255, 255, 0.2) !important;
            }

            .truthguard-settings-save-button:disabled {
                border-color: rgba(148, 163, 184, 0.14);
                background: rgba(51, 65, 85, 0.72) !important;
                color: #94a3b8 !important;
                box-shadow: none !important;
            }

            .truthguard-settings-metrics > div {
                position: relative;
                overflow: hidden;
                border-color: rgba(148, 163, 184, 0.13) !important;
                background: rgba(15, 23, 42, 0.42) !important;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.045) !important;
            }

            .truthguard-settings-metrics > div::after {
                content: '';
                position: absolute;
                top: 0;
                right: 0;
                width: 3rem;
                height: 100%;
                background: linear-gradient(90deg, transparent, rgba(56, 189, 248, 0.055));
                pointer-events: none;
            }

            .truthguard-settings-metrics p:first-child {
                color: #7188a3 !important;
            }

            .truthguard-settings-metrics p:last-child {
                color: #e8f2ff !important;
            }

            .truthguard-settings-rail,
            .truthguard-settings-panel {
                border-color: rgba(203, 213, 225, 0.72) !important;
                background: rgba(255, 255, 255, 0.94) !important;
                box-shadow: 0 24px 60px rgba(15, 23, 42, 0.075), inset 0 1px 0 #fff !important;
                backdrop-filter: blur(22px) !important;
            }

            .truthguard-settings-rail-header {
                background: linear-gradient(180deg, #f8fbff, rgba(255, 255, 255, 0.92));
            }

            .truthguard-settings-rail-header > div:first-of-type,
            .truthguard-settings-search {
                border-color: rgba(191, 219, 254, 0.72) !important;
                background: #fff !important;
                box-shadow: 0 8px 20px rgba(15, 23, 42, 0.045) !important;
            }

            .truthguard-settings-nav-item {
                border: 1px solid transparent;
            }

            .truthguard-settings-nav-item[aria-selected="true"] {
                border-color: rgba(96, 165, 250, 0.42) !important;
                background: linear-gradient(135deg, #1d4ed8, #2563eb 54%, #4f46e5) !important;
                box-shadow: 0 14px 28px rgba(37, 99, 235, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.15) !important;
            }

            .truthguard-settings-nav-item:not([aria-selected="true"]):hover {
                border-color: #dbeafe;
                background: #f8fbff !important;
            }

            .truthguard-settings-rail-progress {
                border-color: #e2e8f0 !important;
                background: linear-gradient(180deg, #fff, #f8fafc) !important;
            }

            .truthguard-settings-panel-header {
                border-color: #e2e8f0 !important;
                background:
                    radial-gradient(circle at 94% 0%, rgba(56, 189, 248, 0.11), transparent 28%),
                    linear-gradient(135deg, #f8fbff, #fff 72%) !important;
            }

            .truthguard-settings-panel-kicker {
                color: #2563eb !important;
            }

            .truthguard-settings-panel > [x-show] {
                min-height: 560px;
            }

            .truthguard-settings-panel section form,
            .truthguard-settings-panel section > .grid > div > div,
            .truthguard-settings-panel section > .grid > aside > div {
                border-color: rgba(203, 213, 225, 0.74) !important;
                border-radius: 1.35rem !important;
                background-color: rgba(255, 255, 255, 0.98) !important;
                box-shadow: 0 16px 38px rgba(15, 23, 42, 0.055) !important;
            }

            .truthguard-settings-panel input:not([type="hidden"]),
            .truthguard-settings-panel textarea,
            .truthguard-settings-panel .focus-within\:border-blue-400 {
                border-radius: 0.9rem !important;
            }

            body.truthguard-settings-modal-open .truthguard-user-content-column {
                overflow: hidden !important;
            }

            .truthguard-settings-overview {
                border: 1px solid rgba(203, 213, 225, 0.76);
                border-radius: 1.75rem;
                background:
                    radial-gradient(circle at 8% 0%, rgba(186, 230, 253, 0.2), transparent 30%),
                    linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.9));
                padding: 1.25rem;
                box-shadow: 0 22px 58px rgba(15, 23, 42, 0.065), inset 0 1px 0 #fff;
            }

            .truthguard-settings-launch-card {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                min-height: 12.5rem;
                border: 1px solid rgba(203, 213, 225, 0.76);
                border-radius: 1.35rem;
                background: rgba(255, 255, 255, 0.94);
                padding: 1.15rem;
                text-align: left;
                box-shadow: 0 12px 30px rgba(15, 23, 42, 0.045), inset 0 1px 0 #fff;
                transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
            }

            .truthguard-settings-launch-card::after {
                content: '';
                position: absolute;
                right: -2.5rem;
                bottom: -3rem;
                z-index: -1;
                width: 8rem;
                height: 8rem;
                border-radius: 9999px;
                background: radial-gradient(circle, rgba(59, 130, 246, 0.11), transparent 68%);
                transition: transform 180ms ease;
            }

            .truthguard-settings-launch-card:hover {
                transform: translateY(-3px);
                border-color: rgba(96, 165, 250, 0.64);
                box-shadow: 0 20px 42px rgba(37, 99, 235, 0.1), inset 0 1px 0 #fff;
            }

            .truthguard-settings-launch-card:hover::after {
                transform: scale(1.25);
            }

            .truthguard-settings-launch-card:focus-visible {
                outline: 3px solid rgba(147, 197, 253, 0.7);
                outline-offset: 3px;
            }

            .truthguard-settings-modal {
                position: fixed;
                inset: 0;
                display: grid;
                place-items: center;
                padding: max(1rem, env(safe-area-inset-top, 0px)) max(1rem, env(safe-area-inset-right, 0px))
                    max(1rem, env(safe-area-inset-bottom, 0px)) max(1rem, env(safe-area-inset-left, 0px));
            }

            .truthguard-settings-modal-backdrop {
                position: absolute;
                inset: 0;
                border: 0;
                background: rgba(7, 20, 38, 0.58);
                backdrop-filter: blur(12px);
            }

            .truthguard-settings-modal-dialog {
                position: relative;
                display: flex;
                width: min(70rem, calc(100vw - 2rem));
                max-height: calc(100dvh - 2rem);
                flex-direction: column;
                overflow: hidden;
                border: 1px solid rgba(191, 219, 254, 0.72);
                border-radius: 1.75rem;
                background: #f8fafc;
                box-shadow: 0 36px 100px rgba(2, 8, 23, 0.34), inset 0 1px 0 #fff;
            }

            .truthguard-settings-modal-header {
                background:
                    radial-gradient(circle at 92% 0%, rgba(56, 189, 248, 0.18), transparent 30%),
                    linear-gradient(135deg, #071426, #102b4c);
            }

            .truthguard-settings-modal-nav {
                scrollbar-width: none;
            }

            .truthguard-settings-modal-nav::-webkit-scrollbar {
                display: none;
            }

            .truthguard-settings-modal-body {
                min-height: 0;
                overflow-x: hidden;
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            .truthguard-settings-modal-body .truthguard-settings-panel {
                border: 0 !important;
                border-radius: 0 !important;
                background: transparent !important;
                box-shadow: none !important;
                backdrop-filter: none !important;
            }

            .truthguard-settings-modal-body .truthguard-settings-panel > [x-show],
            .truthguard-settings-modal-body [role="tabpanel"] {
                min-height: 0 !important;
            }

            @media (max-width: 1023px) {
                .truthguard-settings-hero-summary {
                    width: 100% !important;
                }
            }

            @media (max-width: 640px) {
                .truthguard-settings-page::before {
                    display: none;
                }

                .truthguard-settings-hero {
                    padding: 1rem !important;
                }

                .truthguard-settings-avatar-shell {
                    width: 4.2rem;
                    height: 4.2rem;
                    flex-basis: 4.2rem;
                    border-radius: 1.2rem;
                }

                .truthguard-settings-avatar-shell img {
                    width: 3.45rem !important;
                    height: 3.45rem !important;
                    border-radius: 0.9rem !important;
                }

                .truthguard-settings-hero h1 {
                    font-size: 1.35rem !important;
                }

                .truthguard-settings-hero-copy,
                .truthguard-settings-hero-summary,
                .truthguard-settings-metrics {
                    display: none !important;
                }

                .truthguard-settings-status-chip {
                    padding: 0.35rem 0.55rem;
                    font-size: 0.62rem;
                }

                .truthguard-settings-panel > [x-show] {
                    min-height: 0;
                }

                .truthguard-settings-panel section form,
                .truthguard-settings-panel section > .grid > div > div,
                .truthguard-settings-panel section > .grid > aside > div {
                    border-radius: 0.9rem !important;
                    box-shadow: none !important;
                }

                .truthguard-settings-overview {
                    border-radius: 1.15rem;
                    padding: 0.8rem;
                }

                .truthguard-settings-launch-card {
                    min-height: 9.5rem;
                    border-radius: 1rem;
                    padding: 0.9rem;
                }

                .truthguard-settings-modal {
                    align-items: end;
                    padding: 0;
                }

                .truthguard-settings-modal-dialog {
                    width: 100%;
                    max-height: calc(100dvh - env(safe-area-inset-top, 0px));
                    border-right: 0;
                    border-bottom: 0;
                    border-left: 0;
                    border-radius: 1.25rem 1.25rem 0 0;
                }
            }
        </style>

        <section class="truthguard-settings-hero overflow-hidden rounded-[28px] border px-5 py-5 sm:px-6 sm:py-6">
            <div class="flex flex-col gap-6 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 flex-col gap-5 sm:flex-row sm:items-center">
                    <div class="truthguard-settings-avatar-shell">
                        <img
                            src="{{ $profileAvatarUrl }}"
                            alt="Profile avatar"
                            x-bind:src="profileAvatarUrl"
                            class="object-cover ring-2 ring-white/20"
                        >
                        <span class="truthguard-settings-presence" aria-label="Account active"></span>
                    </div>

                    <div class="min-w-0">
                        <div class="truthguard-settings-eyebrow">
                            <span aria-hidden="true"></span>
                            <span>TruthGuard OS</span>
                            <span class="text-slate-500">/</span>
                            <span>System preferences</span>
                        </div>
                        <h1 class="mt-4 font-black">Settings control center</h1>
                        <p class="truthguard-settings-hero-copy mt-2 max-w-2xl text-sm leading-6 sm:text-base">
                            One secure workspace for your identity, interface, privacy, and account protection.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <span class="truthguard-settings-status-chip is-success">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ $statusLabel }}
                            </span>
                            <span class="truthguard-settings-status-chip">{{ $roleLabel }} access</span>
                            <span @class(['truthguard-settings-status-chip', 'is-success' => $emailVerified])>
                                {{ $emailVerified ? 'Identity verified' : 'Verification pending' }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="truthguard-settings-hero-summary w-full rounded-[24px] border p-4 xl:w-[390px]">
                    <div class="flex items-center gap-4">
                        <div class="truthguard-settings-readiness-ring" style="--completion: {{ $profileCompletion }}">
                            <span>{{ $profileCompletion }}%</span>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-sky-200/70">Profile health</p>
                            <p class="mt-1 text-base font-black text-white">Workspace ready</p>
                            <p class="mt-1 text-xs leading-5 text-slate-400">Complete your identity for stronger account recovery.</p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-white/10 pt-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-500">Quick setup</p>
                            <p class="mt-1 truncate text-sm font-bold text-slate-200">Review your identity profile</p>
                        </div>
                        <button
                            type="button"
                            @click="openSettings('personal')"
                            class="truthguard-settings-save-button inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-bold transition hover:-translate-y-0.5 focus:outline-none focus:ring-4 focus:ring-sky-400/20 disabled:cursor-not-allowed disabled:transform-none"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12.75 10.5 18 19 6"></path>
                            </svg>
                            Open profile
                        </button>
                    </div>
                </div>
            </div>

            <div class="truthguard-settings-metrics mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.15em]">Interface profile</p>
                    <p class="mt-1.5 truncate text-sm font-bold">{{ $themeLabels[$themeKey] }}</p>
                </div>
                <div class="rounded-xl border px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.15em]">Member since</p>
                    <p class="mt-1.5 truncate text-sm font-bold">{{ $createdDate }}</p>
                </div>
                <div class="rounded-xl border px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-[0.15em]">Last secure access</p>
                    <p class="mt-1.5 truncate text-sm font-bold">{{ $lastLogin }}</p>
                </div>
            </div>
        </section>

        <section class="truthguard-settings-overview">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-[11px] font-black uppercase tracking-[0.16em] text-blue-600">Settings modules</p>
                    <h2 class="mt-1 text-xl font-black tracking-tight text-slate-950 sm:text-2xl">Choose what you want to manage</h2>
                    <p class="mt-1 text-sm text-slate-500">Changes open in a focused window, keeping the main settings page clean.</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    5 modules available
                </span>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                @foreach ($settingsSections as $section)
                    @php
                        $overview = match ($section['id']) {
                            'personal' => ['value' => $fullName, 'meta' => (string) ($user?->email ?? ''), 'tone' => 'bg-blue-50 text-blue-700 ring-blue-100'],
                            'photo' => ['value' => (bool) ($user?->uses_starter_profile_avatar ?? true) ? 'Starter avatar' : 'Custom photo', 'meta' => 'Profile image and preview', 'tone' => 'bg-cyan-50 text-cyan-700 ring-cyan-100'],
                            'appearance' => ['value' => $themeLabels[$themeKey], 'meta' => 'Current interface theme', 'tone' => 'bg-violet-50 text-violet-700 ring-violet-100'],
                            'security' => ['value' => 'Password protected', 'meta' => 'Last access '.$lastLogin, 'tone' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
                            default => ['value' => $statusLabel.' account', 'meta' => 'Member since '.$createdDate, 'tone' => 'bg-amber-50 text-amber-700 ring-amber-100'],
                        };
                    @endphp

                    <button
                        type="button"
                        @click="openSettings('{{ $section['id'] }}')"
                        class="truthguard-settings-launch-card group flex flex-col"
                        aria-label="Open {{ $section['label'] }} settings"
                    >
                        <span class="flex items-start justify-between gap-3">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl {{ $overview['tone'] }} ring-1">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $section['icon'] }}"></path>
                                </svg>
                            </span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.1em] text-slate-500">{{ $section['status'] }}</span>
                        </span>

                        <span class="mt-4 block text-base font-black text-slate-950">{{ $section['label'] }}</span>
                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ $section['description'] }}</span>

                        <span class="mt-auto block pt-4">
                            <span class="block truncate text-xs font-bold text-slate-800">{{ $overview['value'] }}</span>
                            <span class="mt-1 flex items-center justify-between gap-2 text-[11px] text-slate-400">
                                <span class="truncate">{{ $overview['meta'] }}</span>
                                <svg class="h-4 w-4 shrink-0 text-blue-600 transition group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"></path>
                                </svg>
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>

        <div
            x-show="settingsModalOpen"
            x-cloak
            x-transition.opacity.duration.180ms
            class="truthguard-settings-modal"
            style="display: none; z-index: 2147483400;"
        >
            <button type="button" class="truthguard-settings-modal-backdrop" @click="closeSettingsModal()" aria-label="Close settings window"></button>

            <section
                x-ref="settingsDialog"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                aria-labelledby="truthguard-settings-modal-title"
                class="truthguard-settings-modal-dialog"
            >
                <header class="truthguard-settings-modal-header shrink-0 px-4 pb-4 pt-4 text-white sm:px-5 sm:pt-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/10 text-sky-300">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.25 12.2 1.85 1.85 3.9-4.1"></path>
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-black uppercase tracking-[0.18em] text-sky-300">Settings workspace</p>
                                <h2 id="truthguard-settings-modal-title" class="mt-1 truncate text-xl font-black text-white" x-text="activeSectionLabel()">Personal Information</h2>
                                <p class="mt-1 truncate text-xs text-slate-300" x-text="activeSectionDescription()">Name, email, identity</p>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="closeSettingsModal()"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/10 text-slate-300 transition hover:bg-white/20 hover:text-white focus:outline-none focus:ring-4 focus:ring-sky-400/20"
                            aria-label="Close settings"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <nav class="truthguard-settings-modal-nav mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Settings modal sections">
                        @foreach ($settingsSections as $section)
                            <button
                                type="button"
                                @click="setSection('{{ $section['id'] }}')"
                                class="inline-flex shrink-0 items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-sky-400/20"
                                :class="activeSection === '{{ $section['id'] }}'
                                    ? 'border-sky-300/40 bg-sky-400/20 text-white'
                                    : 'border-white/10 bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white'"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $section['icon'] }}"></path>
                                </svg>
                                {{ $section['label'] }}
                            </button>
                        @endforeach
                    </nav>
                </header>

                <div class="truthguard-settings-modal-body">
                    <div class="truthguard-settings-panel">
                        <livewire:profile.update-profile-information-form />
                        <livewire:profile.update-password-form />
                        <livewire:profile.delete-user-form />
                    </div>
                </div>
            </section>
        </div>
    </div>
@endsection
