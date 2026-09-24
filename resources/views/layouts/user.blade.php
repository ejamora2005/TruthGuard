<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $faviconPath = ltrim((string) config('app.truthguard_favicon', 'favicon.ico'), '/');
            $faviconFile = public_path($faviconPath);
            $faviconHref = is_file($faviconFile)
                ? asset($faviconPath).'?v='.filemtime($faviconFile)
                : asset('favicon.ico');
            $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
            $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : '';

            $user = auth()->user();
            $displayName = filled($user?->name) ? (string) $user->name : 'TruthGuard User';
            $displayEmail = filled($user?->email) ? (string) $user->email : 'sample@truthguard.ai';
            $displayInitials = $user?->profile_initials ?: 'TG';
            $profilePhotoUrl = $user?->display_profile_avatar_url ?? asset('images/avatars/default-ocean.svg');
            $usesSampleProfile = (bool) ($user?->uses_starter_profile_avatar ?? true);
            $showOnboardingTour = (bool) ($user?->needsCurrentOnboarding() ?? false);
            $themeKey = in_array((string) $user?->theme_preference, ['ocean', 'forest', 'sunset'], true)
                ? (string) $user?->theme_preference
                : 'ocean';
            $themePresets = [
                'ocean' => [
                    'vars' => '--color-brand-50:#eff6ff;--color-brand-100:#dbeafe;--color-brand-300:#93c5fd;--color-brand-400:#60a5fa;--color-brand-500:#2563eb;--color-brand-600:#1d4ed8;--color-brand-800:#1e40af;--color-brand-950:#172554;--tg-body-bg:#f8fafc;--tg-shell-surface:rgba(255,255,255,0.94);--tg-shell-header:rgba(255,255,255,0.88);--tg-shell-border:#e2e8f0;',
                    'avatar' => 'bg-gradient-to-br from-sky-500 to-blue-600 ring-sky-100',
                    'avatar_plain' => 'bg-gradient-to-br from-sky-500 to-blue-600',
                    'badge' => 'bg-sky-50 text-sky-600',
                ],
                'forest' => [
                    'vars' => '--color-brand-50:#ecfdf5;--color-brand-100:#d1fae5;--color-brand-300:#6ee7b7;--color-brand-400:#34d399;--color-brand-500:#10b981;--color-brand-600:#059669;--color-brand-800:#065f46;--color-brand-950:#022c22;--tg-body-bg:#ecfdf5;--tg-shell-surface:rgba(255,255,255,0.94);--tg-shell-header:rgba(255,255,255,0.9);--tg-shell-border:#d1fae5;',
                    'avatar' => 'bg-gradient-to-br from-emerald-500 to-teal-600 ring-emerald-100',
                    'avatar_plain' => 'bg-gradient-to-br from-emerald-500 to-teal-600',
                    'badge' => 'bg-emerald-50 text-emerald-600',
                ],
                'sunset' => [
                    'vars' => '--color-brand-50:#fff7ed;--color-brand-100:#ffedd5;--color-brand-300:#fdba74;--color-brand-400:#fb923c;--color-brand-500:#f97316;--color-brand-600:#ea580c;--color-brand-800:#9a3412;--color-brand-950:#431407;--tg-body-bg:#fff7ed;--tg-shell-surface:rgba(255,255,255,0.94);--tg-shell-header:rgba(255,255,255,0.9);--tg-shell-border:#fed7aa;',
                    'avatar' => 'bg-gradient-to-br from-amber-500 to-orange-600 ring-amber-100',
                    'avatar_plain' => 'bg-gradient-to-br from-amber-500 to-orange-600',
                    'badge' => 'bg-amber-50 text-amber-700',
                ],
            ];
            $themeStyles = collect($themePresets)->mapWithKeys(fn (array $preset, string $key) => [$key => $preset['vars']])->all();
            $themeAvatarClasses = collect($themePresets)->mapWithKeys(fn (array $preset, string $key) => [$key => $preset['avatar']])->all();
            $themeAvatarPlainClasses = collect($themePresets)->mapWithKeys(fn (array $preset, string $key) => [$key => $preset['avatar_plain']])->all();
            $themeBadgeClasses = collect($themePresets)->mapWithKeys(fn (array $preset, string $key) => [$key => $preset['badge']])->all();
            $hideUserFooter = request()->routeIs('detections.create');
            $hideMobileNav = request()->routeIs('detections.*');
            $mobileNavActiveIndex = match (true) {
                request()->routeIs('dashboard') || request()->routeIs('dashboard.*') => 0,
                request()->routeIs('history') => 1,
                request()->routeIs('detections.*') => 2,
                request()->routeIs('notifications.*') => 3,
                request()->routeIs('profile') || request()->routeIs('profile.*') => 4,
                default => 2,
            };
            $notificationQuickItems = [];
            $unreadNotificationCount = 0;
            $latestUnreadNotificationId = null;

            if ($user && \Illuminate\Support\Facades\Schema::hasTable('notifications')) {
                $hasNotificationArchive = \Illuminate\Support\Facades\Schema::hasColumn('notifications', 'archived_at');
                $formatNavNotification = static function ($notification): array {
                    $data = $notification->data ?: [];
                    $message = (string) data_get($data, 'message', 'Open TruthGuard to review the latest update.');

                    if (preg_match('/TG-\d+/i', $message)) {
                        $confidence = (int) data_get($data, 'confidence', 0);
                        $message = ((string) data_get($data, 'category') === 'fact-check')
                            ? ($confidence > 0
                                ? "TruthGuard completed your latest fact check with {$confidence}% confidence."
                                : 'TruthGuard completed your latest fact check.')
                            : trim((string) preg_replace('/\s*TG-\d+\s*/i', ' ', $message));
                    }

                    return [
                        'id' => (string) $notification->id,
                        'title' => (string) data_get($data, 'title', 'TruthGuard notification'),
                        'message' => $message,
                        'category' => (string) data_get($data, 'category', 'system'),
                        'actionUrl' => (string) data_get($data, 'action_url', route('notifications.index', absolute: false)),
                        'actionLabel' => (string) data_get($data, 'action_label', 'Open'),
                        'createdAt' => $notification->created_at?->diffForHumans() ?? '',
                        'unread' => $notification->read_at === null,
                    ];
                };

                $unreadNotificationQuery = $user->unreadNotifications();
                $notificationQuickQuery = $user->notifications();

                if ($hasNotificationArchive) {
                    $unreadNotificationQuery->whereNull('archived_at');
                    $notificationQuickQuery->whereNull('archived_at');
                }

                $unreadNotificationCount = (clone $unreadNotificationQuery)->count();
                $latestUnreadNotificationId = (clone $unreadNotificationQuery)->latest()->value('id');
                $notificationQuickItems = $notificationQuickQuery
                    ->latest()
                    ->take(5)
                    ->get()
                    ->map($formatNavNotification)
                    ->values()
                    ->all();
            }

            $formatTruthGuardPageTitle = static function (string $title, string $fallback): string {
                $label = trim((string) strip_tags($title));
                $label = (string) preg_replace('/\s+/', ' ', $label);
                $label = trim((string) preg_replace('/^truthguard(?:\s*\|\s*|\s+)/i', '', $label));

                if ($label === '') {
                    $label = $fallback;
                }

                return 'TRUTHGUARD | '.$label;
            };
            $rawPageTitle = trim($__env->yieldContent('page_title', ''));
            $rawBrowserTitle = trim($__env->yieldContent('title', ''));
            $truthGuardPageTitle = $formatTruthGuardPageTitle($rawPageTitle !== '' ? $rawPageTitle : $rawBrowserTitle, 'Workspace');
            $mobileTopbarTitle = request()->routeIs('dashboard') ? 'TruthGuard' : trim((string) strip_tags($rawPageTitle !== '' ? $rawPageTitle : $rawBrowserTitle));

            if ($mobileTopbarTitle === '') {
                $mobileTopbarTitle = 'Workspace';
            }

            $pageBackUrl = trim((string) $__env->yieldContent('page_back_url', ''));
        @endphp

        <title>{{ $truthGuardPageTitle }}</title>
        <link rel="icon" href="{{ $faviconHref }}">
        <link rel="shortcut icon" href="{{ $faviconHref }}">
        @include('layouts.partials.pwa', [
            'pwaThemeColor' => match ($themeKey ?? 'ocean') {
                'forest' => '#059669',
                'sunset' => '#ea580c',
                default => '#2563eb',
            },
        ])
        <link rel="stylesheet" href="{{ asset('vendor/tailadmin/admin.css') }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <style>
            [x-cloak] { display: none !important; }

            body {
                font-family: 'Poppins', sans-serif;
            }

            @keyframes truthguard-icon-float {
                0%,
                100% {
                    transform: translateY(0) scale(1);
                }
                50% {
                    transform: translateY(-1.5px) scale(1.03);
                }
            }

            .truthguard-animated-icons svg {
                transition: transform 220ms ease, filter 220ms ease, opacity 220ms ease;
                animation: truthguard-icon-float 3.6s ease-in-out infinite;
                transform-origin: center;
            }

            .truthguard-sidebar {
                isolation: isolate;
                background:
                    radial-gradient(circle at 16% 8%, rgba(37, 99, 235, 0.12), transparent 28%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.93)),
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.035) 0 1px, transparent 1px 11px);
            }

            .truthguard-sidebar::before {
                content: '';
                position: absolute;
                inset: 0;
                pointer-events: none;
                background:
                    linear-gradient(90deg, rgba(37, 99, 235, 0.06), transparent 24%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.72), transparent 34%);
                opacity: 0.86;
            }

            .truthguard-sidebar > * {
                position: relative;
                z-index: 1;
            }

            .truthguard-animated-icons .menu-item > span[class*="menu-item-icon-"] {
                position: relative;
                display: inline-flex;
                height: 1.65rem;
                width: 1.65rem;
                flex-shrink: 0;
                align-items: center;
                justify-content: center;
                border-radius: 9999px;
                color: currentColor;
                transition: color 180ms ease, filter 180ms ease, transform 180ms ease;
            }

            .truthguard-animated-icons .menu-item > span[class*="menu-item-icon-"] svg {
                position: relative;
                z-index: 1;
            }

            .truthguard-animated-icons a:hover svg,
            .truthguard-animated-icons button:hover svg {
                transform: translateY(-1px) scale(1.07);
                filter: drop-shadow(0 6px 14px rgba(37, 99, 235, 0.18));
            }

            @keyframes truthguard-icon-wiggle {
                0%,
                100% {
                    transform: translateY(0) rotate(0deg) scale(1);
                }
                30% {
                    transform: translateY(-1px) rotate(-6deg) scale(1.05);
                }
                60% {
                    transform: translateY(0) rotate(6deg) scale(1.04);
                }
            }

            .truthguard-animated-icons .menu-item:not(.menu-item-inactive) svg {
                animation: truthguard-icon-wiggle 1.4s ease-in-out infinite;
            }

            .truthguard-toggle-active svg {
                animation: truthguard-icon-wiggle 1.1s ease-in-out infinite;
            }

            .truthguard-animated-icons .menu-item:not(.menu-item-inactive) {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                border-radius: 0.9rem;
                background:
                    linear-gradient(135deg, rgba(239, 246, 255, 0.96), rgba(219, 234, 254, 0.62)),
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.045) 0 1px, transparent 1px 10px);
                color: #2563eb;
                box-shadow:
                    0 12px 28px rgba(37, 99, 235, 0.095),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
            }

            .truthguard-animated-icons .menu-item:not(.menu-item-inactive)::before {
                content: '';
                position: absolute;
                left: 0;
                top: 50%;
                height: 58%;
                width: 0.22rem;
                transform: translateY(-50%);
                border-radius: 9999px;
                background: linear-gradient(180deg, #38bdf8, #2563eb);
                box-shadow: 0 0 16px rgba(37, 99, 235, 0.34);
            }

            .truthguard-animated-icons .menu-item:not(.menu-item-inactive) > span[class*="menu-item-icon-"] {
                filter: drop-shadow(0 8px 15px rgba(37, 99, 235, 0.24));
            }

            .truthguard-animated-icons .menu-item.menu-item-inactive {
                border-radius: 0.9rem;
                color: #0f172a;
            }

            .truthguard-animated-icons .menu-item.menu-item-inactive:hover {
                background: rgba(248, 250, 252, 0.72);
                color: #2563eb;
            }

            .truthguard-top-icon {
                position: relative;
                isolation: isolate;
                overflow: visible;
                display: inline-flex;
                height: 2.35rem;
                width: 2.35rem;
                align-items: center;
                justify-content: center;
                border-radius: 9999px;
                border: 1px solid rgba(226, 232, 240, 0.72);
                background:
                    linear-gradient(145deg, rgba(255, 255, 255, 0.72), rgba(239, 246, 255, 0.42));
                color: #334155;
                box-shadow:
                    0 10px 22px rgba(15, 23, 42, 0.045),
                    inset 0 1px 0 rgba(255, 255, 255, 0.82),
                    inset 0 -1px 0 rgba(148, 163, 184, 0.08);
                backdrop-filter: blur(14px) saturate(1.1);
                transition: transform 180ms ease, border-color 180ms ease, background 180ms ease, box-shadow 180ms ease, color 180ms ease;
            }

            .truthguard-top-icon::after {
                content: '';
                position: absolute;
                inset: 0.35rem;
                z-index: 0;
                border-radius: 9999px;
                background:
                    linear-gradient(rgba(37, 99, 235, 0.24), rgba(37, 99, 235, 0.24)) left top / 0.45rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.24), rgba(37, 99, 235, 0.24)) left top / 1px 0.45rem no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.18), rgba(37, 99, 235, 0.18)) right bottom / 0.45rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.18), rgba(37, 99, 235, 0.18)) right bottom / 1px 0.45rem no-repeat;
                opacity: 0;
                pointer-events: none;
                transition: opacity 180ms ease;
            }

            .truthguard-top-icon svg {
                position: relative;
                z-index: 1;
            }

            .truthguard-notification-badge {
                isolation: isolate;
                overflow: visible;
                z-index: 3;
                border: 1px solid rgba(255, 255, 255, 0.86);
                background:
                    radial-gradient(circle at 34% 24%, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0.12) 18%, transparent 34%),
                    linear-gradient(135deg, #fb7185 0%, #f43f5e 44%, #dc2626 100%) !important;
                text-shadow: 0 1px 1px rgba(127, 29, 29, 0.24);
                box-shadow:
                    0 8px 16px rgba(244, 63, 94, 0.28),
                    0 0 0 2px rgba(255, 228, 230, 0.72),
                    inset 0 1px 0 rgba(255, 255, 255, 0.46),
                    inset 0 -1px 0 rgba(127, 29, 29, 0.14);
            }

            .truthguard-top-icon:hover {
                transform: translateY(-1px);
                border-color: rgba(147, 197, 253, 0.88);
                background:
                    linear-gradient(145deg, rgba(255, 255, 255, 0.88), rgba(219, 234, 254, 0.58));
                color: #2563eb;
                box-shadow:
                    0 14px 28px rgba(37, 99, 235, 0.12),
                    0 0 0 3px rgba(219, 234, 254, 0.45),
                    inset 0 1px 0 rgba(255, 255, 255, 0.92);
            }

            .truthguard-top-icon:hover::after {
                opacity: 1;
            }

            .truthguard-utility-strip {
                border: 1px solid rgba(191, 219, 254, 0.88);
                background:
                    radial-gradient(circle at 10% 0%, rgba(255, 255, 255, 0.95), transparent 34%),
                    radial-gradient(circle at 88% 18%, rgba(125, 211, 252, 0.18), transparent 32%),
                    linear-gradient(135deg, rgba(255, 255, 255, 0.8), rgba(239, 246, 255, 0.52)),
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.025) 0 1px, transparent 1px 12px);
                box-shadow:
                    0 18px 42px rgba(37, 99, 235, 0.09),
                    0 1px 0 rgba(255, 255, 255, 0.9) inset,
                    0 -1px 0 rgba(148, 163, 184, 0.08) inset;
                backdrop-filter: blur(22px) saturate(1.16);
            }

            .truthguard-clock-pill {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(191, 219, 254, 0.86);
                background:
                    linear-gradient(135deg, rgba(255, 255, 255, 0.76), rgba(239, 246, 255, 0.58));
                box-shadow:
                    0 8px 18px rgba(37, 99, 235, 0.06),
                    inset 0 1px 0 rgba(255, 255, 255, 0.9);
            }

            .truthguard-clock-pill::after {
                content: '';
                position: absolute;
                inset: 0.42rem;
                border-radius: 9999px;
                background:
                    linear-gradient(rgba(37, 99, 235, 0.2), rgba(37, 99, 235, 0.2)) left top / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.2), rgba(37, 99, 235, 0.2)) left top / 1px 0.5rem no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.12)) right bottom / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0.12)) right bottom / 1px 0.5rem no-repeat;
                pointer-events: none;
            }

            .truthguard-clock-icon {
                background: rgba(239, 246, 255, 0.88);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.86);
            }

            .truthguard-country-flag {
                color: #2563eb;
            }

            .truthguard-country-flag svg {
                filter: drop-shadow(0 6px 12px rgba(15, 23, 42, 0.16));
            }

            .truthguard-country-flag:hover {
                border-color: rgba(96, 165, 250, 0.88);
                background: rgba(239, 246, 255, 0.82);
                box-shadow: 0 14px 30px rgba(37, 99, 235, 0.14);
            }

            .truthguard-topbar {
                background:
                    radial-gradient(circle at 76% 0%, rgba(219, 234, 254, 0.72), transparent 28%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.93), rgba(248, 250, 252, 0.88));
                box-shadow: 0 14px 34px rgba(15, 23, 42, 0.045);
            }

            .truthguard-topbar::before {
                content: '';
                position: absolute;
                inset: 0;
                pointer-events: none;
                background:
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.035) 0 1px, transparent 1px 12px),
                    repeating-linear-gradient(0deg, rgba(37, 99, 235, 0.028) 0 1px, transparent 1px 12px);
                opacity: 0.58;
            }

            .truthguard-topbar > div {
                position: relative;
                z-index: 1;
            }

            .truthguard-shell-toggle {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                display: inline-flex;
                height: 3.25rem;
                width: 3.25rem;
                align-items: center;
                justify-content: center;
                border-radius: 1.15rem;
                border: 1px solid rgba(226, 232, 240, 0.9);
                background:
                    linear-gradient(135deg, rgba(255, 255, 255, 0.94), rgba(239, 246, 255, 0.68)),
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.04) 0 1px, transparent 1px 8px);
                color: #64748b;
                box-shadow:
                    0 12px 28px rgba(15, 23, 42, 0.07),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
                transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease, color 180ms ease;
            }

            .truthguard-shell-toggle::after {
                content: '';
                position: absolute;
                inset: 0.48rem;
                border-radius: 0.72rem;
                background:
                    linear-gradient(rgba(37, 99, 235, 0.28), rgba(37, 99, 235, 0.28)) left top / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.28), rgba(37, 99, 235, 0.28)) left top / 1px 0.5rem no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.18), rgba(37, 99, 235, 0.18)) right bottom / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(37, 99, 235, 0.18), rgba(37, 99, 235, 0.18)) right bottom / 1px 0.5rem no-repeat;
                opacity: 0;
                pointer-events: none;
                transition: opacity 180ms ease;
            }

            .truthguard-shell-toggle:hover,
            .truthguard-toggle-active {
                transform: translateY(-1px);
                border-color: rgba(147, 197, 253, 0.95);
                color: #2563eb;
                box-shadow: 0 16px 34px rgba(37, 99, 235, 0.13);
            }

            .truthguard-shell-toggle:hover::after,
            .truthguard-toggle-active::after {
                opacity: 1;
            }

            .truthguard-profile-button {
                position: relative;
                isolation: isolate;
                overflow: visible;
                height: 3.12rem;
                width: 3.12rem;
                min-width: 3.12rem;
                flex: 0 0 3.12rem;
                aspect-ratio: 1 / 1;
                border-radius: 9999px;
                border: 1px solid rgba(147, 197, 253, 0.92);
                background:
                    radial-gradient(circle at 30% 12%, rgba(255, 255, 255, 0.98), transparent 42%),
                    linear-gradient(145deg, rgba(255, 255, 255, 0.86), rgba(219, 234, 254, 0.5));
                box-shadow:
                    0 16px 34px rgba(37, 99, 235, 0.12),
                    0 0 0 4px rgba(219, 234, 254, 0.54),
                    inset 0 1px 0 rgba(255, 255, 255, 0.95),
                    inset 0 -1px 0 rgba(37, 99, 235, 0.08);
                backdrop-filter: blur(18px) saturate(1.16);
                transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease, background 180ms ease;
            }

            .truthguard-profile-button::before {
                content: '';
                position: absolute;
                inset: -0.28rem;
                z-index: -1;
                border-radius: 9999px;
                background:
                    conic-gradient(from 160deg, rgba(56, 189, 248, 0.36), rgba(37, 99, 235, 0.1), rgba(99, 102, 241, 0.28), rgba(56, 189, 248, 0.36));
                filter: blur(11px);
                opacity: 0.35;
                transition: opacity 180ms ease;
            }

            .truthguard-profile-button::after {
                content: '';
                position: absolute;
                right: -0.02rem;
                bottom: 0.08rem;
                z-index: 4;
                height: 0.72rem;
                width: 0.72rem;
                border-radius: 9999px;
                border: 2px solid #fff;
                background: #10b981;
                box-shadow: 0 0 12px rgba(16, 185, 129, 0.5);
            }

            .truthguard-profile-button img,
            .truthguard-profile-button > span {
                position: relative;
                z-index: 1;
                display: inline-flex;
                flex: 0 0 2.25rem;
                aspect-ratio: 1 / 1;
                border-radius: 9999px !important;
                border: 1px solid rgba(255, 255, 255, 0.9);
                object-fit: cover;
                box-shadow:
                    0 10px 20px rgba(15, 23, 42, 0.16),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
            }

            .truthguard-topbar .truthguard-profile-button {
                padding: 3px;
                box-sizing: border-box;
            }

            .truthguard-topbar .truthguard-profile-button > :is(img, span) {
                width: 100% !important;
                height: 100% !important;
                min-width: 0 !important;
                flex: none !important;
                box-sizing: border-box;
                border-radius: 50% !important;
                clip-path: circle(50%);
                object-fit: cover;
            }

            .truthguard-profile-button:hover,
            .truthguard-profile-button-active {
                transform: translateY(-1px);
                border-color: rgba(96, 165, 250, 0.98);
                box-shadow:
                    0 18px 38px rgba(37, 99, 235, 0.18),
                    0 0 0 5px rgba(191, 219, 254, 0.58),
                    inset 0 1px 0 rgba(255, 255, 255, 0.96);
            }

            .truthguard-profile-button:hover::before,
            .truthguard-profile-button-active::before {
                opacity: 0.72;
            }

            .truthguard-profile-popover {
                overflow: visible;
                border-color: rgba(191, 219, 254, 0.9);
                background:
                    radial-gradient(circle at 88% 0%, rgba(191, 219, 254, 0.64), transparent 32%),
                    radial-gradient(circle at 8% 18%, rgba(125, 211, 252, 0.2), transparent 34%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.82));
                box-shadow:
                    0 30px 70px rgba(15, 23, 42, 0.16),
                    0 0 0 1px rgba(255, 255, 255, 0.52) inset,
                    inset 0 1px 0 rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(26px) saturate(1.18);
            }

            .truthguard-profile-popover::before {
                content: '';
                position: absolute;
                top: -0.72rem;
                right: 1.33rem;
                height: 0.9rem;
                width: 1.38rem;
                clip-path: polygon(50% 0, 0 100%, 100% 100%);
                background:
                    linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.9));
                filter:
                    drop-shadow(0 -1px 0 rgba(191, 219, 254, 0.78))
                    drop-shadow(0 -10px 18px rgba(37, 99, 235, 0.08));
            }

            .truthguard-profile-popover::after {
                content: '';
                position: absolute;
                inset: 0;
                border-radius: inherit;
                background:
                    linear-gradient(135deg, rgba(255, 255, 255, 0.72), transparent 38%),
                    repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.024) 0 1px, transparent 1px 10px),
                    repeating-linear-gradient(0deg, rgba(37, 99, 235, 0.018) 0 1px, transparent 1px 10px);
                pointer-events: none;
            }

            .truthguard-profile-popover > * {
                position: relative;
                z-index: 1;
            }

            .truthguard-profile-capsule {
                position: relative;
                overflow: hidden;
                background: linear-gradient(120deg, #eff6ff, #ffffff);
                border-color: #dbeafe;
                box-shadow:
                    0 18px 38px rgba(15, 23, 42, 0.16),
                    inset 0 1px 0 rgba(255, 255, 255, 0.2),
                    inset 0 -1px 0 rgba(15, 23, 42, 0.14);
                backdrop-filter: blur(18px);
            }

            .truthguard-profile-capsule::after {
                content: '';
                position: absolute;
                inset: auto -24% -54% 32%;
                height: 4rem;
                border-radius: 9999px;
                background: rgba(59, 130, 246, 0.28);
                filter: blur(20px);
                pointer-events: none;
            }

            .truthguard-profile-menu-avatar {
                box-shadow:
                    0 14px 26px rgba(15, 23, 42, 0.18),
                    0 0 0 1px rgba(255, 255, 255, 0.38);
            }

            .truthguard-profile-menu-status-dot {
                position: absolute;
                right: -0.06rem;
                bottom: 0.1rem;
                height: 0.8rem;
                width: 0.8rem;
                border-radius: 9999px;
                border: 2px solid rgba(255, 255, 255, 0.96);
                background: #10b981;
                box-shadow:
                    0 0 0 4px rgba(16, 185, 129, 0.12),
                    0 0 18px rgba(16, 185, 129, 0.55);
            }

            .truthguard-profile-action {
                position: relative;
                overflow: hidden;
                background:
                    radial-gradient(circle at 0% 0%, rgba(219, 234, 254, 0.34), transparent 36%),
                    linear-gradient(135deg, rgba(255, 255, 255, 0.92), rgba(248, 250, 252, 0.74));
                box-shadow:
                    0 12px 24px rgba(15, 23, 42, 0.055),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
                backdrop-filter: blur(14px);
            }

            .truthguard-profile-action::after {
                content: '';
                position: absolute;
                inset: 0;
                background: linear-gradient(90deg, transparent, rgba(37, 99, 235, 0.07), transparent);
                transform: translateX(-100%);
                transition: transform 240ms ease;
                pointer-events: none;
            }

            .truthguard-profile-action:hover::after {
                transform: translateX(100%);
            }

            .truthguard-profile-action-icon {
                display: inline-flex;
                height: 1.95rem;
                width: 1.95rem;
                align-items: center;
                justify-content: center;
                border-radius: 0.85rem;
                border: 1px solid rgba(191, 219, 254, 0.72);
                background:
                    linear-gradient(135deg, rgba(239, 246, 255, 0.98), rgba(219, 234, 254, 0.76));
                box-shadow:
                    0 8px 16px rgba(37, 99, 235, 0.1),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
            }

            .truthguard-profile-action-danger .truthguard-profile-action-icon {
                border-color: rgba(254, 205, 211, 0.76);
                background:
                    linear-gradient(135deg, rgba(255, 241, 242, 0.98), rgba(255, 228, 230, 0.78));
                box-shadow:
                    0 8px 16px rgba(244, 63, 94, 0.1),
                    inset 0 1px 0 rgba(255, 255, 255, 0.86);
            }

            .truthguard-premium-card {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(37, 99, 235, 0.15);
                background:
                    radial-gradient(circle at 12% 0%, rgba(96, 165, 250, 0.18), transparent 34%),
                    linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(239, 246, 255, 0.84));
                box-shadow: 0 18px 46px rgba(15, 23, 42, 0.08);
            }

            .truthguard-premium-card::after {
                content: '';
                position: absolute;
                inset: auto -20% -35% 20%;
                height: 5rem;
                border-radius: 9999px;
                background: rgba(37, 99, 235, 0.12);
                filter: blur(22px);
            }

            .truthguard-premium-crown {
                position: relative;
                isolation: isolate;
                overflow: hidden;
                background:
                    linear-gradient(135deg, rgba(255, 255, 255, 0.34), transparent 36%),
                    linear-gradient(135deg, #38bdf8 0%, #2563eb 48%, #7c3aed 100%);
                box-shadow:
                    0 14px 28px rgba(37, 99, 235, 0.28),
                    inset 0 1px 0 rgba(255, 255, 255, 0.5);
            }

            .truthguard-premium-crown::before {
                content: '';
                position: absolute;
                inset: -0.28rem;
                z-index: -1;
                border-radius: 1.2rem;
                background: linear-gradient(135deg, rgba(56, 189, 248, 0.34), rgba(124, 58, 237, 0.24));
                filter: blur(10px);
                opacity: 0.9;
            }

            .truthguard-premium-crown::after {
                content: '';
                position: absolute;
                inset: 0.36rem;
                border-radius: 0.68rem;
                background:
                    linear-gradient(rgba(254, 243, 199, 0.86), rgba(254, 243, 199, 0.86)) left top / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(254, 243, 199, 0.86), rgba(254, 243, 199, 0.86)) left top / 1px 0.5rem no-repeat,
                    linear-gradient(rgba(254, 243, 199, 0.72), rgba(254, 243, 199, 0.72)) right bottom / 0.5rem 1px no-repeat,
                    linear-gradient(rgba(254, 243, 199, 0.72), rgba(254, 243, 199, 0.72)) right bottom / 1px 0.5rem no-repeat;
            }

            .truthguard-premium-crown svg,
            .truthguard-premium-button-crown svg {
                filter: drop-shadow(0 4px 8px rgba(15, 23, 42, 0.18));
            }

            .truthguard-premium-button-crown {
                background: rgba(255, 255, 255, 0.16);
                box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.24);
            }

            @media (prefers-reduced-motion: reduce) {
                .truthguard-animated-icons svg {
                    animation: none;
                    transition: none;
                }
            }
        </style>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body
        @class([
            'truthguard-user-app truthguard-animated-icons overflow-x-hidden bg-[var(--tg-body-bg)] text-gray-900',
            'truthguard-fact-check-route' => $hideMobileNav,
            'truthguard-fact-check-create-route' => $hideUserFooter,
        ])
        x-data="{
            userSidebarOpen: false,
            sidebarExpanded: true,
            isDesktop: window.innerWidth >= 1024,
            logoutConfirmOpen: false,
            logoutSubmitting: false,
            themeKey: @js($themeKey),
            themeStyles: @js($themeStyles),
            themeAvatarClasses: @js($themeAvatarClasses),
            themeAvatarPlainClasses: @js($themeAvatarPlainClasses),
            themeBadgeClasses: @js($themeBadgeClasses),
            themeOrder: ['ocean', 'forest', 'sunset'],
            currentDay: '',
            currentTime: '',
            clockTimer: null,
            profileName: @js($displayName),
            profileEmail: @js($displayEmail),
            profileInitials: @js($displayInitials),
            profileAvatarUrl: @js($profilePhotoUrl),
            usesSampleProfile: @js($usesSampleProfile),
            init() {
                this.updateClock();
                this.clockTimer = window.setInterval(() => this.updateClock(), 1000);

                this.syncViewport = () => {
                    this.isDesktop = window.innerWidth >= 1024;

                    if (this.isDesktop) {
                        this.userSidebarOpen = false;
                    }
                };

                this.syncViewport();
                window.addEventListener('resize', this.syncViewport);

                window.addEventListener('truthguard-theme-updated', (event) => {
                    if (event.detail?.theme && this.themeStyles[event.detail.theme]) {
                        this.themeKey = event.detail.theme;
                    }
                });

                window.addEventListener('truthguard-profile-updated', (event) => {
                    const detail = event.detail || {};

                    if (detail.name) this.profileName = detail.name;
                    if (detail.email) this.profileEmail = detail.email;
                    if (detail.initials) this.profileInitials = detail.initials;

                    this.profileAvatarUrl = detail.avatarUrl || null;
                    this.usesSampleProfile = !!detail.usesSampleProfile;

                    if (detail.theme && this.themeStyles[detail.theme]) {
                        this.themeKey = detail.theme;
                    }
                });
            },
            updateClock() {
                const now = new Date();

                this.currentDay = new Intl.DateTimeFormat(undefined, {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric'
                }).format(now);

                this.currentTime = new Intl.DateTimeFormat(undefined, {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                }).format(now);
            },
            cycleTheme() {
                const currentIndex = this.themeOrder.indexOf(this.themeKey);
                this.themeKey = this.themeOrder[(currentIndex + 1) % this.themeOrder.length] || 'ocean';
            },
            toggleSidebar() {
                if (this.isDesktop) {
                    this.sidebarExpanded = !this.sidebarExpanded;
                } else {
                    this.userSidebarOpen = !this.userSidebarOpen;
                }
            },
            requestLogout() {
                this.logoutSubmitting = false;
                this.logoutConfirmOpen = true;
            },
            submitLogout() {
                if (this.logoutSubmitting) return;
                this.logoutSubmitting = true;
                this.$refs.logoutForm?.submit();
            }
        }"
        :style="themeStyles[themeKey] ?? themeStyles.ocean"
    >
        @include('layouts.partials.app-splash')

        <div class="truthguard-user-frame overflow-x-clip xl:flex">
            <div
                x-show="!isDesktop && userSidebarOpen"
                x-cloak
                x-transition.opacity
                class="fixed inset-0 z-[99998] bg-gray-900/50 lg:hidden"
                @click="userSidebarOpen = false"
            ></div>

            <aside
                x-cloak
                class="truthguard-sidebar fixed top-0 left-0 z-[99999] flex h-screen w-[min(18rem,calc(100vw-1rem))] max-w-[calc(100vw-1rem)] flex-col overflow-y-hidden rounded-r-[1.75rem] border-r border-[color:var(--tg-shell-border)] text-gray-900 shadow-[0_24px_60px_rgba(15,23,42,0.18)] backdrop-blur-xl transition-[transform,width] duration-300 ease-in-out lg:max-w-none lg:rounded-none lg:shadow-none"
                :class="[
                    sidebarExpanded ? 'lg:w-[290px]' : 'lg:w-[90px]'
                ]"
                :style="isDesktop || userSidebarOpen ? 'transform: translateX(0);' : 'transform: translateX(calc(-100% - 1rem));'"
            >
                <div class="flex items-center justify-between px-4 py-6 sm:px-5 sm:py-7" :class="sidebarExpanded ? 'lg:justify-start' : 'lg:justify-center'">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3 overflow-hidden">
                        @if ($logoUrl !== '')
                            <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-8 w-8 object-contain">
                        @else
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500 text-sm font-semibold text-white">TG</span>
                        @endif

                        <div x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak>
                            <p class="font-semibold text-gray-800 text-theme-xl">TruthGuard</p>
                        </div>
                    </a>

                    <button type="button" class="text-gray-500 lg:hidden" @click="userSidebarOpen = false" aria-label="Close sidebar">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="no-scrollbar flex flex-1 flex-col overflow-y-auto px-3 duration-300 ease-linear sm:px-4">
                    <nav class="flex flex-1 pb-4">
                        <div class="flex min-h-full w-full flex-col gap-4">
                            <div>
                                <h2
                                    class="mb-4 flex text-xs uppercase leading-[20px] text-gray-400"
                                    :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'"
                                >
                                    <template x-if="sidebarExpanded || window.innerWidth < 1024">
                                        <span>Menu</span>
                                    </template>
                                    <template x-if="!sidebarExpanded && window.innerWidth >= 1024">
                                        <span class="inline-flex items-center justify-center">
                                            <svg width="24" height="6" viewBox="0 0 24 6" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <circle cx="12" cy="3" r="2" fill="currentColor"/>
                                                <circle cx="20" cy="3" r="2" fill="currentColor"/>
                                                <circle cx="4" cy="3" r="2" fill="currentColor"/>
                                            </svg>
                                        </span>
                                    </template>
                                </h2>

                                <ul class="flex flex-col gap-2">
                                    <li>
                                        <a href="{{ route('dashboard') }}" data-tour="dashboard" class="menu-item {{ request()->routeIs('dashboard') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="{{ request()->routeIs('dashboard') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <rect x="4" y="4" width="6.5" height="6.5" rx="1.8"></rect>
                                                    <rect x="13.5" y="4" width="6.5" height="4.8" rx="1.8"></rect>
                                                    <rect x="13.5" y="11.2" width="6.5" height="8.8" rx="1.8"></rect>
                                                    <rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"></rect>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Dashboard</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('detections.create') }}" data-tour="fact-check" class="menu-item {{ request()->routeIs('detections.*') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="{{ request()->routeIs('detections.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 4H5.75A1.75 1.75 0 0 0 4 5.75V7"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 4h1.25A1.75 1.75 0 0 1 20 5.75V7"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 20H5.75A1.75 1.75 0 0 1 4 18.25V17"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h1.25A1.75 1.75 0 0 0 20 18.25V17"></path>
                                                    <circle cx="10.75" cy="10.75" r="3.2"></circle>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m13.25 13.25 2.75 2.75"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Fact Check</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('history') }}" data-tour="history" class="menu-item {{ request()->routeIs('history') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="{{ request()->routeIs('history') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5v5l3.2 1.9"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.2 12a8.2 8.2 0 1 1-2.4-5.8"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.2 5.2v4h-4"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">History</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('notifications.index') }}" data-tour="notifications" class="menu-item {{ request()->routeIs('notifications.*') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="{{ request()->routeIs('notifications.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }} relative">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.8 18.2a2.9 2.9 0 0 1-5.6 0"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.3 15.7H5.7l1.25-1.9a3 3 0 0 0 .5-1.66v-2.1a4.55 4.55 0 1 1 9.1 0v2.1c0 .59.17 1.16.5 1.66l1.25 1.9Z"></path>
                                                </svg>
                                                @if ($unreadNotificationCount > 0)
                                                    <span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-rose-500 ring-2 ring-white"></span>
                                                @endif
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Notifications</span>
                                        </a>
                                    </li>
                                </ul>
                            </div>

                        </div>
                    </nav>

                </div>

                <div class="shrink-0 border-t border-[color:var(--tg-shell-border)] px-3 pb-5 pt-4 sm:px-4 sm:pb-6">
                    <ul class="flex flex-col gap-2">
                        <li>
                            <a href="{{ route('profile') }}" data-tour="settings" class="menu-item {{ request()->routeIs('profile') || request()->routeIs('profile.*') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                <span class="{{ request()->routeIs('profile') || request()->routeIs('profile.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.25a3.75 3.75 0 1 0 0 7.5 3.75 3.75 0 0 0 0-7.5Z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.8 4.9 15.1 3h-6.2l-.7 1.9-2 .8-1.8-.85-3.1 5.35 1.55 1.15v1.3L1.3 13.8l3.1 5.35 1.8-.85 2 .8.7 1.9h6.2l.7-1.9 2-.8 1.8.85 3.1-5.35-1.55-1.15v-1.3l1.55-1.15-3.1-5.35-1.8.85-2-.8Z"></path>
                                    </svg>
                                </span>
                                <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Settings</span>
                            </a>
                        </li>
                        <li>
                            <a
                                href="{{ route('logout', absolute: false) }}"
                                class="menu-item menu-item-inactive group"
                                :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'"
                                @click.prevent="requestLogout()"
                            >
                                <span class="menu-item-icon-inactive">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 4.5H6.5A2.5 2.5 0 0 0 4 7v10a2.5 2.5 0 0 0 2.5 2.5h3.25"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.25 8.25 19 12l-3.75 3.75"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 12H9.5"></path>
                                    </svg>
                                </span>
                                <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Log Out</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </aside>

            <div
                class="truthguard-user-content-column flex min-w-0 flex-1 flex-col transition-all duration-300 ease-in-out"
                :class="sidebarExpanded ? 'lg:ml-[290px]' : 'lg:ml-[90px]'"
                :style="{ '--tg-user-sidebar-width': sidebarExpanded ? '290px' : '90px' }"
            >
                <header class="truthguard-topbar sticky top-0 z-[99997] w-full border-b border-[color:var(--tg-shell-border)] backdrop-blur-xl">
                    <div class="flex w-full items-center gap-2.5 px-3 py-2.5 sm:gap-4 sm:px-4 sm:py-3 lg:px-6 lg:py-4">
                        @if ($pageBackUrl !== '')
                            <a href="{{ $pageBackUrl }}" class="truthguard-shell-toggle shrink-0" aria-label="Go back" title="Go back">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                </svg>
                            </a>
                        @elseif ($hideMobileNav)
                            <button
                                type="button"
                                class="truthguard-shell-toggle shrink-0 lg:hidden"
                                aria-label="Go back to the previous page"
                                title="Go back"
                                onclick="window.history.length > 1 ? window.history.back() : window.location.assign(@js(route('dashboard')))"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                </svg>
                            </button>
                        @else
                            <a
                                href="{{ route('dashboard') }}"
                                @class([
                                    'truthguard-mobile-top-logo shrink-0 lg:hidden',
                                    '!hidden' => $hideMobileNav,
                                ])
                                aria-label="TruthGuard home"
                            >
                                @if ($logoUrl !== '')
                                    <img src="{{ $logoUrl }}" alt="TruthGuard logo">
                                @else
                                    <span>TG</span>
                                @endif
                            </a>

                            <button
                                @click="toggleSidebar()"
                                class="truthguard-shell-toggle hidden shrink-0 lg:inline-flex"
                                :class="(isDesktop ? sidebarExpanded : userSidebarOpen) ? 'truthguard-toggle-active' : ''"
                                aria-label="Toggle sidebar"
                            >
                                <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z" fill="currentColor"/>
                                </svg>
                            </button>
                        @endif

                        <div class="min-w-0 flex-1">
                            <h1 class="truthguard-mobile-page-title">{{ $mobileTopbarTitle }}</h1>
                        </div>

                        <div class="hidden items-center gap-3 xl:flex">
                            @hasSection('page_actions')
                                @yield('page_actions')
                            @endif
                        </div>

                        <div class="hidden items-center lg:flex">
                            <div class="truthguard-utility-strip flex items-center gap-1 rounded-full p-1">
                                <div class="truthguard-clock-pill hidden items-center gap-2 rounded-full px-2.5 py-1 xl:flex">
                                    <span class="truthguard-clock-icon relative z-10 inline-flex h-7 w-7 items-center justify-center rounded-full text-blue-600">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V4m8 3V4M4.5 10.25h15M6 6h12a1.8 1.8 0 0 1 1.8 1.8v10.4A1.8 1.8 0 0 1 18 20H6a1.8 1.8 0 0 1-1.8-1.8V7.8A1.8 1.8 0 0 1 6 6Z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 13v2.8l1.8 1.1" />
                                        </svg>
                                    </span>
                                    <span class="relative z-10 min-w-[6.2rem] leading-none">
                                        <span class="block text-[10px] font-black uppercase tracking-[0.14em] text-slate-400" x-text="currentDay">Today</span>
                                        <span class="mt-0.5 block text-[13px] font-black text-slate-900" x-text="currentTime">--:--</span>
                                    </span>
                                </div>

                                <button type="button" class="truthguard-top-icon" aria-label="Cycle theme" title="Cycle theme" @click="cycleTheme()">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m15.36-6.36-1.42 1.42M7.05 16.95l-1.41 1.41m12.72 0-1.42-1.41M7.05 7.05 5.64 5.64" />
                                        <circle cx="12" cy="12" r="4" />
                                    </svg>
                                </button>

                                @include('layouts.partials.notification-center')

                                <a href="{{ route('dashboard') }}" class="truthguard-top-icon truthguard-country-flag" aria-label="Open Philippines feed" title="Philippines feed">
                                    <svg class="h-6 w-6" viewBox="0 0 36 24" fill="none" aria-hidden="true">
                                        <clipPath id="truthguard-ph-flag-clip">
                                            <rect width="36" height="24" rx="6" />
                                        </clipPath>
                                        <g clip-path="url(#truthguard-ph-flag-clip)">
                                            <rect width="36" height="12" fill="#0038A8" />
                                            <rect y="12" width="36" height="12" fill="#CE1126" />
                                            <path d="M0 0 17 12 0 24V0Z" fill="#fff" />
                                            <circle cx="6.2" cy="12" r="2.1" fill="#FCD116" />
                                            <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(5.1 4.5)" />
                                            <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(5.1 23.8)" />
                                            <path d="M6.2 6.2 6.8 7.55l1.47.12-1.12.96.34 1.43-1.29-.75-1.29.75.34-1.43-1.12-.96 1.47-.12.6-1.35Z" fill="#FCD116" transform="scale(.56) translate(23.4 14.1)" />
                                        </g>
                                        <rect x=".5" y=".5" width="35" height="23" rx="5.5" stroke="rgba(15,23,42,.12)" />
                                    </svg>
                                </a>
                            </div>
                        </div>

                        <div class="relative shrink-0" x-data="{ profileOpen: false }">
                                <button
                                    type="button"
                                    data-tour="profile"
                                    class="truthguard-profile-button flex items-center justify-center rounded-full p-1.5 transition hover:border-blue-200"
                                    :class="profileOpen ? 'truthguard-profile-button-active' : ''"
                                    @click="profileOpen = !profileOpen"
                                    @keydown.escape.window="profileOpen = false"
                                    aria-label="Open profile menu"
                                    aria-haspopup="menu"
                                    :aria-expanded="profileOpen ? 'true' : 'false'"
                                >
                                    <template x-if="profileAvatarUrl">
                                        <img :src="profileAvatarUrl" alt="Profile image" class="h-9 w-9 rounded-full object-cover ring-2 ring-white">
                                    </template>
                                    <template x-if="!profileAvatarUrl">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-sm font-semibold text-white ring-2 ring-white" :class="themeAvatarClasses[themeKey] ?? themeAvatarClasses.ocean" x-text="profileInitials"></span>
                                    </template>
                                </button>

                                <div
                                    x-show="profileOpen"
                                    x-cloak
                                    x-transition:enter="ease-out duration-200"
                                    x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
                                    x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                    x-transition:leave="ease-in duration-150"
                                    x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                    x-transition:leave-end="opacity-0 -translate-y-1 scale-95"
                                    @click.outside="profileOpen = false"
                                    class="truthguard-profile-popover absolute right-0 z-50 mt-4 w-[min(18rem,calc(100vw-1rem))] rounded-[22px] border border-blue-100/80 p-1.5 backdrop-blur-xl sm:w-[17rem]"
                                    role="menu"
                                >
                                    <div class="truthguard-profile-capsule rounded-[19px] border border-white/10 px-3.5 py-3">
                                        <div class="relative z-10 flex items-center gap-3">
                                            <span class="truthguard-profile-menu-avatar relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl">
                                                <template x-if="profileAvatarUrl">
                                                    <img :src="profileAvatarUrl" alt="Profile image" class="h-11 w-11 rounded-2xl object-cover ring-2 ring-white/70">
                                                </template>
                                                <template x-if="!profileAvatarUrl">
                                                    <span class="flex h-11 w-11 items-center justify-center rounded-2xl text-sm font-semibold text-white ring-2 ring-white/70" :class="themeAvatarPlainClasses[themeKey] ?? themeAvatarPlainClasses.ocean" x-text="profileInitials"></span>
                                                </template>
                                                <span class="truthguard-profile-menu-status-dot" aria-label="Active account"></span>
                                            </span>
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-black text-slate-800" x-text="profileName"></p>
                                                <p class="mt-1 truncate text-xs font-semibold text-slate-500" x-text="profileEmail"></p>
                                            </div>
                                        </div>

                                        <div x-show="usesSampleProfile" x-cloak class="relative z-10 mt-3 flex flex-wrap items-center gap-2">
                                            <span
                                                class="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.12em] text-blue-700 ring-1 ring-blue-100"
                                            >
                                                Starter Profile
                                            </span>
                                        </div>
                                    </div>

                                    <div class="mt-1.5 grid gap-1.5">
                                        <a href="{{ route('profile') }}" class="truthguard-profile-action group flex items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:border-blue-100 hover:bg-blue-50/80 hover:text-blue-700">
                                            <span class="truthguard-profile-action-icon shrink-0 text-blue-600">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.25a3.75 3.75 0 1 0 0 7.5 3.75 3.75 0 0 0 0-7.5Z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.8 4.9 15.1 3h-6.2l-.7 1.9-2 .8-1.8-.85-3.1 5.35 1.55 1.15v1.3L1.3 13.8l3.1 5.35 1.8-.85 2 .8.7 1.9h6.2l.7-1.9 2-.8 1.8.85 3.1-5.35-1.55-1.15v-1.3l1.55-1.15-3.1-5.35-1.8.85-2-.8Z"></path>
                                                </svg>
                                            </span>
                                            <span class="relative z-10 flex-1">Account settings</span>
                                            <span class="relative z-10 text-slate-300 transition group-hover:text-blue-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                                                </svg>
                                            </span>
                                        </a>

                                        <button
                                            type="button"
                                            class="js-install-app truthguard-profile-action group flex w-full items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:border-blue-100 hover:bg-blue-50/80 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                            disabled
                                        >
                                            <span class="truthguard-profile-action-icon shrink-0 text-blue-600">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75v10.5"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 10.5 3.75 3.75 3.75-3.75"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 15.75v2.5A1.75 1.75 0 0 0 7 20h10a1.75 1.75 0 0 0 1.75-1.75v-2.5"></path>
                                                </svg>
                                            </span>
                                            <span class="relative z-10 flex-1 text-left" data-pwa-install-label>Install app</span>
                                            <span class="relative z-10 text-slate-300 transition group-hover:text-blue-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                                                </svg>
                                            </span>
                                        </button>

                                        <p class="hidden px-3 text-[10px] font-semibold leading-snug text-slate-500" data-pwa-install-help></p>

                                        <button
                                            type="button"
                                            class="truthguard-profile-action truthguard-profile-action-danger group flex w-full items-center gap-3 rounded-2xl border border-slate-100 px-3 py-2.5 text-sm font-bold text-slate-700 transition hover:border-rose-100 hover:bg-rose-50 hover:text-rose-600"
                                            @click="profileOpen = false; requestLogout()"
                                        >
                                            <span class="truthguard-profile-action-icon shrink-0 text-rose-600">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H9"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"></path>
                                                </svg>
                                            </span>
                                            <span class="relative z-10 flex-1 text-left">Sign out</span>
                                            <span class="relative z-10 text-slate-300 transition group-hover:text-rose-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6" />
                                                </svg>
                                            </span>
                                        </button>
                                    </div>
                                </div>
                        </div>
                    </div>
                </header>

                <main @class([
                    'truthguard-user-main mx-auto w-full max-w-[1600px] min-w-0 flex-1 px-3 pt-3 sm:px-4 sm:pt-4 md:px-5 md:pt-5',
                    $hideUserFooter ? 'pb-0 sm:pb-0 md:pb-0' : 'pb-3 sm:pb-4 md:pb-5',
                ])>
                    @yield('content')
                </main>

                @unless ($hideUserFooter)
                    <footer class="truthguard-user-footer mx-auto mt-auto w-full max-w-[1600px] px-3 sm:px-4 md:px-5">
                        <div class="truthguard-user-footer-inner">
                            <p class="truthguard-user-footer-copy">&copy; {{ now()->year }} TruthGuard. AI-assisted fact checking.</p>
                            <nav class="truthguard-user-footer-links" aria-label="Footer links">
                                <a href="https://www.facebook.com/profile.php?id=61593664787606" target="_blank" rel="noreferrer" class="truthguard-user-footer-link">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M13.5 21v-7.2h2.4l.45-3h-2.85V8.85c0-.82.4-1.62 1.69-1.62h1.3V4.68s-1.18-.2-2.31-.2c-2.35 0-3.88 1.42-3.88 4v2.32H7.7v3h2.6V21h3.2Z" />
                                    </svg>
                                    Facebook Page
                                </a>
                                <a href="mailto:truthguard2026@gmail.com" class="truthguard-user-footer-link">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 7 8 6 8-6" />
                                    </svg>
                                    Contact
                                </a>
                            </nav>
                            <p class="truthguard-user-footer-note">
                                <svg class="h-3.5 w-3.5 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 4.5 6v5.25c0 4.55 3.13 8.82 7.5 9.75 4.37-.93 7.5-5.2 7.5-9.75V6L12 3Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 12.5 1.75 1.75L15 10.5" />
                                </svg>
                                <span>Review important claims before sharing.</span>
                            </p>
                        </div>
                    </footer>
                @endunless

                @unless ($hideMobileNav)
                <nav class="truthguard-mobile-tabbar lg:hidden" style="--active-index: {{ $mobileNavActiveIndex }};" aria-label="Mobile navigation">
                    <a href="{{ route('dashboard') }}" class="truthguard-mobile-tab {{ request()->routeIs('dashboard') || request()->routeIs('dashboard.*') ? 'is-active' : '' }}">
                        <span class="truthguard-mobile-tab-icon">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <rect x="4" y="4" width="6.5" height="6.5" rx="1.8"></rect>
                                <rect x="13.5" y="4" width="6.5" height="4.8" rx="1.8"></rect>
                                <rect x="13.5" y="11.2" width="6.5" height="8.8" rx="1.8"></rect>
                                <rect x="4" y="13.5" width="6.5" height="6.5" rx="1.8"></rect>
                            </svg>
                        </span>
                        <span>Home</span>
                    </a>

                    <a href="{{ route('history') }}" class="truthguard-mobile-tab {{ request()->routeIs('history') ? 'is-active' : '' }}">
                        <span class="truthguard-mobile-tab-icon">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5v5l3.2 1.9"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.2 12a8.2 8.2 0 1 1-2.4-5.8"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.2 5.2v4h-4"></path>
                            </svg>
                        </span>
                        <span>History</span>
                    </a>

                    <a href="{{ route('detections.create') }}" class="truthguard-mobile-tab truthguard-mobile-tab-primary {{ request()->routeIs('detections.*') ? 'is-active' : '' }}">
                        <span class="truthguard-mobile-tab-icon">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 4H5.75A1.75 1.75 0 0 0 4 5.75V7"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 4h1.25A1.75 1.75 0 0 1 20 5.75V7"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 20H5.75A1.75 1.75 0 0 1 4 18.25V17"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h1.25A1.75 1.75 0 0 0 20 18.25V17"></path>
                                <circle cx="10.75" cy="10.75" r="3.2"></circle>
                                <path stroke-linecap="round" stroke-linejoin="round" d="m13.25 13.25 2.75 2.75"></path>
                            </svg>
                        </span>
                        <span>Check</span>
                    </a>

                    <a href="{{ route('notifications.index') }}" class="truthguard-mobile-tab {{ request()->routeIs('notifications.*') ? 'is-active' : '' }}">
                        <span class="truthguard-mobile-tab-icon relative">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.8 18.2a2.9 2.9 0 0 1-5.6 0"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.3 15.7H5.7l1.25-1.9a3 3 0 0 0 .5-1.66v-2.1a4.55 4.55 0 1 1 9.1 0v2.1c0 .59.17 1.16.5 1.66l1.25 1.9Z"></path>
                            </svg>
                            @if ($unreadNotificationCount > 0)
                                <span class="truthguard-mobile-tab-dot">{{ $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount }}</span>
                            @endif
                        </span>
                        <span>Alerts</span>
                    </a>

                    <a href="{{ route('profile') }}" class="truthguard-mobile-tab {{ request()->routeIs('profile') || request()->routeIs('profile.*') ? 'is-active' : '' }}">
                        <span class="truthguard-mobile-tab-icon">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.25a3.75 3.75 0 1 0 0 7.5 3.75 3.75 0 0 0 0-7.5Z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.8 4.9 15.1 3h-6.2l-.7 1.9-2 .8-1.8-.85-3.1 5.35 1.55 1.15v1.3L1.3 13.8l3.1 5.35 1.8-.85 2 .8.7 1.9h6.2l.7-1.9 2-.8 1.8.85 3.1-5.35-1.55-1.15v-1.3l1.55-1.15-3.1-5.35-1.8.85-2-.8Z"></path>
                            </svg>
                        </span>
                        <span>Settings</span>
                    </a>
                </nav>
                @endunless
            </div>
        </div>

        <form x-ref="logoutForm" method="POST" action="{{ route('logout', absolute: false) }}" class="hidden">
            @csrf
        </form>

        @include('auth.partials.session-timeout-modal')
        @include('auth.partials.device-permissions-onboarding')
        @include('layouts.partials.logout-confirmation-modal', ['logoutScope' => 'user'])
        @include('layouts.partials.onboarding-tour', ['showOnboardingTour' => $showOnboardingTour])

        @livewireScripts
        @stack('scripts')
    </body>
</html>
