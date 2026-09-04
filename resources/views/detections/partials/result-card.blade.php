@php
    $ratingNormalizer = \App\Services\Detections\FactCheckRatingNormalizer::class;
    $verdictPresenter = \App\Services\Detections\DetectionVerdictPresenter::class;
    $verdictPresentation = $verdictPresenter::forDetection($selectedDetection);
    $categoryKey = (string) ($verdictPresentation['category_key'] ?? 'needs_review');
    $verdict = (string) ($selectedDetection->verdict ?: 'review');
    $riskScore = max(0, min(100, (int) $selectedDetection->fake_score));
    $confidenceScore = $verdictPresenter::confidencePercent($selectedDetection);

    $tone = match ($categoryKey) {
        'confirmed', 'low_risk' => [
            'label' => $verdictPresentation['short_label'] ?? 'LOW-RISK CLAIM',
            'risk' => $verdictPresentation['risk_label'] ?? 'LOW RISK',
            'summary' => $verdictPresentation['summary_lead'] ?? 'The available evidence suggests a lower-risk result.',
            'accent' => '#059669',
            'soft' => '#ecfdf5',
            'text' => 'text-emerald-700',
            'border' => 'border-emerald-200',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'bar' => 'bg-emerald-600',
            'notice' => 'bg-emerald-50 text-emerald-900 border-emerald-200',
            'button' => 'bg-emerald-700 hover:bg-emerald-800',
        ],
        'ai_generated' => [
            'label' => $verdictPresentation['short_label'] ?? 'AI-GENERATED MEDIA',
            'risk' => $verdictPresentation['risk_label'] ?? 'SYNTHETIC MEDIA',
            'summary' => $verdictPresentation['summary_lead'] ?? 'Synthetic media indicators were found.',
            'accent' => '#7c3aed',
            'soft' => '#f5f3ff',
            'text' => 'text-violet-700',
            'border' => 'border-violet-200',
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'bar' => 'bg-violet-600',
            'notice' => 'bg-violet-50 text-violet-950 border-violet-200',
            'button' => 'bg-violet-700 hover:bg-violet-800',
        ],
        'manipulated' => [
            'label' => $verdictPresentation['short_label'] ?? 'MANIPULATED MEDIA',
            'risk' => $verdictPresentation['risk_label'] ?? 'ALTERED CONTENT',
            'summary' => $verdictPresentation['summary_lead'] ?? 'Manipulation indicators were found.',
            'accent' => '#ea580c',
            'soft' => '#fff7ed',
            'text' => 'text-orange-700',
            'border' => 'border-orange-200',
            'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
            'bar' => 'bg-orange-600',
            'notice' => 'bg-orange-50 text-orange-950 border-orange-200',
            'button' => 'bg-orange-700 hover:bg-orange-800',
        ],
        'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => [
            'label' => $verdictPresentation['short_label'] ?? 'NEEDS SOURCE REVIEW',
            'risk' => $verdictPresentation['risk_label'] ?? 'NEEDS REVIEW',
            'summary' => $verdictPresentation['summary_lead'] ?? 'Review this content before sharing.',
            'accent' => '#d97706',
            'soft' => '#fffbeb',
            'text' => 'text-amber-700',
            'border' => 'border-amber-200',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'bar' => 'bg-amber-500',
            'notice' => 'bg-amber-50 text-amber-950 border-amber-200',
            'button' => 'bg-amber-700 hover:bg-amber-800',
        ],
        default => [
            'label' => $verdictPresentation['short_label'] ?? 'LIKELY MISLEADING',
            'risk' => $verdictPresentation['risk_label'] ?? 'HIGH RISK',
            'summary' => $verdictPresentation['summary_lead'] ?? 'High-risk indicators were found.',
            'accent' => '#dc2626',
            'soft' => '#fef2f2',
            'text' => 'text-red-700',
            'border' => 'border-red-200',
            'badge' => 'bg-red-50 text-red-700 ring-red-200',
            'bar' => 'bg-red-600',
            'notice' => 'bg-red-50 text-red-950 border-red-200',
            'button' => 'bg-red-700 hover:bg-red-800',
        ],
    };

    $selectedMediaPath = strtolower((string) $selectedDetection->media_path);
    $selectedIsDocument = $selectedDetection->media_type === 'document'
        || \Illuminate\Support\Str::endsWith($selectedMediaPath, '.pdf');
    $mediaUrl = $selectedDetection->media_url;
    $sourceHost = $selectedDetection->source_url ? parse_url($selectedDetection->source_url, PHP_URL_HOST) : null;
    $sourceLabel = $selectedDetection->platform
        ? Str::headline((string) $selectedDetection->platform)
        : ($sourceHost ? preg_replace('/^www\./', '', $sourceHost) : 'No public source link');
    $contentType = Str::headline($selectedDetection->media_type ?: 'Unknown');
    $claimText = trim((string) ($selectedDetection->caption_text ?: $selectedDetection->notes ?: ''));
    $analysisText = trim((string) (
        $selectedDetection->explanation_summary
        ?: $selectedDetection->analysis_summary
        ?: 'TruthGuard finished the analysis, but no explanation summary was recorded for this case.'
    ));
    $recommendation = trim((string) ($selectedDetection->recommendation ?: match ($verdict) {
        'fake' => 'Do not share this content until a trusted source confirms it.',
        'review' => 'Review the linked sources before sharing this content.',
        default => 'This result is lower risk, but still check the original source before reuse.',
    }));

    $signalGroups = is_array($selectedDetection->signals ?? null) ? $selectedDetection->signals : [];
    $aiBasis = collect($signalGroups['ai_basis'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')
        ->take(3)
        ->values();
    $aiLimitations = collect($signalGroups['ai_limitations'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')
        ->take(2)
        ->values();
    $flatSignals = collect($signalGroups)
        ->except(['ai_basis', 'ai_limitations', 'openai_usage'])
        ->flatten(1)
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->sortByDesc(fn (array $signal): int => (int) ($signal['weight'] ?? 0))
        ->unique('label')
        ->take(5)
        ->values();

    $sources = collect($selectedDetection->verification_sources ?? [])
        ->filter(fn ($source): bool => is_array($source) && filled($source['url'] ?? null))
        ->sortBy(fn (array $source): int => match ($source['source_type'] ?? 'reference') {
            'fact_check' => 1,
            'official', 'weather' => 2,
            'news' => 3,
            'source_trace' => 4,
            'social_context' => 5,
            default => 6,
        })
        ->values();
    $primarySources = $sources->reject(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')->take(5)->values();
    $socialSources = $sources->filter(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')->take(4)->values();
    $trustedSourceCount = $sources->filter(fn (array $source): bool => in_array(($source['source_type'] ?? null), ['fact_check', 'official', 'weather'], true))->count();
    $sourceCount = $sources->count();
    $topSource = $sources->first();

    $sourceTypeLabel = function (array $source): string {
        return match ($source['source_type'] ?? 'reference') {
            'fact_check' => 'Fact-check',
            'official' => 'Official',
            'weather' => 'Weather',
            'news' => 'News',
            'source_trace' => 'Original source',
            'social_context' => 'Social context',
            default => Str::headline((string) ($source['label'] ?? 'Reference')),
        };
    };

    $sourceBadgeClass = function (array $source) use ($ratingNormalizer): string {
        $rating = $ratingNormalizer::normalize(
            (string) ($source['rating'] ?? ''),
            (string) ($source['summary'] ?? ''),
            (string) ($source['purpose'] ?? ''),
            (string) ($source['status'] ?? ''),
        );

        return match ($ratingNormalizer::toneFor($rating)) {
            'danger' => 'bg-red-50 text-red-700 ring-red-200',
            'safe' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    };

    $sourceBadgeText = function (array $source) use ($ratingNormalizer, $sourceTypeLabel): string {
        $rating = $ratingNormalizer::normalize(
            (string) ($source['rating'] ?? ''),
            (string) ($source['summary'] ?? ''),
            (string) ($source['purpose'] ?? ''),
            (string) ($source['status'] ?? ''),
        );

        return $rating !== '' && $rating !== $ratingNormalizer::REVIEWED
            ? $rating
            : $sourceTypeLabel($source);
    };

    $relatedPosts = collect($selectedDetection->scrapedPosts ?? [])->take(2)->values();
    $latestFactCheckItems = data_get($latestFactChecks ?? [], 'items', $latestFactChecks ?? []);
    $recentFactChecks = collect($latestFactCheckItems)
        ->filter(fn ($item): bool => is_array($item))
        ->take(3)
        ->values();
@endphp

@once
    <style>
        .tg-result-card {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.06);
        }

        .tg-result-panel {
            border: 1px solid rgba(226, 232, 240, 0.92);
            border-radius: 8px;
            background: rgba(248, 250, 252, 0.76);
        }

        .tg-result-icon {
            display: inline-flex;
            height: 2.35rem;
            width: 2.35rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid rgba(226, 232, 240, 0.92);
            background: #ffffff;
        }

        .tg-result-media {
            min-height: 15rem;
            max-height: 24rem;
            border-radius: 8px;
            background: #f8fafc;
        }

        .tg-result-source-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1rem;
            align-items: start;
        }

        @media (max-width: 640px) {
            .tg-result-source-row {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
@endonce

<section
    id="latest-detection-result"
    data-tour="result-reports"
    class="truthguard-scroll-target space-y-4"
    style="--tg-result-accent: {{ $tone['accent'] }}; --tg-result-soft: {{ $tone['soft'] }};"
>
    <article class="tg-result-card overflow-hidden">
        <div class="grid gap-0 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="p-5 sm:p-6 lg:p-7" style="background: linear-gradient(180deg, var(--tg-result-soft), #ffffff 76%);">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-1.5 text-xs font-bold uppercase text-slate-700 ring-1 ring-slate-200">
                        <span class="h-2 w-2 rounded-full" style="background: {{ $tone['accent'] }}"></span>
                        TruthGuard Report
                    </span>

                    <div class="flex flex-wrap gap-2">
                        @if (! auth()->user()?->isAdmin())
                            <a href="{{ route('history') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12a8.25 8.25 0 1 0 2.42-5.83" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5v4.25H8" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 7.75v4.5l3 1.75" />
                                </svg>
                                History
                            </a>
                        @endif
                        <a href="{{ route('detections.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
                            </svg>
                            New Scan
                        </a>
                    </div>
                </div>

                <div class="mt-6 max-w-3xl">
                    <p class="text-sm font-semibold uppercase {{ $tone['text'] }}">{{ $tone['risk'] }}</p>
                    <h2 class="mt-2 text-3xl font-black leading-tight text-slate-950 sm:text-4xl">
                        {{ $tone['label'] }}
                    </h2>
                    <p class="mt-3 max-w-2xl text-base leading-7 text-slate-700">
                        {{ $tone['summary'] }}
                    </p>
                </div>

                <div class="mt-5 border-l-4 p-4 {{ $tone['notice'] }}" style="border-left-color: {{ $tone['accent'] }};">
                    <p class="text-sm font-black uppercase">Verdict response</p>
                    <p class="mt-2 text-base leading-8">{{ $analysisText }}</p>
                </div>
            </div>

            <aside class="border-t border-slate-200 bg-white p-5 sm:p-6 lg:border-l lg:border-t-0">
                <div class="space-y-5">
                    <div>
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-slate-500">Verdict confidence</p>
                                <p class="mt-1 text-4xl font-black {{ $tone['text'] }}">{{ $confidenceScore }}%</p>
                            </div>
                            <span class="rounded-lg px-3 py-1.5 text-xs font-black uppercase ring-1 {{ $tone['badge'] }}">
                                {{ $tone['label'] }}
                            </span>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-lg bg-slate-100">
                            <div class="h-full {{ $tone['bar'] }}" style="width: {{ $confidenceScore }}%;"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="tg-result-panel p-3">
                            <p class="text-xs font-semibold text-slate-500">Risk score</p>
                            <p class="mt-1 text-xl font-black text-slate-950">{{ $riskScore }}%</p>
                        </div>
                        <div class="tg-result-panel p-3">
                            <p class="text-xs font-semibold text-slate-500">Sources</p>
                            <p class="mt-1 text-xl font-black text-slate-950">{{ $sourceCount }}</p>
                        </div>
                        <div class="tg-result-panel p-3">
                            <p class="text-xs font-semibold text-slate-500">Trusted checks</p>
                            <p class="mt-1 text-xl font-black text-slate-950">{{ $trustedSourceCount }}</p>
                        </div>
                        <div class="tg-result-panel p-3">
                            <p class="text-xs font-semibold text-slate-500">Scanned</p>
                            <p class="mt-1 truncate text-sm font-black text-slate-950">{{ $selectedDetection->analyzed_at?->format('M d, h:i A') ?? 'Pending' }}</p>
                        </div>
                    </div>

                    @if (is_array($topSource))
                        <a href="{{ $topSource['url'] }}" target="_blank" rel="noreferrer" class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-bold text-white transition {{ $tone['button'] }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                            </svg>
                            Open strongest source
                        </a>
                    @endif
                </div>
            </aside>
        </div>
    </article>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_25rem] xl:items-start">
        <div class="space-y-4">
            <article class="tg-result-card p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="tg-result-icon text-blue-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <rect x="4" y="5" width="16" height="14" rx="2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-black text-slate-950">Submitted Content</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-500">
                            {{ $contentType }} from {{ $sourceLabel }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 overflow-hidden border border-slate-200 tg-result-media">
                    @if ($selectedDetection->media_type === 'image' && $mediaUrl)
                        <img src="{{ $mediaUrl }}" alt="Analyzed media" class="h-full max-h-96 w-full object-contain">
                    @elseif ($selectedDetection->media_type === 'video' && $mediaUrl)
                        <video controls preload="metadata" class="h-full max-h-96 w-full bg-slate-950 object-contain">
                            <source src="{{ $mediaUrl }}">
                        </video>
                    @elseif ($selectedIsDocument && $mediaUrl)
                        <div class="flex min-h-60 flex-col items-center justify-center p-6 text-center">
                            <p class="font-bold text-slate-900">Document evidence attached</p>
                            <a href="{{ $mediaUrl }}" target="_blank" rel="noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-800">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                                </svg>
                                Open PDF
                            </a>
                        </div>
                    @else
                        <div class="flex min-h-60 flex-col justify-center p-6">
                            <p class="text-sm font-black uppercase text-slate-500">Text or link claim</p>
                            <p class="mt-2 text-base leading-7 text-slate-800">
                                {{ $claimText !== '' ? $claimText : ($selectedDetection->source_url ?: 'No media preview was attached.') }}
                            </p>
                        </div>
                    @endif
                </div>

                @if ($claimText !== '' || $selectedDetection->source_url)
                    <div class="mt-5 grid gap-3 md:grid-cols-2">
                        @if ($claimText !== '')
                            <div class="tg-result-panel p-4">
                                <p class="text-xs font-bold uppercase text-slate-500">Claim or caption</p>
                                <p class="mt-2 text-sm leading-6 text-slate-700">{{ $claimText }}</p>
                            </div>
                        @endif
                        @if ($selectedDetection->source_url)
                            <div class="tg-result-panel p-4">
                                <p class="text-xs font-bold uppercase text-slate-500">Source link</p>
                                <a href="{{ $selectedDetection->source_url }}" target="_blank" rel="noreferrer" class="mt-2 block break-words text-sm font-semibold leading-6 text-blue-700 hover:text-blue-900">
                                    {{ $selectedDetection->source_url }}
                                </a>
                            </div>
                        @endif
                    </div>
                @endif
            </article>

            <article class="tg-result-card p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="tg-result-icon {{ $tone['text'] }}">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.25 5.5 6v5.2c0 4.2 2.7 7.5 6.5 9 3.8-1.5 6.5-4.8 6.5-9V6L12 3.25Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.8 12.2 11 14.4l4.4-4.8" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-black text-slate-950">Recommendation</h3>
                        <p class="mt-2 text-base leading-8 text-slate-700">{{ $recommendation }}</p>
                    </div>
                </div>
            </article>
        </div>

        <aside class="space-y-4">
            <article class="tg-result-card p-5 sm:p-6">
                <div class="flex items-start gap-3">
                    <span class="tg-result-icon text-slate-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3.75 8.25 14.3a1.45 1.45 0 0 1-1.25 2.2H5a1.45 1.45 0 0 1-1.25-2.2L12 3.75Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 16.5h.01" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-lg font-black text-slate-950">Key Signals</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Main reasons behind the result.</p>
                    </div>
                </div>

                <div class="mt-5 space-y-3">
                    @forelse ($flatSignals as $signal)
                        @php
                            $weight = max(0, min(100, (int) ($signal['weight'] ?? 0)));
                            $signalClass = $weight >= 70
                                ? 'bg-red-50 text-red-700'
                                : ($weight >= 40 ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-700');
                        @endphp
                        <div class="flex gap-3">
                            <span class="mt-0.5 inline-flex h-7 w-9 shrink-0 items-center justify-center rounded-lg text-xs font-black {{ $signalClass }}">
                                {{ $weight }}
                            </span>
                            <p class="text-sm leading-6 text-slate-700">{{ $signal['label'] }}</p>
                        </div>
                    @empty
                        <p class="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                            TruthGuard completed the scan and generated a verdict from the available source and content signals.
                        </p>
                    @endforelse
                </div>

                @if ($aiBasis->isNotEmpty() || $aiLimitations->isNotEmpty())
                    <div class="mt-5 border-t border-slate-200 pt-5">
                        @if ($aiBasis->isNotEmpty())
                            <p class="text-xs font-black uppercase text-slate-500">Evidence used</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($aiBasis as $basis)
                                    <li class="flex gap-2 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600"></span>
                                        <span>{{ $basis['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($aiLimitations->isNotEmpty())
                            <p class="mt-4 text-xs font-black uppercase text-slate-500">Limits</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($aiLimitations as $limitation)
                                    <li class="flex gap-2 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        <span>{{ $limitation['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif
            </article>
        </aside>
    </div>

    <article class="tg-result-card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <span class="tg-result-icon text-blue-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.7 10.3a3.45 3.45 0 0 0-4.9 0l-3.25 3.25a3.45 3.45 0 0 0 4.9 4.9l1.05-1.05" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.3 13.7a3.45 3.45 0 0 0 4.9 0l3.25-3.25a3.45 3.45 0 0 0-4.9-4.9L12.5 6.6" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-lg font-black text-slate-950">Sources Checked</h3>
                    <p class="mt-1 text-sm leading-6 text-slate-500">
                        Trusted and related sources used to support the final result.
                    </p>
                </div>
            </div>
            <span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-black uppercase text-slate-700">
                {{ $sourceCount }} total
            </span>
        </div>

        <div class="mt-5 divide-y divide-slate-200">
            @forelse ($primarySources as $source)
                @php
                    $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                    $name = $source['name'] ?? $linkHost;
                    $reason = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Related verification source for this result.'));
                    $date = trim((string) ($source['published_at'] ?? $source['date'] ?? ''));
                @endphp
                <div class="tg-result-source-row py-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="min-w-0 break-words text-sm font-black text-slate-950">{{ $name }}</p>
                            <span class="rounded-lg px-2.5 py-1 text-xs font-bold ring-1 {{ $sourceBadgeClass($source) }}">
                                {{ Str::limit($sourceBadgeText($source), 32) }}
                            </span>
                        </div>
                        <p class="mt-1 break-words text-xs font-semibold text-slate-500">{{ preg_replace('/^www\./', '', (string) $linkHost) }}{{ $date !== '' ? ' / '.$date : '' }}</p>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ Str::limit($reason, 150) }}</p>
                    </div>
                    <a href="{{ $source['url'] }}" target="_blank" rel="noreferrer" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                        </svg>
                        Open
                    </a>
                </div>
            @empty
                <p class="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                    No trusted source links were recorded for this scan.
                </p>
            @endforelse
        </div>

        @if ($socialSources->isNotEmpty())
            <details class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                <summary class="cursor-pointer text-sm font-black text-slate-900">
                    Cross-platform social context
                </summary>
                <div class="mt-3 divide-y divide-slate-200">
                    @foreach ($socialSources as $source)
                        @php
                            $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                            $name = $source['name'] ?? $linkHost;
                            $reason = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Search related public posts for context.'));
                        @endphp
                        <div class="tg-result-source-row py-3">
                            <div class="min-w-0">
                                <p class="break-words text-sm font-black text-slate-900">{{ $name }}</p>
                                <p class="mt-1 break-words text-xs font-semibold text-slate-500">{{ preg_replace('/^www\./', '', (string) $linkHost) }}</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ Str::limit($reason, 150) }}</p>
                            </div>
                            <a href="{{ $source['url'] }}" target="_blank" rel="noreferrer" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:text-blue-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                                </svg>
                                Open
                            </a>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </article>

    @if ($relatedPosts->isNotEmpty() || $recentFactChecks->isNotEmpty())
        <details class="tg-result-card p-5 sm:p-6">
            <summary class="cursor-pointer text-lg font-black text-slate-950">
                More related context
            </summary>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($relatedPosts as $post)
                    @php
                        $postUrl = $post->post_url ?: '#';
                        $postHost = $post->post_url ? parse_url($post->post_url, PHP_URL_HOST) : null;
                        $postSource = $post->display_name ?: Str::headline((string) ($post->source_key ?: ($postHost ?: 'Related source')));
                    @endphp
                    <a href="{{ $postUrl }}" target="_blank" rel="noreferrer" class="block rounded-lg border border-slate-200 bg-white p-4 transition hover:border-blue-300">
                        <p class="truncate text-sm font-black text-slate-950">{{ $postSource }}</p>
                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $post->scraped_at?->diffForHumans() ?? 'Recently checked' }}</p>
                        <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-700">
                            {{ Str::limit((string) ($post->caption_text ?: 'Related public content found during source matching.'), 130) }}
                        </p>
                    </a>
                @endforeach

                @foreach ($recentFactChecks as $item)
                    @php
                        $publisherName = $item['publisher'] ?? 'Fact-check partner';
                        $detailUrl = ! empty($item['id']) && ! auth()->user()?->isAdmin()
                            ? route('dashboard.fact-check', ['factCheck' => $item['id']])
                            : ($item['url'] ?? route('dashboard'));
                        $rating = $ratingNormalizer::shortLabel((string) ($item['rating'] ?? 'Reviewed'), (string) ($item['headline'] ?? ''), (string) ($item['claim'] ?? ''));
                        $ratingClass = match ($ratingNormalizer::toneFor($rating)) {
                            'danger' => 'bg-red-50 text-red-700',
                            'safe' => 'bg-emerald-50 text-emerald-700',
                            default => 'bg-amber-50 text-amber-700',
                        };
                    @endphp
                    <a href="{{ $detailUrl }}" target="_blank" rel="noreferrer" class="block rounded-lg border border-slate-200 bg-white p-4 transition hover:border-blue-300">
                        <div class="flex items-center justify-between gap-3">
                            <p class="truncate text-sm font-black text-slate-950">{{ $publisherName }}</p>
                            <span class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-black {{ $ratingClass }}">{{ $rating }}</span>
                        </div>
                        <p class="mt-1 text-xs font-semibold text-slate-500">{{ $item['date_label'] ?? 'Latest review' }}</p>
                        <p class="mt-3 line-clamp-3 text-sm leading-6 text-slate-700">
                            {{ Str::limit((string) ($item['headline'] ?? 'Latest public fact-check review.'), 130) }}
                        </p>
                    </a>
                @endforeach
            </div>
        </details>
    @endif
</section>
