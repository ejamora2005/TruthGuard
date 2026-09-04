@php
    $layout = auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.user';
@endphp

@extends($layout)

@section('title', 'Detection Result')
@section('page_title', 'Detection Result')
@section('page_subtitle', 'AI-Powered Fact Check Report')
@section('page_back_url', route('detections.create'))

@section('content')
    <div class="truthguard-mobile-page truthguard-mobile-result mx-auto w-full max-w-[1242px] py-2 sm:py-3">
        @include('detections.partials.advanced-result-card', ['selectedDetection' => $selectedDetection])
    </div>
@endsection
