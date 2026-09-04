@extends('layouts.user')

@section('title', 'Relevant Fact Check')
@section('page_title', 'Relevant Fact Check')
@section('page_back_url', route('dashboard'))

@section('content')
    @php
        $tone = $item['tone'] ?? 'neutral';
        $logoUrl = $item['logo_url'] ?? null;
        $publisherName = $item['publisher'] ?? 'Fact-check partner';
        $rating = $item['rating'] ?? 'Reviewed';
        $ratingLower = \Illuminate\Support\Str::lower($rating);
        $ratingTone = match (true) {
            \Illuminate\Support\Str::contains($ratingLower, ['false', 'fake', 'hoax', 'fabricated']) => 'danger',
            \Illuminate\Support\Str::contains($ratingLower, ['misleading', 'mixed', 'partly', 'context', 'unproven', 'unsupported']) => 'warning',
            \Illuminate\Support\Str::contains($ratingLower, ['real', 'true', 'correct', 'accurate', 'legitimate']) => 'safe',
            default => $tone,
        };
    @endphp

    @once
        <style>
            .tg-detail-rating-banner {
                position: absolute;
                inset-inline: 0;
                top: 50%;
                z-index: 3;
                display: flex;
                min-height: 2.65rem;
                transform: translateY(-50%);
                align-items: center;
                justify-content: center;
                padding: 0.6rem 1rem;
                color: #fff;
                text-align: center;
                text-transform: uppercase;
                letter-spacing: 0.16em;
                backdrop-filter: blur(10px);
                box-shadow: 0 16px 34px rgb(15 23 42 / 18%);
            }

            .tg-detail-rating-banner-danger {
                background: linear-gradient(90deg, rgb(185 28 28 / 76%), rgb(239 68 68 / 84%), rgb(153 27 27 / 76%));
            }

            .tg-detail-rating-banner-warning {
                background: linear-gradient(90deg, rgb(180 83 9 / 76%), rgb(245 158 11 / 84%), rgb(146 64 14 / 76%));
            }

            .tg-detail-rating-banner-safe {
                background: linear-gradient(90deg, rgb(4 120 87 / 76%), rgb(16 185 129 / 84%), rgb(5 150 105 / 76%));
            }

            .tg-detail-rating-banner-neutral {
                background: linear-gradient(90deg, rgb(15 23 42 / 76%), rgb(51 65 85 / 84%), rgb(15 23 42 / 76%));
            }

            .tg-detail-rating-reviewer {
                position: absolute;
                left: 50%;
                top: calc(50% + 2rem);
                z-index: 4;
                display: inline-flex;
                max-width: calc(100% - 2rem);
                transform: translateX(-50%);
                align-items: center;
                gap: 0.4rem;
                border-radius: 999px;
                border: 1px solid rgb(255 255 255 / 70%);
                background: rgb(255 255 255 / 92%);
                padding: 0.3rem 0.7rem;
                color: #334155;
                font-size: 0.68rem;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                box-shadow: 0 10px 24px rgb(15 23 42 / 12%);
            }
        </style>
    @endonce

    <div class="truthguard-mobile-page truthguard-mobile-fact-detail py-1 md:py-2">
        @if (! empty($item['url']))
            <div class="truthguard-fact-detail-actions mb-5 flex flex-wrap items-center justify-end gap-3">
                <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center rounded-full bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
                    Read full source
                </a>
            </div>
        @endif

        <article class="truthguard-fact-detail-card overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-md">
            <div class="p-5 sm:p-7 lg:p-8">
                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="" loading="lazy" class="h-6 w-6 rounded-full object-contain">
                    @endif
                    <span>{{ $publisherName }}</span>
                    <span class="text-slate-300">/</span>
                    <span>{{ $item['date_label'] ?? 'date unavailable' }}</span>
                    @if (! empty($item['query']))
                        <span class="rounded-full bg-blue-50 px-2.5 py-1 text-[0.68rem] text-blue-700">
                            {{ ($item['feed_source_type'] ?? 'topic') === 'publisher' ? 'Source' : 'Topic' }}: {{ $item['query'] }}
                        </span>
                    @endif
                </div>

                <div class="mt-5 space-y-6">
                    <div class="relative mx-auto overflow-hidden rounded-[22px] border border-slate-200 bg-white shadow-sm" style="width: 720px; max-width: 100%;">
                        @if (! empty($item['image_url']))
                            <div class="flex items-center justify-center bg-white p-3">
                                <img src="{{ $item['image_url'] }}" alt="" class="h-auto w-full object-contain" style="max-height: 420px;" referrerpolicy="no-referrer">
                            </div>
                        @else
                            <div @class([
                                'flex items-center justify-center',
                                'bg-red-50' => $tone === 'danger',
                                'bg-amber-50' => $tone === 'warning',
                                'bg-emerald-50' => $tone === 'safe',
                                'bg-slate-100' => ! in_array($tone, ['danger', 'warning', 'safe'], true),
                            ]) style="min-height: 260px;">
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
                            'tg-detail-rating-banner',
                            'tg-detail-rating-banner-danger' => $ratingTone === 'danger',
                            'tg-detail-rating-banner-warning' => $ratingTone === 'warning',
                            'tg-detail-rating-banner-safe' => $ratingTone === 'safe',
                            'tg-detail-rating-banner-neutral' => ! in_array($ratingTone, ['danger', 'warning', 'safe'], true),
                        ])>
                            <span class="block text-sm font-black leading-none">{{ $rating }}</span>
                        </span>

                        <span class="tg-detail-rating-reviewer">
                            @if ($logoUrl)
                                <img src="{{ $logoUrl }}" alt="" loading="lazy" class="h-3.5 w-3.5 shrink-0 rounded-full object-contain">
                            @endif
                            <span class="truncate">Reviewed by {{ $publisherName }}</span>
                        </span>
                    </div>

                    <div class="mx-auto max-w-5xl">
                        <h2 class="max-w-4xl text-3xl font-semibold leading-tight tracking-tight text-slate-950 sm:text-4xl">
                            {{ $item['headline'] ?? 'Fact-check result' }}
                        </h2>

                        <div class="mt-6">
                            <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Relevant Claim</p>
                                <p class="mt-3 text-base leading-8 text-slate-700">{{ $item['claim'] ?? 'No claim text was provided by the source.' }}</p>
                            </section>
                        </div>

                        <div class="mt-5 grid gap-4 lg:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Claimant</p>
                                <p class="mt-2 text-sm font-semibold text-slate-800">{{ $item['claimant'] ?? 'Online claim' }}</p>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Original Host</p>
                                <p class="mt-2 text-sm font-semibold text-slate-800">{{ $item['host'] ?? 'Source page' }}</p>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-3">
                            <a href="{{ route('detections.create') }}" class="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-blue-700">
                                Verify related claim
                            </a>

                            @if (! empty($item['url']))
                                <a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-blue-700">
                                    Open publisher page
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </article>
    </div>
@endsection
