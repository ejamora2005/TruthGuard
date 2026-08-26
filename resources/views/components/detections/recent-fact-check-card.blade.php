@props(['item'])

@php
    $summary = trim((string) ($item['summary'] ?? ''));
    $thumbnailFit = ($item['thumbnail_fit'] ?? 'cover') === 'contain' ? 'object-contain p-1.5' : 'object-cover';
@endphp

<a
    href="{{ $item['url'] }}"
    {{ $attributes->class('truthguard-recent-card group grid min-h-[6.1rem] grid-cols-[4.85rem_minmax(0,1fr)_1.55rem] gap-2 rounded-xl border border-blue-100/90 bg-white/90 p-2.5 shadow-[0_10px_24px_rgba(15,23,42,0.055)] transition duration-200 hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-[0_14px_30px_rgba(37,99,235,0.1)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100') }}
    aria-label="Open {{ $item['status'] }} result for {{ $item['claim'] }}"
>
    <span class="truthguard-recent-thumb flex h-full min-h-[4.3rem] w-full items-center justify-center overflow-hidden rounded-lg border border-blue-100 bg-blue-50 text-blue-700">
        @if ($item['thumbnail'])
            <img src="{{ $item['thumbnail'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-full w-full {{ $thumbnailFit }}">
        @elseif ($item['input_type'] === 'Video')
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <rect x="3" y="5.5" width="13" height="13" rx="2"></rect>
                <path stroke-linecap="round" stroke-linejoin="round" d="m16 10 4-2v8l-4-2"></path>
            </svg>
        @elseif ($item['input_type'] === 'Link')
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 14.5 5-5M8 16l-1.3 1.3a3.2 3.2 0 0 1-4.5-4.5L5 10a3.2 3.2 0 0 1 4.5 0M16 8l1.3-1.3a3.2 3.2 0 0 1 4.5 4.5L19 14a3.2 3.2 0 0 1-4.5 0"></path>
            </svg>
        @else
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.8h7l4 4v12.4H7V3.8Z"></path>
                <path stroke-linecap="round" d="M9.5 12h5M9.5 15.5h4"></path>
            </svg>
        @endif
    </span>

    <span class="min-w-0">
        <span class="flex flex-wrap items-center gap-2">
            <span class="text-[10px] font-bold uppercase text-slate-500">{{ $item['input_type'] }}</span>
            <x-detections.status-badge :status="$item['status']" :status-key="$item['status_key']" />
        </span>
        <span class="truthguard-recent-claim mt-1.5 text-xs font-bold leading-4 text-slate-900">{{ $item['claim'] }}</span>
        @if ($summary !== '')
            <span class="truthguard-recent-summary mt-1 block line-clamp-1 text-[11px] font-medium leading-4 text-slate-500">{{ $summary }}</span>
        @endif
        <span class="truthguard-recent-meta mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] font-semibold text-slate-500">
            <time datetime="{{ $item['timestamp'] }}">{{ $item['relative_time'] }}</time>
            @if (! empty($item['host']))
                <span>{{ $item['host'] }}</span>
            @endif
            @if ($item['sources_count'] > 0)
                <span>{{ $item['sources_count'] }} {{ Str::plural('source', $item['sources_count']) }}</span>
            @endif
        </span>
    </span>

    <span class="mt-0.5 inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-400 transition group-hover:bg-blue-50 group-hover:text-blue-700" aria-hidden="true">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"></path>
        </svg>
    </span>
</a>
