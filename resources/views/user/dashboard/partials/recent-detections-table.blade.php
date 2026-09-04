<section id="case-table" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-md transition duration-300 hover:shadow-lg sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Recent Detections</p>
            <h3 class="mt-2 text-xl font-semibold text-slate-900">Recent detections table</h3>
            <p class="mt-2 text-sm text-slate-500">Thumbnail previews, verdict badges, confidence scores, and timestamps for your latest checks.</p>
        </div>

        @if (collect($recentDetectionRows)->contains('demo', true))
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Demo data shown</span>
        @endif
    </div>

    <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Detection</th>
                        <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Result</th>
                        <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Confidence</th>
                        <th class="px-5 py-4 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @foreach ($recentDetectionRows as $row)
                        @php
                            $badgeClasses = match ($row['verdict']) {
                                'fake' => 'bg-red-50 text-red-600',
                                'review' => 'bg-amber-50 text-amber-600',
                                default => 'bg-green-50 text-green-600',
                            };
                        @endphp

                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-4">
                                    @if ($row['thumbnail'])
                                        <img src="{{ $row['thumbnail'] }}" alt="{{ $row['case'] }} thumbnail" class="h-14 w-14 rounded-2xl object-cover shadow-sm">
                                    @else
                                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 7h14v10H5z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3"></path>
                                            </svg>
                                        </div>
                                    @endif

                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900">{{ $row['case'] }}</p>
                                        <p class="mt-1 truncate text-sm text-slate-500">{{ $row['summary'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $badgeClasses }}">
                                    {{ $row['result'] }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="max-w-[160px]">
                                    <div class="flex items-center justify-between text-sm font-semibold text-slate-900">
                                        <span>{{ $row['confidence'] }}%</span>
                                    </div>
                                    <div class="mt-2 h-2 rounded-full bg-slate-100">
                                        <div
                                            class="{{ $row['verdict'] === 'fake' ? 'bg-red-500' : ($row['verdict'] === 'review' ? 'bg-amber-500' : 'bg-green-500') }} h-2 rounded-full"
                                            style="width: {{ max(8, min(100, $row['confidence'])) }}%;"
                                        ></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-sm text-slate-600">
                                <p class="font-medium text-slate-900">{{ $row['date'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">{{ $row['time'] }}</p>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>
