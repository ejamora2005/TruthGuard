@php
    $fakeRate = $totalDetections > 0 ? (int) round(($fakeDetections / $totalDetections) * 100) : 0;
    $reviewRate = $totalDetections > 0 ? (int) round(($reviewDetections / $totalDetections) * 100) : 0;
    $realRate = $totalDetections > 0 ? (int) round(($realDetections / $totalDetections) * 100) : 0;
    $subscriberBase = max($regularUsers, 1);
    $subscriptionCoverage = $regularUsers > 0 ? (int) round(($activeSubscribers / $regularUsers) * 100) : 0;
    $paidSubscribers = $starterSubscribers + $proSubscribers + $enterpriseSubscribers;
    $trendLabels = $monthlyDetectionTrend->pluck('label')->all();
    $detectionSeries = $monthlyDetectionTrend->pluck('value')->all();
    $signupSeries = $monthlySignupTrend->pluck('value')->all();

    $heroHighlights = [
        [
            'label' => 'Admin Accounts',
            'value' => number_format($adminUsers),
            'note' => 'Internal staff currently managing TruthGuard operations.',
            'tone' => 'brand',
        ],
        [
            'label' => 'Review Queue',
            'value' => number_format($reviewDetections),
            'note' => 'Cases that still need closer manual inspection.',
            'tone' => 'warning',
        ],
        [
            'label' => 'Paid Plans',
            'value' => number_format($paidSubscribers),
            'note' => 'Starter, Pro, and Enterprise users contributing subscription revenue.',
            'tone' => 'success',
        ],
    ];

    $metricCards = [
        [
            'label' => 'Regular Users',
            'value' => number_format($regularUsers),
            'change' => number_format($currentMonthSignups).' new this month',
            'change_tone' => 'success',
            'icon' => 'users',
        ],
        [
            'label' => 'Active Subscribers',
            'value' => number_format($activeSubscribers),
            'change' => $subscriptionCoverage.'% of user base',
            'change_tone' => 'brand',
            'icon' => 'subscription',
        ],
        [
            'label' => 'Detection Runs',
            'value' => number_format($estimatedApiRequests),
            'change' => number_format($currentMonthDetections).' this month',
            'change_tone' => 'warning',
            'icon' => 'activity',
        ],
        [
            'label' => 'Avg Fake Score',
            'value' => $averageFakeScore.'%',
            'change' => $fakeRate.'% flagged fake overall',
            'change_tone' => 'error',
            'icon' => 'shield',
        ],
    ];

    $subscriptionCards = [
        [
            'label' => 'Free',
            'value' => $freeSubscribers,
            'percent' => (int) round(($freeSubscribers / $subscriberBase) * 100),
            'bar' => 'bg-gray-400',
            'badge' => 'bg-gray-100 text-gray-700',
        ],
        [
            'label' => 'Starter',
            'value' => $starterSubscribers,
            'percent' => (int) round(($starterSubscribers / $subscriberBase) * 100),
            'bar' => 'bg-blue-light-500',
            'badge' => 'bg-blue-light-50 text-blue-light-700',
        ],
        [
            'label' => 'Pro',
            'value' => $proSubscribers,
            'percent' => (int) round(($proSubscribers / $subscriberBase) * 100),
            'bar' => 'bg-brand-500',
            'badge' => 'bg-brand-50 text-brand-500',
        ],
        [
            'label' => 'Enterprise',
            'value' => $enterpriseSubscribers,
            'percent' => (int) round(($enterpriseSubscribers / $subscriberBase) * 100),
            'bar' => 'bg-theme-purple-500',
            'badge' => 'bg-violet-50 text-violet-700',
        ],
    ];

    $moderationCards = [
        [
            'label' => 'Likely Fake',
            'value' => number_format($fakeDetections),
            'percent' => $fakeRate,
            'bar' => 'bg-error-500',
            'badge' => 'bg-error-50 text-error-600',
        ],
        [
            'label' => 'Needs Review',
            'value' => number_format($reviewDetections),
            'percent' => $reviewRate,
            'bar' => 'bg-warning-500',
            'badge' => 'bg-warning-50 text-warning-700',
        ],
        [
            'label' => 'Likely Real',
            'value' => number_format($realDetections),
            'percent' => $realRate,
            'bar' => 'bg-success-500',
            'badge' => 'bg-success-50 text-success-600',
        ],
    ];

    $sourceCards = [
        ['label' => 'Uploads', 'value' => number_format($uploadDetections)],
        ['label' => 'Links', 'value' => number_format($linkDetections)],
        ['label' => 'Images', 'value' => number_format($imageDetections)],
        ['label' => 'Videos', 'value' => number_format($videoDetections)],
    ];
@endphp

@extends('layouts.admin')

@section('title', 'TruthGuard Admin')
@section('page_title', 'Admin Dashboard')

@section('content')
    <div class="grid grid-cols-12 gap-4 md:gap-6">
        @include('admin.dashboard.partials.hero')
        @include('admin.dashboard.partials.metric-cards')
        @include('admin.dashboard.partials.growth-chart')
        @include('admin.dashboard.partials.usage-overview')
        @include('admin.dashboard.partials.subscription-health')
        @include('admin.dashboard.partials.moderation-health')
        @include('admin.dashboard.partials.recent-queue')
        @include('admin.dashboard.partials.newest-users')
        @include('admin.dashboard.partials.top-contributors')
        @include('admin.dashboard.partials.high-risk-cases')
    </div>
@endsection
