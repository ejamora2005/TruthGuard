<div class="col-span-12 xl:col-span-6">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Top Contributors</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">Users generating the most verification activity.</p>
            </div>
        </div>

        @if ($topContributors->isEmpty())
            <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500">
                No contributor data yet.
            </div>
        @else
            <div class="mt-6 space-y-4">
                @foreach ($topContributors as $index => $user)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-800">
                                {{ $index + 1 }}
                            </span>
                            <div>
                                <p class="font-semibold text-gray-800 text-theme-sm">{{ $user->name }}</p>
                                <span class="block text-gray-500 text-theme-xs">{{ $user->email }}</span>
                            </div>
                        </div>
                        <span class="rounded-full bg-brand-50 px-2.5 py-1 text-theme-xs font-medium text-brand-500">
                            {{ number_format($user->detections_count) }} cases
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
