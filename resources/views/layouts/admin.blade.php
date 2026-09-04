<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
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
            $truthGuardPageTitle = $formatTruthGuardPageTitle($rawPageTitle !== '' ? $rawPageTitle : $rawBrowserTitle, 'Admin');
        @endphp

        <title>{{ $truthGuardPageTitle }}</title>
        <link rel="icon" href="{{ $faviconHref }}">
        <link rel="shortcut icon" href="{{ $faviconHref }}">
        @include('layouts.partials.pwa')
        <link rel="stylesheet" href="{{ asset('vendor/tailadmin/admin.css') }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <style>
            [x-cloak] { display: none !important; }

            body {
                font-family: 'Poppins', sans-serif;
            }

            .truthguard-top-icon {
                position: relative;
                display: inline-flex;
                height: 2.75rem;
                width: 2.75rem;
                align-items: center;
                justify-content: center;
                border-radius: 9999px;
                border: 1px solid rgba(226, 232, 240, 0.95);
                background: rgba(255, 255, 255, 0.96);
                color: #475569;
                box-shadow: 0 10px 24px rgba(15, 23, 42, 0.07);
                transition: transform 180ms ease, border-color 180ms ease, color 180ms ease, box-shadow 180ms ease;
            }

            .truthguard-top-icon:hover {
                transform: translateY(-1px);
                border-color: rgba(147, 197, 253, 0.9);
                color: #2563eb;
                box-shadow: 0 14px 28px rgba(37, 99, 235, 0.12);
            }

            .truthguard-notification-badge {
                isolation: isolate;
                overflow: visible;
                z-index: 3;
                border: 1px solid rgba(255, 255, 255, 0.86);
                background: linear-gradient(135deg, #fb7185 0%, #f43f5e 44%, #dc2626 100%) !important;
                box-shadow: 0 8px 16px rgba(244, 63, 94, 0.28), 0 0 0 2px rgba(255, 228, 230, 0.72);
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
        class="truthguard-animated-icons overflow-x-hidden bg-gray-50"
        x-data="{
            adminSidebarOpen: false,
            sidebarExpanded: true,
            isDesktop: window.innerWidth >= 1024,
            logoutConfirmOpen: false,
            logoutSubmitting: false,
            profileName: @js($displayName),
            profileEmail: @js($displayEmail),
            profileInitials: @js($displayInitials),
            profileAvatarUrl: @js($profilePhotoUrl),
            usesSampleProfile: @js($usesSampleProfile),
            init() {
                this.syncViewport = () => {
                    this.isDesktop = window.innerWidth >= 1024;

                    if (this.isDesktop) {
                        this.adminSidebarOpen = false;
                    }
                };

                this.syncViewport();
                window.addEventListener('resize', this.syncViewport);

                window.addEventListener('truthguard-profile-updated', (event) => {
                    const detail = event.detail || {};

                    if (detail.name) this.profileName = detail.name;
                    if (detail.email) this.profileEmail = detail.email;
                    if (detail.initials) this.profileInitials = detail.initials;
                    this.profileAvatarUrl = detail.avatarUrl || null;
                    this.usesSampleProfile = !!detail.usesSampleProfile;
                });
            },
            toggleSidebar() {
                if (this.isDesktop) {
                    this.sidebarExpanded = !this.sidebarExpanded;
                } else {
                    this.adminSidebarOpen = !this.adminSidebarOpen;
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
    >
        @include('layouts.partials.app-splash')

        <div class="min-h-screen overflow-x-clip xl:flex">
            <div
                x-show="!isDesktop && adminSidebarOpen"
                x-cloak
                x-transition.opacity
                class="fixed inset-0 z-[99998] bg-gray-900/50 lg:hidden"
                @click="adminSidebarOpen = false"
            ></div>

            <aside
                x-cloak
                class="fixed top-0 left-0 z-[99999] flex h-screen w-[min(18rem,calc(100vw-1rem))] max-w-[calc(100vw-1rem)] flex-col overflow-y-hidden rounded-r-[1.75rem] border-r border-gray-200 bg-white text-gray-900 shadow-[0_24px_60px_rgba(15,23,42,0.18)] transition-[transform,width] duration-300 ease-in-out lg:max-w-none lg:rounded-none lg:shadow-none"
                :class="[
                    sidebarExpanded ? 'lg:w-[290px]' : 'lg:w-[90px]'
                ]"
                :style="isDesktop || adminSidebarOpen ? 'transform: translateX(0);' : 'transform: translateX(calc(-100% - 1rem));'"
            >
                <div class="flex items-center justify-between px-4 py-6 sm:px-5 sm:py-7" :class="sidebarExpanded ? 'lg:justify-start' : 'lg:justify-center'">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 overflow-hidden">
                        @if ($logoUrl !== '')
                            <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-8 w-8 object-contain">
                        @else
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500 text-sm font-semibold text-white">TG</span>
                        @endif

                        <div x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak>
                            <p class="font-semibold text-gray-800 text-theme-xl">TruthGuard</p>
                            <p class="text-xs text-gray-400">Admin Dashboard</p>
                        </div>
                    </a>

                    <button type="button" class="text-gray-500 lg:hidden" @click="adminSidebarOpen = false" aria-label="Close sidebar">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="no-scrollbar flex-1 overflow-y-auto px-3 duration-300 ease-linear sm:px-4">
                    <nav class="pb-6">
                        <div class="flex flex-col gap-4">
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
                                        <a href="{{ route('admin.dashboard') }}" data-tour="dashboard" class="menu-item group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="menu-item-icon-active">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 15V9"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15V6"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 15v-3"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Overview</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('admin.dashboard') }}#platform-usage" class="menu-item menu-item-inactive group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="menu-item-icon-inactive">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 18h16"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 14V8"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14V5"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 14v-3"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">AI Usage</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('admin.dashboard') }}#subscriptions" class="menu-item menu-item-inactive group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="menu-item-icon-inactive">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 16h8"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Subscriptions</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('admin.dashboard') }}#recent-queue" class="menu-item menu-item-inactive group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="menu-item-icon-inactive">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h12M4 17h8"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Detection Queue</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('detections.create') }}" data-tour="fact-check" class="menu-item menu-item-inactive group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="menu-item-icon-inactive">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12M6 12h12"></path>
                                                </svg>
                                            </span>
                                            <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Fact Check</span>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="{{ route('notifications.index') }}" data-tour="notifications" class="menu-item {{ request()->routeIs('notifications.*') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                            <span class="{{ request()->routeIs('notifications.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }} relative">
                                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
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

                <div class="shrink-0 border-t border-gray-200 px-3 pb-5 pt-4 sm:px-4 sm:pb-6">
                    <ul class="flex flex-col gap-2">
                        <li>
                            <a href="{{ route('profile') }}" data-tour="settings" class="menu-item {{ request()->routeIs('profile') || request()->routeIs('profile.*') ? '' : 'menu-item-inactive' }} group" :class="sidebarExpanded ? 'justify-start' : 'lg:justify-center'">
                                <span class="{{ request()->routeIs('profile') || request()->routeIs('profile.*') ? 'menu-item-icon-active' : 'menu-item-icon-inactive' }}">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <circle cx="12" cy="8" r="3"></circle>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 19a7 7 0 0 1 14 0"></path>
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
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 12H9m0 0 3-3m-3 3 3 3"></path>
                                    </svg>
                                </span>
                                <span x-show="sidebarExpanded || window.innerWidth < 1024" x-cloak class="menu-item-text">Log Out</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </aside>

            <div
                class="min-w-0 flex-1 transition-all duration-300 ease-in-out"
                :class="sidebarExpanded ? 'lg:ml-[290px]' : 'lg:ml-[90px]'"
            >
                <header class="sticky top-0 z-[99997] w-full border-b border-gray-200 bg-white backdrop-blur-xl">
                    <div class="flex w-full items-center gap-2.5 px-3 py-2.5 sm:gap-4 sm:px-4 sm:py-3 lg:px-6 lg:py-4">
                        <button
                            @click="toggleSidebar()"
                            class="truthguard-shell-toggle shrink-0"
                            :class="(isDesktop ? sidebarExpanded : adminSidebarOpen) ? 'truthguard-toggle-active' : ''"
                            aria-label="Toggle sidebar"
                        >
                                <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M0.583252 1C0.583252 0.585788 0.919038 0.25 1.33325 0.25H14.6666C15.0808 0.25 15.4166 0.585786 15.4166 1C15.4166 1.41421 15.0808 1.75 14.6666 1.75L1.33325 1.75C0.919038 1.75 0.583252 1.41422 0.583252 1ZM0.583252 11C0.583252 10.5858 0.919038 10.25 1.33325 10.25L14.6666 10.25C15.0808 10.25 15.4166 10.5858 15.4166 11C15.4166 11.4142 15.0808 11.75 14.6666 11.75L1.33325 11.75C0.919038 11.75 0.583252 11.4142 0.583252 11ZM1.33325 5.25C0.919038 5.25 0.583252 5.58579 0.583252 6C0.583252 6.41421 0.919038 6.75 1.33325 6.75L7.99992 6.75C8.41413 6.75 8.74992 6.41421 8.74992 6C8.74992 5.58579 8.41413 5.25 7.99992 5.25L1.33325 5.25Z" fill="currentColor"/>
                                </svg>
                            </button>

                            <div class="min-w-0 flex-1">
                                <h1 class="sr-only">{{ $rawPageTitle !== '' ? $rawPageTitle : $rawBrowserTitle }}</h1>
                            </div>

                            <div class="hidden items-center gap-3 xl:flex">
                                @hasSection('page_actions')
                                    @yield('page_actions')
                                @endif
                            </div>

                        <div class="ml-auto shrink-0">
                            @include('layouts.partials.notification-center')
                        </div>

                        <div class="relative shrink-0" x-data="{ profileOpen: false }">
                            <button
                                type="button"
                                data-tour="profile"
                                class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-white p-0 transition hover:border-gray-300 hover:shadow-sm sm:h-auto sm:w-auto sm:max-w-[220px] sm:justify-start sm:gap-3 sm:pl-2 sm:pr-3 sm:py-2"
                                @click="profileOpen = !profileOpen"
                                @keydown.escape.window="profileOpen = false"
                            >
                                <template x-if="profileAvatarUrl">
                                    <img :src="profileAvatarUrl" alt="Profile image" class="h-8 w-8 rounded-full object-cover ring-2 ring-white sm:h-9 sm:w-9">
                                </template>
                                <template x-if="!profileAvatarUrl">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-semibold text-white ring-2 ring-white sm:h-9 sm:w-9" x-text="profileInitials"></span>
                                </template>
                                <div class="hidden min-w-0 text-left sm:block">
                                    <p class="truncate text-sm font-semibold text-gray-800" x-text="profileName"></p>
                                    <p class="truncate text-xs text-gray-500" x-text="profileEmail"></p>
                                </div>
                            </button>

                            <div
                                x-show="profileOpen"
                                x-cloak
                                x-transition.opacity
                                @click.outside="profileOpen = false"
                                class="absolute right-0 z-50 mt-3 w-[min(18rem,calc(100vw-1rem))] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl sm:w-60"
                            >
                                <div class="border-b border-gray-100 px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <template x-if="profileAvatarUrl">
                                            <img :src="profileAvatarUrl" alt="Profile image" class="h-11 w-11 rounded-full object-cover">
                                        </template>
                                        <template x-if="!profileAvatarUrl">
                                            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-semibold text-white" x-text="profileInitials"></span>
                                        </template>
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-gray-900" x-text="profileName"></p>
                                            <p class="mt-1 truncate text-xs text-gray-500" x-text="profileEmail"></p>
                                        </div>
                                    </div>

                                    <span
                                        x-show="usesSampleProfile"
                                        x-cloak
                                        class="mt-3 inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-blue-600"
                                    >
                                        Starter Profile
                                    </span>
                                </div>

                                <div class="p-2">
                                    <a href="{{ route('profile') }}" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 hover:text-gray-900">
                                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <circle cx="12" cy="8" r="3"></circle>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 19a7 7 0 0 1 14 0"></path>
                                        </svg>
                                        Settings
                                    </a>

                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-gray-700 transition hover:bg-rose-50 hover:text-rose-600"
                                        @click="profileOpen = false; requestLogout()"
                                    >
                                        <svg class="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12H9"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"></path>
                                        </svg>
                                        Sign out
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </header>

                <main class="mx-auto max-w-[1600px] min-w-0 px-3 pb-3 pt-3 sm:px-4 sm:pb-4 sm:pt-4 md:px-5 md:pb-5 md:pt-5">
                    @yield('content')
                </main>
            </div>
        </div>

        <form x-ref="logoutForm" method="POST" action="{{ route('logout', absolute: false) }}" class="hidden">
            @csrf
        </form>

        @include('auth.partials.session-timeout-modal')
        @include('layouts.partials.logout-confirmation-modal', ['logoutScope' => 'admin'])
        @include('layouts.partials.onboarding-tour', ['showOnboardingTour' => $showOnboardingTour])

        @livewireScripts
        @stack('scripts')
    </body>
</html>
