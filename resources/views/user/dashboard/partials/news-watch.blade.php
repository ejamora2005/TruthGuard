@php
    $feed = $factCheckFeed ?? ['configured' => false, 'items' => []];
    $allFeedItems = collect($feed['items'] ?? []);
    $feedPerPage = $reviewPageSize ?? 10;
    $feedPageName = $reviewPageParameter ?? 'fact_page';
    $feedTotal = $allFeedItems->count();
    $feedLookbackDays = max(0, (int) ($feed['max_age_days'] ?? 0));
    $feedWindowLabel = $feedLookbackDays > 0 ? 'last '.$feedLookbackDays.' days' : 'latest reviews';
    $feedTotalPages = max(1, (int) ceil($feedTotal / $feedPerPage));
    $requestedFeedPage = (int) request()->query($feedPageName, 1);
    $feedCurrentPage = min(max($requestedFeedPage, 1), $feedTotalPages);
    $feedItems = $allFeedItems->forPage($feedCurrentPage, $feedPerPage)->values();
    $feedFirstItem = $feedTotal > 0 ? (($feedCurrentPage - 1) * $feedPerPage) + 1 : 0;
    $feedLastItem = $feedTotal > 0 ? min($feedFirstItem + $feedItems->count() - 1, $feedTotal) : 0;
    $feedPageNumbers = collect(range(1, $feedTotalPages))
        ->filter(fn (int $page): bool => $page === 1 || $page === $feedTotalPages || abs($page - $feedCurrentPage) <= 1)
        ->values();
    $feedRoute = $reviewListRoute ?? 'dashboard';
    $feedPageUrl = function (int $page) use ($feedPageName, $feedRoute): string {
        $query = request()->query();
        unset($query[$feedPageName]);

        if ($page > 1) {
            $query[$feedPageName] = $page;
        }

        return route($feedRoute, $query).'#news-watch';
    };
    $updatedAt = $feed['updated_at'] ?? null;
    $sourceRows = $allFeedItems
        ->map(fn ($item) => [
            'name' => $item['publisher'] ?? 'Source',
            'logo' => $item['logo_url'] ?? null,
            'domain' => $item['source_domain'] ?? ($item['host'] ?? null),
        ])
        ->filter(fn ($source) => filled($source['name']))
        ->unique('name')
        ->take(6)
        ->values();
@endphp

