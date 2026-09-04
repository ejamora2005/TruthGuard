@once
    <style>
        .truthguard-notification-popover {
            overflow: visible;
            border-color: rgba(147, 197, 253, 0.72);
            background: #fff;
            box-shadow:
                0 30px 70px rgba(15, 23, 42, 0.18),
                0 18px 36px rgba(37, 99, 235, 0.08),
                0 0 0 1px rgba(255, 255, 255, 0.78) inset,
                inset 0 1px 0 rgba(255, 255, 255, 0.96);
        }

        .truthguard-notification-popover::before {
            content: '';
            position: absolute;
            z-index: 3;
            top: -0.86rem;
            right: 1.12rem;
            height: 1.02rem;
            width: 1.7rem;
            clip-path: polygon(50% 0, 0 100%, 100% 100%);
            background: linear-gradient(180deg, #ffffff 0%, #f4f9ff 100%);
            filter:
                drop-shadow(0 -1px 0 rgba(147, 197, 253, 0.9))
                drop-shadow(0 -12px 18px rgba(37, 99, 235, 0.17));
        }

        .truthguard-notification-popover::after {
            content: '';
            position: absolute;
            z-index: 2;
            top: -1.02rem;
            right: 0.9rem;
            height: 1.18rem;
            width: 2.18rem;
            clip-path: polygon(50% 0, 0 100%, 100% 100%);
            background: linear-gradient(180deg, rgba(37, 99, 235, 0.34), rgba(219, 234, 254, 0.12));
            filter: blur(0.5px);
            pointer-events: none;
        }

        .truthguard-notification-surface {
            background: #fff;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95);
        }

        .truthguard-notification-popover > * {
            position: relative;
            z-index: 1;
        }

        .truthguard-notification-item {
            position: relative;
            border: 1px solid transparent;
        }

        .truthguard-notification-item.is-unread {
            border-color: rgba(191, 219, 254, 0.95);
            background:
                linear-gradient(90deg, rgba(239, 246, 255, 0.98), rgba(255, 255, 255, 0.98));
            box-shadow: 0 12px 26px rgba(37, 99, 235, 0.08);
        }
    </style>
@endonce

<div
    class="relative"
    x-data="{
        open: false,
        notifications: @js($notificationQuickItems ?? []),
        unreadCount: @js($unreadNotificationCount ?? 0),
        latestId: @js($latestUnreadNotificationId ?? null),
        toast: null,
        toastTimer: null,
        pollTimer: null,
        csrfToken: @js(csrf_token()),
        peekUrl: @js(route('notifications.peek', absolute: false)),
        indexUrl: @js(route('notifications.index', absolute: false)),
        markAllUrl: @js(route('notifications.mark-all-read', absolute: false)),
        readUrlTemplate: @js(url('/notifications/__ID__/read')),
        deleteUrlTemplate: @js(url('/notifications/__ID__')),
        init() {
            this.showInitialToast();
            this.pollTimer = window.setInterval(() => this.fetchNotifications(), 30000);
        },
        showInitialToast() {
            if (!this.unreadCount || !this.notifications.length) return;

            const first = this.notifications.find((item) => item.unread) || this.notifications[0];
            this.showToastOnce(first);
        },
        async fetchNotifications() {
            try {
                const response = await fetch(this.peekUrl, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) return;

                const data = await response.json();
                const previousLatest = this.latestId;

                this.unreadCount = data.unreadCount || 0;
                this.latestId = data.latestId || null;
                this.notifications = data.notifications || [];

                if (this.latestId && this.latestId !== previousLatest && this.notifications.length) {
                    this.showToastOnce(this.notifications[0]);
                }
            } catch (error) {}
        },
        showToastOnce(item) {
            if (!item || !item.id) return;

            const storageKey = `truthguard-notification-toast:${item.id}`;

            try {
                if (sessionStorage.getItem(storageKey)) return;
                sessionStorage.setItem(storageKey, 'shown');
            } catch (error) {}

            this.toast = item;
            clearTimeout(this.toastTimer);
            this.toastTimer = window.setTimeout(() => this.toast = null, 5200);
        },
        async markAllRead() {
            const oldNotifications = this.notifications;
            const oldCount = this.unreadCount;

            this.notifications = this.notifications.map((item) => ({ ...item, unread: false }));
            this.unreadCount = 0;

            try {
                const response = await fetch(this.markAllUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });

                if (!response.ok) throw new Error('Failed to mark notifications read.');
            } catch (error) {
                this.notifications = oldNotifications;
                this.unreadCount = oldCount;
            }
        },
        async markRead(id) {
            const item = this.notifications.find((notification) => notification.id === id);

            if (!item || !item.unread) return;

            item.unread = false;
            this.unreadCount = Math.max(0, this.unreadCount - 1);

            try {
                const response = await fetch(this.readUrlTemplate.replace('__ID__', id), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });

                if (!response.ok) throw new Error('Failed to mark notification read.');
            } catch (error) {
                item.unread = true;
                this.unreadCount += 1;
            }
        },
        async archiveNotification(id) {
            const index = this.notifications.findIndex((notification) => notification.id === id);
            if (index === -1) return;

            const removed = this.notifications[index];
            const oldCount = this.unreadCount;
            const oldLatest = this.latestId;
            const oldToast = this.toast;

            this.notifications.splice(index, 1);

            if (removed.unread) {
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }

            if (this.toast?.id === id) {
                this.toast = null;
            }

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

                const data = await response.json().catch(() => ({}));

                if (Object.prototype.hasOwnProperty.call(data, 'unreadCount')) {
                    this.unreadCount = data.unreadCount;
                }

                if (Object.prototype.hasOwnProperty.call(data, 'latestId')) {
                    this.latestId = data.latestId || null;
                }
            } catch (error) {
                this.notifications.splice(index, 0, removed);
                this.unreadCount = oldCount;
                this.latestId = oldLatest;
                this.toast = oldToast;
            }
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
    }"
    @keydown.escape.window="open = false; toast = null"
