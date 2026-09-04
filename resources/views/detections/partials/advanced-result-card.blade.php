@php
    $ratingNormalizer = \App\Services\Detections\FactCheckRatingNormalizer::class;
    $verdictPresenter = \App\Services\Detections\DetectionVerdictPresenter::class;
    $presentation = $verdictPresenter::forDetection($selectedDetection);
    $categoryKey = (string) ($presentation['category_key'] ?? 'needs_review');
    $verdict = (string) ($selectedDetection->verdict ?: 'review');
    $riskScore = max(0, min(100, (int) $selectedDetection->fake_score));
    $confidenceScore = $verdictPresenter::confidencePercent($selectedDetection);

    $tone = match ($categoryKey) {
        'confirmed', 'low_risk' => [
            'label' => $presentation['short_label'] ?? 'LOW-RISK CLAIM',
            'risk' => $presentation['risk_label'] ?? 'LOW RISK',
            'summary' => $presentation['summary_lead'] ?? 'The available evidence suggests a lower-risk result.',
            'accent' => '#059669',
            'accentRgb' => '5 150 105',
            'text' => 'text-emerald-700',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'iconBg' => 'bg-emerald-50 text-emerald-700',
        ],
        'ai_generated' => [
            'label' => $presentation['short_label'] ?? 'AI-GENERATED MEDIA',
            'risk' => $presentation['risk_label'] ?? 'SYNTHETIC MEDIA',
            'summary' => $presentation['summary_lead'] ?? 'Synthetic media indicators were found.',
            'accent' => '#7c3aed',
            'accentRgb' => '124 58 237',
            'text' => 'text-violet-700',
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'iconBg' => 'bg-violet-50 text-violet-700',
        ],
        'manipulated' => [
            'label' => $presentation['short_label'] ?? 'MANIPULATED MEDIA',
            'risk' => $presentation['risk_label'] ?? 'ALTERED CONTENT',
            'summary' => $presentation['summary_lead'] ?? 'Manipulation indicators were found.',
            'accent' => '#ea580c',
            'accentRgb' => '234 88 12',
            'text' => 'text-orange-700',
            'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
            'iconBg' => 'bg-orange-50 text-orange-700',
        ],
        'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => [
            'label' => $presentation['short_label'] ?? 'NEEDS SOURCE REVIEW',
            'risk' => $presentation['risk_label'] ?? 'NEEDS REVIEW',
            'summary' => $presentation['summary_lead'] ?? 'Review this content before sharing.',
            'accent' => '#d97706',
            'accentRgb' => '217 119 6',
            'text' => 'text-amber-700',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'iconBg' => 'bg-amber-50 text-amber-700',
        ],
        default => [
            'label' => $presentation['short_label'] ?? 'LIKELY MISLEADING',
            'risk' => $presentation['risk_label'] ?? 'HIGH RISK',
            'summary' => $presentation['summary_lead'] ?? 'High-risk indicators were found.',
            'accent' => '#dc2626',
            'accentRgb' => '220 38 38',
            'text' => 'text-red-700',
            'badge' => 'bg-red-50 text-red-700 ring-red-200',
            'iconBg' => 'bg-red-50 text-red-700',
        ],
    };

    $selectedMediaPath = strtolower((string) $selectedDetection->media_path);
    $selectedIsDocument = $selectedDetection->media_type === 'document'
        || \Illuminate\Support\Str::endsWith($selectedMediaPath, '.pdf');
    $mediaUrl = $selectedDetection->media_url;
    $sourceHost = $selectedDetection->source_url ? parse_url($selectedDetection->source_url, PHP_URL_HOST) : null;
    $sourceLabel = $selectedDetection->platform
        ? \Illuminate\Support\Str::headline((string) $selectedDetection->platform)
        : ($sourceHost ? preg_replace('/^www\./', '', $sourceHost) : 'Direct submission');
    $contentType = \Illuminate\Support\Str::headline($selectedDetection->media_type ?: 'Unknown');
    $claimText = trim((string) ($selectedDetection->caption_text ?: $selectedDetection->notes ?: ''));
    $assessment = trim((string) (
        $selectedDetection->analysis_summary
        ?: $selectedDetection->explanation_summary
        ?: 'TruthGuard completed the analysis, but no summary was recorded for this case.'
    ));
    $reasoning = trim((string) ($selectedDetection->explanation_summary ?: $assessment));
    $verificationSummary = trim((string) ($selectedDetection->verification_summary ?: 'Available public evidence was compared with the submitted content.'));
    $recommendation = trim((string) ($selectedDetection->recommendation ?: match ($verdict) {
        'fake' => 'Do not share this content until a trusted source confirms it.',
        'review' => 'Review the linked sources before sharing this content.',
        default => 'This result is lower risk, but still check the original source before reuse.',
    }));

    $signalGroups = is_array($selectedDetection->signals ?? null) ? $selectedDetection->signals : [];
    $aiBasis = collect($signalGroups['ai_basis'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')->take(4)->values();
    $aiLimitations = collect($signalGroups['ai_limitations'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')->take(3)->values();
    $flatSignals = collect($signalGroups)
        ->except(['ai_basis', 'ai_limitations', 'openai_usage', 'verification_policy'])
        ->flatten(1)
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->sortByDesc(fn (array $signal): int => (int) ($signal['weight'] ?? 0))
        ->unique('label')->take(5)->values();

    $sources = collect($selectedDetection->verification_sources ?? [])
        ->filter(fn ($source): bool => is_array($source) && filled($source['url'] ?? null))
        ->sortBy(fn (array $source): int => match ($source['source_type'] ?? 'reference') {
            'fact_check' => 1,
            'official', 'weather' => 2,
            'news' => 3,
            'source_trace' => 4,
            'social_context' => 5,
            default => 6,
        })->values();
    $primarySources = $sources->reject(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')->take(6)->values();
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
            default => \Illuminate\Support\Str::headline((string) ($source['label'] ?? 'Reference')),
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

        return $rating !== '' && $rating !== $ratingNormalizer::REVIEWED ? $rating : $sourceTypeLabel($source);
    };

    $relatedPosts = collect($selectedDetection->scrapedPosts ?? [])->take(2)->values();
    $latestFactCheckItems = data_get($latestFactChecks ?? [], 'items', $latestFactChecks ?? []);
    $recentFactChecks = collect($latestFactCheckItems)->filter(fn ($item): bool => is_array($item))->take(3)->values();
@endphp

@once
    <style>
        .tg-ai-report {
            --tg-accent: #2563eb;
            --tg-accent-rgb: 37 99 235;
            color: #0f172a;
        }

        .tg-ai-surface {
            border: 1px solid rgba(226, 232, 240, .9);
            border-radius: 1.5rem;
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 18px 50px rgba(15, 23, 42, .055);
        }

        .tg-ai-hero {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border-color: rgb(var(--tg-accent-rgb) / .18);
            background:
                radial-gradient(circle at 82% 12%, rgb(var(--tg-accent-rgb) / .17), transparent 32%),
                linear-gradient(135deg, #fff 0%, #f8fafc 48%, rgb(var(--tg-accent-rgb) / .055) 100%);
        }

        .tg-ai-hero::before {
            position: absolute;
            inset: 0;
            z-index: -1;
            content: '';
            opacity: .32;
            background-image: linear-gradient(rgba(148,163,184,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,.12) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: linear-gradient(to left, #000, transparent 74%);
        }

        .tg-score-ring {
            position: relative;
            display: grid;
            height: 8.5rem;
            width: 8.5rem;
            flex-shrink: 0;
            place-items: center;
            border-radius: 9999px;
            background: conic-gradient(var(--tg-accent) calc(var(--tg-score) * 1%), #e2e8f0 0);
            box-shadow: 0 16px 35px rgb(var(--tg-accent-rgb) / .16);
        }

        .tg-score-ring::after {
            position: absolute;
            inset: .65rem;
            border-radius: inherit;
            background: #fff;
            content: '';
        }

        .tg-score-ring > span { position: relative; z-index: 1; }

        .tg-ai-icon {
            display: inline-flex;
            height: 2.65rem;
            width: 2.65rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: .9rem;
        }

        .tg-ai-metric {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: rgba(248, 250, 252, .78);
        }

        .tg-ai-media {
            min-height: 13rem;
            max-height: 30rem;
            border-radius: 1.15rem;
            background: #f8fafc;
        }

        .tg-ai-signal-track {
            height: .35rem;
            overflow: hidden;
            border-radius: 9999px;
            background: #e2e8f0;
        }

        .tg-ai-source {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: #fff;
            transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .tg-ai-source:hover {
            border-color: rgb(var(--tg-accent-rgb) / .35);
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
        }

        .tg-related-image {
            display: block;
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #e2e8f0;
        }

        @media (min-width: 1280px) {
            .tg-ai-sticky { position: sticky; top: 6.5rem; }
        }

        @media (max-width: 640px) {
            .tg-ai-surface { border-radius: 1.15rem; }
            .tg-score-ring { height: 7.25rem; width: 7.25rem; }
            .tg-ai-hero::before { display: none; }
        }
    </style>
@endonce

<section
    id="latest-detection-result"
    data-tour="result-reports"
    class="tg-ai-report truthguard-scroll-target space-y-5 sm:space-y-6"
    style="--tg-accent: {{ $tone['accent'] }}; --tg-accent-rgb: {{ $tone['accentRgb'] }};"
>
    <div class="flex flex-col gap-3 px-1 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex min-w-0 items-center gap-3">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700 ring-1 ring-blue-100">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.4 12.2 1.7 1.7 3.7-4" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-blue-700">AI verification report</p>
                <p class="mt-0.5 truncate text-sm font-medium text-slate-500">
                    Analysis #{{ $selectedDetection->id }} · {{ $selectedDetection->analyzed_at?->format('M d, Y · h:i A') ?? 'Processing' }}
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if (! auth()->user()?->isAdmin())
                <a href="{{ route('history') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 text-sm font-bold text-slate-700 transition hover:border-blue-300 hover:text-blue-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 12a8 8 0 1 0 2.35-5.65M4 4.5v4.2h4.2M12 8v4l2.7 1.7" />
                    </svg>
                    History
                </a>
            @endif
            <a href="{{ route('detections.create') }}" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-slate-950 px-3.5 text-sm font-bold text-white transition hover:bg-blue-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14" />
                </svg>
                New scan
            </a>
        </div>
    </div>

    <article class="tg-ai-surface tg-ai-hero p-5 sm:p-7 lg:p-9">
        <div class="grid items-center gap-7 lg:grid-cols-[minmax(0,1fr)_auto] lg:gap-10">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-black uppercase tracking-[0.12em] ring-1 {{ $tone['badge'] }}">
                        <span class="h-2 w-2 rounded-full" style="background: {{ $tone['accent'] }}"></span>
                        {{ $tone['risk'] }}
                    </span>
                    <span class="rounded-full bg-white/80 px-3 py-1.5 text-xs font-bold text-slate-600 ring-1 ring-slate-200">
                        {{ $contentType }} analysis
                    </span>
                </div>

                <h2 class="mt-5 max-w-3xl text-3xl font-black leading-[1.08] tracking-tight text-slate-950 sm:text-5xl">
                    {{ $tone['label'] }}
                </h2>
                <p class="mt-4 max-w-3xl text-base font-medium leading-7 text-slate-600 sm:text-lg sm:leading-8">
                    {{ $tone['summary'] }}
                </p>

                <div class="mt-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm font-semibold text-slate-600">
                    <span class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 17.5 9 12l3 3 7-8" />
                        </svg>
                        {{ $sourceCount }} sources reviewed
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z" />
                        </svg>
                        {{ $trustedSourceCount }} trusted checks
                    </span>
                </div>
            </div>

            <div class="flex items-center justify-center gap-5 lg:flex-col">
                <div class="tg-score-ring" style="--tg-score: {{ $confidenceScore }}" aria-label="{{ $confidenceScore }} percent verdict confidence">
                    <span class="text-center">
                        <strong class="block text-3xl font-black tracking-tight {{ $tone['text'] }}">{{ $confidenceScore }}%</strong>
                        <small class="mt-0.5 block text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">Confidence</small>
                    </span>
                </div>
                <div class="text-left lg:text-center">
                    <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Risk score</p>
                    <p class="mt-1 text-2xl font-black text-slate-950">{{ $riskScore }}%</p>
                </div>
            </div>
        </div>
    </article>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
        <main class="min-w-0 space-y-5">
            <article class="tg-ai-surface overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-5 sm:px-7">
                    <div class="flex items-center gap-3">
                        <span class="tg-ai-icon bg-blue-50 text-blue-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 14 8.5 19.5 10.5 14 12.5 12 18l-2-5.5L4.5 10.5 10 8.5 12 3Z" />
                                <path stroke-linecap="round" d="m18.5 16 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.16em] text-blue-700">TruthGuard intelligence</p>
                            <h3 class="mt-0.5 text-xl font-black text-slate-950">AI assessment</h3>
                        </div>
                    </div>
                </div>

                <div class="space-y-6 p-5 sm:p-7">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Executive summary</p>
                        <p class="mt-3 text-base font-medium leading-8 text-slate-800 sm:text-lg">{{ $assessment }}</p>
                    </div>

                    @if ($reasoning !== $assessment)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Why this result</p>
                            <p class="mt-2 text-sm leading-7 text-slate-700 sm:text-base">{{ $reasoning }}</p>
                        </div>
                    @endif

                    <div class="rounded-2xl p-4 sm:p-5" style="border: 1px solid rgb(var(--tg-accent-rgb) / .22); background: rgb(var(--tg-accent-rgb) / .055);">
                        <div class="flex gap-3">
                            <span class="tg-ai-icon {{ $tone['iconBg'] }}">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.2 11 14l4-4.4" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-black uppercase tracking-[0.14em] {{ $tone['text'] }}">Recommended action</p>
                                <p class="mt-2 text-base font-semibold leading-7 text-slate-900">{{ $recommendation }}</p>
                                @if (is_array($topSource))
                                    <a href="{{ $topSource['url'] }}" target="_blank" rel="noreferrer" class="mt-4 inline-flex min-h-10 items-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition hover:bg-blue-700">
                                        Review strongest source
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                                        </svg>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="tg-ai-surface p-5 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="tg-ai-icon bg-slate-100 text-slate-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="4" y="5" width="16" height="14" rx="2" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-xl font-black text-slate-950">Evidence submitted</h3>
                            <p class="mt-0.5 text-sm font-medium text-slate-500">{{ $contentType }} · {{ $sourceLabel }}</p>
                        </div>
                    </div>
                    <span class="hidden rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600 sm:inline-flex">Input</span>
                </div>

                <div class="tg-ai-media mt-5 overflow-hidden border border-slate-200">
                    @if ($selectedDetection->media_type === 'image' && $mediaUrl)
                        <img src="{{ $mediaUrl }}" alt="Analyzed media" class="h-full max-h-[30rem] w-full object-contain">
                    @elseif ($selectedDetection->media_type === 'video' && $mediaUrl)
                        <video controls preload="metadata" class="h-full max-h-[30rem] w-full bg-slate-950 object-contain">
                            <source src="{{ $mediaUrl }}">
                        </video>
                    @elseif ($selectedIsDocument && $mediaUrl)
                        <div class="flex min-h-56 flex-col items-center justify-center p-6 text-center">
                            <span class="tg-ai-icon bg-red-50 text-red-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linejoin="round" d="M7 3.5h7L18.5 8v12.5H7V3.5Z" /><path d="M14 3.5V8h4.5" />
                                </svg>
                            </span>
                            <p class="mt-3 font-black text-slate-900">Document evidence attached</p>
                            <a href="{{ $mediaUrl }}" target="_blank" rel="noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Open PDF</a>
                        </div>
                    @else
                        <div class="flex min-h-52 flex-col justify-center p-5 sm:p-7">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Text or link claim</p>
                            <p class="mt-3 text-base leading-8 text-slate-800">{{ $claimText !== '' ? $claimText : ($selectedDetection->source_url ?: 'No media preview was attached.') }}</p>
                        </div>
                    @endif
                </div>

                @if (($selectedDetection->media_type === 'image' || $selectedDetection->media_type === 'video' || $selectedIsDocument) && $claimText !== '')
                    <div class="mt-4 rounded-2xl bg-slate-50 p-4 sm:p-5">
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Claim or caption</p>
                        <p class="mt-2 text-sm leading-7 text-slate-700">{{ $claimText }}</p>
                    </div>
                @endif

                @if ($selectedDetection->source_url)
                    <a href="{{ $selectedDetection->source_url }}" target="_blank" rel="noreferrer" class="mt-4 flex items-center gap-3 rounded-2xl border border-slate-200 p-4 transition hover:border-blue-300 hover:bg-blue-50/30">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 14.5 5-5M8 16l-1.3 1.3a3.2 3.2 0 0 1-4.5-4.5L5 10a3.2 3.2 0 0 1 4.5 0M16 8l1.3-1.3a3.2 3.2 0 0 1 4.5 4.5L19 14a3.2 3.2 0 0 1-4.5 0" /></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-black uppercase tracking-[0.12em] text-slate-400">Original link</span>
                            <span class="mt-1 block truncate text-sm font-bold text-blue-700">{{ $selectedDetection->source_url }}</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                    </a>
                @endif
            </article>

            <article class="tg-ai-surface p-5 sm:p-7">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="tg-ai-icon bg-blue-50 text-blue-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.7 10.3a3.45 3.45 0 0 0-4.9 0l-3.25 3.25a3.45 3.45 0 0 0 4.9 4.9l1.05-1.05M10.3 13.7a3.45 3.45 0 0 0 4.9 0l3.25-3.25a3.45 3.45 0 0 0-4.9-4.9L12.5 6.6" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-xl font-black text-slate-950">Source verification</h3>
                            <p class="mt-0.5 text-sm font-medium text-slate-500">Evidence used to cross-check the result.</p>
                        </div>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-black text-slate-600">{{ $sourceCount }} total</span>
                </div>

                <div class="mt-5 grid gap-3">
                    @forelse ($primarySources as $source)
                        @php
                            $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                            $name = $source['name'] ?? $linkHost;
                            $reason = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Related verification source for this result.'));
                            $date = trim((string) ($source['published_at'] ?? $source['date'] ?? ''));
                        @endphp
                        <a href="{{ $source['url'] }}" target="_blank" rel="noreferrer" class="tg-ai-source group grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <strong class="break-words text-sm font-black text-slate-950">{{ $name }}</strong>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-black ring-1 {{ $sourceBadgeClass($source) }}">{{ \Illuminate\Support\Str::limit($sourceBadgeText($source), 30) }}</span>
                                </span>
                                <span class="mt-1 block break-words text-xs font-semibold text-slate-400">{{ preg_replace('/^www\./', '', (string) $linkHost) }}{{ $date !== '' ? ' · '.$date : '' }}</span>
                                <span class="mt-2 block text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit($reason, 170) }}</span>
                            </span>
                            <span class="inline-flex items-center gap-2 text-sm font-black text-blue-700">
                                Open source
                                <svg class="h-4 w-4 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                            </span>
                        </a>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-5 text-center">
                            <p class="text-sm font-bold text-slate-700">No trusted source links were recorded for this scan.</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Treat this verdict as preliminary and verify it independently.</p>
                        </div>
                    @endforelse
                </div>

                @if ($socialSources->isNotEmpty())
                    <section class="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 sm:p-5" aria-labelledby="social-context-title">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path stroke-linecap="round" d="M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4" /></svg>
                            </span>
                            <div>
                                <h4 id="social-context-title" class="text-base font-black text-slate-950">Cross-platform social context ({{ $socialSources->count() }})</h4>
                                <p class="mt-1 text-sm leading-6 text-slate-500">Related public posts and conversations found across social platforms.</p>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ($socialSources as $source)
                                @php
                                    $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                                    $name = $source['name'] ?? $linkHost;
                                    $socialTitle = trim((string) ($source['title'] ?? $source['headline'] ?? $name));
                                    $socialSummary = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Related public content found for this claim.'));
                                @endphp
                                <a href="{{ $source['url'] }}" target="_blank" rel="noreferrer" class="tg-ai-source group flex h-full flex-col p-4">
                                    <span class="flex items-center justify-between gap-3">
                                        <span class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-[0.1em] text-blue-700">
                                            <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                                            {{ $name }}
                                        </span>
                                        <span class="truncate text-[11px] font-semibold text-slate-400">{{ preg_replace('/^www\./', '', (string) $linkHost) }}</span>
                                    </span>
                                    <strong class="mt-3 text-sm font-black leading-6 text-slate-950">{{ \Illuminate\Support\Str::limit($socialTitle, 110) }}</strong>
                                    <span class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit($socialSummary, 190) }}</span>
                                    <span class="mt-4 inline-flex items-center gap-2 text-sm font-black text-blue-700">
                                        View related content
                                        <svg class="h-4 w-4 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </article>
        </main>

        <aside class="tg-ai-sticky min-w-0 space-y-5">
            <article class="tg-ai-surface p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Decision factors</p>
                        <h3 class="mt-1 text-lg font-black text-slate-950">Key signals</h3>
                    </div>
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl {{ $tone['iconBg'] }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M5 17V9M12 17V5M19 17v-4" /></svg>
                    </span>
                </div>

                <div class="mt-5 space-y-4">
                    @forelse ($flatSignals as $signal)
                        @php $weight = max(0, min(100, (int) ($signal['weight'] ?? 0))); @endphp
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold leading-5 text-slate-700">{{ $signal['label'] }}</p>
                                <span class="shrink-0 text-xs font-black tabular-nums {{ $tone['text'] }}">{{ $weight }}</span>
                            </div>
                            <div class="tg-ai-signal-track mt-2">
                                <div class="h-full rounded-full" style="width: {{ $weight }}%; background: {{ $tone['accent'] }}"></div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">The verdict was generated from the available content and source evidence.</p>
                    @endforelse
                </div>
            </article>

            <article class="tg-ai-surface p-5">
                <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Evidence coverage</p>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="tg-ai-metric p-3.5">
                        <p class="text-xs font-bold text-slate-500">All sources</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ $sourceCount }}</p>
                    </div>
                    <div class="tg-ai-metric p-3.5">
                        <p class="text-xs font-bold text-slate-500">Trusted</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ $trustedSourceCount }}</p>
                    </div>
                </div>
                <p class="mt-4 text-sm leading-6 text-slate-600">{{ $verificationSummary }}</p>
            </article>

            @if ($aiBasis->isNotEmpty() || $aiLimitations->isNotEmpty())
                <article class="tg-ai-surface p-5">
                    <div>
                        <span class="block text-xs font-black uppercase tracking-[0.14em] text-slate-400">Transparency</span>
                        <h3 class="mt-1 text-base font-black text-slate-950">How AI reached this result</h3>
                    </div>
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        @if ($aiBasis->isNotEmpty())
                            <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Evidence used</p>
                            <ul class="mt-3 space-y-2.5">
                                @foreach ($aiBasis as $basis)
                                    <li class="flex gap-2.5 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600"></span>
                                        <span>{{ $basis['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($aiLimitations->isNotEmpty())
                            <p class="mt-5 text-xs font-black uppercase tracking-[0.12em] text-amber-700">Limitations</p>
                            <ul class="mt-3 space-y-2.5">
                                @foreach ($aiLimitations as $limitation)
                                    <li class="flex gap-2.5 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        <span>{{ $limitation['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </article>
            @endif

            <div class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-xs leading-5 text-blue-950">
                <strong class="font-black">Important:</strong> AI-supported results can be incomplete. Check the original material and cited sources before making consequential decisions.
            </div>
        </aside>
    </div>

    @if ($relatedPosts->isNotEmpty() || $recentFactChecks->isNotEmpty())
        <article class="tg-ai-surface overflow-hidden">
            <div class="p-5 sm:p-6">
                <h3 class="text-lg font-black text-slate-950">Related context</h3>
                <p class="mt-1 text-sm text-slate-500">Additional posts and recent public fact checks.</p>
            </div>
            <div class="grid gap-3 border-t border-slate-100 p-5 md:grid-cols-2 xl:grid-cols-3 sm:p-6">
                @foreach ($relatedPosts as $post)
                    @php
                        $postUrl = $post->post_url ?: '#';
                        $postHost = $post->post_url ? parse_url($post->post_url, PHP_URL_HOST) : null;
                        $postSource = $post->display_name ?: \Illuminate\Support\Str::headline((string) ($post->source_key ?: ($postHost ?: 'Related source')));
                        $postImage = collect($post->image_urls ?? [])->filter()->first()
                            ?: collect($post->media_urls ?? [])->filter()->first();
                    @endphp
                    <a href="{{ $postUrl }}" target="_blank" rel="noreferrer" class="tg-ai-source block overflow-hidden">
                        @if ($postImage)
                            <img src="{{ $postImage }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="tg-related-image">
                        @else
                            <span class="tg-related-image flex items-center justify-center text-slate-400">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m7 15 3-3 2.5 2.5L15 12l3 3" /></svg>
                            </span>
                        @endif
                        <span class="block p-4">
                            <span class="block truncate text-sm font-black text-slate-950">{{ $postSource }}</span>
                            <span class="mt-1 block text-xs font-semibold text-slate-400">{{ $post->scraped_at?->diffForHumans() ?? 'Recently checked' }}</span>
                            <span class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit((string) ($post->caption_text ?: 'Related public content found during source matching.'), 130) }}</span>
                        </span>
                    </a>
                @endforeach

                @foreach ($recentFactChecks as $item)
                    @php
                        $publisherName = $item['publisher'] ?? 'Fact-check partner';
                        $detailUrl = ! empty($item['id']) && ! auth()->user()?->isAdmin()
                            ? route('dashboard.fact-check', ['factCheck' => $item['id']])
                            : ($item['url'] ?? route('dashboard'));
                        $rating = $ratingNormalizer::shortLabel((string) ($item['rating'] ?? 'Reviewed'), (string) ($item['headline'] ?? ''), (string) ($item['claim'] ?? ''));
                        $itemImage = $item['image_url'] ?? $item['logo_url'] ?? null;
                    @endphp
                    <a href="{{ $detailUrl }}" target="_blank" rel="noreferrer" class="tg-ai-source block overflow-hidden">
                        @if ($itemImage)
                            <img src="{{ $itemImage }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="tg-related-image">
                        @else
                            <span class="tg-related-image flex items-center justify-center text-slate-400">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m7 15 3-3 2.5 2.5L15 12l3 3" /></svg>
                            </span>
                        @endif
                        <span class="block p-4">
                            <span class="flex items-center justify-between gap-3">
                                <strong class="truncate text-sm font-black text-slate-950">{{ $publisherName }}</strong>
                                <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-black text-slate-700">{{ $rating }}</span>
                            </span>
                            <span class="mt-1 block text-xs font-semibold text-slate-400">{{ $item['date_label'] ?? 'Latest review' }}</span>
                            <span class="mt-3 line-clamp-3 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit((string) ($item['headline'] ?? 'Latest public fact-check review.'), 130) }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </article>
    @endif
</section>
