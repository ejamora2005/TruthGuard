<section class="col-span-12 space-y-6 xl:col-span-5">
    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Detection Breakdown</p>
        <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">How your cases are distributed</h3>

        <div class="mt-5 space-y-4">
            @foreach ($breakdownCards as $item)
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">{{ $item['label'] }}</p>
                            <p class="text-xs text-slate-500">{{ number_format($item['value']) }} cases</p>
                        </div>
                        <span @class([$item['bg'], $item['text'], 'rounded-full px-3 py-1 text-xs font-semibold'])>
                            {{ $item['percent'] }}%
                        </span>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                        <div class="{{ $item['bar'] }} h-full rounded-full" style="width: {{ $item['value'] > 0 ? max(6, $item['percent']) : 0 }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Source Mix</p>
        <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">What you are checking most</h3>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
            @foreach ($sourceMixCards as $item)
                <article class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $item['label'] }}</p>
                    <p class="mt-2 font-[Space_Grotesk] text-3xl font-bold text-slate-900">{{ $item['value'] }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Verification Playbook</p>
        <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">Checks that strengthen your verdicts</h3>

        <div class="mt-5 space-y-3">
            @foreach ($verificationPlaybook as $item)
                @php
                    $isExternal = \Illuminate\Support\Str::startsWith($item['url'], ['http://', 'https://']);
                @endphp
                <article class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                    <p class="font-semibold text-slate-900">{{ $item['title'] }}</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $item['summary'] }}</p>
                    <a
                        href="{{ $item['url'] }}"
                        @if ($isExternal) target="_blank" rel="noreferrer" @endif
                        class="mt-3 inline-flex items-center text-sm font-semibold text-blue-700 transition hover:text-blue-800"
                    >
                        {{ $item['label'] }}
                    </a>
                </article>
            @endforeach
        </div>
    </section>

    <section class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">Quick Actions</p>
        <h3 class="mt-1 font-[Space_Grotesk] text-2xl font-bold text-slate-900">What you can do next</h3>

        <div class="mt-5 space-y-3">
            @foreach ($quickActions as $action)
                <a href="{{ $action['href'] }}" class="block rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 transition hover:border-blue-200 hover:bg-blue-50/50">
                    <p class="font-semibold text-slate-900">{{ $action['title'] }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $action['summary'] }}</p>
                </a>
            @endforeach
        </div>
    </section>
</section>
