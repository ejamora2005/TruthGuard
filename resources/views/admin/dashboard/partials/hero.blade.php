<section class="col-span-12 overflow-hidden rounded-[28px] border border-gray-200 bg-white">
    <div class="grid gap-5 px-5 py-5 sm:px-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(320px,0.7fr)] xl:items-start">
        <div>
            <span class="inline-flex items-center rounded-full bg-brand-50 px-3 py-1 text-xs font-medium uppercase tracking-[0.18em] text-brand-500">
                TruthGuard command center
            </span>
            <h2 class="mt-4 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                Monitor growth, risk, and subscriber health from one admin workspace.
            </h2>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-gray-500 sm:text-base">
                Track verification traffic, monitor the moderation queue, and keep watch over platform adoption without jumping between screens.
            </p>

            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('detections.create') }}" class="inline-flex items-center rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600">
                    Run Detection
                </a>
                <a href="{{ route('home') }}" class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Open Website
                </a>
                <a href="{{ route('admin.dashboard') }}#recent-queue" class="inline-flex items-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Jump to Queue
                </a>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-gray-500">This month</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format($currentMonthDetections) }}</p>
                    <p class="mt-1 text-sm text-gray-500">detections processed</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-gray-500">New signups</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">{{ number_format($currentMonthSignups) }}</p>
                    <p class="mt-1 text-sm text-gray-500">regular users joined this month</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 px-4 py-4">
                    <p class="text-xs uppercase tracking-[0.18em] text-gray-500">Coverage</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $subscriptionCoverage }}%</p>
                    <p class="mt-1 text-sm text-gray-500">of users on active subscriptions</p>
                </div>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-1">
            @foreach ($heroHighlights as $item)
                <div @class([
                    'rounded-2xl border px-4 py-4',
                    'border-brand-200 bg-brand-50' => $item['tone'] === 'brand',
                    'border-warning-200 bg-warning-50' => $item['tone'] === 'warning',
                    'border-success-200 bg-success-50' => $item['tone'] === 'success',
                ])>
                    <p class="text-xs font-medium uppercase tracking-[0.18em] text-gray-500">{{ $item['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold text-gray-900">{{ $item['value'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-gray-600">{{ $item['note'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
