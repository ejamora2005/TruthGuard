<section class="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(340px,0.95fr)]">
    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition duration-300 hover:shadow-lg sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Charts</p>
                <h3 class="mt-2 text-xl font-semibold text-slate-900">Detections over time</h3>
                <p class="mt-2 text-sm text-slate-500">
                    {{ $lineChart['demo'] ? 'Demo trend shown until more live scans arrive in your workspace.' : 'A live seven-day view of scan activity in your workspace.' }}
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Total scanned</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($totalDetections) }}</p>
            </div>
        </div>

        <div id="truthguard-detections-trend" class="mt-6"></div>
    </article>

    <div class="space-y-6">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition duration-300 hover:shadow-lg sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Distribution</p>
                    <h3 class="mt-2 text-xl font-semibold text-slate-900">Fake vs real mix</h3>
                    <p class="mt-2 text-sm text-slate-500">
                        {{ $distributionChart['demo'] ? 'Sample distribution displayed while your real queue is still building up.' : 'Based on verdicts from completed detections in your workspace.' }}
                    </p>
                </div>

                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Live</span>
            </div>

            <div id="truthguard-distribution-chart" class="mt-4"></div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition duration-300 hover:shadow-lg sm:p-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">System Status</p>
                    <h3 class="mt-2 text-xl font-semibold text-slate-900">Operational overview</h3>
                </div>

                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-600">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ $systemStatus['status'] }}
                </span>
            </div>

            <div class="mt-5 space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Last scan time</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $systemStatus['last_scan'] }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $systemStatus['last_scan_relative'] }}</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Monitoring status</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $systemStatus['monitoring'] }}</p>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ $systemStatus['monitoring_detail'] }}</p>
                </div>
            </div>

            <div class="mt-6 space-y-3">
                @foreach (collect($quickActions)->where('primary', false) as $action)
                    <a
                        href="{{ $action['href'] }}"
                        class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition duration-300 hover:scale-[1.01] hover:border-slate-300 hover:bg-slate-50"
                    >
                        <span>{{ $action['title'] }}</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                @endforeach
            </div>
        </article>
    </div>
</section>
