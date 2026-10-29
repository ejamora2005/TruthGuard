@component('layouts.landing', ['title' => 'Claim Reviews | TruthGuard'])
    @php
        $visibleReviewCount = count($factCheckFeed['items'] ?? []);
        $publisherCount = count($sources ?? []);
        $hasActiveFilters = $search !== '' || $source !== '';
    @endphp

    <style>
        .tg-reviews-hero {
            background: radial-gradient(circle at 12% 15%, rgba(96,165,250,.2), transparent 30%), radial-gradient(circle at 88% 18%, rgba(139,92,246,.16), transparent 28%), linear-gradient(135deg,#eff6ff 0%,#fff 48%,#f5f3ff 100%);
        }
        .tg-reviews-search-panel { box-shadow: 0 24px 65px rgba(30,64,175,.12); }
        .tg-public-reviews-page .tg-public-filters { border-bottom: 0; padding: 0; }
        .tg-public-reviews-page .tg-public-filters input,
        .tg-public-reviews-page .tg-public-filters select,
        .tg-public-reviews-page .tg-public-filters .tg-select-trigger { min-height: 3.25rem; border-radius: .85rem; border-color: #cbd5e1; background: #f8fafc; transition: border-color 160ms ease,box-shadow 160ms ease,background 160ms ease; }
        .tg-public-reviews-page .tg-public-filters input:focus,
        .tg-public-reviews-page .tg-public-filters select:focus { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 4px rgba(59,130,246,.12); outline: none; }
        .tg-public-reviews-page .tg-public-submit { min-height: 3.25rem; border-radius: .85rem; padding-inline: 1.6rem; background: linear-gradient(135deg,#2563eb,#7c3aed); box-shadow: 0 10px 24px rgba(37,99,235,.22); transition: transform 160ms ease,box-shadow 160ms ease; }
        .tg-public-reviews-page .tg-public-submit:hover { background: linear-gradient(135deg,#1d4ed8,#6d28d9); box-shadow: 0 14px 28px rgba(37,99,235,.28); transform: translateY(-1px); }
        .tg-review-feed-shell #news-watch { border: 0; border-radius: 1.5rem; background: #fff; box-shadow: 0 18px 55px rgba(15,23,42,.08); }
        .tg-review-feed-shell .tg-fact-feed-row { transition: background-color 160ms ease; }
        .tg-review-feed-shell .tg-fact-feed-row:hover { background: linear-gradient(90deg,rgba(239,246,255,.55),rgba(255,255,255,0)); }
        @media (max-width:640px) {
            .tg-reviews-search-panel { border-radius: 1.25rem; }
            .tg-public-reviews-page .tg-public-submit,.tg-public-reviews-page .tg-public-link { justify-content: center; width: 100%; }
        }
    </style>

    <div class="tg-public-reviews-page bg-slate-50">
        @include('layouts.partials.public-header')
        <main>
            <section class="tg-reviews-hero relative overflow-hidden border-b border-blue-100/70">
                <div class="tg-public-container relative py-14 sm:py-16 lg:py-20">
                    <div aria-hidden="true" class="absolute -right-20 -top-24 h-72 w-72 rounded-full border-[40px] border-blue-100/40"></div>
                    <div class="relative grid gap-10 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                        <div class="max-w-3xl">
                            <span class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-white/80 px-3 py-1.5 text-xs font-bold uppercase tracking-[0.16em] text-blue-700 shadow-sm backdrop-blur"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>The public record</span>
                            <h1 class="mt-5 bg-gradient-to-r from-slate-950 via-blue-800 to-violet-800 bg-clip-text text-4xl font-black tracking-tight text-transparent sm:text-5xl lg:text-6xl">Claim Reviews</h1>
                            <p class="mt-5 max-w-2xl text-base leading-8 text-slate-600 sm:text-lg">Browse recent reviews from independent publishers, compare their ratings, and follow the original evidence before deciding what to trust or share.</p>
                            @guest
                                <p class="mt-4 flex items-center gap-2 text-sm font-medium text-slate-500"><svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 10V8a5 5 0 0 1 10 0v2 M6 10h12v10H6z" /></svg>Sign in to open a full review and explore its evidence.</p>
                            @endguest
                        </div>
                        <div class="grid grid-cols-2 gap-3 sm:flex lg:grid">
                            <div class="min-w-32 rounded-2xl border border-white/90 bg-white/80 px-5 py-4 shadow-sm backdrop-blur"><p class="text-2xl font-black text-slate-950">{{ $visibleReviewCount }}</p><p class="mt-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Reviews shown</p></div>
                            <div class="min-w-32 rounded-2xl border border-white/90 bg-white/80 px-5 py-4 shadow-sm backdrop-blur"><p class="text-2xl font-black text-slate-950">{{ $publisherCount }}</p><p class="mt-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Publishers</p></div>
                        </div>
                    </div>

                    <div class="tg-reviews-search-panel relative mt-10 rounded-3xl border border-white/90 bg-white/90 p-5 backdrop-blur sm:p-6 lg:p-7">
                        <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                            <div><h2 class="text-lg font-bold text-slate-950">Find a claim review</h2><p class="mt-1 text-sm text-slate-500">Search a claim, topic, or publisher name.</p></div>
                            @if ($hasActiveFilters)<span class="inline-flex w-fit rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Filters active</span>@endif
                        </div>
                        <form method="GET" action="{{ route('reviews.index') }}" class="tg-public-filters" data-compact-search="Search reviews" data-compact-search-target="reviews-search-action" data-search-active="{{ $source !== '' ? 'true' : 'false' }}">
                            <label><span>Search reviews</span><input type="search" name="search" value="{{ $search }}" placeholder="Try ‘election’, ‘weather’, or a publisher" maxlength="150"></label>
                            <label><span>Publisher</span><select name="source" data-tg-select aria-label="Publisher"><option value="">All publishers</option>@foreach ($sources as $publisher)<option value="{{ $publisher }}" @selected($source === $publisher)>{{ $publisher }}</option>@endforeach</select></label>
                            <button type="submit" class="tg-public-submit">Search reviews</button>
                            @if ($hasActiveFilters)<a href="{{ route('reviews.index') }}" class="tg-public-link">Clear filters</a>@endif
                        </form>
                    </div>
                </div>
            </section>

            <section class="tg-public-container py-10 sm:py-12 lg:py-16">
                <div class="mb-8 grid gap-4 sm:grid-cols-3">
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16v12H4z M8 10h8 M8 14h5" /></svg></span><h2 class="mt-4 font-bold text-slate-950">Read the reviewed claim</h2><p class="mt-2 text-sm leading-6 text-slate-500">Check exactly what statement or media the publisher assessed.</p></article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4 M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6z" /></svg></span><h2 class="mt-4 font-bold text-slate-950">Understand the rating</h2><p class="mt-2 text-sm leading-6 text-slate-500">Ratings belong to the credited publisher and reflect its review.</p></article>
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1 1 M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1-1" /></svg></span><h2 class="mt-4 font-bold text-slate-950">Open the evidence</h2><p class="mt-2 text-sm leading-6 text-slate-500">Follow the original report and review its sources and publication date.</p></article>
                </div>

                <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">Latest records</p><h2 class="mt-2 text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">Browse publisher reviews</h2></div>
                    <p class="max-w-md text-sm leading-6 text-slate-500">Use the filters inside the review feed to narrow results by category or keyword.</p>
                </div>

                <div class="tg-review-feed-shell">
                    @if (empty($factCheckFeed['items']))
                        <div class="tg-public-empty rounded-3xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm"><span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-3.5-3.5" /></svg></span><h2 class="mt-4">No reviews found</h2><p>{{ $hasActiveFilters ? 'Try another search or clear your filters.' : 'New publisher reviews will appear here when available.' }}</p></div>
                    @else
                        @include('user.dashboard.partials.news-watch', ['reviewListRoute' => 'reviews.index', 'reviewPageSize' => 12, 'reviewPageParameter' => 'page', 'publicReviewList' => true])
                    @endif
                </div>
            </section>
        </main>
        @include('layouts.partials.public-footer')
    </div>
@endcomponent
