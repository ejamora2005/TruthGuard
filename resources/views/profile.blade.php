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
    $themeVars = [
        'ocean' => '--color-brand-50:#eff6ff;--color-brand-100:#dbeafe;--color-brand-300:#93c5fd;--color-brand-400:#60a5fa;--color-brand-500:#2563eb;--color-brand-600:#1d4ed8;',
        'forest' => '--color-brand-50:#ecfdf5;--color-brand-100:#d1fae5;--color-brand-300:#6ee7b7;--color-brand-400:#34d399;--color-brand-500:#10b981;--color-brand-600:#059669;',
        'sunset' => '--color-brand-50:#fff7ed;--color-brand-100:#ffedd5;--color-brand-300:#fdba74;--color-brand-400:#fb923c;--color-brand-500:#f97316;--color-brand-600:#ea580c;',
    ];
    $activeThemeVars = $themeVars[$themeKey] ?? $themeVars['ocean'];
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
            returnFocus: null,
            sections: @js(collect($settingsSections)->mapWithKeys(fn ($section) => [$section['id'] => [
                'label' => $section['label'],
                'description' => $section['description'],
                'status' => $section['status'],
            ]])->all()),
            init() {
                if (this.settingsModalOpen) {
                    document.body.classList.add('truthguard-settings-modal-open');
                    this.$nextTick(() => this.$refs.settingsDialog?.focus());
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
                this.$nextTick(() => {
                    this.$refs.settingsBody?.scrollTo({ top: 0 });
                    this.$refs.settingsDialog?.querySelector('.truthguard-settings-modal-tab.is-active')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                });
            },
            openSettings(section) {
                this.returnFocus = document.activeElement;
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
                this.$nextTick(() => this.returnFocus?.isConnected && this.returnFocus.focus({ preventScroll: true }));
            },
            trapSettingsFocus(event) {
                const dialog = this.$refs.settingsDialog;
                const controls = Array.from(dialog.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'))
                    .filter(el => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length);
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (!first) {
                    event.preventDefault();
                    dialog.focus();
                } else if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
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
        style="{{ $activeThemeVars }}"
        @keydown.escape.window="if (settingsModalOpen && !document.querySelector('.truthguard-account-delete-dialog')?.getClientRects().length) closeSettingsModal()"
    >
        <style>
            .truthguard-settings-page {
                --tg-settings-ink: #1e3a5f;
                --tg-settings-muted: #64748b;
                --tg-settings-line: rgba(148, 163, 184, 0.2);
                --tg-settings-blue: var(--color-brand-500, #2563eb);
                --tg-settings-blue-600: var(--color-brand-600, #1d4ed8);
                --tg-settings-cyan: #06b6d4;
                --tg-settings-soft: var(--color-brand-50, #eff6ff);
                --tg-settings-soft-strong: var(--color-brand-100, #dbeafe);
                position: relative;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                color: var(--tg-settings-ink);
            }

            .truthguard-settings-modal {
                --tg-settings-ink: #1e3a5f;
                --tg-settings-muted: #64748b;
                --tg-settings-line: rgba(148, 163, 184, 0.2);
                --tg-settings-blue: var(--color-brand-500, #2563eb);
                --tg-settings-blue-600: var(--color-brand-600, #1d4ed8);
                --tg-settings-cyan: #06b6d4;
                --tg-settings-soft: var(--color-brand-50, #eff6ff);
                --tg-settings-soft-strong: var(--color-brand-100, #dbeafe);
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
                border-color: rgba(147, 197, 253, 0.78) !important;
                background:
                    radial-gradient(circle at 82% 8%, rgba(56, 189, 248, 0.18), transparent 26%),
                    radial-gradient(circle at 12% 92%, rgba(37, 99, 235, 0.12), transparent 30%),
                    linear-gradient(135deg, #ffffff 0%, #f8fbff 48%, var(--tg-settings-soft) 100%) !important;
                color: var(--tg-settings-ink);
                box-shadow: 0 24px 58px rgba(37, 99, 235, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.96) !important;
                backdrop-filter: none !important;
            }

            .truthguard-settings-hero::before {
                content: '';
                position: absolute;
                inset: 0;
                z-index: -1;
                background-image:
                    linear-gradient(rgba(37, 99, 235, 0.055) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(37, 99, 235, 0.055) 1px, transparent 1px);
                background-size: 28px 28px;
                mask-image: linear-gradient(90deg, rgb(255, 255, 255), transparent 92%);
                -webkit-mask-image: linear-gradient(90deg, rgb(255, 255, 255), transparent 92%);
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
                border: 1px solid rgba(147, 197, 253, 0.42);
                border-radius: 9999px;
                box-shadow: 0 0 0 2.4rem rgba(219, 234, 254, 0.28), 0 0 0 5rem rgba(239, 246, 255, 0.55);
                pointer-events: none;
            }

            .truthguard-settings-eyebrow {
                display: inline-flex;
                align-items: center;
                gap: 0.55rem;
                border: 1px solid rgba(147, 197, 253, 0.82);
                border-radius: 9999px;
                background: rgba(239, 246, 255, 0.86);
                padding: 0.42rem 0.75rem;
                color: var(--tg-settings-blue-600);
                font-size: 0.69rem;
                font-weight: 800;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.82);
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
                border: 1px solid rgba(147, 197, 253, 0.78);
                border-radius: 1.65rem;
                background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(219, 234, 254, 0.7));
                box-shadow: 0 18px 42px rgba(37, 99, 235, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.94);
            }

            .truthguard-settings-avatar-shell img {
                width: 4.55rem !important;
                height: 4.55rem !important;
                border-radius: 1.25rem !important;
                box-shadow: none !important;
                --tw-ring-shadow: 0 0 0 3px rgba(255, 255, 255, 0.92) !important;
            }

            .truthguard-settings-avatar-shell .truthguard-settings-presence {
                position: absolute;
                right: -0.15rem;
                bottom: -0.15rem;
                width: 1.2rem;
                height: 1.2rem;
                border: 4px solid #ffffff;
                border-radius: 9999px;
                background: #34d399;
                box-shadow: 0 0 16px rgba(52, 211, 153, 0.6);
            }

            .truthguard-settings-hero h1 {
                color: var(--tg-settings-ink) !important;
                font-size: clamp(2rem, 3vw, 3rem) !important;
                letter-spacing: 0 !important;
                line-height: 1.02;
            }

            .truthguard-settings-hero-copy {
                color: #64748b !important;
            }

            .truthguard-settings-status-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                border: 1px solid rgba(191, 219, 254, 0.82) !important;
                border-radius: 9999px;
                background: rgba(255, 255, 255, 0.88) !important;
                padding: 0.42rem 0.72rem;
                color: #475569 !important;
                font-size: 0.7rem;
                font-weight: 750;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92);
            }

            .truthguard-settings-status-chip.is-success {
                border-color: rgba(52, 211, 153, 0.34) !important;
                background: rgba(236, 253, 245, 0.92) !important;
                color: #047857 !important;
            }

            .truthguard-settings-hero-summary {
                border-color: rgba(191, 219, 254, 0.86) !important;
                background: linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.84)) !important;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.96), 0 22px 50px rgba(37, 99, 235, 0.1) !important;
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
                background: conic-gradient(var(--tg-settings-blue) calc(var(--completion) * 1%), rgba(191, 219, 254, 0.8) 0);
                box-shadow: 0 0 32px rgba(34, 211, 238, 0.12);
            }

            .truthguard-settings-readiness-ring > span {
                display: grid;
                width: 4.25rem;
                height: 4.25rem;
                place-items: center;
                border-radius: inherit;
                background: #ffffff;
                color: var(--tg-settings-blue-600);
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
                border-color: rgba(191, 219, 254, 0.82);
                background: #e0f2fe !important;
                color: #64748b !important;
                box-shadow: none !important;
            }

            .truthguard-settings-metrics > div {
                position: relative;
                overflow: hidden;
                border-color: rgba(191, 219, 254, 0.74) !important;
                background: rgba(255, 255, 255, 0.82) !important;
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92) !important;
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
                color: #64748b !important;
            }

            .truthguard-settings-metrics p:last-child {
                color: var(--tg-settings-ink) !important;
            }

            .truthguard-settings-rail,
            .truthguard-settings-panel {
                border-color: rgba(203, 213, 225, 0.72) !important;
                background: rgba(255, 255, 255, 0.94) !important;
                box-shadow: 0 24px 60px rgba(37, 99, 235, 0.075), inset 0 1px 0 #fff !important;
                backdrop-filter: blur(22px) !important;
            }

            .truthguard-settings-rail-header {
                background: linear-gradient(180deg, #f8fbff, rgba(255, 255, 255, 0.92));
            }

            .truthguard-settings-rail-header > div:first-of-type,
            .truthguard-settings-search {
                border-color: rgba(191, 219, 254, 0.72) !important;
                background: #fff !important;
                box-shadow: 0 8px 20px rgba(37, 99, 235, 0.055) !important;
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
                box-shadow: 0 16px 38px rgba(37, 99, 235, 0.055) !important;
            }

            .truthguard-settings-panel input:not([type="hidden"]),
            .truthguard-settings-panel textarea,
            .truthguard-settings-panel .focus-within\:border-blue-400 {
                border-radius: 0.9rem !important;
            }

            body.truthguard-settings-modal-open,
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
                box-shadow: 0 22px 58px rgba(37, 99, 235, 0.065), inset 0 1px 0 #fff;
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
                box-shadow: 0 12px 30px rgba(37, 99, 235, 0.045), inset 0 1px 0 #fff;
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
                z-index: 2147483400;
                display: grid;
                place-items: center;
                width: 100%;
                height: 100vh;
                height: 100dvh;
                overflow: hidden;
                padding: max(1rem, env(safe-area-inset-top, 0px)) max(1rem, env(safe-area-inset-right, 0px))
                    max(1rem, env(safe-area-inset-bottom, 0px)) max(1rem, env(safe-area-inset-left, 0px));
            }

            .truthguard-settings-modal-backdrop {
                position: absolute;
                inset: 0;
                border: 0;
                background: rgba(15, 23, 42, 0.38);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }

            .truthguard-settings-modal-dialog {
                position: relative;
                display: flex;
                width: min(70rem, calc(100vw - 2rem));
                height: min(50rem, calc(100dvh - 2rem));
                max-height: 100%;
                min-width: 0;
                flex-direction: column;
                overflow: hidden;
                border: 1px solid rgba(147, 197, 253, 0.9);
                border-radius: 1.5rem;
                background:
                    linear-gradient(180deg, #ffffff 0%, #f8fbff 58%, var(--tg-settings-soft) 100%);
                color: var(--tg-settings-ink);
                box-shadow: 0 34px 88px rgba(37, 99, 235, 0.18), inset 0 1px 0 #ffffff;
                animation: truthguard-settings-dialog-enter 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
            }

            @keyframes truthguard-settings-dialog-enter {
                from { opacity: 0; transform: translateY(12px); }
                to { opacity: 1; transform: translateY(0); }
            }

            @media (prefers-reduced-motion: reduce) {
                .truthguard-settings-modal-dialog {
                    animation: none;
                }

                .truthguard-settings-modal,
                .truthguard-settings-modal * {
                    transition-duration: 0ms !important;
                    scroll-behavior: auto !important;
                }
            }

            .truthguard-settings-modal-header {
                background: linear-gradient(120deg, #ffffff, #f0f7ff);
                border-bottom: 1px solid rgba(191, 219, 254, 0.84);
                color: var(--tg-settings-ink);
            }

            .truthguard-settings-modal-mark,
            .truthguard-settings-modal-close,
            .truthguard-settings-modal-tab {
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92);
            }

            .truthguard-settings-modal-mark {
                border-color: rgba(147, 197, 253, 0.78) !important;
                background: rgba(239, 246, 255, 0.88) !important;
                color: var(--tg-settings-blue-600) !important;
            }

            .truthguard-settings-modal-close {
                border-color: rgba(191, 219, 254, 0.86) !important;
                background: rgba(255, 255, 255, 0.9) !important;
                color: #64748b !important;
            }

            .truthguard-settings-modal-close:hover {
                background: var(--tg-settings-soft) !important;
                color: var(--tg-settings-blue-600) !important;
            }

            .truthguard-settings-modal-kicker {
                color: var(--tg-settings-blue-600) !important;
            }

            .truthguard-settings-modal-title {
                color: var(--tg-settings-ink) !important;
                white-space: normal;
                overflow-wrap: anywhere;
                line-height: 1.3;
                letter-spacing: 0;
            }

            .truthguard-settings-modal-subtitle {
                color: #64748b !important;
            }

            .truthguard-settings-modal-tab {
                min-height: 2.75rem;
                scroll-snap-align: start;
                border-color: rgba(191, 219, 254, 0.82) !important;
                background: rgba(255, 255, 255, 0.82) !important;
                color: #475569 !important;
            }

            .truthguard-settings-modal-tab:hover {
                background: var(--tg-settings-soft) !important;
                color: var(--tg-settings-blue-600) !important;
            }

            .truthguard-settings-modal-tab.is-active {
                border-color: rgba(96, 165, 250, 0.82) !important;
                background: linear-gradient(135deg, var(--tg-settings-soft), #ffffff) !important;
                color: var(--tg-settings-blue-600) !important;
                box-shadow: 0 12px 24px rgba(37, 99, 235, 0.12), inset 0 1px 0 #ffffff;
            }

            .truthguard-settings-modal-nav {
                scrollbar-width: none;
            }

            .truthguard-settings-modal-nav::-webkit-scrollbar {
                display: none;
            }

            .truthguard-settings-modal-body {
                min-height: 0;
                flex: 1;
                background: #f6f8fc;
                scrollbar-gutter: stable;
                overflow-x: hidden;
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            .truthguard-settings-modal :is(input:not([type="checkbox"]):not([type="radio"]), textarea, select) {
                min-width: 0;
                max-width: 100%;
                font-size: 1rem;
            }

            .truthguard-settings-modal :is(button, a):focus-visible {
                outline: 2px solid var(--tg-settings-blue);
                outline-offset: 2px;
            }

            .truthguard-settings-modal-close {
                width: 2.75rem;
                height: 2.75rem;
                border-radius: 0.75rem;
            }

            .truthguard-settings-modal-body :is(section, form, fieldset, div) {
                min-width: 0;
            }

            .truthguard-settings-modal-body .truthguard-settings-panel section form {
                border-color: #e2e8f0;
                box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
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

                .truthguard-settings-eyebrow > span:nth-child(n+3) {
                    display: none;
                }

                .truthguard-settings-eyebrow > span:nth-child(2) {
                    white-space: nowrap;
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

                body:not(.truthguard-admin-app) .truthguard-settings-launch-card {
                    display: grid;
                    grid-template-columns: 2.75rem minmax(0, 1fr);
                    column-gap: 0.85rem;
                    min-height: 0;
                    text-align: left;
                    border-radius: 0.875rem !important;
                    box-shadow: 0 4px 16px rgba(30, 64, 175, 0.045);
                    animation: truthguard-mobile-page-enter 360ms ease both;
                }

                .truthguard-settings-launch-card > span:first-child {
                    grid-column: 1;
                    grid-row: 1 / 4;
                }

                .truthguard-settings-launch-card > span:first-child > span:last-child {
                    display: none;
                }

                .truthguard-settings-launch-card > span:not(:first-child) {
                    grid-column: 2;
                    min-width: 0;
                    margin-top: 0;
                }

                .truthguard-settings-launch-card > span:nth-child(2) {
                    font-size: 0.875rem;
                    line-height: 1.4;
                }

                .truthguard-settings-launch-card > span:last-child {
                    padding-top: 0.5rem;
                }

                .truthguard-settings-launch-card > span:last-child > span:last-child {
                    color: #64748b;
                }

                .truthguard-settings-overview > div:first-child > div > p:last-child {
                    display: none;
                }

                .truthguard-settings-overview h2 {
                    font-size: 1rem !important;
                    letter-spacing: 0;
                }

                .truthguard-settings-modal-dialog {
                    padding-bottom: env(safe-area-inset-bottom, 0px);
                }

                .truthguard-settings-modal {
                    align-items: end;
                    padding: max(0.5rem, env(safe-area-inset-top, 0px)) env(safe-area-inset-right, 0px) 0 env(safe-area-inset-left, 0px);
                }

                .truthguard-settings-modal-dialog {
                    width: 100%;
                    height: 100%;
                    max-height: 100%;
                    border-right: 0;
                    border-bottom: 0;
                    border-left: 0;
                    border-radius: 1.25rem 1.25rem 0 0;
                }

                .truthguard-settings-modal-title {
                    font-size: 1.0625rem;
                }

                .truthguard-settings-modal-mark,
                .truthguard-settings-modal-kicker {
                    display: none;
                }

                .truthguard-settings-modal-body {
                    scrollbar-gutter: auto;
                    padding-bottom: 0.75rem;
                }

                .truthguard-settings-modal-header {
                    padding: 0.875rem 1rem 0.5rem;
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
                            <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-blue-600/70">Profile health</p>
                            <p class="mt-1 text-base font-black text-slate-700">Workspace ready</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Complete your identity for stronger account recovery.</p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3 border-t border-blue-100 pt-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-blue-500">Quick setup</p>
                            <p class="mt-1 truncate text-sm font-bold text-slate-700">Review your identity profile</p>
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
                    <h2 class="mt-1 text-xl font-black tracking-tight text-slate-800 sm:text-2xl">Choose what you want to manage</h2>
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

                        <span class="mt-4 block text-base font-black text-slate-800">{{ $section['label'] }}</span>
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

        <template x-teleport="body">
            <div
                x-show="settingsModalOpen"
                x-cloak
                x-transition.opacity.duration.180ms
                class="truthguard-settings-modal"
                style="display: none; {{ $activeThemeVars }}"
            >
                <button type="button" tabindex="-1" class="truthguard-settings-modal-backdrop" @click="closeSettingsModal()" aria-label="Close settings window"></button>

                <section
                    x-ref="settingsDialog"
                    tabindex="-1"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="truthguard-settings-modal-title"
                    class="truthguard-settings-modal-dialog"
                    @keydown.tab="trapSettingsFocus($event)"
                >
                    <header class="truthguard-settings-modal-header shrink-0 px-4 pb-4 pt-4 sm:px-5 sm:pt-5">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="truthguard-settings-modal-mark inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m9.25 12.2 1.85 1.85 3.9-4.1"></path>
                                    </svg>
                                </span>
                                <div class="min-w-0">
                                    <p class="truthguard-settings-modal-kicker text-[10px] font-black uppercase tracking-[0.18em]">Settings workspace</p>
                                    <h2 id="truthguard-settings-modal-title" class="truthguard-settings-modal-title mt-1 truncate text-xl font-black" x-text="activeSectionLabel()">Personal Information</h2>
                                    <p class="truthguard-settings-modal-subtitle mt-1 truncate text-xs" x-text="activeSectionDescription()">Name, email, identity</p>
                                </div>
                            </div>

                            <button
                                type="button"
                                @click="closeSettingsModal()"
                                class="truthguard-settings-modal-close inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border transition focus:outline-none focus:ring-4 focus:ring-sky-400/20"
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
                                    class="truthguard-settings-modal-tab inline-flex shrink-0 items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold transition focus:outline-none focus:ring-4 focus:ring-sky-400/20"
                                    :class="activeSection === '{{ $section['id'] }}' ? 'is-active' : ''"
                                    :aria-pressed="activeSection === '{{ $section['id'] }}'"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $section['icon'] }}"></path>
                                    </svg>
                                    {{ $section['label'] }}
                                </button>
                            @endforeach
                        </nav>
                    </header>

                    <div class="truthguard-settings-modal-body" x-ref="settingsBody">
                        <div class="truthguard-settings-panel">
                            <livewire:profile.update-profile-information-form />
                            <livewire:profile.update-password-form />
                            <livewire:profile.delete-user-form />
                        </div>
                    </div>
                </section>
            </div>
        </template>
    </div>
@endsection
