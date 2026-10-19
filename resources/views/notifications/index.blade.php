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

@section('content')
    <div
        class="tg-inbox"
        x-data="{
            activeTab: 'all',
            notifications: @js($notifications),
            pendingArchives: [],
            pendingReads: [],
            markingAll: false,
            errorMessage: '',
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
            categoryLabel(category) {
                return { 'fact-check': 'Fact check', news: 'News', security: 'Security', system: 'System', welcome: 'Welcome' }[category] || 'Update';
            },
            async markAsRead(id) {
                const item = this.notifications.find((notification) => notification.id === id);
                if (!item || !item.unread || this.pendingReads.includes(id) || this.markingAll) return;
                this.pendingReads.push(id);
                this.errorMessage = '';
                try {
                    const response = await fetch(this.readUrlTemplate.replace('__ID__', id), {
                        method: 'POST', keepalive: true,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken },
                    });
                    if (!response.ok) throw new Error('Read update failed.');
                    item.unread = false;
                    window.dispatchEvent(new CustomEvent('truthguard:notification'));
                } catch (error) {
                    this.errorMessage = 'Could not mark this notification as read. Please try again.';
                } finally {
                    this.pendingReads = this.pendingReads.filter((pending) => pending !== id);
                }
            },
            async markAllAsRead() {
                if (this.markingAll || this.pendingReads.length || !this.countFor('unread')) return;
                this.markingAll = true;
                this.errorMessage = '';
                try {
                    const response = await fetch(this.markAllUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken },
                    });
                    if (!response.ok) throw new Error('Read update failed.');
                    this.notifications = this.notifications.map((item) => ({ ...item, unread: false }));
                    window.dispatchEvent(new CustomEvent('truthguard:notification'));
                } catch (error) {
                    this.errorMessage = 'Could not mark your notifications as read. Please try again.';
                } finally {
                    this.markingAll = false;
                }
            },
            async archiveNotification(id) {
                if (this.pendingArchives.includes(id)) return;
                this.pendingArchives.push(id);
                this.errorMessage = '';
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
                    window.dispatchEvent(new CustomEvent('truthguard:notification-archived', { detail: { id } }));
                } catch (error) {
                    this.errorMessage = 'Could not remove this notification. Please try again.';
                } finally {
                    this.pendingArchives = this.pendingArchives.filter((pending) => pending !== id);
                }
            },
        }"
        @truthguard:notification-archived.window="notifications = notifications.filter((notification) => notification.id !== $event.detail.id)"
    >
        <header class="tg-inbox-header">
            <div class="tg-inbox-heading">
                <div class="tg-inbox-title-line">
                    <h1>Notifications</h1>
                    <span class="tg-inbox-unread-count" x-show="countFor('unread') > 0" x-cloak x-text="countFor('unread') + ' unread'"></span>
                </div>
                <p>Fact checks, news and account activity.</p>
            </div>
            <div class="tg-inbox-header-actions">
                <button type="button" class="tg-inbox-button" @click="markAllAsRead()"
                    :disabled="markingAll || pendingReads.length > 0 || countFor('unread') === 0" :aria-busy="markingAll">
                    <x-notification-icon category="check" />
                    <span x-text="markingAll ? 'Marking read…' : 'Mark all as read'">Mark all as read</span>
                </button>
                <a href="{{ route('profile', ['section' => 'notifications']) }}" class="tg-inbox-button tg-inbox-preferences" aria-label="Notification settings" title="Notification settings">
                    <x-notification-icon category="settings" /><span>Settings</span>
                </a>
            </div>
        </header>

        <p x-show="errorMessage" x-cloak x-text="errorMessage" role="alert" class="tg-inbox-error"></p>

        <section class="tg-inbox-panel" aria-label="Notification inbox">
            <nav class="tg-inbox-filters" aria-label="Filter notifications">
                @foreach ($tabs as $tab)
                    <button type="button" @click="activeTab = '{{ $tab['id'] }}'"
                        :aria-pressed="activeTab === '{{ $tab['id'] }}'" class="tg-inbox-filter">
                        {{ $tab['label'] }}
                        <span x-text="countFor('{{ $tab['id'] }}')">{{ $tab['count'] }}</span>
                    </button>
                @endforeach
            </nav>
            <div class="tg-inbox-list-heading">
                <h2 x-text="activeTab === 'all' ? 'Latest updates' : activeTab === 'unread' ? 'Unread updates' : categoryLabel(activeTab) + ' updates'">Latest updates</h2>
                <span>Newest first</span>
            </div>

            <template x-if="filteredNotifications.length === 0">
                <div class="tg-inbox-empty" role="status">
                    <span class="tg-inbox-empty-icon"><x-notification-icon category="all" /></span>
                    <h2 x-text="activeTab === 'unread' ? 'You’re all caught up' : 'No notifications here'"></h2>
                    <p x-text="activeTab === 'unread' ? 'You’ve read all your updates. New ones will appear here.' : 'New updates will appear here when there’s something to share.'"></p>
                    <button type="button" class="tg-inbox-button" x-show="activeTab !== 'all'" @click="activeTab = 'all'">View all notifications</button>
                </div>
            </template>

            <div class="tg-inbox-list">
                <template x-for="notification in filteredNotifications" :key="notification.id">
                    <article class="tg-inbox-row" :class="{ 'is-unread': notification.unread }" :data-notification-id="notification.id">
                        <div class="tg-inbox-media" :data-category="notification.category">
                            <template x-if="notification.imageUrl">
                                <img :src="notification.imageUrl" alt="" loading="lazy" x-on:error="$el.hidden = true">
                            </template>
                            <x-notification-icon expression="notification.category" />
                        </div>
                        <div class="tg-inbox-copy">
                            <div class="tg-inbox-meta">
                                <span x-text="categoryLabel(notification.category)"></span>
                                <span aria-hidden="true">·</span>
                                <span x-text="notification.createdAt"></span>
                                <span class="tg-inbox-unread-dot" x-show="notification.unread" role="img" aria-label="Unread"></span>
                            </div>
                            <h3><a :href="notification.actionUrl" @click="markAsRead(notification.id)" x-text="notification.title"></a></h3>
                            <p x-text="notification.message"></p>
                        </div>
                        <div class="tg-inbox-row-actions">
                            <a :href="notification.actionUrl" @click="markAsRead(notification.id)" class="tg-inbox-open">
                                <span x-text="notification.actionLabel"></span><x-notification-icon category="arrow" />
                            </a>
                            <div class="tg-inbox-tools">
                                <button type="button" class="tg-inbox-icon-button" x-show="notification.unread" @click="markAsRead(notification.id)"
                                    :disabled="markingAll || pendingReads.includes(notification.id)" :aria-busy="pendingReads.includes(notification.id)"
                                    :aria-label="'Mark as read: ' + notification.title" title="Mark as read">
                                    <x-notification-icon category="check" />
                                </button>
                                <button type="button" class="tg-inbox-icon-button" @click="archiveNotification(notification.id)"
                                    :disabled="pendingArchives.includes(notification.id)" :aria-busy="pendingArchives.includes(notification.id)"
                                    :aria-label="'Archive ' + notification.title" title="Archive notification">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="5" rx="1.5"/><path d="M5 8v11a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4"/></svg>
                                </button>
                            </div>
                        </div>
                    </article>
                </template>
            </div>
        </section>
        <p class="tg-inbox-footnote">Choose the updates you receive in <a href="{{ route('profile', ['section' => 'notifications']) }}">notification settings</a>.</p>
    </div>
@endsection
