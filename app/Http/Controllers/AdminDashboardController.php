<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        $totalUsers = User::query()->count();
        $adminUsers = User::query()->where('is_admin', true)->count();
        $regularUsers = User::query()->where('is_admin', false)->count();
        $totalDetections = Detection::query()->count();
        $fakeDetections = Detection::query()->where('verdict', 'fake')->count();
        $reviewDetections = Detection::query()->where('verdict', 'review')->count();
        $realDetections = Detection::query()->where('verdict', 'real')->count();
        $uploadDetections = Detection::query()->where('source_kind', 'upload')->count();
        $linkDetections = Detection::query()->where('source_kind', 'link')->count();
        $imageDetections = Detection::query()->where('media_type', 'image')->count();
        $videoDetections = Detection::query()->where('media_type', 'video')->count();
        $averageFakeScore = (int) round(Detection::query()->avg('fake_score') ?? 0);
        $currentMonthDetections = Detection::query()
            ->whereBetween('analyzed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $currentMonthSignups = User::query()
            ->where('is_admin', false)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $activeSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_status', 'active')
            ->count();

        $freeSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_tier', 'free')
            ->count();

        $starterSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_tier', 'starter')
            ->count();

        $proSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_tier', 'pro')
            ->count();

        $enterpriseSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_tier', 'enterprise')
            ->count();

        $estimatedApiRequests = $totalDetections;
        $averageRequestsPerSubscriber = $activeSubscribers > 0
            ? round($estimatedApiRequests / $activeSubscribers, 1)
            : 0;

        $monthlyDetectionTrend = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'value' => Detection::query()
                    ->whereYear('analyzed_at', now()->year)
                    ->whereMonth('analyzed_at', $month)
                    ->count(),
            ];
        });

        $monthlySignupTrend = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'value' => User::query()
                    ->where('is_admin', false)
                    ->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', $month)
                    ->count(),
            ];
        });

        $recentDetections = Detection::query()
            ->with('user')
            ->latest('analyzed_at')
            ->limit(8)
            ->get();

        $highRiskDetections = Detection::query()
            ->with('user')
            ->orderByDesc('fake_score')
            ->orderByDesc('analyzed_at')
            ->limit(5)
            ->get();

        $latestUsers = User::query()
            ->where('is_admin', false)
            ->latest()
            ->limit(5)
            ->get();

        $topContributors = User::query()
            ->where('is_admin', false)
            ->withCount('detections')
            ->orderByDesc('detections_count')
            ->limit(5)
            ->get();

        return view('admin.dashboard', [
            'totalUsers' => $totalUsers,
            'adminUsers' => $adminUsers,
            'regularUsers' => $regularUsers,
            'totalDetections' => $totalDetections,
            'fakeDetections' => $fakeDetections,
            'reviewDetections' => $reviewDetections,
            'realDetections' => $realDetections,
            'uploadDetections' => $uploadDetections,
            'linkDetections' => $linkDetections,
            'imageDetections' => $imageDetections,
            'videoDetections' => $videoDetections,
            'averageFakeScore' => $averageFakeScore,
            'currentMonthDetections' => $currentMonthDetections,
            'currentMonthSignups' => $currentMonthSignups,
            'activeSubscribers' => $activeSubscribers,
            'freeSubscribers' => $freeSubscribers,
            'starterSubscribers' => $starterSubscribers,
            'proSubscribers' => $proSubscribers,
            'enterpriseSubscribers' => $enterpriseSubscribers,
            'estimatedApiRequests' => $estimatedApiRequests,
            'averageRequestsPerSubscriber' => $averageRequestsPerSubscriber,
            'monthlyDetectionTrend' => $monthlyDetectionTrend,
            'monthlySignupTrend' => $monthlySignupTrend,
            'recentDetections' => $recentDetections,
            'highRiskDetections' => $highRiskDetections,
            'latestUsers' => $latestUsers,
            'topContributors' => $topContributors,
        ]);
    }
}
