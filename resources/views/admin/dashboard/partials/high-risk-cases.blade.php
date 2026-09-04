<div class="col-span-12 xl:col-span-6">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">High Risk Scans</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">Scans with the highest fake probability scores.</p>
            </div>
        </div>

        @if ($highRiskDetections->isEmpty())
            <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500">
                No high-risk scans to show yet.
            </div>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($highRiskDetections as $case)
                    <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-semibold text-gray-800 text-theme-sm">Detection result</p>
                                <span class="block text-gray-500 text-theme-xs">
                                    {{ ucfirst($case->source_kind) }} | {{ ucfirst($case->media_type) }} | {{ $case->user?->name ?? 'Unknown user' }}
                                </span>
                                <span class="mt-1 block text-gray-400 text-theme-xs">
                                    {{ $case->analyzed_at?->format('M d, Y h:i A') ?? 'Awaiting analysis' }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full bg-error-50 px-2.5 py-1 text-theme-xs font-medium text-error-600">
                                    {{ $case->fake_score }}%
                                </span>
                                <span @class([
                                    'rounded-full px-2.5 py-1 text-theme-xs font-medium',
                                    'bg-warning-50 text-warning-700' => $case->verdict === 'review',
                                    'bg-success-50 text-success-600' => $case->verdict === 'real',
                                    'bg-error-50 text-error-600' => $case->verdict === 'fake',
                                ])>
                                    {{ ucfirst($case->verdict) }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
