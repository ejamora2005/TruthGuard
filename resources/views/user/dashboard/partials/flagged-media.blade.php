<section id="flagged-media" class="col-span-12 rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Flagged Media</p>
            <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">Fake AI-generated images</h3>
            <p class="mt-2 text-sm text-slate-600">Review the visual scans that were marked as high-risk so you can spot recurring manipulation patterns faster.</p>
        </div>
        <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">
            Auto-marked with X overlay
        </span>
    </div>

    @if ($fakeImages->isEmpty())
        <div class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600">
            No fake images yet. Submit media in Fact Check to populate this visual feed.
        </div>
    @else
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($fakeImages as $item)
                <article class="overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 transition hover:-translate-y-1 hover:shadow-md">
                    <div class="relative aspect-[4/3] overflow-hidden bg-slate-200">
                        <img src="{{ $item->media_url }}" alt="Flagged fake media" class="h-full w-full object-cover">

                        <div class="pointer-events-none absolute inset-0">
                            <div class="absolute inset-0 bg-red-900/20"></div>
                            <div class="absolute left-1/2 top-1/2 h-1.5 w-[140%] -translate-x-1/2 -translate-y-1/2 rotate-45 bg-red-600/90 shadow-[0_0_16px_rgba(220,38,38,.6)]"></div>
                            <div class="absolute left-1/2 top-1/2 h-1.5 w-[140%] -translate-x-1/2 -translate-y-1/2 -rotate-45 bg-red-600/90 shadow-[0_0_16px_rgba(220,38,38,.6)]"></div>
                            <span class="absolute right-2 top-2 rounded-full bg-red-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">
                                Fake
                            </span>
                        </div>
                    </div>

                    <div class="space-y-1 px-4 py-4 text-sm">
                        <p class="font-semibold text-slate-800">High-risk image scan</p>
                        <p class="text-slate-600">Fake score: {{ $item->fake_score }}%</p>
                        <p class="text-xs uppercase tracking-[0.16em] text-slate-400">{{ $item->analyzed_at?->format('M d, Y h:i A') }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
