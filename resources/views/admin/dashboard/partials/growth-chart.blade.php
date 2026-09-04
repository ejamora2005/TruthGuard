<div class="col-span-12">
    <div class="rounded-2xl border border-gray-200 bg-white px-5 pb-5 pt-5 sm:px-6 sm:pt-6">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Platform Growth</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">
                    Monthly detection volume and new user signups across {{ now()->year }}.
                </p>
            </div>

            <div class="inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-theme-xs font-medium text-brand-500">
                Live trend tracking
            </div>
        </div>

        <div
            data-admin-growth-chart
            data-categories='@json($trendLabels)'
            data-detections='@json($detectionSeries)'
            data-signups='@json($signupSeries)'
            class="min-h-[320px]"
        ></div>
    </div>
</div>
