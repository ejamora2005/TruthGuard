<section id="recent-cases" class="col-span-12 xl:col-span-7">
    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Recent Activity</p>
                <h3 class="mt-1 text-2xl font-semibold text-slate-900">Latest detections</h3>
            </div>
            <a href="{{ route('history') }}" class="text-sm font-semibold text-slate-500 transition hover:text-slate-700">Open history</a>
        </div>

        @if ($recentDetections->isEmpty())
            <div class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">
                No detections submitted yet.
            </div>
        @else
            <div class="mt-5 space-y-3">
                @foreach ($recentDetections->take(4) as $case)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-4 transition hover:border-slate-300 hover:bg-white">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <a href="{{ route('detections.result', $case) }}" class="font-semibold text-slate-900 transition hover:text-blue-700">
                                    Fact check result
                                </a>
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ \Illuminate\Support\Str::limit($case->explanation_summary ?: ($case->notes ?: 'No additional notes were attached to this detection request.'), 120) }}
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 sm:flex-col sm:items-end sm:text-right">
                                <span @class([
                                    'rounded-full px-3 py-1 text-xs font-semibold',
                                    'bg-red-100 text-red-700' => $case->verdict === 'fake',
                                    'bg-amber-100 text-amber-700' => $case->verdict === 'review',
                                    'bg-emerald-100 text-emerald-700' => $case->verdict === 'real',
                                ])>
                                    {{ ucfirst($case->verdict) }}
                                </span>
                                <span class="rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white">
                                    {{ $case->fake_score }}%
                                </span>
                                <span class="text-xs uppercase tracking-[0.16em] text-slate-400">
                                    {{ $case->analyzed_at?->format('M d, Y h:i A') }}
                                </span>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</section>
