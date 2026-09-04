@php
    $layout = auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.user';
    $tabs = [
        ['id' => 'all', 'label' => 'All', 'count' => $counts['all'] ?? 0],
        ['id' => 'unread', 'label' => 'Unread', 'count' => $counts['unread'] ?? 0],
        ['id' => 'fact-check', 'label' => 'Fact Checks', 'count' => $counts['fact_check'] ?? 0],
        ['id' => 'news', 'label' => 'News', 'count' => $counts['news'] ?? 0],
        ['id' => 'security', 'label' => 'Security', 'count' => $counts['security'] ?? 0],
        ['id' => 'system', 'label' => 'System', 'count' => $counts['system'] ?? 0],
    ];
@endphp

@extends($layout)

@section('title', 'Notifications')
@section('page_title', 'Notifications')
@section('page_subtitle', 'Alerts, system updates, and account activity')

@once
    <style>
        .truthguard-notifications-shell {
            position: relative;
            isolation: isolate;
            border-color: rgba(203, 213, 225, 0.86);
            background: #fff;
            box-shadow:
                0 28px 72px rgba(15, 23, 42, 0.08),
                0 1px 0 rgba(255, 255, 255, 0.95) inset;
        }

        .truthguard-notifications-shell::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            background:
                radial-gradient(circle at 14% 8%, rgba(37, 99, 235, 0.11), transparent 30%),
                radial-gradient(circle at 90% 6%, rgba(124, 58, 237, 0.08), transparent 24%),
                linear-gradient(rgba(37, 99, 235, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, 0.035) 1px, transparent 1px);
            background-size: auto, auto, 28px 28px, 28px 28px;
        }

        .truthguard-notifications-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 251, 255, 0.95));
        }

        .truthguard-notifications-hero::after {
            content: '';
            position: absolute;
            right: 2rem;
            top: 1rem;
            height: 4.75rem;
            width: 4.75rem;
            border-radius: 9999px;
            border: 1px solid rgba(147, 197, 253, 0.45);
            background:
                linear-gradient(135deg, rgba(219, 234, 254, 0.72), rgba(255, 255, 255, 0.86));
            box-shadow: 0 20px 45px rgba(37, 99, 235, 0.12);
            opacity: 0.55;
        }

        .truthguard-notifications-stat {
            border: 1px solid rgba(226, 232, 240, 0.9);
            background: rgba(255, 255, 255, 0.86);
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.045);
            backdrop-filter: blur(12px);
        }

        .truthguard-notifications-tab {
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }

        .truthguard-notifications-tab.is-active {
            border-color: rgba(37, 99, 235, 0.78);
            background: linear-gradient(180deg, #eff6ff, #ffffff);
            color: #1d4ed8;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.13);
        }

        .truthguard-notification-page-card {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.96);
        }

        .truthguard-notification-page-card::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            top: 0;
            width: 3px;
            background: transparent;
        }

        .truthguard-notification-page-card.is-unread {
            border-color: rgba(147, 197, 253, 0.9);
            box-shadow:
                0 18px 42px rgba(37, 99, 235, 0.08),
                0 0 0 1px rgba(239, 246, 255, 0.9) inset;
        }

        .truthguard-notification-page-card.is-unread::before {
            background: linear-gradient(180deg, #2563eb, #7c3aed);
        }

        .truthguard-empty-notifications {
            background:
                linear-gradient(180deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.96)),
                linear-gradient(rgba(37, 99, 235, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, 0.04) 1px, transparent 1px);
            background-size: auto, 30px 30px, 30px 30px;
        }
    </style>
@endonce

