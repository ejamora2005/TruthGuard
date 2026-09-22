@extends('layouts.user')
@section('title', 'Fact-Check Source')
@section('page_title', 'Fact-Check Source')
@section('page_back_url', isset($reportDetection) ? route('detections.result', $reportDetection) : route('dashboard').'#news-watch')
@section('content')
    @php
        $safeUrl = fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true) ? $value : null;
        $articleUrl = $safeUrl($item['url'] ?? null);
        $originalUrl = $safeUrl($item['original_url'] ?? null);
        if ($originalUrl && $articleUrl && rtrim(\Illuminate\Support\Str::lower($originalUrl), '/') === rtrim(\Illuminate\Support\Str::lower($articleUrl), '/')) {
            $originalUrl = null;
        }
        $imageUrl = $safeUrl($item['image_url'] ?? null);
        $logoUrl = $safeUrl($item['logo_url'] ?? null);
        $rating = $item['source_rating'] ?? null;
        $claimant = $item['source_claimant'] ?? null;
        $claim = $item['full_claim'] ?? $item['claim'] ?? null;
        $headline = $item['full_headline'] ?? $item['headline'] ?? '';
        $publisher = $item['publisher'] ?? null;
        $domain = $item['source_domain'] ?? $item['host'] ?? null;
        $originalDomain = $originalUrl ? parse_url($originalUrl, PHP_URL_HOST) : null;
        $date = ! empty($item['timestamp']) ? ($item['date_label'] ?? null) : null;
        $tone = $rating ? \App\Services\Detections\FactCheckRatingNormalizer::toneFor($rating) : 'neutral';
        $articleHost = $articleUrl ? parse_url($articleUrl, PHP_URL_HOST) : null;
        $relatedArticleCards = collect();

        if ($articleUrl) {
            $relatedArticleCards->push([
                'label' => 'Claim wording source',
                'title' => $headline,
                'summary' => $claim,
                'publisher' => $publisher,
                'date' => $date,
                'rating' => $rating,
                'url' => $articleUrl,
                'host' => $articleHost,
                'image_url' => $imageUrl,
            ]);
        }

        if ($originalUrl) {
            $relatedArticleCards->push([
                'label' => 'Original post',
                'title' => 'Original post connected to this claim',
                'summary' => $claim,
                'publisher' => $originalDomain,
                'date' => null,
                'rating' => null,
                'url' => $originalUrl,
                'host' => $originalDomain,
                'image_url' => null,
            ]);
        }

        foreach (($relatedFactChecks ?? []) as $relatedItem) {
            if (! is_array($relatedItem)) {
                continue;
            }

            $relatedOriginalUrl = $safeUrl($relatedItem['original_url'] ?? null);
            $relatedFactCheckUrl = $safeUrl($relatedItem['url'] ?? null);

            if ($relatedOriginalUrl && $relatedFactCheckUrl && rtrim(\Illuminate\Support\Str::lower($relatedOriginalUrl), '/') === rtrim(\Illuminate\Support\Str::lower($relatedFactCheckUrl), '/')) {
                $relatedOriginalUrl = null;
            }

            $relatedUrl = $relatedOriginalUrl ?: $relatedFactCheckUrl;

            if (! $relatedUrl) {
                continue;
            }

            $relatedArticleCards->push([
                'label' => $relatedOriginalUrl ? 'Original post' : 'Related source',
                'title' => $relatedItem['full_headline'] ?? $relatedItem['headline'] ?? 'Related source',
                'summary' => $relatedItem['full_claim'] ?? $relatedItem['claim'] ?? null,
                'publisher' => $relatedItem['publisher'] ?? null,
                'date' => ! empty($relatedItem['timestamp']) ? ($relatedItem['date_label'] ?? null) : null,
                'rating' => $relatedItem['source_rating'] ?? $relatedItem['rating'] ?? null,
                'url' => $relatedUrl,
                'host' => parse_url($relatedUrl, PHP_URL_HOST),
                'image_url' => $safeUrl($relatedItem['image_url'] ?? null),
            ]);
        }

        $relatedArticleCards = $relatedArticleCards
            ->filter(fn (array $card): bool => filled($card['url'] ?? null))
            ->unique(fn (array $card): string => rtrim(\Illuminate\Support\Str::lower((string) $card['url']), '/'))
            ->values();
        $glanceItems = collect([
            filled($claim) ? 'Reviewed claim: '.\Illuminate\Support\Str::limit($claim, 210) : null,
            filled($rating) ? 'Source rating: '.$rating.'.' : null,
            filled($publisher) ? 'Fact-check provider: '.$publisher.($date ? ' on '.$date.'.' : '.') : null,
            $originalUrl
                ? 'A direct original post link is available'.($originalDomain ? ' from '.$originalDomain : '').'.'
                : ($articleUrl ? 'No direct original-post URL was provided by the feed; use the fact-check source for the full context.' : null),
        ])->filter()->values();
    @endphp
    <style>
        .tg-public-article { max-width: 1050px; margin: 0 auto; color: #0f172a; overflow-wrap: anywhere; }
        .tg-public-article * { letter-spacing: 0; }
        .tg-public-article nav { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem; font-size: .875rem; color: #1d4ed8; }
        .tg-public-article article { background: #fff; padding: 2rem; border-top: 3px solid #059669; }
        .tg-public-article header { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; color: #64748b; font-size: .8125rem; }
        .tg-public-article figure { max-width: 720px; margin: 1.35rem auto; }
        .tg-public-article figure img { display: block; width: 100%; height: auto; max-height: 360px; object-fit: contain; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .tg-public-article figcaption { margin-top: .5rem; color: #64748b; font-size: .75rem; }
        .tg-public-article h1 { margin: 1.5rem 0 1rem; font-size: 2rem; line-height: 1.3; font-weight: 700; }
        .tg-public-article h2 { font-size: 1rem; font-weight: 700; margin-bottom: .75rem; }
        .tg-public-article section { margin-top: 1.75rem; }
        .tg-public-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.25rem; padding: 1.25rem 0; border-top: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .tg-public-meta dt { font-size: .75rem; color: #64748b; margin-bottom: .4rem; }
        .tg-public-meta dd { font-size: .875rem; font-weight: 600; }
        .tg-public-rating { display: inline-flex; padding: .5rem .85rem; border-radius: 6px; font-weight: 700; background: #f1f5f9; color: #475569; }
        .tg-public-rating-danger { background: #fef2f2; color: #b91c1c; }
        .tg-public-rating-warning { background: #fffbeb; color: #92400e; }
        .tg-public-rating-safe { background: #ecfdf5; color: #047857; }
        .tg-public-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.25rem; }
        .tg-public-actions a { display: inline-flex; align-items: center; min-height: 44px; padding: .65rem 1rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: .875rem; font-weight: 600; }
        .tg-public-actions .tg-public-primary { background: #047857; border-color: #047857; color: #fff; }
        .tg-public-article a:focus-visible { outline: 2px solid #2563eb; outline-offset: 4px; }
        .tg-at-glance { margin-top: 1.25rem; border: 1px solid #bfdbfe; border-radius: 8px; background: linear-gradient(135deg, #eff6ff 0%, #f8fbff 100%); box-shadow: 0 14px 34px rgba(37, 99, 235, .08); }
        .tg-at-glance summary { display: flex; align-items: center; gap: .85rem; padding: 1rem 1.15rem; cursor: pointer; list-style: none; }
        .tg-at-glance summary::-webkit-details-marker { display: none; }
        .tg-at-glance-title { display: flex; min-width: 0; flex: 1; align-items: center; gap: .75rem; font-size: .9rem; font-weight: 800; text-transform: uppercase; color: #0f172a; }
        .tg-at-glance-spark { display: inline-grid; width: 2.25rem; height: 2.25rem; flex: 0 0 auto; place-items: center; border-radius: 7px; background: #fff; color: #2563eb; box-shadow: inset 0 0 0 1px #dbeafe; }
        .tg-at-glance-status { display: inline-flex; align-items: center; border-radius: 999px; background: #fff; padding: .35rem .65rem; font-size: .72rem; font-weight: 800; color: #2563eb; box-shadow: inset 0 0 0 1px #dbeafe; }
        .tg-at-glance-chevron { width: 1rem; height: 1rem; color: #2563eb; transition: transform .18s ease; }
        .tg-at-glance[open] .tg-at-glance-chevron { transform: rotate(180deg); }
        .tg-at-glance ul { margin: 0; padding: 0 1.25rem 1.25rem 4rem; color: #334155; }
        .tg-at-glance li { padding-left: .25rem; font-size: .95rem; line-height: 1.75; }
        .tg-at-glance li + li { margin-top: .55rem; }
        .tg-claim-details, .tg-related-articles { margin-top: 1.25rem; border: 1px solid #dbe4f0; border-radius: 8px; background: #fff; overflow: hidden; box-shadow: 0 16px 36px rgba(15, 23, 42, .06); }
        .tg-claim-details summary, .tg-related-articles summary { display: flex; align-items: center; gap: .85rem; padding: 1rem 1.15rem; cursor: pointer; list-style: none; background: #f8fafc; }
        .tg-claim-details summary::-webkit-details-marker, .tg-related-articles summary::-webkit-details-marker { display: none; }
        .tg-dropdown-icon { display: inline-grid; width: 2.35rem; height: 2.35rem; flex: 0 0 auto; place-items: center; border-radius: 7px; background: #e0f2fe; color: #075985; }
        .tg-dropdown-heading { min-width: 0; flex: 1; }
        .tg-dropdown-heading strong { display: block; font-size: .96rem; font-weight: 800; color: #0f172a; }
        .tg-dropdown-heading span { display: block; margin-top: .2rem; font-size: .78rem; font-weight: 700; text-transform: uppercase; color: #64748b; }
        .tg-dropdown-count { display: inline-flex; align-items: center; border-radius: 999px; background: #e0f2fe; padding: .35rem .65rem; font-size: .72rem; font-weight: 800; color: #075985; }
        .tg-dropdown-chevron { width: 1rem; height: 1rem; color: #475569; transition: transform .18s ease; }
        .tg-claim-details[open] .tg-dropdown-chevron, .tg-related-articles[open] .tg-dropdown-chevron { transform: rotate(180deg); }
        .tg-claim-body { padding: 1.25rem; border-top: 1px solid #e2e8f0; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); }
        .tg-claim-body p { max-width: 860px; font-size: 1rem; line-height: 1.9; color: #334155; white-space: pre-line; }
        .tg-related-list { display: grid; gap: .85rem; padding: 1rem; border-top: 1px solid #e2e8f0; background: #fff; }
        .tg-related-card { display: grid; grid-template-columns: 116px minmax(0, 1fr); gap: .9rem; padding: .75rem; border: 1px solid #e2e8f0; border-radius: 8px; color: inherit; transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
        .tg-related-card:hover { border-color: #93c5fd; box-shadow: 0 14px 30px rgba(37, 99, 235, .1); transform: translateY(-1px); }
        .tg-related-thumb { display: grid; min-height: 82px; place-items: center; overflow: hidden; border-radius: 7px; background: #f1f5f9; color: #64748b; }
        .tg-related-thumb img { width: 100%; height: 100%; min-height: 82px; object-fit: cover; }
        .tg-related-meta { display: flex; flex-wrap: wrap; gap: .45rem; align-items: center; font-size: .72rem; font-weight: 800; text-transform: uppercase; color: #64748b; }
        .tg-related-label { border-radius: 999px; background: #ecfdf5; padding: .25rem .55rem; color: #047857; }
        .tg-related-rating { border-radius: 999px; background: #fff7ed; padding: .25rem .55rem; color: #9a3412; }
        .tg-related-title { margin-top: .45rem; font-size: .98rem; font-weight: 800; line-height: 1.35; color: #0f172a; }
        .tg-related-summary { margin-top: .35rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: .86rem; line-height: 1.55; color: #475569; }
        .tg-related-host { margin-top: .55rem; font-size: .78rem; font-weight: 700; color: #2563eb; }
        @media (max-width: 640px) { .tg-public-article article { padding: 1.25rem; } .tg-public-article figure { max-width: 100%; } .tg-public-article figure img { max-height: 280px; } .tg-public-article h1 { font-size: 1.5rem; } .tg-public-meta { grid-template-columns: minmax(0, 1fr); } .tg-at-glance summary, .tg-claim-details summary, .tg-related-articles summary { align-items: flex-start; } .tg-at-glance-title { align-items: flex-start; } .tg-at-glance-status, .tg-dropdown-count { display: none; } .tg-at-glance ul { padding-left: 2.25rem; } .tg-related-card { grid-template-columns: minmax(0, 1fr); } .tg-related-thumb { min-height: 150px; } }
    </style>
    <div class="tg-public-article">
        <article>
            <header>
                @if ($logoUrl)<img src="{{ $logoUrl }}" alt="" width="24" height="24">@endif
                @if ($publisher)<strong>{{ $publisher }}</strong>@endif
                @if ($date)<span>{{ $date }}</span>@endif
                @if (! empty($item['query']))<span>{{ str_starts_with($item['feed_source_type'] ?? '', 'publisher') ? 'Source' : 'Topic' }}: {{ $item['query'] }}</span>@endif
            </header>
            @if ($imageUrl)
                <figure>
                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer" aria-label="View full fact-check image"><img src="{{ $imageUrl }}" alt="{{ $headline }}" referrerpolicy="no-referrer"></a>
                    <figcaption>Image from {{ $publisher ?: $domain }}. <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">View full image</a></figcaption>
                </figure>
            @endif
            <h1>{{ $headline }}</h1>
            @if (filled($rating))<span class="tg-public-rating tg-public-rating-{{ $tone }}">Source rating: {{ $rating }}</span>@endif
            @if ($glanceItems->isNotEmpty())
                <details class="tg-at-glance" open>
                    <summary>
                        <span class="tg-at-glance-title">
                            <span class="tg-at-glance-spark" aria-hidden="true">
                                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3l1.8 5.4L19 10.2l-5.2 1.8L12 17.5 10.2 12 5 10.2l5.2-1.8L12 3Z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15Z"></path>
                                </svg>
                            </span>
                            At a glance
                        </span>
                        <span class="tg-at-glance-status">{{ $glanceItems->count() }} key points</span>
                        <svg class="tg-at-glance-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"></path>
                        </svg>
                    </summary>
                    <ul>
                        @foreach ($glanceItems as $glanceItem)
                            <li>{{ $glanceItem }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
            @if (filled($claim))
                <details class="tg-claim-details" open>
                    <summary>
                        <span class="tg-dropdown-icon" aria-hidden="true">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7h8M8 11h8M8 15h5"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14v16H5z"></path>
                            </svg>
                        </span>
                        <span class="tg-dropdown-heading">
                            <strong>Relevant Claim</strong>
                            <span>Claim reviewed by the source</span>
                        </span>
                        <span class="tg-dropdown-count">Claim text</span>
                        <svg class="tg-dropdown-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"></path>
                        </svg>
                    </summary>
                    <div class="tg-claim-body">
                        <p>{{ $claim }}</p>
                    </div>
                </details>
            @endif
            @if ($relatedArticleCards->isNotEmpty())
                <details class="tg-related-articles" open>
                    <summary>
                        <span class="tg-dropdown-icon" aria-hidden="true">
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9M7.5 12h6M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z"></path>
                            </svg>
                        </span>
                        <span class="tg-dropdown-heading">
                            <strong>Claim Sources &amp; References</strong>
                            <span>Open the sources used to present or verify this wording</span>
                        </span>
                        <span class="tg-dropdown-count">{{ $relatedArticleCards->count() }} links</span>
                        <svg class="tg-dropdown-chevron" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"></path>
                        </svg>
                    </summary>
                    <div class="tg-related-list">
                        @foreach ($relatedArticleCards as $relatedArticle)
                            <a href="{{ $relatedArticle['url'] }}" target="_blank" rel="noopener noreferrer" class="tg-related-card">
                                <span class="tg-related-thumb" aria-hidden="true">
                                    @if (! empty($relatedArticle['image_url']))
                                        <img src="{{ $relatedArticle['image_url'] }}" alt="" referrerpolicy="no-referrer">
                                    @else
                                        <svg width="30" height="30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-8.5A1.75 1.75 0 0 0 17.75 4H6.25A1.75 1.75 0 0 0 4.5 5.75v12.5A1.75 1.75 0 0 0 6.25 20h6.5"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 8h8M8 11.5h5M16 20l4-4m0 0h-3.25M20 16v3.25"></path>
                                        </svg>
                                    @endif
                                </span>
                                <span class="min-w-0">
                                    <span class="tg-related-meta">
                                        <span class="tg-related-label">{{ $relatedArticle['label'] }}</span>
                                        @if (! empty($relatedArticle['publisher']))<span>{{ $relatedArticle['publisher'] }}</span>@endif
                                        @if (! empty($relatedArticle['date']))<span>{{ $relatedArticle['date'] }}</span>@endif
                                        @if (! empty($relatedArticle['rating']))<span class="tg-related-rating">{{ $relatedArticle['rating'] }}</span>@endif
                                    </span>
                                    <span class="tg-related-title">{{ $relatedArticle['title'] }}</span>
                                    @if (! empty($relatedArticle['summary']))
                                        <span class="tg-related-summary">{{ \Illuminate\Support\Str::limit($relatedArticle['summary'], 180) }}</span>
                                    @endif
                                    @if (! empty($relatedArticle['host']))
                                        <span class="tg-related-host">{{ $relatedArticle['host'] }} &nearr;</span>
                                    @endif
                                </span>
                            </a>
                        @endforeach
                    </div>
                </details>
            @endif
            <section>
                <h2>Fact-Check Details</h2>
                <dl class="tg-public-meta">
                    @foreach (['Claimant' => $claimant, 'Original post domain' => $originalDomain, 'Publisher domain' => $domain, 'Fact-check provider' => $publisher, 'Published date' => $date, 'Source rating' => $rating, 'Original post' => $originalUrl, 'Fact-check source' => $articleUrl] as $label => $value)
                        @if (filled($value))<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endif
                    @endforeach
                </dl>
            </section>
            <section>
                <h2>{{ $originalUrl ? 'Original Post' : 'Fact-Check Source' }}</h2>
                @if ($originalUrl)
                    <p class="text-sm leading-7 text-slate-600">{{ $originalDomain ?: 'Original source' }}</p>
                    <p class="mt-2 text-sm leading-7 text-slate-500">Open the original post connected to this claim.</p>
                @else
                    <p class="text-sm leading-7 text-slate-600">{{ $publisher }}@if ($domain) &middot; {{ $domain }}@endif</p>
                    @if ($articleUrl)<p class="mt-2 text-sm leading-7 text-slate-500">The feed did not include an original-post URL for this item. Open the publisher's fact check for its evidence and full explanation.</p>@endif
                @endif
                <div class="tg-public-actions">
                    @if ($originalUrl)<a href="{{ $originalUrl }}" target="_blank" rel="noopener noreferrer" class="tg-public-primary">View Original Post &nearr;</a>@endif
                    @if ($articleUrl)<a href="{{ $articleUrl }}" target="_blank" rel="noopener noreferrer" @class(['tg-public-primary' => ! $originalUrl])>{{ $originalUrl ? 'Read Fact Check' : 'Read Fact Check Source' }} &nearr;</a>@endif
                    <a href="{{ route('detections.create') }}">Verify related claim</a>
                </div>
            </section>
        </article>
    </div>
@endsection
