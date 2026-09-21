@extends('layouts.user')

@section('title', 'History')
@section('page_title', 'History')
@section('page_subtitle', 'View and manage your detection history')

@php
    $activeStatus = $historyFilters['status'] ?? 'all';
    $statusPreservedQuery = collect($historyFilters ?? [])
        ->except(['status'])
        ->filter(fn ($value) => filled($value))
        ->all();
    $datePreservedFields = [
        'status' => $activeStatus,
        'search' => $historyFilters['search'] ?? '',
        'sort' => $historyFilters['sort'] ?? 'newest',
    ];
    $searchPreservedFields = [
        'status' => $activeStatus,
        'date_from' => $historyFilters['date_from'] ?? '',
        'date_to' => $historyFilters['date_to'] ?? '',
    ];
    $historyTabs = [
        [
            'key' => 'all',
            'label' => 'All History',
            'count' => $historyStatusCounts['all'] ?? 0,
            'countClass' => 'bg-blue-50 text-blue-700',
            'activeClass' => 'bg-blue-50 text-blue-700 shadow-sm ring-1 ring-blue-100',
            'activeCountClass' => 'bg-blue-100 text-blue-700',
        ],
        [
            'key' => 'real',
            'label' => 'Real',
            'count' => $historyStatusCounts['real'] ?? 0,
            'countClass' => 'bg-emerald-50 text-emerald-700',
            'activeClass' => 'bg-emerald-50 text-emerald-700 shadow-sm ring-1 ring-emerald-100',
            'activeCountClass' => 'bg-emerald-100 text-emerald-700',
        ],
        [
            'key' => 'fake',
            'label' => 'Fake',
            'count' => $historyStatusCounts['fake'] ?? 0,
            'countClass' => 'bg-rose-50 text-rose-700',
            'activeClass' => 'bg-rose-50 text-rose-700 shadow-sm ring-1 ring-rose-100',
            'activeCountClass' => 'bg-rose-100 text-rose-700',
        ],
        [
            'key' => 'review',
            'label' => 'Inconclusive',
            'count' => $historyStatusCounts['review'] ?? 0,
            'countClass' => 'bg-amber-50 text-amber-700',
            'activeClass' => 'bg-amber-50 text-amber-700 shadow-sm ring-1 ring-amber-100',
            'activeCountClass' => 'bg-amber-100 text-amber-700',
        ],
        [
            'key' => 'archived',
            'label' => 'Archive',
            'count' => $historyStatusCounts['archived'] ?? 0,
            'countClass' => 'bg-slate-100 text-slate-700',
            'activeClass' => 'bg-slate-900 text-white shadow-sm ring-1 ring-slate-300',
            'activeCountClass' => 'bg-white/15 text-white',
        ],
    ];
    $currentPage = $historyDetections->currentPage();
    $lastPage = max(1, $historyDetections->lastPage());
    $visiblePages = collect([1, $currentPage - 1, $currentPage, $currentPage + 1, $lastPage])
        ->filter(fn ($page) => $page >= 1 && $page <= $lastPage)
        ->unique()
        ->sort()
        ->values();
@endphp