@once
    <style>
        @media (min-width: 1280px) {
            .tg-fact-feed-row {
                grid-template-columns: 18rem minmax(0, 1fr);
            }
        }

        #news-watch .tg-rating-banner {
            /* tan(28deg) keeps the center midway between the two clipped edges. */
            --ribbon-inset: 4.5rem;
            align-items: center;
            border: 1px solid rgb(255 255 255 / 0.55);
            border-radius: 0;
            bottom: calc(var(--ribbon-inset) * 0.531709 - 1rem);
            box-shadow: 0 10px 24px rgb(15 23 42 / 0.22);
            box-sizing: border-box;
            display: flex;
            gap: 0.35rem;
            height: 2rem;
            justify-content: center;
            left: auto;
            letter-spacing: 0;
            max-width: none;
            min-height: 2rem;
            opacity: 1;
            overflow: hidden;
            padding: 0.4rem 0.65rem;
            pointer-events: none;
            position: absolute;
            right: calc(var(--ribbon-inset) - 7rem);
            text-align: center;
            top: auto;
            transform: rotate(-28deg);
            transform-origin: center;
            visibility: visible;
            white-space: nowrap;
            width: 14rem;
            writing-mode: horizontal-tb;
            z-index: 3;
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
        }

        #news-watch .tg-rating-banner span {
            font-size: 0.78rem;
            letter-spacing: 0;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        #news-watch .tg-rating-banner .tg-rating-source-logo {
            width: 1.15rem;
            height: 1.15rem;
            flex: 0 0 1.15rem;
            margin-right: 0;
            padding: 0.1rem;
            border-radius: 50%;
            background: #fff;
            object-fit: contain;
        }

        .tg-rating-banner-danger {
            background: rgb(220 38 38 / 0.84);
            color: #fff;
        }

        .tg-rating-banner-warning {
            background: rgb(251 191 36 / 0.88);
            color: #431407;
        }

        .tg-rating-banner-safe {
            background: rgb(16 185 129 / 0.84);
            color: #fff;
        }

        .tg-rating-banner-neutral {
            background: rgb(15 23 42 / 0.78);
            color: #fff;
        }

        .tg-rating-meta-chip {
            align-items: center;
            border: 1px solid transparent;
            border-radius: 6px;
            display: inline-flex;
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0;
            line-height: 1;
            min-height: 1.45rem;
            padding: 0.25rem 0.5rem;
            text-transform: none;
            white-space: nowrap;
        }

        .tg-rating-meta-chip-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #b91c1c;
        }

        .tg-rating-meta-chip-warning {
            background: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .tg-rating-meta-chip-safe {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #047857;
        }

        .tg-rating-meta-chip-neutral {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #475569;
        }

        .tg-rating-reviewer {
            display: none;
        }

        .tg-media-shade {
            position: absolute;
            right: 0;
            bottom: 0;
            left: 0;
            top: auto;
            width: 100%;
            height: 5.5rem;
            max-height: 5.5rem;
            display: none;
            opacity: 0;
            pointer-events: none;
            transition: opacity 180ms ease, visibility 180ms ease;
            visibility: hidden;
        }

        #news-watch .tg-feed-window {
            position: relative;
            display: inline-flex;
            min-width: 12.75rem;
            align-items: center;
            gap: 0.75rem;
            overflow: hidden;
            border: 1px solid rgba(147, 197, 253, 0.72);
            border-radius: 1rem;
            background:
                radial-gradient(circle at 100% 0%, rgba(34, 211, 238, 0.2), transparent 38%),
                linear-gradient(135deg, #eff6ff, #ffffff 58%, #ecfeff);
            padding: 0.65rem 0.8rem;
            box-shadow: 0 12px 28px rgba(37, 99, 235, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.94);
        }

        #news-watch .tg-feed-window-icon {
            display: inline-flex;
            width: 2.35rem;
            height: 2.35rem;
            flex: 0 0 2.35rem;
            align-items: center;
            justify-content: center;
            border-radius: 0.75rem;
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            color: #fff;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.24);
        }

        #news-watch .tg-feed-window-copy {
            display: grid;
            min-width: 0;
            gap: 0.12rem;
        }

        #news-watch .tg-feed-window-copy > span {
            color: #64748b;
            font-size: 0.6rem;
            font-weight: 800;
            letter-spacing: 0.13em;
            line-height: 1.1;
            text-transform: uppercase;
        }

        #news-watch .tg-feed-window-copy > strong {
            color: #0f172a;
            font-size: 0.82rem;
            font-weight: 900;
            line-height: 1.25;
        }

        #news-watch .tg-feed-window-live {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-left: auto;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.8);
            padding: 0.28rem 0.48rem;
            color: #047857;
            font-size: 0.6rem;
            font-weight: 900;
            box-shadow: 0 0 0 1px rgba(167, 243, 208, 0.9);
        }

        #news-watch .tg-feed-window-live::before {
            content: '';
            width: 0.38rem;
            height: 0.38rem;
            border-radius: 9999px;
            background: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.11);
        }

        #news-watch .tg-feed-window-mobile {
            display: none;
        }

        #news-watch .tg-feed-pagination-bar {
            border-top: 1px solid rgba(203, 213, 225, 0.78);
            background:
                radial-gradient(circle at 92% 0%, rgba(219, 234, 254, 0.7), transparent 34%),
                linear-gradient(180deg, #ffffff, #f8fafc);
            padding: 1rem 1.25rem;
        }

        #news-watch .tg-feed-pagination-summary {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 0.7rem;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 650;
        }

        #news-watch .tg-feed-pagination-summary-icon {
            display: inline-flex;
            width: 2rem;
            height: 2rem;
            flex: 0 0 2rem;
            align-items: center;
            justify-content: center;
            border: 1px solid #dbeafe;
            border-radius: 0.65rem;
            background: #eff6ff;
            color: #2563eb;
        }

        #news-watch .tg-feed-pagination-summary strong {
            color: #0f172a;
            font-weight: 900;
        }

        #news-watch .tg-feed-page-status {
            display: inline-flex;
            flex: 0 0 auto;
            align-items: center;
            border: 1px solid #e2e8f0;
            border-radius: 9999px;
            background: #fff;
            padding: 0.32rem 0.6rem;
            color: #475569;
            font-size: 0.68rem;
            font-weight: 800;
            box-shadow: 0 5px 14px rgba(15, 23, 42, 0.045);
        }

        #news-watch .tg-feed-pagination {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid #dbe3ee;
            border-radius: 0.95rem;
            background: rgba(255, 255, 255, 0.92);
            padding: 0.3rem;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.07), inset 0 1px 0 #fff;
        }

        #news-watch .tg-feed-page-button {
            display: inline-flex;
            min-width: 2.35rem;
            height: 2.35rem;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            border: 1px solid transparent;
            border-radius: 0.7rem;
            background: transparent;
            padding: 0 0.65rem;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 850;
            line-height: 1;
            transition: transform 180ms ease, border-color 180ms ease, background-color 180ms ease, color 180ms ease, box-shadow 180ms ease;
        }

        #news-watch a.tg-feed-page-button:hover {
            transform: translateY(-1px);
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.1);
        }

        #news-watch .tg-feed-page-button.is-current {
            border-color: #2563eb;
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            color: #fff;
            box-shadow: 0 9px 20px rgba(37, 99, 235, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }

        #news-watch .tg-feed-page-button.is-disabled {
            color: #cbd5e1;
            cursor: not-allowed;
        }

        #news-watch .tg-feed-page-edge {
            min-width: auto;
        }

        #news-watch .tg-feed-page-ellipsis {
            display: inline-flex;
            min-width: 1.5rem;
            height: 2.35rem;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 0.8rem;
            font-weight: 900;
        }

        @media (max-width: 640px) {
            #news-watch .tg-feed-monitor-title-row {
                flex: 1 1 100%;
                min-width: 0;
                justify-content: space-between;
            }

            #news-watch .tg-feed-monitor-label {
                min-width: 0;
                font-size: 0.62rem;
                letter-spacing: 0.14em;
                white-space: nowrap;
            }

            #news-watch .tg-rating-banner {
                --ribbon-inset: 4rem;
                min-height: 2rem;
                padding: 0.34rem 0.5rem;
            }

            #news-watch .tg-feed-window-desktop {
                display: none;
            }

            #news-watch .tg-feed-window-mobile {
                display: inline-flex;
                width: auto;
                min-width: 0;
                flex: 0 0 auto;
                gap: 0.32rem;
                border-radius: 9999px;
                padding: 0.34rem 0.5rem;
                box-shadow: 0 7px 15px rgba(37, 99, 235, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.94);
            }

            #news-watch .tg-feed-window-mobile .tg-feed-window-icon,
            #news-watch .tg-feed-window-mobile .tg-feed-window-copy > span,
            #news-watch .tg-feed-window-mobile .tg-feed-window-live {
                display: none;
            }

            #news-watch .tg-feed-window-mobile .tg-feed-window-copy {
                display: block;
            }

            #news-watch .tg-feed-window-mobile .tg-feed-window-copy > strong {
                display: block;
                font-size: 0.66rem;
                line-height: 1;
                white-space: nowrap;
            }

            #news-watch .tg-feed-pagination-bar {
                padding: 0.75rem;
            }

            #news-watch .tg-feed-pagination-summary {
                width: 100%;
                font-size: 0.68rem;
            }

            #news-watch .tg-feed-pagination {
                width: 100%;
                max-width: 100%;
                justify-content: flex-start;
                overflow-x: auto;
                scrollbar-width: none;
            }

            #news-watch .tg-feed-pagination::-webkit-scrollbar {
                display: none;
            }

            #news-watch .tg-feed-page-button {
                flex: 0 0 auto;
            }

            #news-watch .tg-feed-page-edge span {
                position: absolute;
                width: 1px;
                height: 1px;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
            }
        }
    </style>
