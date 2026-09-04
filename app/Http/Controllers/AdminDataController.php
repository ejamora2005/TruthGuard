<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AdminDataController extends Controller
{
    public function dashboard(): JsonResponse
    {
        [$monthlyDetectionTrend, $monthlySignupTrend] = $this->monthlyBaseTrends();

        $regularUsers = User::query()->where('is_admin', false)->count();
        $adminUsers = User::query()->where('is_admin', true)->count();
        $totalUsers = $regularUsers + $adminUsers;
        $verifiedUsers = User::query()->whereNotNull('email_verified_at')->count();
        $currentMonthSignups = User::query()
            ->where('is_admin', false)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $totalDetections = Detection::query()->count();
        $fakeDetections = Detection::query()->where('verdict', 'fake')->count();
        $reviewDetections = Detection::query()->where('verdict', 'review')->count();
        $realDetections = Detection::query()->where('verdict', 'real')->count();
        $currentMonthDetections = Detection::query()
            ->whereBetween('analyzed_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $averageFakeScore = (int) round(Detection::query()->avg('fake_score') ?? 0);

        $freeSubscribers = User::query()->where('is_admin', false)->where('subscription_tier', 'free')->count();
        $starterSubscribers = User::query()->where('is_admin', false)->where('subscription_tier', 'starter')->count();
        $proSubscribers = User::query()->where('is_admin', false)->where('subscription_tier', 'pro')->count();
        $enterpriseSubscribers = User::query()->where('is_admin', false)->where('subscription_tier', 'enterprise')->count();
        $activeSubscribers = User::query()
            ->where('is_admin', false)
            ->where('subscription_status', 'active')
            ->count();
        $subscriptionCoverage = $regularUsers > 0 ? (int) round(($activeSubscribers / $regularUsers) * 100) : 0;

        $estimatedApiRequests = $totalDetections;
        $averageRequestsPerSubscriber = $activeSubscribers > 0
            ? round($estimatedApiRequests / $activeSubscribers, 1)
            : 0;
        $aiUsageSummary = $this->buildAiUsageSummary(
            totalRequests: $estimatedApiRequests,
            currentMonthRequests: $currentMonthDetections,
            activeSubscribers: $activeSubscribers,
            averageFakeScore: $averageFakeScore,
        );

        $uploadDetections = Detection::query()->where('source_kind', 'upload')->count();
        $linkDetections = Detection::query()->where('source_kind', 'link')->count();
        $imageDetections = Detection::query()->where('media_type', 'image')->count();
        $videoDetections = Detection::query()->where('media_type', 'video')->count();

        $renewingSoon = User::query()
            ->where('is_admin', false)
            ->whereNotNull('subscription_renews_at')
            ->whereBetween('subscription_renews_at', [now(), now()->addDays(30)])
            ->count();

        $dormantUsers = User::query()
            ->where('is_admin', false)
            ->where(function ($query) {
                $query->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', now()->subDays(30));
            })
            ->count();

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
            ->latest()
            ->limit(6)
            ->get();

        $topContributors = User::query()
            ->where('is_admin', false)
            ->withCount('detections')
            ->orderByDesc('detections_count')
            ->limit(5)
            ->get();

        $alerts = array_values(array_filter([
            $reviewDetections > 0 ? [
                'title' => 'Review queue waiting',
                'value' => $reviewDetections,
                'description' => 'Cases still awaiting a final moderation decision.',
                'tone' => 'warning',
            ] : null,
            $renewingSoon > 0 ? [
                'title' => 'Renewals due soon',
                'value' => $renewingSoon,
                'description' => 'Subscriber renewals expected within the next 30 days.',
                'tone' => 'brand',
            ] : null,
            $dormantUsers > 0 ? [
                'title' => 'Dormant users',
                'value' => $dormantUsers,
                'description' => 'Regular accounts with no recent login activity.',
                'tone' => 'error',
            ] : null,
        ]));

        return response()->json([
            'overviewMetrics' => [
                [
                    'label' => 'Total Accounts',
                    'value' => number_format($totalUsers),
                    'note' => number_format($adminUsers).' admins | '.number_format($regularUsers).' users',
                ],
                [
                    'label' => 'Active Subscribers',
                    'value' => number_format($activeSubscribers),
                    'note' => $subscriptionCoverage.'% of the user base',
                ],
                [
                    'label' => 'Detection Runs',
                    'value' => number_format($estimatedApiRequests),
                    'note' => number_format($currentMonthDetections).' this month',
                ],
                [
                    'label' => 'Verified Accounts',
                    'value' => number_format($verifiedUsers),
                    'note' => number_format($currentMonthSignups).' joined this month',
                ],
            ],
            'monthlyTrend' => [
                'labels' => $monthlyDetectionTrend->pluck('label')->all(),
                'detections' => $monthlyDetectionTrend->pluck('value')->all(),
                'signups' => $monthlySignupTrend->pluck('value')->all(),
            ],
            'moderation' => [
                'items' => [
                    [
                        'label' => 'Likely Fake',
                        'value' => $fakeDetections,
                        'percent' => $this->percentage($fakeDetections, $totalDetections),
                    ],
                    [
                        'label' => 'Needs Review',
                        'value' => $reviewDetections,
                        'percent' => $this->percentage($reviewDetections, $totalDetections),
                    ],
                    [
                        'label' => 'Likely Real',
                        'value' => $realDetections,
                        'percent' => $this->percentage($realDetections, $totalDetections),
                    ],
                ],
                'sources' => [
                    ['label' => 'Uploads', 'value' => $uploadDetections],
                    ['label' => 'Links', 'value' => $linkDetections],
                    ['label' => 'Images', 'value' => $imageDetections],
                    ['label' => 'Videos', 'value' => $videoDetections],
                ],
            ],
            'subscription' => [
                'coverage' => $subscriptionCoverage,
                'tiers' => [
                    ['label' => 'Free', 'value' => $freeSubscribers],
                    ['label' => 'Starter', 'value' => $starterSubscribers],
                    ['label' => 'Pro', 'value' => $proSubscribers],
                    ['label' => 'Enterprise', 'value' => $enterpriseSubscribers],
                ],
                'renewingSoon' => $renewingSoon,
            ],
            'aiUsage' => [
                'estimatedRequests' => $estimatedApiRequests,
                'currentMonth' => $currentMonthDetections,
                'averagePerSubscriber' => $averageRequestsPerSubscriber,
                'averageFakeScore' => $averageFakeScore,
                'note' => $aiUsageSummary['usageNote'],
            ] + $aiUsageSummary['tokenUsage'] + [
                'model' => $aiUsageSummary['modelConfig']['model'],
                'providerEnabled' => $aiUsageSummary['modelConfig']['providerEnabled'],
                'status' => $aiUsageSummary['capacity']['status'],
                'statusLabel' => $aiUsageSummary['capacity']['label'],
            ],
            'alerts' => $alerts,
            'recentDetections' => $recentDetections->map(fn (Detection $detection) => $this->serializeDetection($detection))->all(),
            'highRiskDetections' => $highRiskDetections->map(fn (Detection $detection) => $this->serializeDetection($detection))->all(),
            'latestUsers' => $latestUsers->map(fn (User $user) => $this->serializeUser($user))->all(),
            'topContributors' => $topContributors->map(fn (User $user) => $this->serializeUser($user))->all(),
        ]);
    }

    public function users(): JsonResponse
    {
        $users = User::query()
            ->withCount('detections')
            ->latest()
            ->limit(30)
            ->get();

        $totalUsers = User::query()->count();
        $adminUsers = User::query()->where('is_admin', true)->count();
        $verifiedUsers = User::query()->whereNotNull('email_verified_at')->count();
        $activeSubscribers = User::query()->where('is_admin', false)->where('subscription_status', 'active')->count();
        $joinedThisMonth = User::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->count();

        return response()->json([
            'summaryCards' => [
                ['label' => 'Total Accounts', 'value' => number_format($totalUsers), 'note' => 'All admin and regular accounts'],
                ['label' => 'Admin Accounts', 'value' => number_format($adminUsers), 'note' => 'Internal platform access only'],
                ['label' => 'Verified Emails', 'value' => number_format($verifiedUsers), 'note' => 'Accounts with verified email addresses'],
                ['label' => 'Joined This Month', 'value' => number_format($joinedThisMonth), 'note' => number_format($activeSubscribers).' active subscribers'],
            ],
            'users' => $users->map(fn (User $user) => $this->serializeUser($user))->all(),
        ]);
    }

    public function subscriptions(): JsonResponse
    {
        $regularUsers = User::query()->where('is_admin', false)->count();
        $activeSubscribers = User::query()->where('is_admin', false)->where('subscription_status', 'active')->count();
        $paidSubscribers = User::query()
            ->where('is_admin', false)
            ->whereIn('subscription_tier', ['starter', 'pro', 'enterprise'])
            ->count();
        $renewingSoon = User::query()
            ->where('is_admin', false)
            ->whereNotNull('subscription_renews_at')
            ->whereBetween('subscription_renews_at', [now(), now()->addDays(30)])
            ->count();

        $tierCounts = [
            ['label' => 'Free', 'value' => User::query()->where('is_admin', false)->where('subscription_tier', 'free')->count()],
            ['label' => 'Starter', 'value' => User::query()->where('is_admin', false)->where('subscription_tier', 'starter')->count()],
            ['label' => 'Pro', 'value' => User::query()->where('is_admin', false)->where('subscription_tier', 'pro')->count()],
            ['label' => 'Enterprise', 'value' => User::query()->where('is_admin', false)->where('subscription_tier', 'enterprise')->count()],
        ];

        $accounts = User::query()
            ->where('is_admin', false)
            ->orderByRaw("CASE subscription_tier WHEN 'enterprise' THEN 1 WHEN 'pro' THEN 2 WHEN 'starter' THEN 3 ELSE 4 END")
            ->latest('subscription_renews_at')
            ->limit(25)
            ->get();

        return response()->json([
            'summaryCards' => [
                ['label' => 'Regular Users', 'value' => number_format($regularUsers), 'note' => 'Accounts eligible for subscriptions'],
                ['label' => 'Active Subscriptions', 'value' => number_format($activeSubscribers), 'note' => $regularUsers > 0 ? $this->percentage($activeSubscribers, $regularUsers).'%' : '0%'],
                ['label' => 'Paid Accounts', 'value' => number_format($paidSubscribers), 'note' => 'Starter, Pro, and Enterprise tiers'],
                ['label' => 'Renewing Soon', 'value' => number_format($renewingSoon), 'note' => 'Renewals due within 30 days'],
            ],
            'tierDistribution' => $tierCounts,
            'accounts' => $accounts->map(fn (User $user) => $this->serializeUser($user))->all(),
        ]);
    }

    public function detections(): JsonResponse
    {
        $totalDetections = Detection::query()->count();
        $fakeDetections = Detection::query()->where('verdict', 'fake')->count();
        $reviewDetections = Detection::query()->where('verdict', 'review')->count();
        $realDetections = Detection::query()->where('verdict', 'real')->count();
        $imageDetections = Detection::query()->where('media_type', 'image')->count();
        $videoDetections = Detection::query()->where('media_type', 'video')->count();
        $linkDetections = Detection::query()->where('source_kind', 'link')->count();
        $uploadDetections = Detection::query()->where('source_kind', 'upload')->count();

        $monthlyVerdicts = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'fake' => Detection::query()->whereYear('analyzed_at', now()->year)->whereMonth('analyzed_at', $month)->where('verdict', 'fake')->count(),
                'review' => Detection::query()->whereYear('analyzed_at', now()->year)->whereMonth('analyzed_at', $month)->where('verdict', 'review')->count(),
                'real' => Detection::query()->whereYear('analyzed_at', now()->year)->whereMonth('analyzed_at', $month)->where('verdict', 'real')->count(),
            ];
        });

        $recentDetections = Detection::query()->with('user')->latest('analyzed_at')->limit(30)->get();
        $highRiskDetections = Detection::query()->with('user')->orderByDesc('fake_score')->orderByDesc('analyzed_at')->limit(10)->get();

        return response()->json([
            'summaryCards' => [
                ['label' => 'Total Detections', 'value' => number_format($totalDetections), 'note' => 'All verification runs stored in TruthGuard'],
                ['label' => 'Likely Fake', 'value' => number_format($fakeDetections), 'note' => $this->percentage($fakeDetections, $totalDetections).'% of all cases'],
                ['label' => 'Needs Review', 'value' => number_format($reviewDetections), 'note' => 'Cases still pending stronger confirmation'],
                ['label' => 'Likely Real', 'value' => number_format($realDetections), 'note' => 'Low-risk outputs after verification'],
            ],
            'monthlyVerdicts' => [
                'labels' => $monthlyVerdicts->pluck('label')->all(),
                'fake' => $monthlyVerdicts->pluck('fake')->all(),
                'review' => $monthlyVerdicts->pluck('review')->all(),
                'real' => $monthlyVerdicts->pluck('real')->all(),
            ],
            'mediaMix' => [
                ['label' => 'Images', 'value' => $imageDetections],
                ['label' => 'Videos', 'value' => $videoDetections],
                ['label' => 'Links', 'value' => $linkDetections],
                ['label' => 'Uploads', 'value' => $uploadDetections],
            ],
            'recentDetections' => $recentDetections->map(fn (Detection $detection) => $this->serializeDetection($detection))->all(),
            'highRiskDetections' => $highRiskDetections->map(fn (Detection $detection) => $this->serializeDetection($detection))->all(),
        ]);
    }

    public function aiUsage(): JsonResponse
    {
        $monthlyDetectionTrend = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'value' => Detection::query()->whereYear('analyzed_at', now()->year)->whereMonth('analyzed_at', $month)->count(),
            ];
        });

        $activeSubscribers = User::query()->where('is_admin', false)->where('subscription_status', 'active')->count();
        $estimatedApiRequests = Detection::query()->count();
        $currentMonthDetections = Detection::query()->whereBetween('analyzed_at', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $averageRequestsPerSubscriber = $activeSubscribers > 0 ? round($estimatedApiRequests / $activeSubscribers, 1) : 0;
        $averageFakeScore = (int) round(Detection::query()->avg('fake_score') ?? 0);
        $aiUsageSummary = $this->buildAiUsageSummary(
            totalRequests: $estimatedApiRequests,
            currentMonthRequests: $currentMonthDetections,
            activeSubscribers: $activeSubscribers,
            averageFakeScore: $averageFakeScore,
        );

        $topContributors = User::query()
            ->where('is_admin', false)
            ->withCount('detections')
            ->orderByDesc('detections_count')
            ->limit(8)
            ->get();

        $bySource = [
            ['label' => 'Uploads', 'value' => Detection::query()->where('source_kind', 'upload')->count()],
            ['label' => 'Links', 'value' => Detection::query()->where('source_kind', 'link')->count()],
        ];

        $byMedia = [
            ['label' => 'Images', 'value' => Detection::query()->where('media_type', 'image')->count()],
            ['label' => 'Videos', 'value' => Detection::query()->where('media_type', 'video')->count()],
        ];

        return response()->json([
            'summaryCards' => [
                ['label' => 'Token Capacity', 'value' => $aiUsageSummary['capacity']['label'], 'note' => $aiUsageSummary['capacity']['description']],
                ['label' => 'Tokens Used', 'value' => number_format($aiUsageSummary['tokenUsage']['tokensUsed']), 'note' => $aiUsageSummary['tokenUsage']['usageSourceLabel']],
                ['label' => 'Tokens Remaining', 'value' => $aiUsageSummary['tokenUsage']['tokensRemainingLabel'], 'note' => $aiUsageSummary['tokenUsage']['hasTokenCapacity'] === false ? 'Budget needs attention' : 'Estimated monthly availability'],
                ['label' => 'This Month', 'value' => number_format($currentMonthDetections), 'note' => 'Detection runs in the current month'],
            ],
            'monthlyUsage' => [
                'labels' => $monthlyDetectionTrend->pluck('label')->all(),
                'values' => $monthlyDetectionTrend->pluck('value')->all(),
            ],
            'tokenUsage' => $aiUsageSummary['tokenUsage'],
            'capacity' => $aiUsageSummary['capacity'],
            'modelConfig' => $aiUsageSummary['modelConfig'],
            'forecast' => $aiUsageSummary['forecast'],
            'recommendations' => $aiUsageSummary['recommendations'],
            'overview' => [
                'estimatedRequests' => $estimatedApiRequests,
                'currentMonth' => $currentMonthDetections,
                'averagePerSubscriber' => $averageRequestsPerSubscriber,
                'averageFakeScore' => $averageFakeScore,
            ],
            'topContributors' => $topContributors->map(fn (User $user) => $this->serializeUser($user))->all(),
            'sourceMix' => $bySource,
            'mediaMix' => $byMedia,
            'usageNote' => $aiUsageSummary['usageNote'],
        ]);
    }

    public function projectTracker(): JsonResponse
    {
        $capabilitySections = $this->projectTrackerCapabilitySections();
        $allCapabilities = collect($capabilitySections)
            ->flatMap(fn (array $section) => $section['items'] ?? [])
            ->values();

        $statusCounts = [
            'added' => $allCapabilities->where('status', 'added')->count(),
            'partial' => $allCapabilities->where('status', 'partial')->count(),
            'missing' => $allCapabilities->where('status', 'missing')->count(),
        ];

        $weightedCompleted = $statusCounts['added'] + ($statusCounts['partial'] * 0.5);
        $completionPercent = $allCapabilities->isNotEmpty()
            ? (int) round(($weightedCompleted / $allCapabilities->count()) * 100)
            : 0;

        $sprints = $this->projectTrackerSprintBoard();
        $sprintSummary = [
            'done' => collect($sprints)->where('status', 'done')->count(),
            'inProgress' => collect($sprints)->where('status', 'in_progress')->count(),
            'next' => collect($sprints)->where('status', 'next')->count(),
            'total' => count($sprints),
        ];

        $focusItems = $allCapabilities
            ->filter(fn (array $item) => in_array($item['status'] ?? '', ['partial', 'missing'], true))
            ->sortBy([
                fn (array $item) => ($item['status'] ?? '') === 'missing' ? 0 : 1,
                fn (array $item) => $item['title'] ?? '',
            ])
            ->values()
            ->all();

        return response()->json([
            'overview' => [
                'completionPercent' => $completionPercent,
                'totalCapabilities' => $allCapabilities->count(),
                'addedCount' => $statusCounts['added'],
                'partialCount' => $statusCounts['partial'],
                'missingCount' => $statusCounts['missing'],
                'currentFocus' => 'Complete CNN training, connect the first validated checkpoint, and harden deployment telemetry before adding broader model families.',
                'summary' => 'This board tracks implemented capabilities, incomplete work, and the next sprint sequence so the team can finish the build without gaps.',
            ],
            'summaryCards' => [
                [
                    'label' => 'Added',
                    'value' => number_format($statusCounts['added']),
                    'note' => 'Capabilities already working in the current system',
                ],
                [
                    'label' => 'Partial',
                    'value' => number_format($statusCounts['partial']),
                    'note' => 'Capabilities that exist but still need stronger completion',
                ],
                [
                    'label' => 'Missing',
                    'value' => number_format($statusCounts['missing']),
                    'note' => 'Capabilities still not integrated into the current build',
                ],
                [
                    'label' => 'Sprints Done',
                    'value' => number_format($sprintSummary['done']).' / '.number_format($sprintSummary['total']),
                    'note' => number_format($sprintSummary['inProgress']).' in progress | '.number_format($sprintSummary['next']).' next',
                ],
            ],
            'latestUpdates' => $this->projectTrackerUpdates(),
            'capabilitySections' => $capabilitySections,
            'focusItems' => $focusItems,
            'nextActions' => $this->projectTrackerNextActions(),
            'sprints' => $sprints,
        ]);
    }

    private function monthlyBaseTrends(): array
    {
        $monthlyDetectionTrend = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'value' => Detection::query()->whereYear('analyzed_at', now()->year)->whereMonth('analyzed_at', $month)->count(),
            ];
        });

        $monthlySignupTrend = collect(range(1, 12))->map(function (int $month) {
            return [
                'label' => now()->startOfYear()->addMonths($month - 1)->format('M'),
                'value' => User::query()->where('is_admin', false)->whereYear('created_at', now()->year)->whereMonth('created_at', $month)->count(),
            ];
        });

        return [$monthlyDetectionTrend, $monthlySignupTrend];
    }

    private function percentage(int $value, int $total): int
    {
        return $total > 0 ? (int) round(($value / $total) * 100) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAiUsageSummary(int $totalRequests, int $currentMonthRequests, int $activeSubscribers, int $averageFakeScore): array
    {
        $providerEnabled = filled(config('services.openai.key'));
        $model = (string) config('services.openai.model', 'gpt-5.4');
        $maxCompletionTokens = max(0, (int) config('services.openai.max_completion_tokens', 1400));
        $estimatedInputTokens = max(0, (int) config('services.openai.estimated_input_tokens_per_request', 1800));
        $estimatedOutputTokens = max(0, (int) config('services.openai.estimated_output_tokens_per_request', $maxCompletionTokens));
        $estimatedTokensPerRequest = max(1, $estimatedInputTokens + $estimatedOutputTokens);
        $monthlyTokenBudget = max(0, (int) config('services.openai.monthly_token_budget', 0));
        $warningThreshold = max(1, min(99, (int) config('services.openai.token_warning_threshold', 80)));

        $monthRange = [now()->startOfMonth(), now()->endOfMonth()];
        $loggedMonthlyTokens = $this->loggedTokenTotalForRange($monthRange);
        $estimatedMonthlyTokens = $currentMonthRequests * $estimatedTokensPerRequest;
        $tokensUsed = $loggedMonthlyTokens > 0 ? $loggedMonthlyTokens : $estimatedMonthlyTokens;
        $usageSource = $loggedMonthlyTokens > 0 ? 'logged' : 'estimated';
        $usagePercent = $monthlyTokenBudget > 0 ? min(100, (int) round(($tokensUsed / $monthlyTokenBudget) * 100)) : 0;
        $tokensRemaining = $monthlyTokenBudget > 0 ? max(0, $monthlyTokenBudget - $tokensUsed) : null;
        $hasTokenCapacity = match (true) {
            ! $providerEnabled => false,
            $monthlyTokenBudget <= 0 => null,
            default => $tokensRemaining > 0,
        };

        [$capacityStatus, $capacityLabel, $capacityDescription] = $this->aiCapacityMeta(
            providerEnabled: $providerEnabled,
            monthlyTokenBudget: $monthlyTokenBudget,
            usagePercent: $usagePercent,
            tokensRemaining: $tokensRemaining,
            warningThreshold: $warningThreshold,
        );

        $dayOfMonth = max(1, now()->day);
        $daysInMonth = max(1, now()->daysInMonth);
        $dailyAverageRequests = round($currentMonthRequests / $dayOfMonth, 1);
        $projectedMonthlyRequests = (int) round($dailyAverageRequests * $daysInMonth);
        $projectedMonthlyTokens = $projectedMonthlyRequests * $estimatedTokensPerRequest;
        $projectedPercent = $monthlyTokenBudget > 0 ? min(100, (int) round(($projectedMonthlyTokens / $monthlyTokenBudget) * 100)) : 0;
        $projectedRemaining = $monthlyTokenBudget > 0 ? max(0, $monthlyTokenBudget - $projectedMonthlyTokens) : null;

        return [
            'tokenUsage' => [
                'estimatedRequests' => $totalRequests,
                'currentMonth' => $currentMonthRequests,
                'averagePerSubscriber' => $activeSubscribers > 0 ? round($totalRequests / $activeSubscribers, 1) : 0,
                'averageFakeScore' => $averageFakeScore,
                'monthlyTokenBudget' => $monthlyTokenBudget,
                'monthlyTokenBudgetLabel' => $monthlyTokenBudget > 0 ? number_format($monthlyTokenBudget) : 'Not set',
                'tokensUsed' => $tokensUsed,
                'tokensUsedLabel' => number_format($tokensUsed),
                'tokensRemaining' => $tokensRemaining,
                'tokensRemainingLabel' => $tokensRemaining === null ? 'Set budget' : number_format($tokensRemaining),
                'usagePercent' => $usagePercent,
                'estimatedTokensPerRequest' => $estimatedTokensPerRequest,
                'estimatedInputTokensPerRequest' => $estimatedInputTokens,
                'estimatedOutputTokensPerRequest' => $estimatedOutputTokens,
                'usageSource' => $usageSource,
                'usageSourceLabel' => $usageSource === 'logged'
                    ? 'Stored token telemetry'
                    : 'Estimated from detection runs',
                'hasTokenCapacity' => $hasTokenCapacity,
            ],
            'capacity' => [
                'status' => $capacityStatus,
                'label' => $capacityLabel,
                'description' => $capacityDescription,
                'warningThreshold' => $warningThreshold,
            ],
            'modelConfig' => [
                'provider' => 'OpenAI',
                'providerEnabled' => $providerEnabled,
                'model' => $model,
                'timeout' => (int) config('services.openai.timeout', 40),
                'maxCompletionTokens' => $maxCompletionTokens,
            ],
            'forecast' => [
                'dailyAverageRequests' => $dailyAverageRequests,
                'projectedMonthlyRequests' => $projectedMonthlyRequests,
                'projectedMonthlyTokens' => $projectedMonthlyTokens,
                'projectedPercent' => $projectedPercent,
                'projectedRemaining' => $projectedRemaining,
                'projectedRemainingLabel' => $projectedRemaining === null ? 'Set budget' : number_format($projectedRemaining),
            ],
            'recommendations' => $this->aiUsageRecommendations($providerEnabled, $monthlyTokenBudget, $usagePercent, $projectedPercent, $usageSource),
            'usageNote' => $usageSource === 'logged'
                ? 'Token usage is based on telemetry stored with detection results.'
                : 'Token usage is estimated from verification runs until direct OpenAI billing telemetry is stored per request.',
        ];
    }

    /**
     * @param  array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}  $range
     */
    private function loggedTokenTotalForRange(array $range): int
    {
        return Detection::query()
            ->whereBetween('analyzed_at', $range)
            ->get(['signals'])
            ->sum(fn (Detection $detection): int => $this->extractTokenUsage($detection->signals ?? []));
    }

    private function extractTokenUsage(mixed $signals): int
    {
        if (! is_array($signals)) {
            return 0;
        }

        foreach (['ai_usage', 'openai_usage', 'token_usage', 'usage'] as $key) {
            $usage = $signals[$key] ?? null;

            if (! is_array($usage)) {
                continue;
            }

            foreach (['total_tokens', 'totalTokens', 'tokens', 'estimated_tokens'] as $tokenKey) {
                if (isset($usage[$tokenKey]) && is_numeric($usage[$tokenKey])) {
                    return max(0, (int) $usage[$tokenKey]);
                }
            }

            $prompt = is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : 0;
            $completion = is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : 0;

            if (($prompt + $completion) > 0) {
                return $prompt + $completion;
            }
        }

        return 0;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function aiCapacityMeta(bool $providerEnabled, int $monthlyTokenBudget, int $usagePercent, ?int $tokensRemaining, int $warningThreshold): array
    {
        if (! $providerEnabled) {
            return ['offline', 'OpenAI not configured', 'Add an OpenAI API key before relying on external AI analysis.'];
        }

        if ($monthlyTokenBudget <= 0) {
            return ['unknown', 'Budget not set', 'Set OPENAI_MONTHLY_TOKEN_BUDGET to monitor remaining monthly capacity.'];
        }

        if (($tokensRemaining ?? 0) <= 0) {
            return ['critical', 'Budget exhausted', 'Estimated monthly token use has reached the configured budget.'];
        }

        if ($usagePercent >= $warningThreshold) {
            return ['warning', 'Running low', "Usage has reached {$usagePercent}% of the configured monthly budget."];
        }

        return ['healthy', 'Tokens available', "Usage is at {$usagePercent}% of the configured monthly budget."];
    }

    /**
     * @return array<int, array{title: string, detail: string, tone: string}>
     */
    private function aiUsageRecommendations(bool $providerEnabled, int $monthlyTokenBudget, int $usagePercent, int $projectedPercent, string $usageSource): array
    {
        $items = [];

        if (! $providerEnabled) {
            $items[] = [
                'title' => 'Connect OpenAI before deployment',
                'detail' => 'OPENAI_API_KEY is missing, so the system will rely on fallback analysis instead of the external model.',
                'tone' => 'error',
            ];
        }

        if ($monthlyTokenBudget <= 0) {
            $items[] = [
                'title' => 'Set a monthly token budget',
                'detail' => 'Add OPENAI_MONTHLY_TOKEN_BUDGET so admins can see if the AI still has capacity this month.',
                'tone' => 'warning',
            ];
        } elseif ($usagePercent >= 100 || $projectedPercent >= 100) {
            $items[] = [
                'title' => 'Prepare to raise capacity',
                'detail' => 'Current or projected token usage can exhaust the configured monthly budget.',
                'tone' => 'error',
            ];
        } elseif ($usagePercent >= 80 || $projectedPercent >= 80) {
            $items[] = [
                'title' => 'Watch token burn rate',
                'detail' => 'Usage is nearing the warning threshold. Review high-volume users and detection volume.',
                'tone' => 'warning',
            ];
        }

        if ($usageSource !== 'logged') {
            $items[] = [
                'title' => 'Add direct token telemetry later',
                'detail' => 'The dashboard is estimating tokens from detection volume. Store OpenAI usage per request for exact billing numbers.',
                'tone' => 'brand',
            ];
        }

        return $items ?: [[
            'title' => 'AI capacity looks healthy',
            'detail' => 'Configured budget and projected usage are within the current operating range.',
            'tone' => 'success',
        ]];
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->isAdmin() ? 'Admin' : 'User',
            'initial' => strtoupper(substr($user->name, 0, 1)),
            'subscriptionTier' => ucfirst((string) ($user->subscription_tier ?? 'free')),
            'subscriptionStatus' => ucfirst((string) ($user->subscription_status ?? 'inactive')),
            'subscriptionRenewsAt' => $user->subscription_renews_at?->toIso8601String(),
            'subscriptionRenewsLabel' => $user->subscription_renews_at?->format('M d, Y') ?? 'Not scheduled',
            'detectionsCount' => (int) ($user->detections_count ?? 0),
            'emailVerified' => $user->email_verified_at !== null,
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'lastLoginLabel' => $user->last_login_at?->format('M d, Y h:i A') ?? 'No login recorded',
            'createdAt' => $user->created_at?->toIso8601String(),
            'joinedLabel' => $user->created_at?->format('M d, Y') ?? 'Unknown',
        ];
    }

    private function serializeDetection(Detection $detection): array
    {
        return [
            'id' => $detection->id,
            'caseCode' => 'Detection result',
            'user' => $detection->user ? [
                'name' => $detection->user->name,
                'email' => $detection->user->email,
            ] : null,
            'sourceKind' => ucfirst((string) $detection->source_kind),
            'platform' => $detection->platform ? Str::headline($detection->platform) : null,
            'mediaType' => ucfirst((string) $detection->media_type),
            'fakeScore' => (int) $detection->fake_score,
            'processingStatus' => ucfirst((string) $detection->processing_status),
            'verdict' => ucfirst((string) $detection->verdict),
            'verdictKey' => (string) $detection->verdict,
            'captionText' => $detection->caption_text,
            'notes' => $detection->notes,
            'preprocessingSummary' => $detection->preprocessing_summary,
            'analysisSummary' => $detection->analysis_summary,
            'verificationSummary' => $detection->verification_summary,
            'explanationSummary' => $detection->explanation_summary,
            'recommendation' => $detection->recommendation,
            'signals' => $detection->signals,
            'verificationSources' => $detection->verification_sources,
            'mediaUrl' => $detection->media_url,
            'analyzedAt' => $detection->analyzed_at?->toIso8601String(),
            'analyzedLabel' => $detection->analyzed_at?->format('M d, Y h:i A') ?? 'Pending',
        ];
    }

    private function projectTrackerUpdates(): array
    {
        return [
            [
                'title' => 'Laravel pipeline became the main controller',
                'detail' => 'Laravel owns the submission, analysis, evidence enrichment, and result storage flow.',
                'tone' => 'success',
            ],
            [
                'title' => 'Evidence collection runs inside Laravel',
                'detail' => 'Playwright scraping plus API evidence gathering run before AI comparison inside the Laravel detection pipeline.',
                'tone' => 'success',
            ],
            [
                'title' => 'External AI is now OpenAI-only',
                'detail' => 'Gemini and Anthropic paths were removed so one provider handles the AI comparison stage.',
                'tone' => 'success',
            ],
            [
                'title' => 'Deepfake stage now has a real model wrapper',
                'detail' => 'The pipeline can call a model adapter before the evidence engine and fall back safely if the model is unavailable.',
                'tone' => 'success',
            ],
            [
                'title' => 'CNN adapter and training utilities were added',
                'detail' => 'A CNN inference adapter, a starter Keras trainer, and dataset preparation tooling are now in the repo.',
                'tone' => 'brand',
            ],
            [
                'title' => 'Admin tracker page was added for sprint monitoring',
                'detail' => 'Admins can now monitor what is added, what is missing, and which sprint should happen next.',
                'tone' => 'brand',
            ],
        ];
    }

    private function projectTrackerNextActions(): array
    {
        return [
            'Download the real FaceForensics++ and Celeb-DF datasets or point the manifest to their existing local paths.',
            'Run dataset preparation so training, validation, and test folders are generated for the CNN workflow.',
            'Install tensorflow-cpu and FFmpeg in the model environment before training and video frame extraction.',
            'Train the first CNN checkpoint, record validation accuracy, and connect the saved model through DEEPFAKE_CNN_MODEL_PATH.',
            'Run an end-to-end admin smoke test after the first real CNN model is connected.',
            'Prepare deployment hardening such as production env setup, model file delivery, and request telemetry.',
        ];
    }

    private function projectTrackerCapabilitySections(): array
    {
        return [
            [
                'title' => 'Planning And System Foundation',
                'description' => 'Core architecture and workflow decisions that shape the rest of the project.',
                'items' => [
                    [
                        'title' => 'Agile methodology and sprint sequence',
                        'status' => 'added',
                        'summary' => 'The project now has a sprint-oriented implementation path from planning up to deployment.',
                        'proof' => 'Objectives, phased build order, and sprint monitoring are already mapped.',
                    ],
                    [
                        'title' => 'Unified Laravel detection pipeline',
                        'status' => 'added',
                        'summary' => 'The system follows one Laravel-owned flow from submission to final detection result.',
                        'proof' => 'The pipeline already stores preprocessing, analysis, verification, explanation, recommendation, signals, sources, and verdict.',
                    ],
                    [
                        'title' => 'Admin monitoring workspace for system progress',
                        'status' => 'added',
                        'summary' => 'Admins now have a page dedicated to feature progress, gaps, and sprint tracking.',
                        'proof' => 'This tracker page is served inside the admin application.',
                    ],
                ],
            ],
            [
                'title' => 'Input And Preprocessing',
                'description' => 'User submission intake and the extraction work that prepares evidence for later stages.',
                'items' => [
                    [
                        'title' => 'Upload intake for text, image, and video',
                        'status' => 'added',
                        'summary' => 'The platform already accepts link or upload submissions and routes them into the detection pipeline.',
                        'proof' => 'Current detection flows support text context plus image and video files.',
                    ],
                    [
                        'title' => 'Text profiling and lightweight NLP cues',
                        'status' => 'added',
                        'summary' => 'Named entities, urgency terms, weak-sourcing cues, and document text profiling are already used.',
                        'proof' => 'Laravel builds the source, text, media, and contextual signals before scoring.',
                    ],
                    [
                        'title' => 'Dedicated OCR and speech-to-text extraction',
                        'status' => 'missing',
                        'summary' => 'The system does not yet extract text from images or audio transcripts from videos as a dedicated module.',
                        'proof' => 'No OCR engine or STT pipeline is connected yet.',
                    ],
                    [
                        'title' => 'Frame-level preprocessing for deepfake video analysis',
                        'status' => 'partial',
                        'summary' => 'Video frame sampling is available in the CNN data preparation path, but not yet as a production-grade preprocessing module.',
                        'proof' => 'Dataset tooling can extract frames, but end-user video inference still needs a stronger deployed path.',
                    ],
                ],
            ],
            [
                'title' => 'Evidence Engine',
                'description' => 'External evidence gathering, topic detection, comparison, and credibility scoring.',
                'items' => [
                    [
                        'title' => 'Topic detection and claim query building',
                        'status' => 'added',
                        'summary' => 'The system already detects claim context and builds evidence collection queries from the submission.',
                        'proof' => 'Laravel builds claim and source context before scraping and API collection.',
                    ],
                    [
                        'title' => 'Playwright scraping from fact-check and newsroom sources',
                        'status' => 'added',
                        'summary' => 'Laravel now owns Playwright-powered evidence collection from configured publishers and fact-check sites.',
                        'proof' => 'GMA News and other configured sources are already part of the verification collector.',
                    ],
                    [
                        'title' => 'API-based evidence collection',
                        'status' => 'added',
                        'summary' => 'Google Fact Check, GNews, NewsAPI, and OpenWeather can enrich the evidence bundle when configured.',
                        'proof' => 'Evidence collection runs before the AI comparison stage in Laravel.',
                    ],
                    [
                        'title' => 'Similarity analysis and credibility scoring',
                        'status' => 'added',
                        'summary' => 'Claims are compared against evidence items and converted into risk and credibility summaries.',
                        'proof' => 'The evidence engine already reports similarity and scoring results in the pipeline contract.',
                    ],
                ],
            ],
            [
                'title' => 'AI And Deepfake Detection',
                'description' => 'The analysis models that interpret the submission and contribute to the final verdict.',
                'items' => [
                    [
                        'title' => 'OpenAI-only external AI analysis',
                        'status' => 'added',
                        'summary' => 'The external AI comparison stage now uses only OpenAI when configured.',
                        'proof' => 'Gemini and Anthropic paths were removed from the AI analysis layer.',
                    ],
                    [
                        'title' => 'Deepfake model wrapper stage',
                        'status' => 'added',
                        'summary' => 'The pipeline can run a pluggable deepfake model adapter before evidence analysis.',
                        'proof' => 'The deepfake_detection stage now reports real wrapper statuses such as completed or not-configured.',
                    ],
                    [
                        'title' => 'CNN adapter and dataset tooling',
                        'status' => 'added',
                        'summary' => 'A CNN adapter, a starter training script, and dataset preparation tooling are now available.',
                        'proof' => 'The repo includes cnn_adapter.py, train_cnn.py, and prepare_dataset.py.',
                    ],
                    [
                        'title' => 'First trained CNN checkpoint connected to production flow',
                        'status' => 'missing',
                        'summary' => 'The wrapper is ready, but a real trained model file still has to be trained and connected.',
                        'proof' => 'DEEPFAKE_CNN_MODEL_PATH still needs a real model checkpoint.',
                    ],
                    [
                        'title' => 'ViT and CNN+LSTM extensions',
                        'status' => 'missing',
                        'summary' => 'Only the CNN-first path has been scaffolded so far.',
                        'proof' => 'No ViT or temporal CNN+LSTM adapter is wired yet.',
                    ],
                ],
            ],
            [
                'title' => 'Decision, Testing, And Deployment',
                'description' => 'Final verdict generation, regression safety, and production readiness.',
                'items' => [
                    [
                        'title' => 'Decision engine and user-facing recommendation',
                        'status' => 'added',
                        'summary' => 'The pipeline already produces verdict, explanation, and recommendation outputs.',
                        'proof' => 'Decision data is saved directly on the detection result.',
                    ],
                    [
                        'title' => 'Regression and smoke verification around major pipeline changes',
                        'status' => 'partial',
                        'summary' => 'Syntax checks and smoke tests are in place, but automated end-to-end coverage is still incomplete.',
                        'proof' => 'Some verification exists, but deeper deployment-grade test coverage is still pending.',
                    ],
                    [
                        'title' => 'Deployment hardening and telemetry',
                        'status' => 'missing',
                        'summary' => 'Production deployment, direct request telemetry, and model-refresh operations still need to be formalized.',
                        'proof' => 'The repo is not yet tracking production-grade AI billing and model lifecycle telemetry.',
                    ],
                ],
            ],
        ];
    }

    private function projectTrackerSprintBoard(): array
    {
        return [
            [
                'name' => 'Sprint 1',
                'title' => 'Planning And Scope',
                'status' => 'done',
                'goal' => 'Define the problem, workflow, and system objectives before implementation.',
                'tasks' => [
                    ['label' => 'Define the TruthGuard workflow from upload to final result', 'status' => 'done'],
                    ['label' => 'Map general and specific objectives for the manuscript', 'status' => 'done'],
                    ['label' => 'Choose Agile as the development methodology', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 2',
                'title' => 'Core Pipeline Contract',
                'status' => 'done',
                'goal' => 'Keep one Laravel-owned flow for analysis.',
                'tasks' => [
                    ['label' => 'Create the Laravel detection pipeline flow', 'status' => 'done'],
                    ['label' => 'Route raw inputs through Laravel services', 'status' => 'done'],
                    ['label' => 'Keep local scoring available when OpenAI is unavailable', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 3',
                'title' => 'Evidence Engine Ownership',
                'status' => 'done',
                'goal' => 'Run collection and comparison of external evidence inside Laravel.',
                'tasks' => [
                    ['label' => 'Run Playwright article-search scraping from Laravel', 'status' => 'done'],
                    ['label' => 'Run evidence APIs before AI analysis', 'status' => 'done'],
                    ['label' => 'Keep collected evidence attached to the saved detection result', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 4',
                'title' => 'OpenAI-Only AI Layer',
                'status' => 'done',
                'goal' => 'Standardize the external AI step on one provider.',
                'tasks' => [
                    ['label' => 'Remove Gemini and Anthropic branches', 'status' => 'done'],
                    ['label' => 'Use OpenAI structured JSON analysis output', 'status' => 'done'],
                    ['label' => 'Keep local fallback when OpenAI is unavailable', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 5',
                'title' => 'Deepfake Wrapper Foundation',
                'status' => 'done',
                'goal' => 'Create a real deepfake model stage in the pipeline instead of a placeholder.',
                'tasks' => [
                    ['label' => 'Run deepfake inference before the evidence engine', 'status' => 'done'],
                    ['label' => 'Expose deepfake stage statuses through the detection result', 'status' => 'done'],
                    ['label' => 'Merge model findings back into the final scoring path', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 6',
                'title' => 'CNN Path And Data Preparation',
                'status' => 'in_progress',
                'goal' => 'Stand up the first trainable CNN deepfake workflow.',
                'tasks' => [
                    ['label' => 'Add the CNN inference adapter', 'status' => 'done'],
                    ['label' => 'Add the starter CNN training script', 'status' => 'done'],
                    ['label' => 'Add dataset preparation tooling and example manifest', 'status' => 'done'],
                    ['label' => 'Prepare the real FaceForensics++ and Celeb-DF dataset paths', 'status' => 'in_progress'],
                    ['label' => 'Train and connect the first real CNN checkpoint', 'status' => 'missing'],
                    ['label' => 'Record validation and external-test results', 'status' => 'missing'],
                ],
            ],
            [
                'name' => 'Sprint 7',
                'title' => 'Admin Monitoring',
                'status' => 'done',
                'goal' => 'Give admins a place to monitor implementation progress and remaining gaps.',
                'tasks' => [
                    ['label' => 'Add a dedicated project tracker page in admin', 'status' => 'done'],
                    ['label' => 'Show added, partial, and missing capabilities', 'status' => 'done'],
                    ['label' => 'Show sprint sequencing so the team avoids skipping steps', 'status' => 'done'],
                ],
            ],
            [
                'name' => 'Sprint 8',
                'title' => 'Deployment And Hardening',
                'status' => 'next',
                'goal' => 'Make the full pipeline production-ready after the first CNN model is validated.',
                'tasks' => [
                    ['label' => 'Install TensorFlow and FFmpeg on the deployment environment', 'status' => 'missing'],
                    ['label' => 'Publish the trained CNN model with environment wiring', 'status' => 'missing'],
                    ['label' => 'Add production telemetry and deeper smoke checks', 'status' => 'missing'],
                    ['label' => 'Run final deployment verification across admin and detection flows', 'status' => 'missing'],
                ],
            ],
        ];
    }
}
