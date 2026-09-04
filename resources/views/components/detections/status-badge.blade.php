@props([
    'status',
    'statusKey' => 'unverified',
])

@php
    $classes = match ($statusKey) {
        'verified' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'false' => 'border-rose-200 bg-rose-50 text-rose-700',
        'misleading' => 'border-orange-200 bg-orange-50 text-orange-700',
        'needs_context' => 'border-amber-200 bg-amber-50 text-amber-800',
        'processing' => 'border-blue-200 bg-blue-50 text-blue-700',
        'failed' => 'border-rose-300 bg-white text-rose-700',
        default => 'border-slate-200 bg-slate-100 text-slate-700',
    };
@endphp

<span {{ $attributes->class("truthguard-status-badge inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-bold {$classes}") }}>
    @if ($statusKey === 'verified')
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.3" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 12.5 3 3 6-7"></path>
            <circle cx="12" cy="12" r="9"></circle>
        </svg>
    @elseif ($statusKey === 'processing')
        <span class="h-2 w-2 animate-pulse rounded-full bg-current" aria-hidden="true"></span>
    @elseif (in_array($statusKey, ['false', 'failed'], true))
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.3" aria-hidden="true">
            <path stroke-linecap="round" d="M12 8.5v4.2M12 16h.01"></path>
            <circle cx="12" cy="12" r="9"></circle>
        </svg>
    @else
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.3" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5v5M12 16h.01"></path>
            <circle cx="12" cy="12" r="9"></circle>
        </svg>
    @endif
    <span>{{ $status }}</span>
</span>
