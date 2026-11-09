<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\PublicClaimReview;
use App\Services\Automation\RelatedPostScraper;
use App\Services\Detections\ActiveDetectionManager;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\DetectionRetentionService;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\FactCheckClaimMatcher;
use App\Services\Detections\FactCheckResultService;
use App\Services\Detections\ImageUploadIdentity;
use App\Services\Detections\KnownFactCheckCatalog;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\PipelineFeedback;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ReviewedClaimLookup;
use App\Services\Detections\SubmissionInput;
use App\Services\Detections\TrustedFactCheckVerdictResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DetectionController extends Controller
{
    public function feedback(Request $request, Detection $detection): RedirectResponse
    {
        abort_unless((int) $detection->user_id === (int) $request->user()->id, 403);
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(PipelineFeedback::options(true)))],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $details = PipelineFeedback::details(
            is_array($detection->fact_check_result) ? $detection->fact_check_result : [],
            $validated['category'], $validated['note'] ?? null, $detection->media_checksum,
        );
        DB::table('detection_feedback')->updateOrInsert([
            'detection_id' => $detection->id, 'user_id' => $request->user()->id,
            'category' => $validated['category'],
        ], ['details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(), 'created_at' => now()]);

        return back()->with('status', 'Thank you. This report was flagged for quality review.');
    }

    public function create(
        Request $request,
        DetectionRetentionService $retentionService,
        ActiveDetectionManager $activeDetectionManager,
    ): View|RedirectResponse {
        if (! $request->user()->isAdmin()) {
            try {
                $retentionService->archiveExpired($request->user()->id);
            } catch (\Throwable $exception) {
                Log::warning('Unable to archive expired detections while opening Fact Check.', [
                    'user_id' => $request->user()->id,
                    'exception' => $exception,
                ]);
            }

            try {
                $activeDetectionManager->expireStaleForUser($request->user()->id);
            } catch (\Throwable $exception) {
                // Expiring abandoned work is maintenance, not a prerequisite for
                // opening Fact Check. Keep the page available and leave enough
                // context in the server log to repair the affected job safely.
                Log::warning('Unable to expire stale detection while opening Fact Check.', [
                    'user_id' => $request->user()->id,
                    'exception' => $exception,
                ]);
            }
        }

        $pendingDetectionId = $request->integer('pending');
        $selectedDetectionId = $pendingDetectionId ?: $request->integer('detection');

        if ($selectedDetectionId <= 0 && config('fact_check.queue_enabled')) {
            $pendingDetection = Detection::query()->where('user_id', $request->user()->id)
                ->whereIn('processing_status', ['queued', 'processing'])->latest('id')->first();
            if ($pendingDetection) {
                return view('detections.create', ['selectedDetection' => null, 'pendingDetection' => $pendingDetection]);
            }
        }

        if ($selectedDetectionId > 0) {
            $selectedDetectionQuery = Detection::query()->whereKey($selectedDetectionId);

            if (! $request->user()->isAdmin()) {
                $selectedDetectionQuery->where('user_id', $request->user()->id);
                $retentionService->applyRetentionWindow($selectedDetectionQuery);
            }

            $selectedDetection = $selectedDetectionQuery->first();

            abort_if($pendingDetectionId > 0 && ! $selectedDetection, 404);

            if ($selectedDetection) {
                if (in_array($selectedDetection->processing_status, ['queued', 'processing'], true)
                    || ($selectedDetection->processing_status === 'failed'
                        && data_get($selectedDetection->fact_check_result, 'verdict') !== 'ANALYSIS_UNAVAILABLE')) {
                    return view('detections.create', [
                        'selectedDetection' => null,
                        'pendingDetection' => $selectedDetection,
                    ]);
                }

                return redirect()->route('detections.result', $selectedDetection);
            }
        }

        return view('detections.create', [
            'selectedDetection' => null,
        ]);
    }

    public function progress(Request $request, Detection $detection, ActiveDetectionManager $manager): JsonResponse
    {
        abort_if($detection->user_id !== $request->user()->id, 404);
        $manager->expireStaleForUser($request->user()->id);
        $detection->refresh();
        $status = $detection->processing_status;
        $cancelled = ! in_array($status, ['queued', 'processing'], true)
            && data_get($detection->signals, 'analysis_cancellation.reason') === 'user_stopped';

        return response()->json([
            'processing_status' => $status,
            'cancelled' => $cancelled,
            'stop_url' => route('detections.stop', $detection),
            'stage' => $cancelled ? 'Fact check stopped' : (data_get($detection->signals, 'analysis_progress.0.label')
                ?: match ($status) {
                    'queued' => 'Waiting for an analysis worker',
                    'processing' => match ($detection->media_type) {
                        'image' => 'Checking the submitted image',
                        'video' => 'Checking the submitted video',
                        default => 'Reading submitted content',
                    },
                    'completed' => 'Analysis complete',
                    default => 'Analysis could not finish',
                }),
            'elapsed_seconds' => max(0, (int) $detection->created_at->diffInSeconds(now())),
            'message' => $cancelled ? 'You stopped this fact check. You can start a new one whenever you are ready.' : ($status === 'failed'
                ? 'The analysis could not finish. Open the report to review its status and retry.'
                : ($status === 'queued' ? 'Your upload is saved and waiting to start. You can continue using TruthGuard.' : 'You can continue using TruthGuard while this check runs.')),
            'result_url' => route('detections.result', $detection),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function stop(Request $request, Detection $detection, ActiveDetectionManager $manager): JsonResponse
    {
        abort_unless((int) $detection->user_id === (int) $request->user()->id, 404);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $detection = $manager->stopForUser($detection->id, $request->user()->id);
        abort_unless($detection, 404);

        return response()->json(['processing_status' => $detection->processing_status,
            'cancelled' => data_get($detection->signals, 'analysis_cancellation.reason') === 'user_stopped',
            'result_url' => route('detections.result', $detection)])->header('Cache-Control', 'no-store, private');
    }

    public function result(
        Request $request,
        Detection $detection,
        DetectionRetentionService $retentionService,
        LiveVerificationEvidenceService $liveVerificationEvidenceService,
        OpenAiDetectionReportService $openAiDetectionReportService,
        ActiveDetectionManager $activeDetectionManager,
    ): View|JsonResponse|RedirectResponse {
        if (! $request->user()->isAdmin()) {
            $retentionService->archiveExpired($request->user()->id);

            if (in_array($detection->processing_status, ['queued', 'processing'], true)) {
                try {
                    // Polling must also close abandoned work. Otherwise a browser
                    // left on the progress view can spin forever until the user
                    // manually opens Fact Check again.
                    $activeDetectionManager->expireStaleForUser($request->user()->id);
                } catch (\Throwable $exception) {
                    Log::warning('Unable to expire stale detection while polling a report.', [
                        'user_id' => $request->user()->id,
                        'detection_id' => $detection->id,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        abort_if(! $request->user()->isAdmin() && $detection->user_id !== $request->user()->id, 404);

        $selectedDetection = Detection::query()
            ->whereKey($detection->id)
            ->with([
                'scrapedPosts' => fn ($query) => $query
                    ->with('scrapeRun')
                    ->latest('scraped_at')
                    ->take(12),
            ])
            ->firstOrFail();

        $selectedDetection = $this->restoreKnownFactCheck($selectedDetection);

        if ($selectedDetection->processing_status === 'failed'
            && data_get($selectedDetection->fact_check_result, 'verdict') !== 'ANALYSIS_UNAVAILABLE') {
            $hasSavedResult = $selectedDetection->fact_check_result !== null;
            $failure = ReportAnswer::complete([
                'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'ANALYSIS_UNAVAILABLE',
                'summary' => 'The analysis could not finish. Your upload is saved. Retry the assessment to try again.',
                'sources' => [],
            ]);
            $selectedDetection->forceFill(['fact_check_result' => $failure,
                'analysis_summary' => $failure['summary'], 'explanation_summary' => $failure['summary'],
            ]);
            // Hide stale findings on a failed retry without overwriting its
            // previously saved evidence during a read-only result request.
            if (! $hasSavedResult) {
                $selectedDetection->saveQuietly();
            }
        }

        // Include archived submissions so retention does not renumber the owner's reports.
        $userReportNumber = $request->expectsJson() ? null : Detection::query()
            ->where('user_id', $selectedDetection->user_id)
            ->where('id', '<=', $selectedDetection->id)
            ->count();

        if (in_array($selectedDetection->processing_status, ['queued', 'processing', 'failed'], true)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'processing_status' => $selectedDetection->processing_status,
                    'stage' => data_get($selectedDetection->signals, 'analysis_progress.0.label'),
                    'source_domains' => data_get($selectedDetection->signals, 'analysis_progress.0.source_domains', []),
                ]);
            }

            if ($selectedDetection->processing_status !== 'failed'
                || data_get($selectedDetection->fact_check_result, 'verdict') !== 'ANALYSIS_UNAVAILABLE') {
                return redirect()->route('detections.create', ['pending' => $selectedDetection->id]);
            }

            return view('detections.result', [
                'selectedDetection' => $selectedDetection,
                'userReportNumber' => $userReportNumber,
                'latestFactChecks' => [],
            ]);
        }

        // Keep JSON clients compatible without projecting or rewriting the saved
        // explanation/verdict/source fields used by the committed HTML report.
        if ($request->expectsJson()) {
            $payload = is_array($selectedDetection->fact_check_result)
                ? ReportAnswer::forPresentation($selectedDetection->fact_check_result)
                : ReportAnswer::forLegacyDetection($selectedDetection);

            return response()->json($payload);
        }

        // Related cards are context, never additional verdict citations. Match
        // the assessed claim locally instead of showing arbitrary recent items.
        $savedResult = is_array($selectedDetection->fact_check_result) ? $selectedDetection->fact_check_result : [];
        $claimTexts = in_array($savedResult['primary_task'] ?? 'claim', ['claim', 'context'], true)
            ? collect($savedResult['claims'] ?? [])->filter(fn ($claim) => is_array($claim) && ($claim['kind'] ?? '') === 'factual')
                ->pluck('text')->push($savedResult['claim'] ?? null)->filter(fn ($text) => is_string($text) && trim($text) !== '')
                ->unique()->values()->all() : [];
        $recentReviews = PublicClaimReview::query();
        $maxAgeDays = max(0, (int) config('services.google_fact_check.feed_max_age_days', 120));
        if ($maxAgeDays > 0) {
            $recentReviews->where('published_at', '>=', now()->subDays($maxAgeDays)->startOfDay());
        }

        $matcher = app(FactCheckClaimMatcher::class);
        $relatedReviews = $claimTexts === [] ? [] : $recentReviews->latest('published_at')->latest('last_seen_at')->latest('id')
            ->limit(500)->get()->map(function (PublicClaimReview $review) use ($claimTexts, $matcher): array {
                $score = 0;
                if (ReportAnswer::safeUrl($review->url)) {
                    foreach ($claimTexts as $text) {
                        $match = $matcher->assess($text, $review->claim ?: $review->headline);
                        if ($match['level'] === 'exact') {
                            $score = max($score, 1000 + $match['score']);
                        } elseif ($match['level'] === 'related' && $match['entity_match'] && $match['action_match']
                            && $match['date_match'] && $match['roles_match']) {
                            $score = max($score, $match['score']);
                        }
                    }
                }

                return ['review' => $review, 'score' => $score];
            })->filter(fn ($row) => $row['score'] > 0)->sortByDesc('score')->take(6)
            ->map(fn ($row): array => $row['review']->toFeedItem())->values()->all();

        return view('detections.result', [
            'selectedDetection' => $selectedDetection,
            'userReportNumber' => $userReportNumber,
            'latestFactChecks' => ['items' => $relatedReviews],
        ]);
    }

    /**
     * Upgrade an older saved report when its already-extracted text matches a
     * reviewed catalog answer. This repairs production records without another
     * model or web-search request.
     */
    private function restoreKnownFactCheck(Detection $detection): Detection
    {
        // Opening a stopped report must not restart or replace its assessment.
        if (data_get($detection->signals, 'analysis_cancellation.reason') === 'user_stopped') {
            return $detection;
        }
        // A catalogue shortcut must not replace newly researched evidence,
        // especially a fresh assessment that found conflicting reviews.
        if (data_get($detection->fact_check_result, 'diagnostics.pipeline_version') === EvidenceVerificationService::VERSION) {
            return $detection;
        }
        if ((bool) data_get($detection->fact_check_result, 'diagnostics.known_fact_check')
            && data_get($detection->fact_check_result, 'diagnostics.known_fact_check_version') === KnownFactCheckCatalog::VERSION) {
            return $detection;
        }

        $extraction = data_get($detection->signals, 'submission_extraction.0', [])
            ?: data_get($detection->fact_check_result, 'extraction', []);
        $text = implode(' ', array_filter([
            $detection->caption_text,
            $detection->notes,
            data_get($extraction, 'analysis_text'),
            data_get($extraction, 'reconstructed_text'),
            data_get($extraction, 'visible_text'),
        ]));
        $knownResult = ReviewedClaimLookup::lookup($text)
            ?? KnownFactCheckCatalog::match($text);
        if ($knownResult === null) {
            foreach (['analysis_text', 'reconstructed_text', 'visible_text'] as $key) {
                $candidate = (string) data_get($extraction, $key, '');
                $knownResult = ReviewedClaimLookup::lookup($candidate);
                if ($knownResult !== null) {
                    break;
                }
                if (KnownFactCheckCatalog::matchesReviewedVariant($candidate)) {
                    $knownResult = KnownFactCheckCatalog::match($candidate);
                    break;
                }
            }
        }

        if ($knownResult === null) {
            return $detection;
        }
        if (data_get($detection->fact_check_result, 'diagnostics.reviewed_claim_id') === data_get($knownResult, 'diagnostics.reviewed_claim_id')
            && data_get($detection->fact_check_result, 'summary') === $knownResult['summary']
            && data_get($detection->fact_check_result, 'verdict') === $knownResult['verdict']) {
            return $detection;
        }
        $knownResult['diagnostics']['api_usage'] = data_get($detection->fact_check_result, 'diagnostics.api_usage',
            data_get($detection->signals, 'openai_usage', $knownResult['diagnostics']['api_usage']));

        $knownResult['request'] = array_replace((array) data_get($detection->fact_check_result, 'request', []), [
            'question' => (string) ($detection->caption_text ?? ''),
        ]);
        $signals = is_array($detection->signals) ? $detection->signals : [];
        unset($signals['analysis_progress'], $signals['analysis_timeout']);
        $signals['known_fact_check'] = [[
            'label' => 'Matched reviewed claim',
            'weight' => 0,
        ]];

        $detection->forceFill([
            'processing_status' => 'completed',
            'fact_check_result' => $knownResult,
            'analysis_summary' => $knownResult['summary'],
            'explanation_summary' => $knownResult['summary'],
            'verification_summary' => implode(' ', $knownResult['key_findings'] ?? []),
            'recommendation' => data_get($knownResult, 'generated_wording.recommended_action'),
            'verification_sources' => $knownResult['sources'] ?? [],
            'signals' => $signals,
            'verdict' => 'review',
            'analyzed_at' => $detection->analyzed_at ?? now(),
        ])->saveQuietly();

        return $detection->fresh(['scrapedPosts.scrapeRun']);
    }

    public function store(
        Request $request,
        DetectionRetentionService $retentionService,
        DetectionPipeline $pipeline,
        RelatedPostScraper $relatedPostScraper,
    ): RedirectResponse|JsonResponse {
        if (! $request->user()->isAdmin()) {
            $retentionService->archiveExpired($request->user()->id);
        }

        $request->merge($this->normalizeDetectionInputs($request));
        $mediaMaxMb = (int) config('truthguard.uploads.media_max_mb', 20);
        $mediaMaxKb = (int) config('truthguard.uploads.media_max_kb', $mediaMaxMb * 1024);
        $claimMaxCharacters = (int) config('truthguard.claims.max_characters', 4000);

        $validated = $request->validate([
            'source_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'media_file' => ['nullable', 'file', "max:{$mediaMaxKb}", 'mimes:jpg,jpeg,png,webp,mp4,mov,webm,m4v,pdf'],
            'source_platform' => ['nullable', 'string', 'in:auto,facebook,instagram,tiktok,x,youtube,web,other'],
            'caption_text' => ['nullable', 'string', "max:{$claimMaxCharacters}"],
            'analysis_task' => ['nullable', 'in:auto,media,claim,context,manipulation'],
            'force_refresh' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'return_context' => ['nullable', 'string', 'in:dashboard,detection-center'],
            'weather_consent' => ['nullable', 'boolean'],
            'weather_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'weather_lon' => ['nullable', 'numeric', 'between:-180,180'],
            'weather_label' => ['nullable', 'string', 'max:120'],
        ], [
            'media_file.max' => "Evidence uploads must be {$mediaMaxMb}MB or less.",
            'caption_text.max' => "The claim must not exceed {$claimMaxCharacters} characters.",
        ]);

        $validated = array_merge($validated, $this->normalizeWeatherInputs($validated));

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->file('media_file');

        if (! $uploadedFile && blank($validated['source_url'] ?? null) && ! SubmissionInput::hasEvidenceText($validated['caption_text'] ?? null)) {
            throw ValidationException::withMessages([
                'caption_text' => 'Enter the claim you want checked, paste a source link, or attach evidence. A guide question alone is not enough.',
            ]);
        }

        $identity = ImageUploadIdentity::forUpload($validated, $uploadedFile);
        $submit = fn () => $this->submitPreparedEvidence($request, $pipeline, $relatedPostScraper, $validated, $uploadedFile, $identity);
        if (! $identity || ! config('fact_check.result_reuse.enabled', true)
            || ! empty($validated['force_refresh']) || ! empty($validated['weather_consent'])) {
            return $submit();
        }
        try {
            // Serialize only matching submissions on this account, including the
            // queue insert, so double taps cannot dispatch duplicate paid jobs.
            return Cache::lock('image-submit:'.$request->user()->id.':'.$identity['checksum'].':'.$identity['scope_key'], 900)
                ->block(5, function () use ($request, $pipeline, $validated, $uploadedFile, $identity, $submit) {
                    $saved = $pipeline->reuseImageUpload($request->user(), $validated, $uploadedFile, $identity);
                    if (! $saved) {
                        return $submit();
                    }
                    $active = in_array($saved->processing_status, ['queued', 'processing'], true);
                    if (! $active) {
                        $request->session()->flash('status', 'Opened the first matching saved report from '
                            .$saved->analyzed_at?->format('M d, Y h:i A').'. No new analysis or API tokens were used. Use Retry for a fresh assessment.');
                    }
                    if ($request->expectsJson()) {
                        return response()->json(['processing_status' => $saved->processing_status,
                            'result_url' => route('detections.result', $saved),
                            'stop_url' => route('detections.stop', $saved), 'reused' => true], $active ? 202 : 200);
                    }

                    return $active ? redirect()->route('detections.create', ['pending' => $saved->id])
                        : redirect()->route('detections.result', $saved);
                });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['media_file' => 'This identical upload is already being submitted. Open its progress or try again shortly; no second analysis was started.']);
        }
    }

    private function submitPreparedEvidence(Request $request, DetectionPipeline $pipeline, RelatedPostScraper $relatedPostScraper,
        array $validated, ?UploadedFile $uploadedFile, ?array $identity): RedirectResponse|JsonResponse
    {
        if (config('fact_check.queue_enabled')) {
            $detection = Detection::create([
                'user_id' => $request->user()->id, 'source_kind' => $uploadedFile ? 'upload' : 'link',
                'source_url' => $validated['source_url'] ?? null, 'caption_text' => $validated['caption_text'] ?? null,
                'notes' => $validated['notes'] ?? null, 'media_path' => $uploadedFile?->store('detections', 'public'),
                'media_type' => $uploadedFile ? (str_starts_with((string) $uploadedFile->getMimeType(), 'video/') ? 'video' : ($uploadedFile->getMimeType() === 'application/pdf' ? 'document' : 'image')) : 'text',
                'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0,
                'media_checksum' => $identity['checksum'] ?? null,
                'signals' => $identity ? ['upload_reuse' => $identity] : [],
            ]);
            // The worker reopens the persisted original; UploadedFile cannot be serialized.
            unset($validated['media_file']);
            try {
                AnalyzeDetectionEvidence::dispatch($detection->id, $validated);
            } catch (\Throwable $error) {
                (new AnalyzeDetectionEvidence($detection->id, $validated))->failed($error);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'processing_status' => 'queued',
                    'result_url' => route('detections.result', $detection),
                    'stop_url' => route('detections.stop', $detection),
                ], 202);
            }

            return redirect()->route('detections.create', ['pending' => $detection->id]);
        }

        $detection = $pipeline->run($request->user(), $validated, $uploadedFile);

        if ((bool) config('playwright.related_posts_inline', false)) {
            $relatedPostScraper->scrapeForDetection($detection, $validated);
        }

        if (! empty($validated['weather_consent'])) {
            $request->session()->flash('weather_context', [
                'weather_consent' => true,
                'weather_lat' => $validated['weather_lat'] ?? null,
                'weather_lon' => $validated['weather_lon'] ?? null,
                'weather_label' => $validated['weather_label'] ?? null,
            ]);
        }

        $status = 'Assessment complete: '.($detection->fact_check_result['assessment_label'] ?? $detection->fact_check_result['verdict'] ?? $detection->verdict).'.';

        return redirect()
            ->route('detections.result', $detection)
            ->with('status', $status);
    }

    public function retryMedia(Request $request, Detection $detection, DetectionPipeline $pipeline): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() || $detection->user_id === $request->user()->id, 404);
        abort_unless(in_array($detection->media_type, ['image', 'video'], true), 422);
        $validated = $request->validate(['analysis_task' => ['nullable', 'in:media,claim,context,manipulation']]);
        $file = $this->uploadedImageForRefresh($detection);
        if (! $file) {
            return redirect()->route('detections.result', $detection)->withErrors(['media_file' => 'The original upload is no longer available. Upload the file again.']);
        }
        $lock = Cache::lock('media-retry:'.$detection->id, 360);
        if (! $lock->get()) {
            return redirect()->route('detections.result', $detection)->with('status', 'Analysis is already running for this report.');
        }
        try {
            $detection->refresh();
            if (in_array($detection->processing_status, ['queued', 'processing'], true)) {
                return redirect()->route('detections.result', $detection)->with('status', 'Analysis is already running for this report.');
            }
            $selectedMode = $detection->fact_check_result['request']['selected_mode'] ?? 'auto';
            if (! in_array($selectedMode, ['auto', 'media', 'claim', 'context', 'manipulation'], true)) {
                $selectedMode = 'auto';
            }
            $context = [
                'analysis_task' => $validated['analysis_task'] ?? $selectedMode,
                'caption_text' => $detection->caption_text, 'notes' => $detection->notes,
                'source_url' => $detection->source_url, 'source_platform' => $detection->platform,
            ];
            if (config('fact_check.queue_enabled')) {
                $previousStatus = $detection->processing_status;
                $previousSignals = $detection->signals;
                $retryId = (string) Str::uuid();
                $signals = $previousSignals ?? [];
                unset($signals['analysis_progress'], $signals['analysis_cancellation']);
                $signals['analysis_retry'] = ['id' => $retryId, 'status' => 'queued', 'previous_status' => $previousStatus];
                $detection->forceFill(['processing_status' => 'queued', 'signals' => $signals])->saveQuietly();
                try {
                    AnalyzeDetectionEvidence::dispatch($detection->id, $context, $retryId, $previousStatus);
                } catch (\Throwable $error) {
                    $detection->forceFill(['processing_status' => $previousStatus, 'signals' => $previousSignals])->saveQuietly();
                    report($error);

                    return redirect()->route('detections.result', $detection)->with('status', 'The retry could not be queued. Your saved result is unchanged. Please try again later.');
                }

                return redirect()->route('detections.result', $detection)->with('status', 'Assessment retry queued. Your saved result will be replaced after the new assessment finishes.');
            }
            $previousStatus = $detection->processing_status;
            $retryId = (string) Str::uuid();
            $signals = $detection->signals ?? [];
            unset($signals['analysis_progress'], $signals['analysis_cancellation']);
            $signals['analysis_retry'] = ['id' => $retryId, 'status' => 'processing', 'previous_status' => $previousStatus];
            $detection->forceFill(['processing_status' => 'processing', 'signals' => $signals])->saveQuietly();
            try {
                $pipeline->run($detection->user, $context, $file, $detection);
            } finally {
                // Release a synchronous retry that failed before persistence,
                // while preserving a cancellation or a successfully saved result.
                Detection::whereKey($detection->id)->where('processing_status', 'processing')
                    ->where('signals->analysis_retry->id', $retryId)
                    ->update(['processing_status' => $previousStatus]);
            }
        } finally {
            $lock->release();
        }

        return redirect()->route('detections.result', $detection)->with('status', 'Assessment retry finished. Review the recorded status below.');
    }

    public function factCheckMediaClaim(Request $request, Detection $detection, DetectionPipeline $pipeline): RedirectResponse
    {
        abort_unless($request->user()->isAdmin() || $detection->user_id === $request->user()->id, 404);
        abort_unless(in_array($detection->media_type, ['image', 'video'], true), 422);
        $notice = data_get($detection->fact_check_result, 'media_claim_notice', []);
        $claim = trim((string) ($notice['text'] ?? ''));
        abort_unless(($notice['status'] ?? '') === 'claim_detected' && $claim !== '', 422);
        $context = ['analysis_task' => 'claim', 'caption_text' => $claim, 'notes' => null,
            'source_url' => $detection->source_url, 'source_platform' => $detection->platform];

        if (config('fact_check.queue_enabled')) {
            $sourcePath = (string) $detection->media_path;
            abort_unless($sourcePath !== '' && Storage::disk('public')->exists($sourcePath), 422);
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
            $newPath = 'detections/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
            abort_unless(Storage::disk('public')->copy($sourcePath, $newPath), 500);
            $newDetection = Detection::create(['user_id' => $request->user()->id, 'source_kind' => 'upload',
                'source_url' => $detection->source_url, 'caption_text' => $claim, 'media_path' => $newPath,
                'media_type' => $detection->media_type, 'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);
            AnalyzeDetectionEvidence::dispatch($newDetection->id, $context);

            return redirect()->route('detections.create', ['pending' => $newDetection->id])
                ->with('status', 'The visible claim was submitted as a separate factual assessment.');
        }

        $file = $this->uploadedImageForRefresh($detection);
        abort_unless($file, 422);
        $newDetection = $pipeline->run($request->user(), $context, $file);

        return redirect()->route('detections.result', $newDetection)
            ->with('status', 'The visible claim was checked separately from the AI-origin assessment.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeDetectionInputs(Request $request): array
    {
        $sourceUrl = $request->input('source_url', '');
        $captionText = $request->input('caption_text', '');
        // Leave malformed types intact so validation returns 422 instead of
        // raising an array-to-string error before the rules can run.
        $sourceUrl = is_string($sourceUrl) ? trim($sourceUrl) : $sourceUrl;
        $captionText = is_string($captionText) ? trim($captionText) : $captionText;
        // The composer can submit a pasted link in both fields. The URL is
        // evidence to fetch, not a factual statement to assess in its place.
        if (is_string($sourceUrl) && $sourceUrl !== '' && is_string($captionText)) {
            $captionText = trim(str_replace($sourceUrl, '', $captionText));
        }

        if (($sourceUrl === '' || $sourceUrl === null) && is_string($captionText) && $captionText !== '') {
            $extractedUrl = $this->extractUrlFromText($captionText);

            if ($extractedUrl !== null) {
                $sourceUrl = $extractedUrl;
                $captionText = trim(Str::replaceFirst($extractedUrl, '', $captionText));
            }
        }

        return [
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'caption_text' => $captionText !== '' ? $captionText : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeWeatherInputs(array $validated): array
    {
        $consent = filter_var($validated['weather_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $consent) {
            return [
                'weather_consent' => false,
                'weather_lat' => null,
                'weather_lon' => null,
                'weather_label' => null,
            ];
        }

        $lat = $this->normalizeCoordinate($validated['weather_lat'] ?? null, -90, 90, 4);
        $lon = $this->normalizeCoordinate($validated['weather_lon'] ?? null, -180, 180, 4);

        if ($lat === null || $lon === null) {
            return [
                'weather_consent' => false,
                'weather_lat' => null,
                'weather_lon' => null,
                'weather_label' => null,
            ];
        }

        $label = trim((string) ($validated['weather_label'] ?? ''));

        if ($label === '') {
            $label = 'Current location';
        }

        return [
            'weather_consent' => true,
            'weather_lat' => $lat,
            'weather_lon' => $lon,
            'weather_label' => $label,
        ];
    }

    private function normalizeCoordinate(mixed $value, float $min, float $max, int $precision): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            return null;
        }

        return round($number, $precision);
    }

    private function extractUrlFromText(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s<>"\']+/i', $text, $matches) !== 1) {
            return null;
        }

        $candidate = rtrim($matches[0], '.,!?:;)]}');

        return $candidate !== '' ? $candidate : null;
    }

    private function refreshDetectionEvidence(
        Detection $detection,
        LiveVerificationEvidenceService $liveVerificationEvidenceService,
        OpenAiDetectionReportService $openAiDetectionReportService,
        array $extraContext = [],
    ): void {
        // A saved media report is immutable on GET. A CSRF-protected POST retries
        // inference against the original file instead of reusing an old signal.
        if (isset($detection->fact_check_result['request']) || ($detection->fact_check_result['primary_task'] ?? '') === 'media') {
            return;
        }
        $uploadedImage = $this->uploadedImageForRefresh($detection);
        $openAiExtractedClaim = config('fact_check.enhanced_enabled') ? null : $this->extractClaimTextForRefresh($detection, $openAiDetectionReportService, $uploadedImage);
        $analysis = [
            'platform' => $detection->platform,
            'media_type' => $detection->media_type,
            'media_assessment' => $detection->fact_check_result['media_assessment'] ?? null,
            'fake_score' => (int) $detection->fake_score,
            'processing_status' => (string) $detection->processing_status,
            'preprocessing_summary' => (string) $detection->preprocessing_summary,
            'analysis_summary' => (string) $detection->analysis_summary,
            'verification_summary' => (string) $detection->verification_summary,
            'explanation_summary' => (string) $detection->explanation_summary,
            'recommendation' => (string) $detection->recommendation,
            'signals' => $detection->signals ?? [],
            'verification_sources' => $detection->verification_sources ?? [],
            'verdict' => (string) $detection->verdict,
        ];

        if ($openAiExtractedClaim !== null) {
            $analysis = $this->attachExtractedClaimText($analysis, $openAiExtractedClaim);
        }

        $context = [
            'source_url' => $detection->source_url,
            'caption_text' => $detection->caption_text,
            'notes' => $detection->notes,
            'media_type' => $detection->media_type,
            'platform' => $detection->platform,
            'openai_extracted_claim' => $openAiExtractedClaim,
        ] + $extraContext;

        if ((bool) config('fact_check.enhanced_enabled', true)) {
            $refreshed = app(EvidenceVerificationService::class)->apply($analysis, $context, $uploadedImage);
        } else {
            $refreshed = $liveVerificationEvidenceService->enrich($analysis, $context);
            $refreshed = app(FactCheckResultService::class)->apply($refreshed, $context);
        }

        $updates = [
            'fake_score' => (int) ($refreshed['fake_score'] ?? $detection->fake_score),
            'verdict' => (string) ($refreshed['verdict'] ?? $detection->verdict),
            'analysis_summary' => (string) ($refreshed['analysis_summary'] ?? $detection->analysis_summary),
            'verification_summary' => (string) ($refreshed['verification_summary'] ?? $detection->verification_summary),
            'explanation_summary' => (string) ($refreshed['explanation_summary'] ?? $detection->explanation_summary),
            'recommendation' => (string) ($refreshed['recommendation'] ?? $detection->recommendation),
            'signals' => $refreshed['signals'] ?? ($detection->signals ?? []),
            'verification_sources' => $refreshed['verification_sources'] ?? ($detection->verification_sources ?? []),
            'fact_check_result' => $refreshed['fact_check_result'],
        ];

        $hasChanges = false;

        foreach ($updates as $field => $value) {
            if ($detection->getAttribute($field) != $value) {
                $detection->setAttribute($field, $value);
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $detection->saveQuietly();
        }
    }

    private function needsSourceReconciliationRefresh(Detection $detection): bool
    {
        if ($detection->fact_check_result !== null) {
            return false;
        }

        $signals = $detection->signals ?? [];

        if (! is_array($signals)) {
            return true;
        }

        return ! collect($signals['verification_policy'] ?? [])
            ->filter(fn ($signal) => is_array($signal))
            ->contains(fn (array $signal): bool => ($signal['version'] ?? null) === TrustedFactCheckVerdictResolver::POLICY_VERSION);
    }

    private function storedOpenAiClaimText(Detection $detection): ?string
    {
        $signals = $detection->signals ?? [];

        if (! is_array($signals)) {
            return null;
        }

        $label = collect($signals['content_extraction'] ?? [])
            ->filter(fn ($signal) => is_array($signal))
            ->pluck('label')
            ->first(fn ($label) => is_string($label) && Str::contains($label, 'OpenAI read visible claim text'));

        if (! is_string($label)) {
            return null;
        }

        $parts = explode(':', $label, 2);
        $claimText = trim((string) ($parts[1] ?? ''));

        return $claimText !== '' ? $claimText : null;
    }

    private function extractClaimTextForRefresh(
        Detection $detection,
        OpenAiDetectionReportService $openAiDetectionReportService,
        ?UploadedFile $uploadedImage,
    ): ?string {
        $storedClaimText = $this->storedOpenAiClaimText($detection);

        if ($storedClaimText !== null) {
            return $storedClaimText;
        }

        if ($uploadedImage === null) {
            return null;
        }

        return $openAiDetectionReportService->extractClaimText([
            'source_url' => $detection->source_url,
            'caption_text' => $detection->caption_text,
            'notes' => $detection->notes,
            'media_type' => $detection->media_type,
            'platform' => $detection->platform,
        ], $uploadedImage);
    }

    private function uploadedImageForRefresh(Detection $detection): ?UploadedFile
    {
        if ((! config('fact_check.enhanced_enabled') && $detection->media_type !== 'image') || blank($detection->media_path)) {
            return null;
        }

        if (! Storage::disk('public')->exists((string) $detection->media_path)) {
            return null;
        }

        $path = Storage::disk('public')->path((string) $detection->media_path);

        if (! is_file($path)) {
            return null;
        }

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function attachExtractedClaimText(array $analysis, string $claimText): array
    {
        $claimText = Str::limit(trim($claimText), 260, '');

        if ($claimText === '') {
            return $analysis;
        }

        $label = "OpenAI read visible claim text from the image: {$claimText}";
        $signals = is_array($analysis['signals'] ?? null) ? $analysis['signals'] : [];
        $contentExtraction = collect($signals['content_extraction'] ?? [])
            ->filter(fn ($signal) => is_array($signal))
            ->reject(fn (array $signal): bool => ($signal['label'] ?? null) === $label)
            ->values();
        $contentExtraction[] = [
            'label' => $label,
            'weight' => 42,
        ];
        $signals['content_extraction'] = $contentExtraction->values()->all();

        $analysis['signals'] = $signals;

        return $analysis;
    }
}
