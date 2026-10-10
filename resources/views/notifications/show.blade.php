@extends(auth()->user()->isAdmin() ? 'layouts.admin' : 'layouts.user')

@section('title', $notification['title'])
@section('page_title', 'Notification')
@section('page_subtitle', 'Your TruthGuard update')

@section('content')
    <style>
        .tg-notice { --notice-ink: #152443; --notice-muted: #596b85; --notice-line: #e2e9f3; width: 100%; max-width: 880px; margin: 0 auto; color: var(--notice-ink); }
        .tg-notice a:focus-visible { outline: 3px solid #38bdf8; outline-offset: 4px; }
        .tg-notice-nav { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px; }
        .tg-notice-back { display: inline-flex; align-items: center; gap: 9px; min-height: 44px; color: var(--notice-muted); font-size: 14px; font-weight: 600; border-radius: 8px; }
        .tg-notice-back:hover { color: #2563eb; }
        .tg-notice svg { width: 20px; height: 20px; flex-shrink: 0; }
        .tg-notice-read { display: inline-flex; align-items: center; gap: 6px; color: var(--notice-muted); font-size: 12px; }
        .tg-notice-read svg { width: 16px; height: 16px; color: #059669; }
        .tg-notice-card { overflow: hidden; border: 1px solid var(--notice-line); border-radius: 28px; background: #fff; box-shadow: 0 20px 60px -30px rgba(30, 64, 175, .25); }
        .tg-notice-accent { height: 4px; background: linear-gradient(90deg, #2563eb, #06b6d4, #7c3aed); }
        .tg-notice-heading { padding: 36px 40px 32px; border-bottom: 1px solid var(--notice-line); background: radial-gradient(ellipse at 100% 0, #e0f2fe 0, transparent 65%), linear-gradient(135deg, #f8fbff, #fff); }
        .tg-notice-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .tg-notice-icon { display: grid; place-items: center; width: 48px; height: 48px; border: 1px solid #dbeafe; border-radius: 16px; background: #fff; color: #2563eb; box-shadow: 0 5px 12px rgba(37, 99, 235, .06); }
        .tg-notice-category { display: inline-flex; padding: 5px 11px; border: 1px solid #dbeafe; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .tg-notice-date { color: var(--notice-muted); font-size: 13px; }
        .tg-notice-title { margin: 24px 0 0; max-width: 22ch; font-size: clamp(24px, 3vw, 36px); font-weight: 750; line-height: 1.3; letter-spacing: -.025em; overflow-wrap: anywhere; }
        .tg-notice-body { padding: 32px 40px 40px; }
        .tg-notice-message { margin: 0; white-space: pre-line; overflow-wrap: anywhere; font-size: 16px; line-height: 1.95; color: var(--notice-ink); }
        .tg-notice-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--notice-line); }
        .tg-notice-button { display: inline-flex; justify-content: center; align-items: center; gap: 10px; min-height: 48px; padding: 12px 20px; border: 1px solid var(--notice-line); border-radius: 12px; color: var(--notice-ink); font-size: 14px; font-weight: 650; text-align: center; overflow-wrap: anywhere; }
        .tg-notice-button:hover { background: #f1f5f9; }
        .tg-notice-primary { border-color: transparent; background: linear-gradient(115deg, #2563eb, #4f46e5); color: white; box-shadow: 0 6px 16px rgba(37, 99, 235, .18); }
        .tg-notice-primary:hover { background: #1d4ed8; }
        .tg-notice-footer { margin: 20px 0 0; text-align: center; color: var(--notice-muted); font-size: 12px; line-height: 1.7; }
        .tg-notice-footer a { text-decoration: underline; text-underline-offset: 3px; }
        .dark .tg-notice { --notice-ink: #e2e8f0; --notice-muted: #a8b8cf; --notice-line: #2a3950; }
        .dark .tg-notice-card { background: #111c2e; }
        .dark .tg-notice-heading { background: radial-gradient(ellipse at 100% 0, #17384a, transparent 70%), #152239; }
        .dark .tg-notice-icon, .dark .tg-notice-category { background: #1a3050; border-color: #304c71; color: #93c5fd; }
        .dark .tg-notice-button:not(.tg-notice-primary):hover { background: #24344c; }
        @media (max-width: 600px) {
            .tg-notice-nav { margin-bottom: 16px; }
            .tg-notice-card { border-radius: 20px; }
            .tg-notice-heading { padding: 24px 22px; }
            .tg-notice-body { padding: 24px 22px 28px; }
            .tg-notice-meta { gap: 10px; }
            .tg-notice-date { flex-basis: 100%; }
            .tg-notice-button { width: 100%; }
        }
    </style>
    <div class="tg-notice">
        <nav class="tg-notice-nav" aria-label="Notification navigation">
            <a href="{{ route('notifications.index') }}" class="tg-notice-back">
                <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m12 19-7-7 7-7M5 12h14" /></svg>
                All notifications
            </a>
            <span class="tg-notice-read"><svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>Marked as read</span>
        </nav>
        <article class="tg-notice-card" aria-labelledby="notification-title">
            <div class="tg-notice-accent" aria-hidden="true"></div>
            <header class="tg-notice-heading">
                <div class="tg-notice-meta">
                    <span class="tg-notice-icon" aria-hidden="true"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" /></svg></span>
                    <span class="tg-notice-category">{{ str_replace('-', ' ', $notification['category']) }}</span>
                    <span class="tg-notice-date">{{ $notification['createdAt'] }}</span>
                </div>
                <h1 id="notification-title" class="tg-notice-title">{{ $notification['title'] }}</h1>
            </header>
            <div class="tg-notice-body">
                <p class="tg-notice-message">{{ $notification['message'] }}</p>
                <div class="tg-notice-actions">
                    @if ($actionUrl !== '/notifications' && $actionUrl !== $notification['actionUrl'])
                        <a href="{{ $actionUrl }}" class="tg-notice-button tg-notice-primary">
                            {{ $actionLabel }}
                            <svg aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-7-7 7 7-7 7" /></svg>
                        </a>
                    @endif
                    <a href="{{ route('notifications.index') }}" class="tg-notice-button">Back to notifications</a>
                </div>
            </div>
        </article>
        <p class="tg-notice-footer">Choose which updates you receive in <a href="{{ route('profile', ['section' => 'notifications']) }}">notification settings</a>.</p>
    </div>
@endsection