@once
    <style>
        .truthguard-history-tabs,
        .truthguard-history-control,
        .truthguard-history-panel {
            background:
                radial-gradient(circle at 8% 0%, rgba(255, 255, 255, 0.96), transparent 34%),
                linear-gradient(145deg, rgba(255, 255, 255, 0.94), rgba(248, 250, 252, 0.88));
            box-shadow:
                0 18px 42px rgba(15, 23, 42, 0.06),
                inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .truthguard-history-panel {
            position: relative;
            isolation: isolate;
        }

        .truthguard-history-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 12% 16%, rgba(14, 165, 233, 0.08), transparent 28%),
                radial-gradient(circle at 88% 0%, rgba(79, 70, 229, 0.07), transparent 32%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.84), rgba(248, 250, 252, 0.58));
        }

        .truthguard-history-toolbar {
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.82), rgba(248, 250, 252, 0.72)),
                repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.025) 0 1px, transparent 1px 12px);
        }

        .truthguard-history-row {
            background: rgba(255, 255, 255, 0.9);
        }

        .truthguard-history-row:hover {
            background:
                linear-gradient(90deg, rgba(239, 246, 255, 0.72), rgba(255, 255, 255, 0.92));
        }

        .truthguard-history-thumb-frame {
            display: inline-flex;
            width: 4.35rem;
            height: 4.35rem;
            aspect-ratio: 1 / 1;
            flex: 0 0 4.35rem;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 1.05rem;
            border: 1px solid rgba(191, 219, 254, 0.82);
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(219, 234, 254, 0.66)),
                linear-gradient(135deg, rgba(14, 165, 233, 0.25), rgba(79, 70, 229, 0.18));
            padding: 0.18rem;
            box-shadow:
                0 14px 28px rgba(15, 23, 42, 0.11),
                0 0 0 4px rgba(239, 246, 255, 0.82),
                inset 0 1px 0 rgba(255, 255, 255, 0.88);
        }

        .truthguard-history-thumb,
        .truthguard-history-thumb-placeholder {
            display: flex;
            width: 100%;
            height: 100%;
            aspect-ratio: 1 / 1;
            align-items: center;
            justify-content: center;
            border-radius: 0.85rem;
            object-fit: cover;
        }

        .truthguard-history-thumb-placeholder {
            background:
                radial-gradient(circle at 30% 20%, rgba(255, 255, 255, 0.9), transparent 34%),
                linear-gradient(135deg, #eff6ff, #e2e8f0);
            color: #64748b;
        }

        .truthguard-premium-action {
            background:
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.82));
            box-shadow:
                0 12px 24px rgba(37, 99, 235, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.92);
        }

        .truthguard-premium-action:hover {
            box-shadow:
                0 16px 28px rgba(37, 99, 235, 0.14),
                0 0 0 4px rgba(219, 234, 254, 0.62),
                inset 0 1px 0 rgba(255, 255, 255, 0.96);
        }
    </style>
@endonce

