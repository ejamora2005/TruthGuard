@props(['category' => 'system', 'expression' => null])
@php
    $paths = [
        'all' => 'M4 4h16v16H4z M4 14h5l2 3h2l2-3h5',
        'unread' => 'M4 6h16v12H4z M4 6l8 6 8-6',
        'welcome' => 'm12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z',
        'fact-check' => 'M14 3H5v18h14V8l-5-5v5h5 M8 14l2 2 5-5',
        'news' => 'M4 4h16v16H4z M8 8h8 M8 12h8 M8 16h5',
        'security' => 'M12 3 5 6v5c0 5 7 10 7 10s7-5 7-10V6l-7-3Z m-3 9 2 2 4-4',
        'system' => 'M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9 M10 21h4',
        'check' => 'm5 12 4 4L19 6',
        'settings' => 'M4 7h9m4 0h3 M4 17h3m4 0h9 M13 4v6h4V4z M7 14v6h4v-6z',
        'arrow' => 'M5 12h14 m-6-6 6 6-6 6',
    ];
@endphp
<svg {{ $attributes->class(['tg-notification-icon']) }} width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @if ($expression)
        <path :d="@js($paths)[{{ $expression }}] || @js($paths['system'])" />
    @else
        <path d="{{ $paths[$category] ?? $paths['system'] }}" />
    @endif
</svg>