@endonce

<section id="news-watch" class="tg-mobile-feed overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-md">
    <div class="tg-mobile-feed-head border-b border-slate-200 bg-slate-50/80 px-5 py-5 sm:px-6 sm:py-6">
        <div class="flex flex-col gap-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="tg-feed-monitor-title-row flex items-center gap-2">
                        <p class="tg-feed-monitor-label text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Verified source monitor</p>
                        @if ($feedLookbackDays > 0)
                            <span class="tg-feed-window tg-feed-window-mobile" aria-label="Coverage window: {{ $feedWindowLabel }}">
                                <span class="tg-feed-window-icon" aria-hidden="true">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.75 9h14.5M6 5.5h12A1.5 1.5 0 0 1 19.5 7v11A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18V7A1.5 1.5 0 0 1 6 5.5Z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 13h3m-3 3h6"></path>
                                    </svg>
                                </span>
                                <span class="tg-feed-window-copy">
                                    <span>Coverage window</span>
                                    <strong>{{ \Illuminate\Support\Str::headline($feedWindowLabel) }}</strong>
                                </span>
                                <span class="tg-feed-window-live">Live</span>
                            </span>
                        @endif
                    </div>
                    <div class="flex -space-x-2">
                        @foreach ($sourceRows as $source)
                            <span class="flex h-8 w-8 items-center justify-center rounded-full border border-white bg-slate-100 shadow-sm" title="{{ $source['name'] }}">
                                @if ($source['logo'])
                                    <img src="{{ $source['logo'] }}" alt="" loading="lazy" class="h-5 w-5 rounded-full object-contain">
                                @else
                                    <span class="text-[0.65rem] font-bold text-slate-500">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($source['name'], 0, 1)) }}</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
                <h3 class="mt-2 text-2xl font-semibold tracking-tight text-slate-950 sm:text-3xl">Latest public claim reviews</h3>
            </div>

            @if ($feedLookbackDays > 0)
                <div class="tg-feed-window tg-feed-window-desktop" aria-label="Coverage window: {{ $feedWindowLabel }}">
                    <span class="tg-feed-window-icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.75 9h14.5M6 5.5h12A1.5 1.5 0 0 1 19.5 7v11A1.5 1.5 0 0 1 18 19.5H6A1.5 1.5 0 0 1 4.5 18V7A1.5 1.5 0 0 1 6 5.5Z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 13h3m-3 3h6"></path>
                        </svg>
                    </span>
                    <span class="tg-feed-window-copy">
                        <span>Coverage window</span>
                        <strong>{{ \Illuminate\Support\Str::headline($feedWindowLabel) }}</strong>
                    </span>
                    <span class="tg-feed-window-live">Live</span>
                </div>
            @endif
        </div>
    </div>

    @if ($feedItems->isNotEmpty())
        <div class="tg-mobile-feed-list divide-y divide-slate-200 px-5 sm:px-6">
            @foreach ($feedItems as $item)
                @php
                    $tone = $item['tone'] ?? 'neutral';
                    $rawRating = (string) ($item['rating'] ?? 'Reviewed');
                    $rating = \App\Services\Detections\FactCheckRatingNormalizer::shortLabel(
                        $rawRating,
                        (string) ($item['headline'] ?? ''),
                        (string) ($item['claim'] ?? ''),
                    );
                    $ratingTone = \App\Services\Detections\FactCheckRatingNormalizer::toneFor($rating) ?: $tone;
                    $logoUrl = $item['logo_url'] ?? null;
                    $publisherName = $item['publisher'] ?? 'Fact-check partner';
                    $detailUrl = ! empty($item['id'])
                        ? route('dashboard.fact-check', ['factCheck' => $item['id']])
                        : ($item['url'] ?: route('detections.create'));
                @endphp
                <article class="tg-fact-feed-row tg-mobile-fact-card grid gap-5 py-6 xl:items-stretch">
                    <a
                        href="{{ $detailUrl }}"
                        class="tg-mobile-fact-media group relative min-h-52 overflow-hidden rounded-2xl border border-slate-200 bg-slate-100"
                    >
                        @if (! empty($item['image_url']))
                            <img
                                src="{{ $item['image_url'] }}"
                                alt=""
                                loading="lazy"
                                referrerpolicy="no-referrer"
                                class="tg-fact-main-image h-full min-h-52 w-full object-contain p-1"
                            >
                            <span class="tg-media-shade bg-gradient-to-t from-slate-950/70 via-slate-950/20 to-transparent"></span>
                        @else
                            <div @class([
                                'flex h-full min-h-52 w-full items-center justify-center p-6',
                                'bg-red-50' => $tone === 'danger',
                                'bg-amber-50' => $tone === 'warning',
                                'bg-emerald-50' => $tone === 'safe',
                                'bg-slate-100' => ! in_array($tone, ['danger', 'warning', 'safe'], true),
                            ])>
                                @if ($logoUrl)
                                    <div class="flex h-28 w-28 items-center justify-center rounded-[28px] border border-white/80 bg-white p-6 shadow-sm">
                                        <img src="{{ $logoUrl }}" alt="" loading="lazy" class="h-full w-full object-contain">
                                    </div>
                                @else
                                    <span class="h-20 w-20 rounded-[24px] border border-white/80 bg-white/80 shadow-sm"></span>
                                @endif
                            </div>
                        @endif

                        <span @class([
                            'tg-rating-banner',
                            'tg-rating-banner-danger' => $ratingTone === 'danger',
                            'tg-rating-banner-warning' => $ratingTone === 'warning',
                            'tg-rating-banner-safe' => $ratingTone === 'safe',
                            'tg-rating-banner-neutral' => ! in_array($ratingTone, ['danger', 'warning', 'safe'], true),
                        ])>
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $publisherName }} logo" loading="lazy" width="18" height="18" class="tg-rating-source-logo">
                            @endif
                            <span class="block text-sm font-black leading-none">
                                {{ $rating }}
                            </span>
                        </span>

                        <span class="tg-rating-reviewer">
                                @if ($logoUrl)
                                    <img src="{{ $logoUrl }}" alt="" loading="lazy" class="tg-reviewer-logo">
                                @endif
                                <span class="truncate">Reviewed by {{ $publisherName }}</span>
                        </span>
                    </a>

                    <div class="tg-mobile-fact-copy min-w-0">
                        <div class="tg-mobile-fact-labels flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="" loading="lazy" class="h-5 w-5 rounded-full object-contain">
                            @endif
                            <span>{{ $publisherName }}</span>
                            <span class="text-slate-300">/</span>
                            <span>{{ $item['date_label'] ?? 'date unavailable' }}</span>
                            <span @class([
                                'tg-rating-meta-chip',
                                'tg-rating-meta-chip-danger' => $ratingTone === 'danger',
                                'tg-rating-meta-chip-warning' => $ratingTone === 'warning',
                                'tg-rating-meta-chip-safe' => $ratingTone === 'safe',
                                'tg-rating-meta-chip-neutral' => ! in_array($ratingTone, ['danger', 'warning', 'safe'], true),
                            ])>
                                Rating: {{ $rating }}
                            </span>
                            @if (! empty($item['query']))
                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-[0.68rem] text-blue-700">
                                    {{ ($item['feed_source_type'] ?? 'topic') === 'publisher' ? 'Source' : 'Topic' }}: {{ $item['query'] }}
                                </span>
                            @endif
                        </div>

                        <h4 class="mt-3 text-xl font-semibold leading-snug text-slate-900 sm:text-2xl">
                            @if (! empty($item['id']))
                                <a href="{{ $detailUrl }}" class="transition hover:text-blue-700">
                                    {{ $item['headline'] }}
                                </a>
                            @else
                                {{ $item['headline'] }}
                            @endif
                        </h4>

                        <p class="mt-3 max-w-4xl text-sm leading-7 text-slate-600 sm:text-base">{{ $item['claim'] }}</p>

                        <div class="tg-mobile-fact-chips mt-4 flex flex-wrap gap-2 text-xs font-semibold text-slate-500">
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5">Claimant: {{ $item['claimant'] ?? 'Online claim' }}</span>
                            @if (! empty($item['host']))
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5">{{ $item['host'] }}</span>
                            @endif
                            @if (! empty($item['url']))
                                <a href="{{ ($publicReviewList ?? false) ? $detailUrl : $item['url'] }}" @unless($publicReviewList ?? false) target="_blank" rel="noopener noreferrer" @endunless class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-blue-700 transition hover:border-blue-200 hover:bg-blue-50">
                                    {{ ($publicReviewList ?? false) ? 'Read review' : 'Original source' }}
                                </a>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="tg-feed-pagination-bar">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="tg-feed-pagination-summary">
                    <span class="tg-feed-pagination-summary-icon" aria-hidden="true">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 4.5h10.5A2.25 2.25 0 0 1 19.5 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H6.75a2.25 2.25 0 0 1-2.25-2.25V6.75A2.25 2.25 0 0 1 6.75 4.5Z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9h7.5M8.25 12h7.5M8.25 15h4.5"></path>
                        </svg>
                    </span>
                    <span class="min-w-0">
                        Showing <strong>{{ $feedFirstItem }}–{{ $feedLastItem }}</strong> of <strong>{{ $feedTotal }}</strong>
                        {{ $feedLookbackDays > 0 ? 'verified reviews' : 'latest reviews' }}
                    </span>
                    <span class="tg-feed-page-status">Page {{ $feedCurrentPage }} of {{ $feedTotalPages }}</span>
                </div>

                @if ($feedTotalPages > 1)
                    <nav class="tg-feed-pagination" aria-label="Latest fact-check pagination">
                        @if ($feedCurrentPage > 1)
                            <a href="{{ $feedPageUrl($feedCurrentPage - 1) }}" class="tg-feed-page-button tg-feed-page-edge" rel="prev" aria-label="Previous page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"></path>
                                </svg>
                                <span>Previous</span>
                            </a>
                        @else
                            <span class="tg-feed-page-button tg-feed-page-edge is-disabled" aria-disabled="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15 6-6 6 6 6"></path>
                                </svg>
                                <span>Previous</span>
                            </span>
                        @endif

                        @php $previousFeedPage = null; @endphp
                        @foreach ($feedPageNumbers as $pageNumber)
                            @if ($previousFeedPage !== null && $pageNumber - $previousFeedPage > 1)
                                <span class="tg-feed-page-ellipsis" aria-hidden="true">•••</span>
                            @endif

                            @if ($pageNumber === $feedCurrentPage)
                                <span class="tg-feed-page-button is-current" aria-current="page" aria-label="Page {{ $pageNumber }}, current page">
                                    {{ $pageNumber }}
                                </span>
                            @else
                                <a href="{{ $feedPageUrl($pageNumber) }}" class="tg-feed-page-button" aria-label="Go to page {{ $pageNumber }}">
                                    {{ $pageNumber }}
                                </a>
                            @endif

                            @php $previousFeedPage = $pageNumber; @endphp
                        @endforeach

                        @if ($feedCurrentPage < $feedTotalPages)
                            <a href="{{ $feedPageUrl($feedCurrentPage + 1) }}" class="tg-feed-page-button tg-feed-page-edge" rel="next" aria-label="Next page">
                                <span>Next</span>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"></path>
                                </svg>
                            </a>
                        @else
                            <span class="tg-feed-page-button tg-feed-page-edge is-disabled" aria-disabled="true">
                                <span>Next</span>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9 6 6 6-6 6"></path>
                                </svg>
                            </span>
                        @endif
                    </nav>
                @endif
            </div>
        </div>
    @else
        <div class="m-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center sm:m-6">
            <p class="text-sm font-semibold text-slate-800">
                {{ ($feed['configured'] ?? false) ? ($feedLookbackDays > 0 ? 'No public claim reviews were returned from the '.$feedWindowLabel.'.' : 'No public claim reviews were returned for the current feed topics.') : 'Connect Google Fact Check API to enable the live fact-check wire.' }}
            </p>
            <p class="mt-2 text-sm text-slate-500">Feed topics: {{ $feed['query_label'] ?? 'Philippines, viral misinformation, fake news' }}</p>
        </div>
    @endif
</section>
