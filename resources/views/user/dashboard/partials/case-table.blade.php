<section id="case-table" class="col-span-12 rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Scan Table</p>
            <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">Recent detection scans</h3>
            <p class="mt-2 text-sm text-slate-600">Use the table for a compact view of verdicts, timestamps, and the explanation summary attached to each scan.</p>
        </div>
        <a href="{{ route('detections.create') }}" class="text-sm font-semibold text-blue-700 transition hover:text-blue-800">Open Fact Check</a>
    </div>

    <div class="mt-5 overflow-x-auto">
        <table class="w-full min-w-[980px] text-left text-sm">
            <thead class="border-b border-slate-200 text-xs uppercase tracking-[0.16em] text-slate-500">
                <tr>
                    <th class="px-3 py-3">Scan</th>
                    <th class="px-3 py-3">Source</th>
                    <th class="px-3 py-3">Type</th>
                    <th class="px-3 py-3">Fake Score</th>
                    <th class="px-3 py-3">Verdict</th>
                    <th class="px-3 py-3">Analyzed</th>
                    <th class="px-3 py-3">Summary</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($recentDetections as $case)
                    <tr class="transition hover:bg-slate-50">
                        <td class="px-3 py-4 font-semibold text-slate-900">
                            <a href="{{ route('detections.result', $case) }}" class="transition hover:text-blue-700">
                                Fact check result
                            </a>
                        </td>
                        <td class="px-3 py-4">
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                                    {{ ucfirst($case->source_kind) }}
                                </span>
                                @if ($case->platform)
                                    <span class="rounded-full bg-cyan-50 px-2.5 py-1 text-xs font-semibold text-cyan-700">
                                        {{ \Illuminate\Support\Str::headline($case->platform) }}
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-4 text-slate-700">{{ ucfirst($case->media_type) }}</td>
                        <td class="px-3 py-4 font-medium text-slate-900">{{ $case->fake_score }}%</td>
                        <td class="px-3 py-4">
                            @if ($case->verdict === 'fake')
                                <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Fake</span>
                            @elseif ($case->verdict === 'review')
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">Review</span>
                            @else
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Likely Real</span>
                            @endif
                        </td>
                        <td class="px-3 py-4 text-slate-600">{{ $case->analyzed_at?->format('M d, Y h:i A') }}</td>
                        <td class="px-3 py-4 text-slate-600">
                            {{ \Illuminate\Support\Str::limit($case->explanation_summary ?: ($case->notes ?: 'No explanation summary recorded.'), 110) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-600">
                            No detections submitted yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
