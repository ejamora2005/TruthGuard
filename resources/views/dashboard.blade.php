
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-[Space_Grotesk] text-2xl font-bold text-slate-900 leading-tight">
                    TruthGuard Dashboard
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Review AI-fake detection results and monitor flagged media.
                </p>
            </div>
            <a
                href="{{ route('detections.create') }}"
                class="inline-flex items-center rounded-lg bg-gradient-to-r from-cyan-600 to-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-md transition hover:opacity-95"
            >
                New Detection
            </a>
        </div>
    </x-slot>

    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="relative overflow-hidden rounded-2xl border border-slate-200 bg-slate-900 p-6 text-white shadow-md">
            <img
                src="https://picsum.photos/seed/truthguard-dashboard-live-feed/1800/700"
                alt="Dashboard hero background"
                class="absolute inset-0 h-full w-full object-cover opacity-30"
            >
            <div class="absolute inset-0 bg-gradient-to-r from-slate-950/90 via-slate-900/75 to-cyan-900/50"></div>

            <div class="relative z-10 max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-cyan-200">Live System View</p>
                <h3 class="mt-2 font-[Space_Grotesk] text-2xl font-bold sm:text-3xl">
                    Fake Media Detection Feed
                </h3>
                <p class="mt-3 text-sm text-slate-200 sm:text-base">
                    Uploaded media and source links are analyzed, scored, and classified automatically.
                </p>
            </div>
        </section>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Media Analyzed</p>
                <p class="mt-2 font-[Space_Grotesk] text-3xl font-bold text-slate-900">{{ number_format($totalDetections) }}</p>
                <p class="mt-1 text-sm text-slate-500">All detection requests</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Flagged Fake</p>
                <p class="mt-2 font-[Space_Grotesk] text-3xl font-bold text-red-700">{{ number_format($fakeDetections) }}</p>
                <p class="mt-1 text-sm text-red-600">High-risk media posts</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Needs Review</p>
                <p class="mt-2 font-[Space_Grotesk] text-3xl font-bold text-amber-700">{{ number_format($reviewDetections) }}</p>
                <p class="mt-1 text-sm text-amber-600">Manual verification queue</p>
            </article>
            <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Avg Fake Score</p>
                <p class="mt-2 font-[Space_Grotesk] text-3xl font-bold text-slate-900">{{ $averageFakeScore }}%</p>
                <p class="mt-1 text-sm text-cyan-700">Computed confidence</p>
            </article>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h3 class="font-[Space_Grotesk] text-xl font-semibold text-slate-900">Fake AI-Generated Images</h3>
                <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700">
                    Auto-marked with X
                </span>
            </div>

            @if ($fakeImages->isEmpty())
                <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">
                    No fake images yet. Submit media in the Detection page to populate this feed.
                </div>
            @else
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($fakeImages as $item)
                        <article class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                            <div class="relative aspect-[4/3] overflow-hidden bg-slate-200">
                                <img src="{{ $item->media_url }}" alt="Flagged fake media #{{ $item->id }}" class="h-full w-full object-cover">

                                <div class="pointer-events-none absolute inset-0">
                                    <div class="absolute inset-0 bg-red-900/20"></div>
                                    <div class="absolute left-1/2 top-1/2 h-1.5 w-[140%] -translate-x-1/2 -translate-y-1/2 rotate-45 bg-red-600/90 shadow-[0_0_16px_rgba(220,38,38,.6)]"></div>
                                    <div class="absolute left-1/2 top-1/2 h-1.5 w-[140%] -translate-x-1/2 -translate-y-1/2 -rotate-45 bg-red-600/90 shadow-[0_0_16px_rgba(220,38,38,.6)]"></div>
                                    <span class="absolute right-2 top-2 rounded bg-red-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                                        Fake
                                    </span>
                                </div>
                            </div>
                            <div class="space-y-1 px-3 py-2 text-xs">
                                <p class="font-semibold text-slate-800">Case #TG-{{ str_pad((string) $item->id, 4, '0', STR_PAD_LEFT) }}</p>
                                <p class="text-slate-600">Fake score: {{ $item->fake_score }}%</p>
                                <p class="text-slate-500">{{ $item->analyzed_at?->format('M d, Y h:i A') }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <h3 class="font-[Space_Grotesk] text-xl font-semibold text-slate-900">Recent Detection Cases</h3>
                <a href="{{ route('detections.create') }}" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800">Run another detection</a>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Case ID</th>
                            <th class="px-3 py-3">Source</th>
                            <th class="px-3 py-3">Type</th>
                            <th class="px-3 py-3">Fake Score</th>
                            <th class="px-3 py-3">Verdict</th>
                            <th class="px-3 py-3">Analyzed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($recentDetections as $case)
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-3 font-medium text-slate-900">TG-{{ str_pad((string) $case->id, 4, '0', STR_PAD_LEFT) }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                        {{ ucfirst($case->source_kind) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3">{{ ucfirst($case->media_type) }}</td>
                                <td class="px-3 py-3">{{ $case->fake_score }}%</td>
                                <td class="px-3 py-3">
                                    @if ($case->verdict === 'fake')
                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Fake</span>
                                    @elseif ($case->verdict === 'review')
                                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">Review</span>
                                    @else
                                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Likely Real</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-slate-600">{{ $case->analyzed_at?->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center text-sm text-slate-600">
                                    No detections submitted yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
