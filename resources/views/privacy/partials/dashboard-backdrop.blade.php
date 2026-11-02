<div class="pointer-events-none absolute inset-0 overflow-hidden bg-slate-100" aria-hidden="true">
    <div class="absolute inset-[-10px] flex select-none blur-[5px] opacity-70">
        <aside class="hidden w-64 shrink-0 border-r border-slate-200 bg-white p-6 md:block">
            <div class="flex items-center gap-3">
                <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-cyan-400 to-blue-700"></div>
                <div class="h-5 w-32 rounded-full bg-slate-800"></div>
            </div>
            <div class="mt-14 space-y-4">
                <div class="h-12 rounded-2xl bg-blue-100"></div>
                <div class="h-12 rounded-2xl bg-slate-100"></div>
                <div class="h-12 rounded-2xl bg-slate-100"></div>
                <div class="h-12 rounded-2xl bg-slate-100"></div>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            <header class="flex h-24 items-center justify-between border-b border-slate-200 bg-white px-8">
                <div class="space-y-2">
                    <div class="h-4 w-24 rounded-full bg-slate-300"></div>
                    <div class="h-7 w-52 rounded-full bg-slate-800"></div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="h-11 w-32 rounded-2xl bg-slate-100"></div>
                    <div class="h-12 w-12 rounded-full bg-blue-100"></div>
                </div>
            </header>

            <div class="space-y-6 p-8">
                <div class="rounded-[28px] bg-gradient-to-r from-blue-700 via-blue-600 to-cyan-500 p-8 shadow-xl">
                    <div class="h-7 w-72 rounded-full bg-white/90"></div>
                    <div class="mt-4 h-4 w-3/5 rounded-full bg-white/55"></div>
                    <div class="mt-2 h-4 w-2/5 rounded-full bg-white/40"></div>
                </div>
                <div class="grid grid-cols-2 gap-5 lg:grid-cols-4">
                    @foreach (range(1, 4) as $card)
                        <div class="h-32 rounded-3xl border border-slate-200 bg-white shadow-sm">
                            <div class="m-5 h-10 w-10 rounded-2xl bg-blue-100"></div>
                        </div>
                    @endforeach
                </div>
                <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                    <div class="h-72 rounded-3xl border border-slate-200 bg-white shadow-sm"></div>
                    <div class="h-72 rounded-3xl border border-slate-200 bg-white shadow-sm"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="absolute inset-0 bg-slate-100/45 backdrop-blur-[2px]"></div>
</div>