@section('content')
    <div class="truthguard-mobile-page truthguard-mobile-history mx-auto w-full max-w-[1240px] space-y-4 py-1 md:space-y-2">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <nav class="truthguard-history-tabs inline-flex w-full flex-wrap items-center gap-1 rounded-[18px] border border-blue-100/80 p-1.5 xl:w-auto" aria-label="History filters">
                @foreach ($historyTabs as $tab)
                    @php
                        $isActive = $activeStatus === $tab['key'];
                        $tabQuery = $statusPreservedQuery;

                        if ($tab['key'] === 'archived' && $activeStatus !== 'archived') {
                            unset($tabQuery['date_from'], $tabQuery['date_to']);
                        }

                        $tabUrl = route('history', array_merge($tabQuery, ['status' => $tab['key']]));
                    @endphp

                    <a
                        href="{{ $tabUrl }}"
                        @class([
                            'inline-flex min-h-10 items-center gap-2 rounded-[14px] px-3.5 py-2 text-sm font-semibold transition',
                            $isActive ? $tab['activeClass'] : 'text-slate-700 hover:bg-slate-50 hover:text-blue-700',
                        ])
                    >
                        <span>{{ $tab['label'] }}</span>
                        <span @class([
                            'inline-flex min-w-[2rem] items-center justify-center rounded-full px-2 py-1 text-xs font-bold',
                            $isActive ? $tab['activeCountClass'] : $tab['countClass'],
                        ])>
                            {{ number_format($tab['count']) }}
                        </span>
                    </a>

                    @if (! $loop->last)
                        <span class="hidden h-7 w-px bg-slate-200 sm:inline-flex"></span>
                    @endif
                @endforeach
            </nav>

            <form method="GET" action="{{ route('history') }}" class="truthguard-history-date-filters flex flex-col gap-2 sm:flex-row sm:items-center">
                @foreach ($datePreservedFields as $name => $value)
                    @if (filled($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach

                <label class="truthguard-history-control relative flex min-h-11 items-center rounded-[14px] border border-blue-100/80 px-3 transition focus-within:border-blue-200 focus-within:ring-4 focus-within:ring-blue-50">
                    <svg class="h-[18px] w-[18px] shrink-0 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.75 9h14.5M6 5.5h12A1.5 1.5 0 0 1 19.5 7v11A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18V7A1.5 1.5 0 0 1 6 5.5Z"></path>
                    </svg>
                    <input
                        type="date"
                        name="date_from"
                        value="{{ $historyFilters['date_from'] ?? '' }}"
                        class="min-h-10 border-0 bg-transparent px-2 text-sm font-semibold text-slate-900 outline-none focus:ring-0"
                        onchange="this.form.submit()"
                        aria-label="Start date"
                    >
                </label>

                <span class="hidden px-1 text-sm font-semibold text-slate-700 sm:inline">To</span>

                <label class="truthguard-history-control relative flex min-h-11 items-center rounded-[14px] border border-blue-100/80 px-3 transition focus-within:border-blue-200 focus-within:ring-4 focus-within:ring-blue-50">
                    <svg class="h-[18px] w-[18px] shrink-0 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.75 9h14.5M6 5.5h12A1.5 1.5 0 0 1 19.5 7v11A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18V7A1.5 1.5 0 0 1 6 5.5Z"></path>
                    </svg>
                    <input
                        type="date"
                        name="date_to"
                        value="{{ $historyFilters['date_to'] ?? '' }}"
                        class="min-h-10 border-0 bg-transparent px-2 text-sm font-semibold text-slate-900 outline-none focus:ring-0"
                        onchange="this.form.submit()"
                        aria-label="End date"
                    >
                </label>
            </form>
        </div>

        <section class="truthguard-history-panel overflow-hidden rounded-[24px] border border-blue-100/80 shadow-[0_22px_60px_rgba(15,23,42,0.08)]">
            <form method="GET" action="{{ route('history') }}" class="truthguard-history-toolbar flex flex-col gap-3 border-b border-blue-100/80 p-4 lg:flex-row lg:items-center lg:justify-between">
                @foreach ($searchPreservedFields as $name => $value)
                    @if (filled($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach

                <label class="truthguard-history-control relative flex min-h-12 w-full items-center rounded-[14px] border border-blue-100/80 px-4 transition focus-within:border-blue-200 focus-within:ring-4 focus-within:ring-blue-50 lg:max-w-md">
                    <svg class="h-5 w-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z"></path>
                    </svg>
                    <input
                        type="search"
                        name="search"
                        value="{{ $historyFilters['search'] ?? '' }}"
                        placeholder="Search by title or keyword..."
                        class="min-h-11 min-w-0 flex-1 border-0 bg-transparent px-3 text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:ring-0"
                    >
                </label>

                <label class="truthguard-history-control relative flex min-h-12 items-center rounded-[14px] border border-blue-100/80 px-4 transition focus-within:border-blue-200 focus-within:ring-4 focus-within:ring-blue-50">
                    <svg class="h-5 w-5 shrink-0 text-slate-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M7 12h10M10 17h4"></path>
                    </svg>
                    <select
                        data-tg-select
                        name="sort"
                        class="min-h-11 min-w-[9rem] border-0 bg-transparent px-3 text-sm font-semibold text-slate-900 outline-none focus:ring-0"
                        onchange="this.form.submit()"
                        aria-label="Sort history"
                    >
                        @foreach ($historySortOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($historyFilters['sort'] ?? 'newest') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </form>

            @if ($historyRows->isEmpty())
                <div class="px-6 py-16 text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-[18px] bg-blue-50 text-blue-600">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 4.5h9A1.5 1.5 0 0 1 18 6v12a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 18V6a1.5 1.5 0 0 1 1.5-1.5Z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 8h6M9 12h6M9 16h3"></path>
                        </svg>
                    </div>
                    <h2 class="mt-4 text-lg font-bold text-slate-900">No matching history found.</h2>
                    <p class="mt-2 text-sm text-slate-500">Try another filter or run a new Fact Check scan.</p>
                    <a href="{{ route('detections.create') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-[14px] bg-blue-600 px-5 text-sm font-bold text-white shadow-md transition hover:bg-blue-700">
                        Start a detection
                    </a>
                </div>
            @else
                <div class="hidden overflow-x-auto lg:block">
                    <table class="min-w-full text-left">
                        <thead>
                            <tr class="border-b border-blue-100/80 bg-white/70 text-xs font-bold uppercase tracking-[0.2em] text-slate-500">
                                <th class="px-7 py-4">Detection</th>
                                <th class="px-7 py-4">Result</th>
                                <th class="px-7 py-4">Confidence</th>
                                <th class="px-7 py-4">Date</th>
                                <th class="px-7 py-4 text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($historyRows as $row)
                                @php
                                    $isFake = $row['verdict'] === 'fake';
                                    $isReview = $row['verdict'] === 'review';
                                    $categoryKey = $row['category_key'] ?? null;
                                    $badgeClasses = match ($categoryKey) {
                                        'ai_generated' => 'bg-violet-50 text-violet-700 ring-1 ring-violet-100',
                                        'manipulated' => 'bg-orange-50 text-orange-700 ring-1 ring-orange-100',
                                        'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
                                        'confirmed', 'low_risk' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
                                        default => $isFake
                                            ? 'bg-rose-50 text-rose-600 ring-1 ring-rose-100'
                                            : ($isReview ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-100' : 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100'),
                                    };
                                    $barClasses = match ($categoryKey) {
                                        'ai_generated' => 'bg-violet-500',
                                        'manipulated' => 'bg-orange-500',
                                        'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => 'bg-amber-400',
                                        'confirmed', 'low_risk' => 'bg-emerald-500',
                                        default => $isFake ? 'bg-rose-500' : ($isReview ? 'bg-amber-400' : 'bg-emerald-500'),
                                    };
                                @endphp

                                <tr class="truthguard-history-row transition duration-200">
                                    <td class="px-7 py-4">
                                        <div class="flex items-center gap-4">
                                            <span class="truthguard-history-thumb-frame">
                                                @if ($row['thumbnail'])
                                                    <img src="{{ $row['thumbnail'] }}" alt="Detection thumbnail" class="truthguard-history-thumb">
                                                @else
                                                    <span class="truthguard-history-thumb-placeholder">
                                                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14v10H5z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3"></path>
                                                    </svg>
                                                    </span>
                                                @endif
                                            </span>

                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <a href="{{ $row['url'] }}" class="text-base font-bold text-slate-900 transition hover:text-blue-700">{{ $row['case'] }}</a>
                                                    @if ($row['archived'] ?? false)
                                                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-[0.12em] text-slate-600">Archived</span>
                                                    @endif
                                                </div>
                                                <p class="mt-1 max-w-[22rem] text-sm leading-6 text-slate-500">{{ $row['summary'] }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-7 py-4">
                                        <span class="inline-flex min-w-[5rem] items-center justify-center rounded-[14px] px-4 py-2 text-sm font-bold {{ $badgeClasses }}">
                                            {{ $row['result'] }}
                                        </span>
                                    </td>
                                    <td class="px-7 py-4">
                                        <div class="w-40">
                                            <p class="text-base font-bold text-slate-900">{{ $row['confidence'] }}%</p>
                                            <div class="mt-3 h-2 rounded-full bg-slate-100">
                                                <div class="h-2 rounded-full {{ $barClasses }}" style="width: {{ max(8, min(100, $row['confidence'])) }}%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-7 py-4">
                                        <p class="text-base font-semibold text-slate-900">{{ $row['date'] }}</p>
                                        <p class="mt-1 text-sm font-medium text-slate-500">{{ $row['time'] }}</p>
                                    </td>
                                    <td class="px-7 py-4">
                                        <a href="{{ $row['url'] }}" class="truthguard-premium-action mx-auto inline-flex min-h-11 items-center justify-center gap-2 rounded-[14px] border border-blue-100/80 px-4 text-sm font-bold text-blue-700 transition hover:border-blue-200 hover:bg-blue-50">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.75 12s3.5-6 9.25-6 9.25 6 9.25 6-3.5 6-9.25 6-9.25-6-9.25-6Z"></path>
                                                <circle cx="12" cy="12" r="2.6"></circle>
                                            </svg>
                                            Open result
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="truthguard-history-mobile-list divide-y divide-blue-100/80 lg:hidden">
                    @foreach ($historyRows as $row)
                        @php
                            $isFake = $row['verdict'] === 'fake';
                            $isReview = $row['verdict'] === 'review';
                            $categoryKey = $row['category_key'] ?? null;
                            $badgeClasses = match ($categoryKey) {
                                'ai_generated' => 'bg-violet-50 text-violet-700 ring-1 ring-violet-100',
                                'manipulated' => 'bg-orange-50 text-orange-700 ring-1 ring-orange-100',
                                'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-100',
                                'confirmed', 'low_risk' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100',
                                default => $isFake
                                    ? 'bg-rose-50 text-rose-600 ring-1 ring-rose-100'
                                    : ($isReview ? 'bg-amber-50 text-amber-600 ring-1 ring-amber-100' : 'bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100'),
                            };
                            $barClasses = match ($categoryKey) {
                                'ai_generated' => 'bg-violet-500',
                                'manipulated' => 'bg-orange-500',
                                'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => 'bg-amber-400',
                                'confirmed', 'low_risk' => 'bg-emerald-500',
                                default => $isFake ? 'bg-rose-500' : ($isReview ? 'bg-amber-400' : 'bg-emerald-500'),
                            };
                        @endphp

                        <article class="truthguard-history-row truthguard-history-mobile-card p-4 transition duration-200">
                            <div class="flex items-start gap-3">
                                <span class="truthguard-history-thumb-frame">
                                    @if ($row['thumbnail'])
                                        <img src="{{ $row['thumbnail'] }}" alt="Detection thumbnail" class="truthguard-history-thumb">
                                    @else
                                        <span class="truthguard-history-thumb-placeholder">
                                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14v10H5z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3"></path>
                                        </svg>
                                        </span>
                                    @endif
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <a href="{{ $row['url'] }}" class="text-sm font-bold text-slate-900 transition hover:text-blue-700">{{ $row['case'] }}</a>
                                            @if ($row['archived'] ?? false)
                                                <span class="mt-1 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.12em] text-slate-600">Archived</span>
                                            @endif
                                        </div>
                                        <span class="inline-flex rounded-[12px] px-3 py-1 text-xs font-bold {{ $badgeClasses }}">{{ $row['result'] }}</span>
                                    </div>
                                    <p class="mt-1 line-clamp-2 text-sm leading-6 text-slate-500">{{ $row['summary'] }}</p>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Confidence</p>
                                            <div class="mt-1 flex items-center gap-3">
                                                <span class="text-sm font-bold text-slate-900">{{ $row['confidence'] }}%</span>
                                                <div class="h-2 flex-1 rounded-full bg-slate-100">
                                                    <div class="h-2 rounded-full {{ $barClasses }}" style="width: {{ max(8, min(100, $row['confidence'])) }}%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-400">Date</p>
                                            <p class="mt-1 text-sm font-semibold text-slate-900">{{ $row['date'] }} <span class="text-slate-500">{{ $row['time'] }}</span></p>
                                        </div>
                                    </div>
                                    <a href="{{ $row['url'] }}" class="truthguard-premium-action mt-4 inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-[14px] border border-blue-100/80 px-4 text-sm font-bold text-blue-700 transition hover:border-blue-200 hover:bg-blue-50">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.75 12s3.5-6 9.25-6 9.25 6 9.25 6-3.5 6-9.25 6-9.25-6-9.25-6Z"></path>
                                            <circle cx="12" cy="12" r="2.6"></circle>
                                        </svg>
                                        Open result
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <div class="truthguard-history-pagination flex flex-col gap-3 border-t border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm font-medium text-slate-500">
                        Showing {{ number_format($historyDetections->firstItem() ?? 0) }} to {{ number_format($historyDetections->lastItem() ?? 0) }} of {{ number_format($historyDetections->total()) }} results
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($historyDetections->onFirstPage())
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-[12px] border border-slate-200 bg-slate-50 text-slate-300" aria-disabled="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"></path>
                                </svg>
                            </span>
                        @else
                            <a href="{{ $historyDetections->previousPageUrl() }}" class="inline-flex h-10 w-10 items-center justify-center rounded-[12px] border border-slate-200 bg-white text-slate-700 transition hover:border-blue-200 hover:text-blue-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"></path>
                                </svg>
                            </a>
                        @endif

                        @php $previousVisiblePage = null; @endphp
                        @foreach ($visiblePages as $page)
                            @if ($previousVisiblePage !== null && $page - $previousVisiblePage > 1)
                                <span class="inline-flex h-10 min-w-[2.5rem] items-center justify-center px-1 text-sm font-bold text-slate-400">...</span>
                            @endif

                            @if ($page === $currentPage)
                                <span class="inline-flex h-10 min-w-[2.5rem] items-center justify-center rounded-[12px] bg-blue-600 px-3 text-sm font-bold text-white shadow-md">{{ $page }}</span>
                            @else
                                <a href="{{ $historyDetections->url($page) }}" class="inline-flex h-10 min-w-[2.5rem] items-center justify-center rounded-[12px] border border-slate-200 bg-white px-3 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:text-blue-700">{{ $page }}</a>
                            @endif

                            @php $previousVisiblePage = $page; @endphp
                        @endforeach

                        @if ($historyDetections->hasMorePages())
                            <a href="{{ $historyDetections->nextPageUrl() }}" class="inline-flex h-10 w-10 items-center justify-center rounded-[12px] border border-slate-200 bg-white text-slate-700 transition hover:border-blue-200 hover:text-blue-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"></path>
                                </svg>
                            </a>
                        @else
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-[12px] border border-slate-200 bg-slate-50 text-slate-300" aria-disabled="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"></path>
                                </svg>
                            </span>
                        @endif

                        <span class="inline-flex h-10 items-center justify-center rounded-[12px] border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700">
                            15 / page
                        </span>
                    </div>
                </div>
            @endif
        </section>
    </div>
@endsection