@section('content')
    <div
        class="truthguard-mobile-page truthguard-mobile-notifications mx-auto w-full max-w-[1280px] space-y-5"
        x-data="{
            activeTab: 'all',
            notifications: @js($notifications),
            csrfToken: @js(csrf_token()),
            markAllUrl: @js(route('notifications.mark-all-read', absolute: false)),
            readUrlTemplate: @js(url('/notifications/__ID__/read')),
            deleteUrlTemplate: @js(url('/notifications/__ID__')),
            get filteredNotifications() {
                if (this.activeTab === 'all') return this.notifications;
                if (this.activeTab === 'unread') return this.notifications.filter((item) => item.unread);
                if (this.activeTab === 'system') return this.notifications.filter((item) => ['system', 'welcome'].includes(item.category));

                return this.notifications.filter((item) => item.category === this.activeTab);
            },
            countFor(tab) {
                if (tab === 'all') return this.notifications.length;
                if (tab === 'unread') return this.notifications.filter((item) => item.unread).length;
                if (tab === 'system') return this.notifications.filter((item) => ['system', 'welcome'].includes(item.category)).length;
                if (tab === 'fact_check') return this.notifications.filter((item) => item.category === 'fact-check').length;

                return this.notifications.filter((item) => item.category === tab).length;
            },
            tone(category) {
                return {
                    'welcome': 'bg-blue-50 text-blue-700 ring-blue-100',
                    'fact-check': 'bg-violet-50 text-violet-700 ring-violet-100',
                    'news': 'bg-cyan-50 text-cyan-700 ring-cyan-100',
                    'security': 'bg-rose-50 text-rose-700 ring-rose-100',
                    'system': 'bg-slate-100 text-slate-700 ring-slate-200',
                }[category] || 'bg-slate-100 text-slate-700 ring-slate-200';
            },
            async markAsRead(id) {
                const item = this.notifications.find((notification) => notification.id === id);
                if (!item || !item.unread) return;

                item.unread = false;

                await fetch(this.readUrlTemplate.replace('__ID__', id), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                }).catch(() => {
                    item.unread = true;
                });
            },
            async markAllAsRead() {
                const previouslyUnread = this.notifications.filter((item) => item.unread);
                this.notifications = this.notifications.map((item) => ({ ...item, unread: false }));

                await fetch(this.markAllUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                }).catch(() => {
                    previouslyUnread.forEach((item) => {
                        const current = this.notifications.find((notification) => notification.id === item.id);
                        if (current) current.unread = true;
                    });
                });
            },
            async archiveNotification(id) {
                const index = this.notifications.findIndex((notification) => notification.id === id);
                if (index === -1) return;

                const removed = this.notifications[index];
                this.notifications.splice(index, 1);

                try {
                    const response = await fetch(this.deleteUrlTemplate.replace('__ID__', id), {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    });

                    if (!response.ok) throw new Error('Failed to archive notification.');
                } catch (error) {
                    this.notifications.splice(index, 0, removed);
                }
            },
        }"
    >
        <section class="truthguard-notifications-shell overflow-hidden rounded-[24px] border">
            <div class="truthguard-notifications-hero border-b border-slate-200/80 px-5 py-5 sm:px-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Activity inbox</h1>
                        <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-500">Fact checks, news, security, and account updates.</p>
                    </div>

                    <div class="truthguard-notifications-actions relative z-10 flex flex-col items-start gap-3 sm:flex-row sm:items-center">
                        <div class="truthguard-notifications-stats grid grid-cols-3 gap-2">
                            <div class="truthguard-notifications-stat rounded-2xl px-3 py-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Total</p>
                                <p class="mt-0.5 text-sm font-bold text-slate-950" x-text="countFor('all')">{{ $counts['all'] ?? 0 }}</p>
                            </div>
                            <div class="truthguard-notifications-stat rounded-2xl px-3 py-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">Unread</p>
                                <p class="mt-0.5 text-sm font-bold text-blue-700" x-text="countFor('unread')">{{ $counts['unread'] ?? 0 }}</p>
                            </div>
                            <div class="truthguard-notifications-stat rounded-2xl px-3 py-2">
                                <p class="text-[10px] font-bold uppercase tracking-[0.14em] text-slate-400">News</p>
                                <p class="mt-0.5 text-sm font-bold text-cyan-700" x-text="countFor('news')">{{ $counts['news'] ?? 0 }}</p>
                            </div>
                        </div>

                        <button
                            type="button"
                            @click="markAllAsRead()"
                            class="inline-flex w-fit items-center justify-center rounded-full border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 hover:shadow-[0_14px_28px_rgba(37,99,235,0.12)] focus:outline-none focus:ring-4 focus:ring-blue-100"
                        >
                            Mark all as read
                        </button>
                    </div>
                </div>

                <div class="truthguard-notifications-tabs mt-5 flex gap-2 overflow-x-auto pb-1">
                    @foreach ($tabs as $tab)
                        <button
                            type="button"
                            @click="activeTab = '{{ $tab['id'] }}'"
                            class="truthguard-notifications-tab inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition focus:outline-none focus:ring-4 focus:ring-blue-100"
                            :class="activeTab === '{{ $tab['id'] }}' ? 'is-active' : 'border-slate-200 bg-white text-slate-600 hover:-translate-y-0.5 hover:border-blue-200 hover:text-slate-950'"
                        >
                            {{ $tab['label'] }}
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs ring-1 ring-slate-200" x-text="countFor('{{ $tab['id'] }}')">{{ $tab['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="truthguard-notifications-list p-4 sm:p-5">
                <template x-if="filteredNotifications.length === 0">
                    <div class="truthguard-empty-notifications flex min-h-[340px] flex-col items-center justify-center rounded-[22px] border border-dashed border-slate-200 px-6 py-12 text-center">
                        <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-blue-600 shadow-sm ring-1 ring-slate-200">
                            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5 11 15l4.5-5"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a8.7 8.7 0 0 0 7-8.5V6.25L12 3.75 5 6.25v6.25A8.7 8.7 0 0 0 12 21Z"></path>
                            </svg>
                        </span>
                        <h2 class="mt-4 text-lg font-bold text-slate-950">No notifications here</h2>
                        <p class="mt-2 max-w-md text-sm leading-6 text-slate-500">When TruthGuard has something new for this category, it will appear here.</p>
                    </div>
                </template>

                <div class="space-y-3">
                    <template x-for="notification in filteredNotifications" :key="notification.id">
                        <article
                            class="truthguard-notification-page-card group rounded-[22px] border p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-100 hover:shadow-[0_18px_42px_rgba(15,23,42,0.09)]"
                            :class="notification.unread ? 'is-unread' : 'border-slate-200'"
                        >
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 gap-4">
                                    <template x-if="notification.imageUrl">
                                        <img
                                            :src="notification.imageUrl"
                                            :alt="notification.title"
                                            class="h-20 w-24 shrink-0 rounded-2xl border border-slate-200 object-cover shadow-sm"
                                            loading="lazy"
                                        >
                                    </template>

                                    <span
                                        x-show="!notification.imageUrl"
                                        class="mt-0.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ring-1"
                                        :class="tone(notification.category)"
                                    >
                                        <svg x-show="notification.category === 'news'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 18.75h12.5a1.5 1.5 0 0 0 1.5-1.5V7.5a1.25 1.25 0 0 0-1.25-1.25H8.75v11a1.5 1.5 0 0 1-3 0V6.75h-1.5v10.5a1.5 1.5 0 0 0 1.5 1.5Z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 9.25h5.5M11.25 12.25h5.5M11.25 15.25h3"></path>
                                        </svg>
                                        <svg x-show="notification.category !== 'news'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                                        </svg>
                                    </span>

                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="text-sm font-bold text-slate-950" x-text="notification.title"></h2>
                                            <span x-show="notification.unread" class="h-2 w-2 rounded-full bg-blue-600"></span>
                                        </div>
                                        <p class="mt-1 text-sm leading-6 text-slate-500" x-text="notification.message"></p>
                                        <div class="mt-3 flex flex-wrap items-center gap-2">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1" :class="tone(notification.category)" x-text="notification.category.replace('-', ' ')"></span>
                                            <span class="text-xs font-medium text-slate-400" x-text="notification.createdAt"></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="truthguard-notification-card-actions flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        @click="archiveNotification(notification.id)"
                                        class="inline-flex items-center gap-1.5 rounded-full border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-600 transition hover:-translate-y-0.5 hover:bg-rose-50 hover:text-rose-700"
                                        :aria-label="'Archive ' + notification.title"
                                        title="Archive notification"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5h10.5M9 7.5V5.75A1.75 1.75 0 0 1 10.75 4h2.5A1.75 1.75 0 0 1 15 5.75V7.5m-7.25 0 .7 11.2A1.5 1.5 0 0 0 9.95 20h4.1a1.5 1.5 0 0 0 1.5-1.3l.7-11.2M10.5 11v5M13.5 11v5"></path>
                                        </svg>
                                        Archive
                                    </button>

                                    <button
                                        type="button"
                                        x-show="notification.unread"
                                        @click="markAsRead(notification.id)"
                                        class="rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-600 transition hover:-translate-y-0.5 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                                    >
                                        Mark read
                                    </button>

                                    <a
                                        :href="notification.actionUrl"
                                        @click="markAsRead(notification.id)"
                                        class="rounded-full bg-slate-950 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-[0_12px_24px_rgba(37,99,235,0.18)]"
                                        x-text="notification.actionLabel"
                                    ></a>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>
            </div>
        </section>
    </div>
@endsection
