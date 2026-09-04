<div id="subscriptions" class="col-span-12 xl:col-span-4">
    <div class="rounded-2xl border border-gray-200 bg-gray-100">
        <div class="rounded-2xl bg-white px-5 pb-11 pt-5 shadow-theme-md sm:px-6 sm:pt-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Subscription Health</h3>
                    <p class="mt-1 text-gray-500 text-theme-sm">
                        Active subscriptions compared against the full user base.
                    </p>
                </div>
                <span class="rounded-full bg-brand-50 px-3 py-1 text-theme-xs font-medium text-brand-500">
                    {{ $subscriptionCoverage }}%
                </span>
            </div>

            <div class="relative mx-auto mt-6 max-w-[330px]">
                <div
                    data-admin-radial-chart
                    data-value="{{ $subscriptionCoverage }}"
                    class="min-h-[250px]"
                ></div>
                <span class="absolute left-1/2 top-[82%] -translate-x-1/2 -translate-y-[82%] rounded-full bg-success-50 px-3 py-1 text-xs font-medium text-success-600">
                    {{ $activeSubscribers }} active
                </span>
            </div>

            <p class="mx-auto mt-1.5 w-full max-w-[320px] text-center text-sm text-gray-500 sm:text-base">
                {{ $freeSubscribers }} free, {{ $starterSubscribers + $proSubscribers + $enterpriseSubscribers }} paid or managed accounts are currently active in TruthGuard.
            </p>
        </div>

        <div class="space-y-4 px-6 py-5">
            @foreach ($subscriptionCards as $item)
                <div>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-gray-800 text-theme-sm">{{ $item['label'] }}</p>
                            <p class="text-theme-xs text-gray-500">{{ $item['value'] }} users</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-theme-xs font-medium {{ $item['badge'] }}">
                            {{ $item['percent'] }}%
                        </span>
                    </div>
                    <div class="mt-3 h-2 rounded-full bg-gray-200">
                        <div class="h-2 rounded-full {{ $item['bar'] }}" style="width: {{ $regularUsers > 0 ? max(8, $item['percent']) : 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
