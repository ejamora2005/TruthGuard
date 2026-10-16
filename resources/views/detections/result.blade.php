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
        @if (session('status'))
            <p role="status" class="mb-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-800">{{ session('status') }}</p>
        @endif
        @if (in_array($selectedDetection->processing_status, ['queued', 'processing'], true)
            || ($selectedDetection->processing_status === 'failed' && data_get($selectedDetection->fact_check_result, 'verdict') !== 'ANALYSIS_UNAVAILABLE'))
            @include('detections.partials.pending-result', ['selectedDetection' => $selectedDetection])
        @else
            @include('detections.partials.advanced-result-card', ['selectedDetection' => $selectedDetection])
        @endif
    </div>
@endsection
