<section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-md sm:p-7">
    <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <span class="inline-flex items-center gap-2 rounded-full border border-blue-100 bg-blue-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">
                <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                Dashboard Overview
            </span>

            <h2 class="mt-4 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                A cleaner view of your detection workspace.
            </h2>

            <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">
                Check your key numbers, open Fact Check when you need a new scan, and use History when you want to review past results.
            </p>

            <div class="mt-5 flex flex-wrap gap-2">
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">
                    Total scanned: {{ number_format($totalDetections) }}
                </span>
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">
                    Fake rate: {{ $fakeRate }}%
                </span>
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700">
                    Confidence: {{ $averageConfidenceScore }}%
                </span>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a
                    href="{{ route('detections.create') }}"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white shadow-md transition duration-300 hover:scale-[1.02] hover:bg-blue-700"
                >
                    New Detection
                </a>

                <a
                    href="{{ route('history') }}"
                    class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:text-slate-900"
                >
                    Open History
                </a>
            </div>
        </div>

        <div data-tour="result-reports" class="w-full max-w-md rounded-[24px] border border-slate-200 bg-slate-50/90 p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Latest Scan</p>
                    <h3 class="mt-1 text-lg font-semibold text-slate-900">
                        {{ $latestDetection ? 'Ready to review' : 'No detections yet' }}
                    </h3>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600">
                    {{ $latestDetection ? ucfirst($latestDetection->verdict) : 'Waiting' }}
                </span>
            </div>

            @if ($latestDetection)
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <span class="text-sm font-medium text-slate-500">Risk score</span>
                        <span class="text-sm font-semibold text-slate-900">{{ $latestDetection->fake_score }}%</span>
                    </div>
                    <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <span class="text-sm font-medium text-slate-500">Last scan</span>
                        <span class="text-sm font-semibold text-slate-900">{{ $systemStatus['last_scan_relative'] }}</span>
                    </div>
                    <p class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-600">
                        {{ \Illuminate\Support\Str::limit($latestDetection->explanation_summary ?: ($latestDetection->notes ?: 'No explanation recorded for this case yet.'), 150) }}
                    </p>
                    <a
                        href="{{ route('detections.result', $latestDetection) }}"
                        class="inline-flex items-center text-sm font-semibold text-blue-700 transition hover:text-blue-800"
                    >
                        Open result
                    </a>
                </div>
            @else
                <div class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-600">
                    Start your first scan in Fact Check to see activity here.
                </div>
            @endif
        </div>
    </div>
</section>
