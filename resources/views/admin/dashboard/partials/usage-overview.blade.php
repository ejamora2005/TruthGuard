<div id="platform-usage" class="col-span-12 xl:col-span-8">
    <div class="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 sm:px-6 sm:pt-6">
        <div class="mb-6 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="w-full">
                <h3 class="text-lg font-semibold text-gray-800">AI Usage Overview</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">
                    TruthGuard is currently using detection activity as the main AI request signal across the platform.
                </p>
            </div>

            <div class="inline-flex items-center gap-0.5 rounded-lg bg-gray-100 p-0.5">
                <span class="rounded-md bg-white px-3 py-2 text-theme-sm font-medium text-gray-900 shadow-theme-xs">This Year</span>
                <span class="px-3 py-2 text-theme-sm font-medium text-gray-500">Live</span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-gray-500 text-theme-xs uppercase tracking-[0.18em]">Total Runs</p>
                <p class="mt-2 font-semibold text-gray-800 text-title-sm">{{ number_format($estimatedApiRequests) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-gray-500 text-theme-xs uppercase tracking-[0.18em]">This Month</p>
                <p class="mt-2 font-semibold text-gray-800 text-title-sm">{{ number_format($currentMonthDetections) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-gray-500 text-theme-xs uppercase tracking-[0.18em]">Avg per Subscriber</p>
                <p class="mt-2 font-semibold text-gray-800 text-title-sm">{{ number_format($averageRequestsPerSubscriber, 1) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                <p class="text-gray-500 text-theme-xs uppercase tracking-[0.18em]">Avg Fake Score</p>
                <p class="mt-2 font-semibold text-gray-800 text-title-sm">{{ $averageFakeScore }}%</p>
            </div>
        </div>

        <div class="mt-6">
            <div
                data-admin-usage-chart
                data-categories='@json($trendLabels)'
                data-series='@json($detectionSeries)'
                class="min-h-[280px] rounded-2xl border border-gray-200 bg-gray-50 px-3 py-4 sm:px-5"
            ></div>
        </div>
    </div>
</div>
