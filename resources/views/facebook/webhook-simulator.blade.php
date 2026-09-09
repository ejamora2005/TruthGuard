@extends('layouts.admin')

@section('title', 'Facebook Webhook Simulator')
@section('page_title', 'Facebook Webhook Simulator')

@section('page_actions')
    <a href="{{ route('admin.dashboard') }}" class="truthguard-header-action">
        Back to Admin Dashboard
    </a>
@endsection

@section('content')
    @php
        $simulation = session('facebook_webhook_simulation');
    @endphp

    <div class="mx-auto grid w-full max-w-5xl gap-5">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col gap-2">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-blue-600">Meta approval fallback</p>
                <h2 class="text-2xl font-black text-slate-950">Simulate a Facebook Page mention</h2>
                <p class="max-w-3xl text-sm leading-6 text-slate-600">
                    Use this for thesis/capstone demos while real public Facebook webhooks are blocked by Meta Business Verification and App Review.
                    It runs the same TruthGuard detection pipeline and prepares the Page reply.
                </p>
            </div>

            <form method="POST" action="{{ route('facebook.webhook-simulator.store') }}" class="mt-6 grid gap-4">
                @csrf

                <div>
                    <label for="caption_text" class="text-sm font-bold text-slate-800">Facebook post/comment text</label>
                    <textarea
                        id="caption_text"
                        name="caption_text"
                        rows="7"
                        required
                        class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm leading-6 text-slate-800 shadow-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                    >{{ old('caption_text', '@TruthGuard please check this sample claim: A circulating post says Alex Eala publicly announced that she is DDS and endorsed a political group. This is intentionally fictional and for thesis/capstone application testing only.') }}</textarea>
                    @error('caption_text')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="source_url" class="text-sm font-bold text-slate-800">Optional Facebook post URL</label>
                    <input
                        id="source_url"
                        type="url"
                        name="source_url"
                        value="{{ old('source_url') }}"
                        placeholder="https://www.facebook.com/..."
                        class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm text-slate-800 shadow-sm outline-none transition focus:border-blue-300 focus:ring-4 focus:ring-blue-100"
                    >
                    @error('source_url')
                        <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700">
                        Simulate mention
                    </button>
                    <a href="{{ route('detections.create') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-blue-200 hover:text-blue-700">
                        Open normal fact check
                    </a>
                </div>
            </form>
        </section>

        @if ($simulation)
            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.16em] text-emerald-700">Simulation complete</p>
                        <h3 class="mt-1 text-xl font-black text-slate-950">
                            Detection #{{ $simulation['detection_id'] }}: {{ \Illuminate\Support\Str::headline($simulation['verdict']) }}
                            ({{ $simulation['fake_score'] }}% risk)
                        </h3>
                    </div>

                    <a href="{{ route('detections.result', $simulation['detection_id']) }}" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-blue-700">
                        View result
                    </a>
                </div>

                <div class="mt-5 rounded-2xl border border-white/80 bg-white p-4">
                    <p class="text-xs font-black uppercase tracking-[0.16em] text-slate-500">Prepared Facebook reply</p>
                    <pre class="mt-3 whitespace-pre-wrap text-sm leading-6 text-slate-800">{{ $simulation['reply'] }}</pre>
                </div>
            </section>
        @endif
    </div>
@endsection