>
    <button
        type="button"
        data-tour="notifications-bell"
        class="truthguard-top-icon relative"
        aria-label="Open notifications"
        title="Notifications"
        @click="open = !open"
    >
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.8 18.2a2.9 2.9 0 0 1-5.6 0"></path>
            <path stroke-linecap="round" stroke-linejoin="round" d="M18.3 15.7H5.7l1.25-1.9a3 3 0 0 0 .5-1.66v-2.1a4.55 4.55 0 1 1 9.1 0v2.1c0 .59.17 1.16.5 1.66l1.25 1.9Z"></path>
            <path stroke-linecap="round" stroke-linejoin="round" d="M18.1 5.15h.01M20.15 7.2h.01"></path>
        </svg>

        <span
            x-show="unreadCount > 0"
            x-cloak
            class="truthguard-notification-badge absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white ring-2 ring-white"
            x-text="unreadCount > 9 ? '9+' : unreadCount"
        ></span>
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition:enter="ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1 scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 -translate-y-1 scale-95"
        @click.outside="open = false"
        class="truthguard-notification-popover absolute right-0 z-[99999] mt-4 w-[min(24rem,calc(100vw-1rem))] rounded-[24px] border p-1.5"
    >
        <div class="truthguard-notification-surface overflow-hidden rounded-[20px]">
        <div class="border-b border-slate-100 bg-[linear-gradient(180deg,#ffffff,#f8fbff)] px-4 py-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">Notifications</p>
                    <h2 class="mt-1 text-base font-bold text-slate-950">Latest updates</h2>
                </div>

                <button
                    type="button"
                    x-show="unreadCount > 0"
                    @click="markAllRead()"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                >
                    Mark all read
                </button>
            </div>
        </div>

        <div class="max-h-[22rem] overflow-y-auto bg-white p-2">
            <template x-if="notifications.length === 0">
                <div class="px-4 py-8 text-center">
                    <p class="text-sm font-semibold text-slate-900">No notifications yet</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">New TruthGuard updates will appear here.</p>
                </div>
            </template>

            <template x-for="notification in notifications" :key="notification.id">
                <div
                    class="truthguard-notification-item group flex gap-3 rounded-2xl px-3 py-3 transition hover:-translate-y-0.5 hover:border-blue-100 hover:bg-blue-50/70 hover:shadow-[0_14px_30px_rgba(15,23,42,0.08)]"
                    :class="notification.unread ? 'is-unread' : ''"
                >
                    <a
                        :href="notification.actionUrl || indexUrl"
                        @click="markRead(notification.id); open = false"
                        class="flex min-w-0 flex-1 gap-3"
                    >
                        <template x-if="notification.imageUrl">
                            <img
                                :src="notification.imageUrl"
                                :alt="notification.title"
                                class="mt-0.5 h-12 w-14 shrink-0 rounded-xl border border-slate-200 object-cover shadow-sm"
                                loading="lazy"
                            >
                        </template>

                        <span
                            x-show="!notification.imageUrl"
                            class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1"
                            :class="tone(notification.category)"
                        >
                            <svg x-show="notification.category === 'news'" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 18.75h12.5a1.5 1.5 0 0 0 1.5-1.5V7.5a1.25 1.25 0 0 0-1.25-1.25H8.75v11a1.5 1.5 0 0 1-3 0V6.75h-1.5v10.5a1.5 1.5 0 0 0 1.5 1.5Z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 9.25h5.5M11.25 12.25h5.5M11.25 15.25h3"></path>
                            </svg>
                            <svg x-show="notification.category !== 'news'" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.25 6v5.25c0 4.13 2.8 7.89 6.75 9 3.95-1.11 6.75-4.87 6.75-9V6L12 3.75Z"></path>
                            </svg>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm font-bold text-slate-950" x-text="notification.title"></span>
                                <span x-show="notification.unread" class="h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600"></span>
                            </span>
                            <span class="mt-1 line-clamp-2 block text-xs leading-5 text-slate-500" x-text="notification.message"></span>
                            <span class="mt-1.5 block text-[11px] font-semibold text-slate-400" x-text="notification.createdAt"></span>
                        </span>
                    </a>

                    <button
                        type="button"
                        @click.stop.prevent="archiveNotification(notification.id)"
                        class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                        :aria-label="'Archive ' + notification.title"
                        title="Archive notification"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5h10.5M9 7.5V5.75A1.75 1.75 0 0 1 10.75 4h2.5A1.75 1.75 0 0 1 15 5.75V7.5m-7.25 0 .7 11.2A1.5 1.5 0 0 0 9.95 20h4.1a1.5 1.5 0 0 0 1.5-1.3l.7-11.2M10.5 11v5M13.5 11v5"></path>
                        </svg>
                    </button>
                </div>
            </template>
        </div>

        <div class="border-t border-slate-100 bg-white p-2">
            <a
                href="{{ route('notifications.index', absolute: false) }}"
                class="flex items-center justify-center rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
            >
                View all notifications
            </a>
        </div>
        </div>
    </div>

    <div
        x-show="toast"
        x-cloak
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-2 sm:translate-y-0 sm:translate-x-3"
        x-transition:enter-end="opacity-100 translate-y-0 sm:translate-x-0"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed right-4 top-24 z-[99999] w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-[22px] border border-blue-100 bg-white/95 shadow-[0_24px_60px_rgba(15,23,42,0.18)] backdrop-blur-xl"
    >
        <div class="flex gap-3 p-4">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                <svg x-show="toast?.category === 'news'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.75 18.75h12.5a1.5 1.5 0 0 0 1.5-1.5V7.5a1.25 1.25 0 0 0-1.25-1.25H8.75v11a1.5 1.5 0 0 1-3 0V6.75h-1.5v10.5a1.5 1.5 0 0 0 1.5 1.5Z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 9.25h5.5M11.25 12.25h5.5M11.25 15.25h3"></path>
                </svg>
                <svg x-show="toast?.category !== 'news'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12.5 11 15l4.5-5"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a8.7 8.7 0 0 0 7-8.5V6.25L12 3.75 5 6.25v6.25A8.7 8.7 0 0 0 12 21Z"></path>
                </svg>
            </span>

            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-blue-600">New notification</p>
                <h3 class="mt-1 truncate text-sm font-bold text-slate-950" x-text="toast?.title"></h3>
                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500" x-text="toast?.message"></p>
                <template x-if="toast?.imageUrl">
                    <img
                        :src="toast.imageUrl"
                        :alt="toast.title"
                        class="mt-3 h-24 w-full rounded-2xl border border-slate-200 object-cover shadow-sm"
                        loading="lazy"
                    >
                </template>
            </div>

            <button type="button" @click="toast = null" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="Dismiss notification">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
    </div>
</div>
