@php
    $layout = auth()->user()?->isAdmin() ? 'layouts.admin' : 'layouts.user';
    $backRoute = auth()->user()?->isAdmin() ? route('admin.dashboard') : route('dashboard');
    $backLabel = auth()->user()?->isAdmin() ? 'Back to Admin Dashboard' : 'Back to Dashboard';
    $selectedDetectionId = ($selectedDetection ?? null)?->id;
@endphp

@extends($layout)

@section('title', 'Fact Check')
@section('page_title', 'Fact Check')

@if (auth()->user()?->isAdmin())
    @section('page_actions')
        <a href="{{ $backRoute }}" class="truthguard-header-action">
            {{ $backLabel }}
        </a>
        <a href="{{ route('detections.create') }}" class="truthguard-header-action truthguard-header-action-primary">
            New Detection
        </a>
    @endsection
@endif

@section('content')
    <div class="truthguard-mobile-page truthguard-mobile-check mx-auto w-full max-w-[1120px]">
        <div class="w-full">
            <livewire:detections.ai-composer :selected-detection-id="$selectedDetectionId" />
        </div>
    </div>
@endsection
