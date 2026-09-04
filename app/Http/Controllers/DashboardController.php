<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Services\Detections\DetectionVerdictPresenter;
use App\Services\Detections\DetectionRetentionService;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const FACT_CHECK_FEED_LIMIT = 500;

    private const FACT_CHECK_FEED_LOOKBACK_DAYS = 7;

    public function __invoke(Request $request, DetectionRetentionService $retentionService): View|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        $retentionService->archiveExpired($request->user()->id);

        return view('user.dashboard', [
            'user' => $request->user(),
            'factCheckFeed' => app(GoogleFactCheckFeedService::class)->latest(
                self::FACT_CHECK_FEED_LIMIT,
                self::FACT_CHECK_FEED_LOOKBACK_DAYS,
            ),
        ]);
    }

    public function history(Request $request, DetectionRetentionService $retentionService): View|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        $user = $request->user();
        $retentionService->archiveExpired($user->id);

        $activeStatus = $this->normalizeHistoryStatus($request->query('status'));
        $showArchived = $activeStatus === 'archived';
        $historyQuery = $showArchived
            ? $retentionService->archivedQuery($user->id)
            : $retentionService->retainedQuery($user->id);
        $historySearch = trim((string) $request->query('search', ''));
        $historySort = $this->normalizeHistorySort($request->query('sort'));
        $dateFrom = $this->normalizeHistoryDate($request->query('date_from'))
            ?? ($showArchived ? null : now()->subDays(6)->startOfDay());
        $dateTo = $this->normalizeHistoryDate($request->query('date_to'), true)
            ?? ($showArchived ? null : now()->endOfDay());

        if ($dateFrom && $dateTo && $dateFrom->greaterThan($dateTo)) {
            [$dateFrom, $dateTo] = [
                $dateTo->copy()->startOfDay(),
                $dateFrom->copy()->endOfDay(),
            ];
        }

        $filteredHistoryQuery = clone $historyQuery;

        if (! $showArchived && $activeStatus !== 'all') {
            $filteredHistoryQuery->where('verdict', $activeStatus);
        }

        if ($historySearch !== '') {
            $this->applyHistorySearch($filteredHistoryQuery, $historySearch);
        }

        if ($dateFrom && $dateTo) {
            $filteredHistoryQuery->whereBetween('analyzed_at', [$dateFrom, $dateTo]);
        } elseif ($dateFrom) {
            $filteredHistoryQuery->where('analyzed_at', '>=', $dateFrom);
        } elseif ($dateTo) {
            $filteredHistoryQuery->where('analyzed_at', '<=', $dateTo);
        }

        $this->applyHistorySort($filteredHistoryQuery, $historySort);

        $historyDetections = $filteredHistoryQuery
            ->paginate(DetectionRetentionService::PAGE_SIZE)
            ->withQueryString();

        $latestDetection = (clone $historyQuery)
            ->latest('analyzed_at')
            ->first();

        $activeHistoryQuery = $retentionService->retainedQuery($user->id);
        $totalDetections = (clone $activeHistoryQuery)->count();

        $fakeDetections = (clone $activeHistoryQuery)
            ->where('verdict', 'fake')
            ->count();

        $reviewDetections = (clone $activeHistoryQuery)
            ->where('verdict', 'review')
            ->count();

        return view('user.history', [
            'historyDetections' => $historyDetections,
            'historyRows' => $this->buildRecentDetectionRows(collect($historyDetections->items()), false),
            'historyFilters' => [
                'status' => $activeStatus,
                'search' => $historySearch,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
                'sort' => $historySort,
            ],
            'historyStatusCounts' => [
                'all' => $totalDetections,
                'real' => (clone $activeHistoryQuery)->where('verdict', 'real')->count(),
                'fake' => $fakeDetections,
                'review' => $reviewDetections,
                'archived' => $retentionService->archivedQuery($user->id)->count(),
            ],
            'historySortOptions' => $this->historySortOptions(),
            'totalDetections' => $totalDetections,
            'fakeDetections' => $fakeDetections,
            'reviewDetections' => $reviewDetections,
            'latestDetection' => $latestDetection,
        ]);
    }

    public function factCheck(Request $request, GoogleFactCheckFeedService $feedService, string $factCheck): View|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        $item = $feedService->find($factCheck);

        abort_if($item === null, 404);

        return view('user.dashboard.fact-check', [
            'item' => $item,
        ]);
    }

    public function factCheckFeed(Request $request, GoogleFactCheckFeedService $feedService): Response|RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.dashboard');
        }

        return response()
            ->view('user.dashboard.partials.news-watch', [
                'factCheckFeed' => $feedService->latest(
                    self::FACT_CHECK_FEED_LIMIT,
                    self::FACT_CHECK_FEED_LOOKBACK_DAYS,
                ),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array{direction:string, percent:int, label:string, tone:string}
     */
    private function buildTrendMeta(int|float $current, int|float $previous, bool $positiveWhenDown = false): array
    {
        $delta = $current - $previous;
        $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'steady');
        $percent = $previous > 0
            ? (int) round((abs($delta) / $previous) * 100)
            : ($current > 0 ? 100 : 0);

        $isPositive = match ($direction) {
            'up' => ! $positiveWhenDown,
            'down' => $positiveWhenDown,
            default => true,
        };

        $tone = $direction === 'steady'
            ? 'slate'
            : ($isPositive ? 'emerald' : 'rose');

        $label = match ($direction) {
            'up' => '+'.$percent.'% vs previous 30 days',
            'down' => '-'.$percent.'% vs previous 30 days',
            default => 'No change vs previous 30 days',
        };

        return [
            'direction' => $direction,
            'percent' => $percent,
            'label' => $label,
            'tone' => $tone,
        ];
    }

    /**
     * @return array{labels: array<int, string>, series: array<int, int>, demo: bool}
     */
    private function buildDetectionTrendChart(int $userId): array
    {
        $points = collect(range(6, 0))->map(function (int $offset) use ($userId) {
            $date = now()->subDays($offset);

            return [
                'label' => $date->format('M d'),
                'value' => Detection::query()
                    ->where('user_id', $userId)
                    ->whereDate('analyzed_at', $date->toDateString())
                    ->count(),
            ];
        });

        if ($points->sum('value') === 0) {
            return [
                'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                'series' => [4, 7, 6, 11, 9, 13, 10],
                'demo' => true,
            ];
        }

        return [
            'labels' => $points->pluck('label')->all(),
            'series' => $points->pluck('value')->all(),
            'demo' => false,
        ];
    }

    /**
     * @return array{labels: array<int, string>, series: array<int, int>, demo: bool}
     */
    private function buildDistributionChart(int $fakeDetections, int $realDetections): array
    {
        if (($fakeDetections + $realDetections) === 0) {
            return [
                'labels' => ['Fake', 'Real'],
                'series' => [32, 68],
                'demo' => true,
            ];
        }

        return [
            'labels' => ['Fake', 'Real'],
            'series' => [$fakeDetections, $realDetections],
            'demo' => false,
        ];
    }

    private function normalizeHistoryStatus(mixed $status): string
    {
        $status = Str::lower(trim((string) $status));

        return match ($status) {
            'real' => 'real',
            'fake' => 'fake',
            'review', 'inconclusive' => 'review',
            'archived', 'archive' => 'archived',
            default => 'all',
        };
    }

    private function normalizeHistorySort(mixed $sort): string
    {
        $sort = Str::lower(trim((string) $sort));

        return array_key_exists($sort, $this->historySortOptions()) ? $sort : 'newest';
    }

    private function normalizeHistoryDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if (! $date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function applyHistorySearch($query, string $search): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
        $caseId = (int) ltrim((string) preg_replace('/\D+/', '', $search), '0');

        $query->where(function ($query) use ($caseId, $like) {
            if ($caseId > 0) {
                $query->orWhereKey($caseId);
            }

            $query
                ->orWhere('caption_text', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhere('explanation_summary', 'like', $like)
                ->orWhere('source_url', 'like', $like);
        });
    }

    private function applyHistorySort($query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->oldest('analyzed_at')->oldest('id'),
            'confidence_desc' => $query->orderByDesc('fake_score')->latest('analyzed_at'),
            'confidence_asc' => $query->orderBy('fake_score')->latest('analyzed_at'),
            default => $query->latest('analyzed_at')->latest('id'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function historySortOptions(): array
    {
        return [
            'newest' => 'Newest first',
            'oldest' => 'Oldest first',
            'confidence_desc' => 'Confidence high',
            'confidence_asc' => 'Confidence low',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Detection>  $recentDetections
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function buildRecentDetectionRows($recentDetections, bool $includeDemoFallback = true)
    {
        $rows = $recentDetections->map(function (Detection $detection) {
            $presentation = DetectionVerdictPresenter::forDetection($detection);
            $confidence = DetectionVerdictPresenter::confidencePercent($detection);

            return [
                'case' => 'Fact check result',
                'summary' => Str::limit((string) ($detection->caption_text ?: $detection->notes ?: 'Detection request submitted without extra context.'), 72),
                'thumbnail' => $detection->media_type === 'image' ? $detection->media_url : null,
                'result' => $presentation['history_label'],
                'category_key' => $presentation['category_key'],
                'verdict' => $detection->verdict,
                'confidence' => $confidence,
                'url' => route('detections.result', $detection),
                'date' => $detection->analyzed_at?->format('M d, Y') ?? 'Pending',
                'time' => $detection->analyzed_at?->format('h:i A') ?? '--',
                'archived' => filled($detection->archived_at),
                'archived_label' => $detection->archived_at?->format('M d, Y'),
                'demo' => false,
            ];
        });

        if ($rows->isNotEmpty()) {
            return $rows;
        }

        if (! $includeDemoFallback) {
            return collect();
        }

        return collect([
            [
                'case' => 'Sample review',
                'summary' => 'Edited flood image paired with a false evacuation caption.',
                'thumbnail' => asset('welcome/0a60b3ab-4470-43b9-a4e2-7aecac181213-large16x9_fakeweathernews.png'),
                'result' => 'Fake',
                'category_key' => 'likely_misleading',
                'verdict' => 'fake',
                'confidence' => 94,
                'url' => null,
                'date' => now()->subHours(2)->format('M d, Y'),
                'time' => now()->subHours(2)->format('h:i A'),
                'demo' => true,
            ],
            [
                'case' => 'Sample verification',
                'summary' => 'Original street photo verified as real after reverse-image checks.',
                'thumbnail' => asset('welcome/how-to-identify-fake-news-1.jpg'),
                'result' => 'Real',
                'category_key' => 'low_risk',
                'verdict' => 'real',
                'confidence' => 91,
                'url' => null,
                'date' => now()->subHours(5)->format('M d, Y'),
                'time' => now()->subHours(5)->format('h:i A'),
                'demo' => true,
            ],
            [
                'case' => 'Sample context check',
                'summary' => 'Misleading caption reused an old protest image out of context.',
                'thumbnail' => asset('welcome/Misleading.jpg'),
                'result' => 'Fake',
                'category_key' => 'likely_misleading',
                'verdict' => 'fake',
                'confidence' => 88,
                'url' => null,
                'date' => now()->subDay()->format('M d, Y'),
                'time' => now()->subDay()->format('h:i A'),
                'demo' => true,
            ],
        ]);
    }
}
