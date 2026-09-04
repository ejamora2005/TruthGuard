<section class="col-span-12 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($overviewCards as $card)
        <article @class([
            'rounded-2xl border p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md',
            'border-slate-200 bg-white' => $card['tone'] === 'slate',
            'border-blue-100 bg-blue-50/60' => $card['tone'] === 'blue',
            'border-red-100 bg-red-50/60' => $card['tone'] === 'red',
            'border-emerald-100 bg-emerald-50/70' => $card['tone'] === 'emerald',
        ])>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ $card['label'] }}</p>
            <p class="mt-3 font-[Space_Grotesk] text-4xl font-bold text-slate-900">{{ $card['value'] }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ $card['note'] }}</p>
        </article>
    @endforeach
</section>
