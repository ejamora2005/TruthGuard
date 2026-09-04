<div id="recent-queue" class="col-span-12 xl:col-span-7">
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 sm:px-6">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Recent Detection Queue</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">Latest submissions moving through TruthGuard verification.</p>
            </div>
            <a href="{{ route('detections.create') }}" class="truthguard-header-action">
                Run Detection
            </a>
        </div>

        <div class="mb-4 rounded-xl border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700">
            {{ number_format($reviewDetections) }} scan(s) currently need manual review attention.
        </div>

        <div class="max-w-full overflow-x-auto custom-scrollbar">
            <table class="min-w-full">
                <thead>
                    <tr class="border-t border-gray-100">
                        <th class="py-3 text-left"><p class="font-medium text-gray-500 text-theme-xs">Scan</p></th>
                        <th class="py-3 text-left"><p class="font-medium text-gray-500 text-theme-xs">User</p></th>
                        <th class="py-3 text-left"><p class="font-medium text-gray-500 text-theme-xs">Source</p></th>
                        <th class="py-3 text-left"><p class="font-medium text-gray-500 text-theme-xs">Score</p></th>
                        <th class="py-3 text-left"><p class="font-medium text-gray-500 text-theme-xs">Verdict</p></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentDetections as $case)
                        <tr class="border-t border-gray-100">
                            <td class="whitespace-nowrap py-3">
                                <div>
                                    <p class="font-medium text-gray-800 text-theme-sm">Detection result</p>
                                    <span class="text-gray-500 text-theme-xs">{{ ucfirst($case->media_type) }} | {{ $case->analyzed_at?->format('M d, Y') ?? 'Pending' }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap py-3">
                                <div>
                                    <p class="text-gray-800 text-theme-sm">{{ $case->user?->name ?? 'Unknown user' }}</p>
                                    <span class="text-gray-500 text-theme-xs">{{ $case->user?->email ?? 'No email found' }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap py-3">
                                <span class="text-gray-500 text-theme-sm">{{ ucfirst($case->source_kind) }}</span>
                            </td>
                            <td class="whitespace-nowrap py-3">
                                <span class="text-gray-500 text-theme-sm">{{ $case->fake_score }}%</span>
                            </td>
                            <td class="whitespace-nowrap py-3">
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-theme-xs font-medium',
                                    'bg-success-50 text-success-600' => $case->verdict === 'real',
                                    'bg-warning-50 text-warning-700' => $case->verdict === 'review',
                                    'bg-error-50 text-error-600' => $case->verdict === 'fake',
                                ])>
                                    {{ ucfirst($case->verdict) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr class="border-t border-gray-100">
                            <td colspan="5" class="py-8 text-center text-theme-sm text-gray-500">
                                No detections yet. The latest queue will appear here once users start submitting media.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
