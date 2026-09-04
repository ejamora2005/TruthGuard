<div class="col-span-12 xl:col-span-5">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
        <div class="flex justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Moderation Health</h3>
                <p class="mt-1 text-gray-500 text-theme-sm">Current verdict mix and content source distribution.</p>
            </div>
            <span class="rounded-full bg-gray-100 px-3 py-1 text-theme-xs font-medium text-gray-700">
                {{ number_format($totalDetections) }} cases
            </span>
        </div>

        <div class="mt-6 space-y-5">
            @foreach ($moderationCards as $item)
                <div>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-semibold text-gray-800 text-theme-sm">{{ $item['label'] }}</p>
                            <span class="block text-gray-500 text-theme-xs">{{ $item['value'] }} cases</span>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-theme-xs font-medium {{ $item['badge'] }}">
                            {{ $item['percent'] }}%
                        </span>
                    </div>
                    <div class="mt-3 h-2 rounded-sm bg-gray-200">
                        <div class="h-2 rounded-sm {{ $item['bar'] }}" style="width: {{ $item['value'] > 0 ? max(8, $item['percent']) : 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid grid-cols-2 gap-3">
            @foreach ($sourceCards as $item)
                <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                    <p class="text-gray-500 text-theme-xs uppercase tracking-[0.16em]">{{ $item['label'] }}</p>
                    <p class="mt-2 font-semibold text-gray-800 text-theme-xl">{{ $item['value'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</div>
