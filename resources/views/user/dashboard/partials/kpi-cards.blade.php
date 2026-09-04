<section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($kpiCards as $card)
        @php
            $trendClasses = match ($card['trend']['tone']) {
                'emerald' => 'bg-emerald-50 text-emerald-600',
                'rose' => 'bg-rose-50 text-rose-600',
                default => 'bg-slate-100 text-slate-600',
            };
        @endphp

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition duration-300 hover:scale-[1.02] hover:shadow-lg">
            <div class="flex items-start justify-between gap-4">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                    @switch($card['icon'])
                        @case('scan')
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7V5a1 1 0 0 1 1-1h2"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7V5a1 1 0 0 0-1-1h-2"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 17v2a1 1 0 0 0 1 1h2"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 17v2a1 1 0 0 1-1 1h-2"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 12h10"></path>
                            </svg>
                            @break
                        @case('shield')
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v6c0 4.6-2.9 7.8-7 9-4.1-1.2-7-4.4-7-9V6l7-3Z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 17h.01"></path>
                            </svg>
                            @break
                        @case('pending')
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5l3 2"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-9-9"></path>
                            </svg>
                            @break
                        @default
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 19h16"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 15V9"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15V6"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 15v-3"></path>
                            </svg>
                    @endswitch
                </div>

                <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold {{ $trendClasses }}">
                    @if ($card['trend']['direction'] === 'up')
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 7h7v7"></path>
                        </svg>
                    @elseif ($card['trend']['direction'] === 'down')
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 7l10 10"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 17h7v-7"></path>
                        </svg>
                    @else
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"></path>
                        </svg>
                    @endif
                    {{ $card['trend']['percent'] }}%
                </span>
            </div>

            <div class="mt-6">
                <p class="text-3xl font-semibold tracking-tight text-slate-900">{{ $card['value'] }}</p>
                <p class="mt-2 text-sm font-medium text-slate-500">{{ $card['label'] }}</p>
                <p class="mt-3 text-xs text-slate-400">{{ $card['trend']['label'] }}</p>
            </div>
        </article>
    @endforeach
</section>
