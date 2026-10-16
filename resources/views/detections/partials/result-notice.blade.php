<aside class="tg-result-notice tg-result-notice-{{ $notice['type'] }}" aria-label="{{ $notice['label'] }}">
    <svg viewBox="0 0 24 24" aria-hidden="true">
        @if ($notice['type'] === 'info')
            <path d="m12 3 9 4v5c0 5-9 9-9 9S3 17 3 12V7l9-4Z"/><path d="m8 12 3 3 5-6"/>
        @else
            <path d="m12 3 10 18H2L12 3Zm0 6v5m0 3h.01"/>
        @endif
    </svg>
    <div><h3>{{ $notice['title'] }}</h3><p>{{ $notice['message'] }}</p></div>
</aside>
