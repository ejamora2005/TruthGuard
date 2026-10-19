@extends('layouts.user')

@section('title', 'History')
@section('page_title', 'History')
@section('page_subtitle', 'View and manage your detection history')

@php
    $activeStatus = $historyFilters['status'] ?? 'all';
    $statusPreservedQuery = collect($historyFilters)->except('status')->filter(fn ($value) => filled($value))->all();
    $datePreservedFields = collect($historyFilters)->only(['status', 'search', 'sort'])->all();
    $searchPreservedFields = collect($historyFilters)->only(['status', 'date_from', 'date_to'])->all();
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

    $badgeClasses = static function (array $row): string {
        return match ($row['category_key'] ?? '') {
            'ai_generated' => 'tg-history-tone-violet',
            'manipulated' => 'tg-history-tone-orange',
            'satire_parody', 'opinion' => 'tg-history-tone-neutral',
            'confirmed', 'low_risk' => 'tg-history-tone-green',
            'miscaptioned', 'missing_context', 'misleading', 'partly_true', 'partly_false', 'outdated', 'conflicting_evidence', 'no_confirmation', 'needs_review' => 'tg-history-tone-amber',
            default => match ($row['verdict']) { 'fake' => 'tg-history-tone-red', 'real' => 'tg-history-tone-green', default => 'tg-history-tone-amber' },
        };
    };
@endphp

@section('content')
    <div class="tg-history">
        <x-workspace-page-heading title="Your history" description="Your saved assessments, evidence, and results in one place." />
        @if (session('status') === 'history-archived')
            <p class="tg-history-flash" role="status">Report archived. You can find it in Archive.</p>
        @endif
        <div class="tg-history-filterbar">
            <nav class="tg-history-tabs" aria-label="History filters">
                @foreach ($historyTabs as $tab)
                    @php
                        $tabQuery = $statusPreservedQuery;
                        if ($tab['key'] === 'archived' && $activeStatus !== 'archived') {
                            unset($tabQuery['date_from'], $tabQuery['date_to']);
                        }
                    @endphp
                    <a href="{{ route('history', array_merge($tabQuery, ['status' => $tab['key']])) }}"
                        @if ($activeStatus === $tab['key']) aria-current="page" @endif>
                        {{ $tab['label'] }} <span>{{ number_format($tab['count']) }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
        <section class="tg-history-panel" aria-label="Saved assessments">
            <div class="tg-history-toolbar">
                <form id="history-search-form" method="GET" action="{{ route('history') }}" class="tg-history-search-form">
                    @foreach ($searchPreservedFields as $name => $value)
                        @if (filled($value)) <input type="hidden" name="{{ $name }}" value="{{ $value }}"> @endif
                    @endforeach
                    <div class="tg-history-search">
                        <input type="search" name="search" value="{{ $historyFilters['search'] ?? '' }}" placeholder="Search your assessments…" aria-label="Search history">
                        <button type="submit" aria-label="Search history"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg></button>
                    </div>
                </form>
                <select name="sort" form="history-search-form" aria-label="Sort history" onchange="this.form.requestSubmit()">
                    @foreach ($historySortOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($historyFilters['sort'] ?? 'newest') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <details class="tg-history-date-picker" x-data @click.outside="$el.removeAttribute('open')" @keydown.escape.stop="$el.removeAttribute('open'); $el.querySelector('summary').focus()">
                    <summary aria-label="Date range (UTC+8)" title="Filter by date (UTC+8)">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>
                        <span class="tg-history-date-label">Date range <span class="tg-history-zone">UTC+8</span></span>
                    </summary>
                    <form method="GET" action="{{ route('history') }}">
                        <p class="tg-history-range-label">Filter by date <span>(UTC+8)</span></p>
                        @foreach ($datePreservedFields as $name => $value)
                            @if (filled($value)) <input type="hidden" name="{{ $name }}" value="{{ $value }}"> @endif
                        @endforeach
                        <label>From <input type="date" name="date_from" value="{{ $historyFilters['date_from'] ?? '' }}" aria-label="Start date"></label>
                        <label>To <input type="date" name="date_to" value="{{ $historyFilters['date_to'] ?? '' }}" aria-label="End date"></label>
                        <button type="submit">Apply dates</button>
                    </form>
                </details>
            </div>
            @if ($historyRows->isEmpty())
                <div class="tg-history-empty">
                    <h2>No matching history found.</h2>
                    <p>Try another filter or run a new Fact Check scan.</p>
                    <a class="tg-history-open" href="{{ route('detections.create') }}">Start a detection</a>
                </div>
            @else
                <div class="tg-history-desktop">
                    <table class="tg-history-table">
                        <colgroup><col style="width:32%"><col style="width:17%"><col style="width:20%"><col style="width:12%"><col style="width:19%"></colgroup>
                        <thead><tr><th>Assessment</th><th>Result</th><th>Assessment score</th><th>Date <span>(UTC+8)</span></th><th class="tg-history-action-heading">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($historyRows as $row)
                                <tr data-history-id="{{ $row['id'] }}">
                                    <td>@include('user.partials.history-identity')</td>
                                    <td><span class="tg-history-badge {{ $badgeClasses($row) }}">{{ $row['result'] }}</span></td>
                                    <td>@include('user.partials.history-score')</td>
                                    <td>@include('user.partials.history-date')</td>
                                    <td>@include('user.partials.history-actions')</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="tg-history-mobile-list">
                    @foreach ($historyRows as $row)
                        <article class="tg-history-card" data-history-id="{{ $row['id'] }}">
                            @include('user.partials.history-identity')
                            <div class="tg-history-card-result">
                                <span class="tg-history-badge {{ $badgeClasses($row) }}">{{ $row['result'] }}</span>
                                @include('user.partials.history-date')
                            </div>
                            <div class="tg-history-card-footer">
                                @include('user.partials.history-score')
                                @include('user.partials.history-actions')
                            </div>
                        </article>
                    @endforeach
                </div>
                <x-pagination
                    :current-page="$historyDetections->currentPage()"
                    :last-page="$historyDetections->lastPage()"
                    :total="$historyDetections->total()"
                    :first-item="$historyDetections->firstItem()"
                    :last-item="$historyDetections->lastItem()"
                    :page-url="fn (int $page) => $historyDetections->url($page)"
                    label="History pages"
                />

            @endif
        </section>
    </div>
@endsection
