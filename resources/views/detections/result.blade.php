@php
    $layout = auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.user';
@endphp

@extends($layout)

@section('title', 'Detection Result')
@section('page_title', 'Detection Result')
@section('page_subtitle', 'AI-Powered Fact Check Report')
@section('page_back_url', route('detections.create'))

@section('content')
    <div class="truthguard-mobile-page truthguard-mobile-result mx-auto w-full max-w-[1400px] py-2 sm:py-3">
        <a href="{{ route('detections.create') }}" class="mb-3 inline-flex min-h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="M12 5v14M5 12h14" />
            </svg>
            New fact check
        </a>
        @if (session('status'))
            <p role="status" class="mb-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">{{ session('status') }}</p>
        @endif
        @if (data_get($selectedDetection->signals, 'analysis_retry.status') === 'failed' && $selectedDetection->fact_check_result !== null)
            <p role="status" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">The retry could not be completed. The previous saved result is shown below.</p>
        @endif
        @include('detections.partials.advanced-result-card', ['selectedDetection' => $selectedDetection])
    </div>
@endsection
