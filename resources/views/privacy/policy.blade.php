@extends('layouts.auth-modern')

@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $logoUrl = is_file(public_path($logoPath)) ? asset($logoPath) : null;
@endphp

@section('content')
    <main class="relative min-h-dvh overflow-x-hidden bg-slate-100 px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
        <div class="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,#f8fbff_0%,#eef4ff_52%,#e8f1ff_100%)]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_14%_10%,rgba(14,165,233,0.2)_0%,transparent_32%),radial-gradient(circle_at_88%_78%,rgba(37,99,235,0.16)_0%,transparent_38%)]"></div>

        <div class="relative z-10 mx-auto w-full max-w-4xl">
            <header class="mb-6 rounded-[28px] border border-slate-200 bg-white/90 p-6 shadow-[0_22px_60px_rgba(15,23,42,0.10)] sm:p-8">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
                    @if ($logoUrl)
                        <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-12 w-12 object-contain">
                    @else
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-50 text-sm font-black text-blue-700">TG</span>
                    @endif
                    <span>
                        <span class="block text-lg font-black text-slate-950">TruthGuard</span>
                        <span class="block text-xs font-semibold text-slate-500">AI-Powered Media Verification</span>
                    </span>
                </a>

                <div class="mt-8">
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-blue-600">Privacy Policy</p>
                    <h1 class="mt-2 text-3xl font-black tracking-tight text-slate-950 sm:text-4xl">How TruthGuard handles your data</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-7 text-slate-500">
                        This page explains how account data and submitted evidence are processed for AI-assisted
                        fact-checking, security, notifications, and platform operations.
                    </p>
                </div>
            </header>

            <section class="rounded-[28px] border border-slate-200 bg-white/92 p-5 shadow-[0_22px_60px_rgba(15,23,42,0.08)] sm:p-8">
                @include('privacy.partials.policy-content', ['policyVersion' => $policyVersion])
            </section>
        </div>
    </main>
@endsection
