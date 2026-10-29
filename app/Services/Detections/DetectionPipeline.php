<?php

namespace App\Services\Detections;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\ClaimPlanner;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DetectionPipeline
{
    private const VERIFICATION_POLICY_VERSION = TrustedFactCheckVerdictResolver::POLICY_VERSION;

    public function __construct(
        private readonly LiveVerificationEvidenceService $liveVerificationEvidenceService,
        private readonly DetectionRetentionService $retentionService,
        private readonly OpenAiDetectionReportService $openAiDetectionReportService,
        private readonly TrustedFactCheckVerdictResolver $trustedFactCheckVerdictResolver,
        private readonly FilipinoNewsClassifier $filipinoNewsClassifier,
        private readonly DeepfakeCnnClassifier $deepfakeCnnClassifier,
        private readonly FactCheckResultService $factCheckResultService,
        private readonly EvidenceVerificationService $evidenceVerificationService,
        private readonly AiOriginClassifier $aiOriginClassifier,
        private readonly OpenAiMediaExplanation $mediaExplanation,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function run(User $user, array $validated, ?UploadedFile $uploadedFile, ?Detection $pending = null): Detection
    {
        $sourceUrl = $this->nullableString($validated['source_url'] ?? null);
        $captionText = $this->nullableString($validated['caption_text'] ?? null);
        $notes = $this->nullableString($validated['notes'] ?? null);
        $selectedPlatform = $this->normalizePlatform($validated['source_platform'] ?? null);
        $weatherConsent = (bool) ($validated['weather_consent'] ?? false);
        $weatherLat = $weatherConsent ? ($validated['weather_lat'] ?? null) : null;
        $weatherLon = $weatherConsent ? ($validated['weather_lon'] ?? null) : null;
        $weatherLabel = $weatherConsent ? ($validated['weather_label'] ?? null) : null;
        $weatherContext = [
            'weather_consent' => $weatherConsent,
            'weather_lat' => $weatherLat,
            'weather_lon' => $weatherLon,
            'weather_label' => $weatherLabel,
        ];

        $mediaType = $this->detectMediaType($uploadedFile, $sourceUrl);
        $submission = $validated + ['media_type' => $mediaType];
        $resolvedRequest = SubmissionTask::resolve($submission);
        $primaryTask = $resolvedRequest['resolved_task'];
        $requestedTasks = $resolvedRequest['tasks'];
        // Use the same resolved request as the report. An empty literal claim
        // after stripping a question is not permission to switch to AI screening.
        $runMediaDetector = in_array('media', $requestedTasks, true);
        $analysisId = (string) Str::uuid();
        Log::info('TruthGuard analysis started.', [
            'analysis_id' => $analysisId,
            'detection_id' => $pending?->id,
            'route' => $primaryTask,
            'media_type' => $mediaType,
            'has_upload' => $uploadedFile !== null,
            'has_url' => $sourceUrl !== null,
        ]);
        $platform = $selectedPlatform ?? $this->detectPlatform($sourceUrl, $captionText, $notes);
        $mediaChecksum = $this->buildMediaChecksum($uploadedFile);
        $requestFingerprint = $this->buildRequestFingerprint(
            $sourceUrl,
            $captionText,
            $notes,
            $mediaType,
            $platform,
            $mediaChecksum,
            $validated['analysis_task'] ?? 'auto',
            ['tasks' => $requestedTasks, 'weather' => $weatherContext, 'locale' => app()->getLocale()],
        );
        $mediaPath = $pending?->media_path ?? $this->persistUploadedFile($uploadedFile);
        // Infer against the persisted original, also used by queued analysis and retries.
        if ($uploadedFile && $mediaPath) {
            $uploadedFile = new UploadedFile(Storage::disk('public')->path($mediaPath), $uploadedFile->getClientOriginalName(), null, null, true);
        }
        // A URL alone does not identify immutable content: its page may change.
        $reuseEnabled = (bool) config('fact_check.result_reuse.enabled', true) && ! $weatherConsent
            && ($sourceUrl === null || $mediaChecksum !== null);
        $forceRefresh = (bool) ($validated['force_refresh'] ?? false) || ($pending && $pending->fact_check_result !== null);
        $reuseLock = $reuseEnabled && $requestFingerprint ? Cache::lock('truthguard-result:'.$requestFingerprint, 900) : null;
        // Serialise identical submissions, then check the database again after
        // acquiring the lock. A second worker must not pay for the same analysis.
        try {
            $reuseLock?->block(max(0, (int) config('fact_check.result_reuse.wait_seconds', 240)));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['media_file' => 'An identical submission is still being analyzed. Retry after it finishes; no duplicate analysis was started.']);
        }
        try {
            if ($reuseEnabled && ! $forceRefresh && ($reusableDetection = $this->findReusableDetection($requestFingerprint))) {
                $detection = $this->createReusedDetection($user, $reusableDetection, $sourceUrl, $captionText, $notes, $mediaPath, $mediaChecksum, $requestFingerprint, $pending);
                Log::info('TruthGuard analysis reused a saved result without inference.', ['analysis_id' => $analysisId,
                    'detection_id' => $detection->id, 'reused_from_detection_id' => $reusableDetection->id]);

                return $detection;
            }

            $analysis = $this->buildLocalAnalysis($uploadedFile, $sourceUrl, $captionText, $notes, $mediaType, $platform,
                $primaryTask !== 'claim', $runMediaDetector);
            $platform = $this->nullableString($analysis['platform'] ?? null) ?? $platform;
            $mediaType = $this->nullableString($analysis['media_type'] ?? null) ?? $mediaType;

            if ((bool) config('fact_check.enhanced_enabled', true) || $primaryTask !== 'claim') {
                $analysis = $this->evidenceVerificationService->apply($analysis, [
                    'source_url' => $sourceUrl, 'caption_text' => $captionText, 'notes' => $notes,
                    'media_type' => $mediaType, 'platform' => $platform,
                    'analysis_task' => $validated['analysis_task'] ?? 'auto',
                    'analysis_id' => $analysisId,
                    'resolved_request' => $resolvedRequest,
                ] + $weatherContext, $uploadedFile, $pending ? function (string $stage, array $domains = []) use ($pending): void {
                    $signals = $pending->signals ?? [];
                    $signals['analysis_progress'] = [['label' => $stage, 'weight' => 0,
                        'source_domains' => $domains ?: data_get($signals, 'analysis_progress.0.source_domains', [])]];
                    $pending->forceFill(['signals' => $signals])->saveQuietly();
                } : null);
            } else {
                $openAiExtractedClaim = $this->openAiDetectionReportService->extractClaimText([
                    'source_url' => $sourceUrl,
                    'caption_text' => $captionText,
                    'notes' => $notes,
                    'media_type' => $mediaType,
                    'platform' => $platform,
                ], $uploadedFile);

                if ($openAiExtractedClaim !== null) {
                    $analysis = $this->attachExtractedClaimText($analysis, $openAiExtractedClaim);
                }

                $analysis = $this->enrichAnalysis(
                    $analysis,
                    $sourceUrl,
                    $captionText,
                    $notes,
                    $mediaType,
                    $platform,
                    $weatherContext,
                    $openAiExtractedClaim,
                );
                $analysis = $this->factCheckResultService->apply($analysis, [
                    'source_url' => $sourceUrl,
                    'caption_text' => $captionText,
                    'notes' => $notes,
                    'media_type' => $mediaType,
                    'platform' => $platform,
                    'openai_extracted_claim' => $openAiExtractedClaim,
                    'locale' => app()->getLocale(),
                ]);
            }
            $analysis = $this->markVerificationPolicyVersion($analysis);
            // Every route, including the legacy fallback, crosses the same typed
            // answer boundary before it can be compared, saved or displayed.
            $analysis['fact_check_result'] = ReportAnswer::complete($analysis['fact_check_result']);
            $analysis = $this->mediaExplanation->apply($analysis);
            $analysis['fact_check_result'] = ResultWording::ensure($analysis['fact_check_result'], $analysis['recommendation'] ?? '');
            $analysis['analysis_summary'] = $analysis['explanation_summary'] = $analysis['fact_check_result']['summary'];
            // Older saved reports have not been admitted to this fresh cache.
            $analysis['signals']['result_cache'] = ['generation' => (string) config('fact_check.result_reuse.generation')];

            $attributes = [
                'user_id' => $user->id,
                'source_kind' => $uploadedFile ? 'upload' : 'link',
                'platform' => $platform,
                'source_url' => $sourceUrl,
                'media_path' => $mediaPath,
                'media_checksum' => $mediaChecksum,
                'media_type' => $mediaType,
                'request_fingerprint' => $requestFingerprint,
                'reused_from_detection_id' => null,
                'caption_text' => $captionText,
                'fake_score' => (int) $analysis['fake_score'],
                'processing_status' => (string) $analysis['processing_status'],
                'preprocessing_summary' => (string) $analysis['preprocessing_summary'],
                'analysis_summary' => (string) $analysis['analysis_summary'],
                'verification_summary' => (string) $analysis['verification_summary'],
                'explanation_summary' => (string) $analysis['explanation_summary'],
                'recommendation' => (string) $analysis['recommendation'],
                'signals' => $analysis['signals'],
                'verification_sources' => $analysis['verification_sources'],
                'fact_check_result' => $analysis['fact_check_result'],
                'verdict' => (string) $analysis['verdict'],
                'notes' => $notes,
                'analyzed_at' => now(),
            ];

            if ($pending) {
                $pending->refresh();

                // Logout/session expiry can cancel a running worker. Never let a
                // late provider response overwrite the cancellation record.
                if ($pending->processing_status !== 'processing') {
                    Log::info('TruthGuard analysis result discarded after cancellation.', [
                        'analysis_id' => $analysisId,
                        'detection_id' => $pending->id,
                    ]);

                    return $pending;
                }

                $pending->fill($attributes)->save();
                Log::info('TruthGuard analysis persisted.', ['analysis_id' => $analysisId, 'detection_id' => $pending->id, 'source_count' => count($analysis['fact_check_result']['sources'] ?? [])]);

                return $pending;
            }

            $detection = Detection::create($attributes);
            Log::info('TruthGuard analysis persisted.', ['analysis_id' => $analysisId, 'detection_id' => $detection->id, 'source_count' => count($analysis['fact_check_result']['sources'] ?? [])]);

            return $detection;
        } finally {
            $reuseLock?->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLocalAnalysis(
        ?UploadedFile $uploadedFile,
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        string $mediaType,
        ?string $platform,
        bool $mediaPrimary = false,
        bool $runMediaDetector = true,
    ): array {
        $signals = $this->buildSignals($uploadedFile, $sourceUrl, $captionText, $notes, $mediaType);
        $enhanced = (bool) config('fact_check.enhanced_enabled', true) || $mediaPrimary;
        $localNlpClassification = $enhanced ? null : $this->filipinoNewsClassifier->classify($captionText, $notes);
        $mediaClassification = null;
        if (! $runMediaDetector && in_array($mediaType, ['image', 'video'], true)) {
            $mediaClassification = ['status' => 'not_requested', 'media_type' => $mediaType,
                'task' => $mediaType === 'image' ? 'AI-generation screening' : 'Sampled-frame synthetic-media screening',
                'reason' => 'The requested factual or context analysis does not require media-origin screening.',
                'label' => null, 'fake_probability' => null, 'model_version' => null];
        } elseif ($mediaType === 'image') {
            $mediaClassification = $uploadedFile && config('services.ai_origin.enabled', false)
                ? $this->aiOriginClassifier->classify($uploadedFile)
                : ['status' => 'unavailable', 'media_type' => 'image', 'task' => 'AI-generation classification',
                    'reason' => $uploadedFile ? 'disabled' : 'missing_media',
                    'label' => null, 'fake_probability' => null, 'model_version' => null];
        } elseif ($mediaType === 'video') {
            $mediaClassification = $this->deepfakeCnnClassifier->classify($uploadedFile, $mediaType);
        }

        if ($localNlpClassification !== null) {
            $signals = $this->attachLocalNlpSignal($signals, $localNlpClassification);
        }

        if (($mediaClassification['status'] ?? '') === 'completed') {
            $signals = $this->attachDeepfakeCnnSignal($signals, $mediaClassification);
        }

        // The legacy numeric column is media screening only on the evidence path.
        // It is never consumed by the factual assessment or presented as truth confidence.
        $fakeScore = $enhanced ? (int) ($mediaClassification['screening_score'] ?? 0) : $this->calculateFakeScore($signals);
        if (! $enhanced) {
            $fakeScore = $this->applyLocalNlpRiskFloor($fakeScore, $localNlpClassification);
            $fakeScore = $this->applyDeepfakeCnnRiskFloor($fakeScore, $mediaClassification);
        }
        $verdict = $this->determineVerdict($fakeScore);
        $verificationSources = $this->buildVerificationSources($sourceUrl, $captionText, $notes, $platform);

        return [
            'platform' => $platform,
            'media_type' => $mediaType,
            'media_assessment' => $mediaClassification,
            'fake_score' => $fakeScore,
            'processing_status' => 'completed',
            'preprocessing_summary' => $this->buildPreprocessingSummary($uploadedFile, $sourceUrl, $mediaType, $platform, $captionText),
            'analysis_summary' => $this->buildAnalysisSummary($signals, $mediaType),
            'verification_summary' => $this->buildVerificationSummary($verificationSources),
            'explanation_summary' => $this->buildExplanationSummary($verdict, $fakeScore, $signals, $verificationSources, $mediaType),
            'recommendation' => $this->buildRecommendation($verdict, $verificationSources, $mediaType),
            'signals' => $signals,
            'verification_sources' => $verificationSources,
            'verdict' => $verdict,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function buildMediaChecksum(?UploadedFile $uploadedFile): ?string
    {
        if (! $uploadedFile || ! $uploadedFile->isValid()) {
            return null;
        }

        $sourcePath = $this->resolveUploadedFilePath($uploadedFile);

        if ($sourcePath === null) {
            return null;
        }

        $checksum = hash_file('sha256', $sourcePath);

        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    private function buildRequestFingerprint(
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        string $mediaType,
        ?string $platform,
        ?string $mediaChecksum,
        string $analysisTask = 'auto',
        array $requestContext = [],
    ): ?string {
        $payload = [
            'source_url' => $this->normalizeFingerprintString($sourceUrl),
            'caption_text' => $this->normalizeFingerprintString($captionText),
            'notes' => $this->normalizeFingerprintString($notes),
            'media_type' => $this->normalizeFingerprintString($mediaType),
            'platform' => $this->normalizeFingerprintString($platform),
            'media_checksum' => $this->normalizeFingerprintString($mediaChecksum),
            'analysis_task' => $analysisTask,
            'request_context' => $requestContext,
            'reuse_version' => 'exact-submission-v1',
            'wording_version' => ResultWording::VERSION,
            'inference_settings' => [
                'enhanced' => config('fact_check.enhanced_enabled'),
                'openai_enabled' => config('services.openai.analysis_enabled'),
                'extraction_enabled' => config('services.openai.claim_extraction_enabled'),
                'routine_models' => [config('services.openai.extraction_model'), config('services.openai.planning_model')],
                'research_limits' => [config('fact_check.web_search'), config('fact_check.max_model_input_bytes'),
                    config('fact_check.model_output_tokens'), config('fact_check.plan_output_tokens')],
                'media_explanation' => [OpenAiMediaExplanation::VERSION,
                    config('services.openai.media_explanation_enabled', true), config('services.openai.media_explanation_max_tokens', 400),
                    config('services.openai.model')],
                'deepfake' => Arr::except(config('services.deepfake_cnn', []), ['python_binary', 'timeout']),
                'origin' => Arr::only(config('services.ai_origin', []), ['enabled', 'model_path']),
                ...($mediaType === 'image' ? ['image_detector_policy' => 'primary-origin-only-v1'] : []),
                'article_model' => Arr::except(config('services.filipino_news_classifier', []), ['timeout', 'connect_timeout']),
                'artifacts' => $this->modelArtifactIdentity(),
            ],
            'routing_version' => 'question-routing-v4',
            'pipeline_version' => AssessmentVerdict::VERSION.'|'.AssessmentScore::RUBRIC.'|'.SubmissionTask::VERSION
                .'|'.EvidenceVerificationService::VERSION.'|'.EvidenceVerdictCalibration::identity()
                .'|'.(config('services.ai_origin.enabled', false) ? AiOriginClassifier::VERSION : 'origin-disabled')
                .'|'.(config('fact_check.verdict_calibration.enabled', false)
                    ? EvidenceVerdictCalibration::METHOD.':'.config('fact_check.verdict_calibration.sha256', '') : 'evidence-calibration-disabled')
                .'|'.(config('services.media_calibration.enabled', false)
                    ? MediaScoreCalibration::METHOD.':'.config('services.media_calibration.sha256', '') : 'calibration-disabled'),
        ];

        $hasMeaningfulInput = filled($sourceUrl) || filled($captionText) || filled($notes) || filled($mediaChecksum);

        if (! $hasMeaningfulInput) {
            return null;
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeFingerprintString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // URL paths and submitted wording can be case-sensitive.
        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function findReusableDetection(?string $requestFingerprint): ?Detection
    {
        if ($requestFingerprint === null) {
            return null;
        }

        $query = Detection::query()
            ->where('request_fingerprint', $requestFingerprint)
            ->where('processing_status', 'completed')
            ->whereNull('reused_from_detection_id')
            ->whereNotNull('analyzed_at');

        return $this->retentionService
            ->applyRetentionWindow($query)
            ->latest('analyzed_at')
            ->cursor()
            ->first(fn (Detection $candidate): bool => $this->canReuseResult($candidate));
    }

    private function canReuseResult(Detection $candidate): bool
    {
        $result = $candidate->fact_check_result;
        if (! $this->hasCurrentVerificationPolicyVersion($candidate) || ! is_array($result)
            || data_get($candidate->signals, 'result_cache.generation') !== (string) config('fact_check.result_reuse.generation')
            || ($result['schema_version'] ?? null) !== 4 || ($result['processing_status'] ?? null) !== 'completed'
            || in_array($result['verdict'] ?? '', ['ANALYSIS_UNAVAILABLE', 'UNSUPPORTED_ANALYSIS', 'CLARIFICATION_NEEDED'], true)
            || filled(data_get($candidate->signals, 'submission_extraction.0.failure_reason'))
            || filled(data_get($candidate->signals, 'media_explanation.0.failure_reason'))
            || collect(data_get($result, 'diagnostics.model_stages', []))->contains(fn ($status) => $status !== 'completed')
            || in_array(data_get($result, 'claim_assessment.status'), ['partial', 'unavailable', 'failed'], true)) {
            return false;
        }
        $question = implode(' ', array_filter([$candidate->caption_text, $candidate->notes,
            $result['claim'] ?? null, data_get($candidate->signals, 'submission_extraction.0.visible_text')]));
        if (ClaimPlanner::isCurrentStatus($question)
            || preg_match('/\b(?:today|tonight|tomorrow|yesterday|now|latest|weather|forecast|live|ongoing)\b|ngayon|bukas|kasalukuyan|pinakabago/iu', $question)) {
            return false;
        }
        $mediaOnly = ($result['primary_task'] ?? '') === 'media' && empty($result['secondary_assessments']);
        $seconds = (int) config('fact_check.result_reuse.'.($mediaOnly ? 'media_seconds' : 'claim_seconds'), $mediaOnly ? 604800 : 86400);
        if (in_array($result['verdict'] ?? '', ['UNVERIFIED', 'CONFLICTING_EVIDENCE', 'MISSING_CONTEXT'], true)) {
            $seconds = min($seconds, (int) config('fact_check.result_reuse.unresolved_seconds', 300));
        }

        return $seconds > 0 && $candidate->analyzed_at->gte(now()->subSeconds($seconds));
    }

    private function modelArtifactIdentity(): array
    {
        $files = [__FILE__, __DIR__.'/MediaAnalysisReport.php', __DIR__.'/DeepfakeCnnClassifier.php', __DIR__.'/AiOriginClassifier.php', __DIR__.'/FilipinoNewsClassifier.php'];
        foreach (['model_path', 'config_path', 'video_model_path', 'video_config_path', 'script_path'] as $key) {
            $path = config('services.deepfake_cnn.'.$key);
            if (is_string($path) && $path !== '') {
                $files[] = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) ? $path : base_path($path);
            }
        }
        $origin = config('services.ai_origin.model_path');
        if (is_string($origin) && $origin !== '') {
            $origin = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $origin) ? $origin : base_path($origin);
            $files = array_merge($files, is_dir($origin) ? (glob($origin.'/*') ?: []) : [$origin]);
        }
        $identity = [];
        foreach ($files as $file) {
            clearstatcache(true, $file);
            $identity[$file] = is_file($file) ? [filesize($file), filemtime($file),
                filesize($file) < 2097152 ? hash_file('sha256', $file) : null] : null;
        }

        return $identity;
    }

    private function createReusedDetection(
        User $user,
        Detection $reusableDetection,
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        ?string $mediaPath,
        ?string $mediaChecksum,
        ?string $requestFingerprint,
        ?Detection $pending = null,
    ): Detection {
        $sourceDetection = $reusableDetection->reused_from_detection_id
            ? Detection::query()->find($reusableDetection->reused_from_detection_id) ?? $reusableDetection
            : $reusableDetection;

        $reuseNote = 'TruthGuard matched this submission with an identical detection already analyzed on '
            .$reusableDetection->analyzed_at?->format('M d, Y h:i A')
            .' and reused the stored result to avoid reprocessing the same evidence.';

        $analysis = [
            'platform' => $reusableDetection->platform,
            'media_type' => $reusableDetection->media_type,
            'fake_score' => (int) $reusableDetection->fake_score,
            'processing_status' => 'completed',
            'preprocessing_summary' => trim(($reusableDetection->preprocessing_summary ?: '').' '.$reuseNote),
            'analysis_summary' => $reusableDetection->analysis_summary,
            'verification_summary' => $reusableDetection->verification_summary,
            'explanation_summary' => $reusableDetection->explanation_summary,
            'recommendation' => $reusableDetection->recommendation,
            'signals' => $reusableDetection->signals ?? [],
            'verification_sources' => $reusableDetection->verification_sources ?? [],
            'fact_check_result' => $reusableDetection->fact_check_result,
            'verdict' => $reusableDetection->verdict,
        ];

        // Keep the saved facts, verdict, citations, and assessment date, while
        // presenting the cached answer with fresh wording at zero token cost.
        $analysis['fact_check_result'] = ResultWording::varyForReuse(
            $analysis['fact_check_result'],
            implode('|', [$requestFingerprint, $user->id, now()->timestamp]),
        );
        $analysis['analysis_summary'] = $analysis['explanation_summary']
            = $analysis['fact_check_result']['summary'];

        $analysis['signals']['result_reuse'] = ['source_detection_id' => $sourceDetection->id,
            'original_assessed_at' => $reusableDetection->analyzed_at?->toIso8601String(),
            'original_openai_usage' => $analysis['signals']['openai_usage'] ?? [],
            'original_usage_aliases' => Arr::only($analysis['signals'], ['ai_usage', 'token_usage', 'usage']),
            'wording_refreshed_locally' => true, 'new_api_tokens' => 0];
        // Preserve historical usage above, but never bill/count it again on a cache hit.
        $analysis['signals']['openai_usage'] = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        unset($analysis['signals']['analysis_progress'], $analysis['signals']['ai_usage'], $analysis['signals']['token_usage'], $analysis['signals']['usage']);
        $attributes = [
            'user_id' => $user->id,
            'source_kind' => $mediaPath ? 'upload' : 'link',
            'platform' => $reusableDetection->platform,
            'source_url' => $sourceUrl,
            'media_path' => $mediaPath,
            'media_checksum' => $mediaChecksum,
            'media_type' => $reusableDetection->media_type,
            'request_fingerprint' => $requestFingerprint,
            'reused_from_detection_id' => $sourceDetection->id,
            'caption_text' => $captionText,
            'fake_score' => (int) $analysis['fake_score'],
            'processing_status' => (string) $analysis['processing_status'],
            'preprocessing_summary' => (string) $analysis['preprocessing_summary'],
            'analysis_summary' => (string) $analysis['analysis_summary'],
            'verification_summary' => (string) $analysis['verification_summary'],
            'explanation_summary' => (string) $analysis['explanation_summary'],
            'recommendation' => (string) $analysis['recommendation'],
            'signals' => $analysis['signals'],
            'verification_sources' => $analysis['verification_sources'],
            'fact_check_result' => $analysis['fact_check_result'],
            'verdict' => (string) $analysis['verdict'],
            'notes' => $notes,
            'analyzed_at' => $reusableDetection->analyzed_at,
        ];

        if ($pending) {
            $pending->fill($attributes)->save();

            return $pending;
        }

        return Detection::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function enrichAnalysis(
        array $analysis,
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        string $mediaType,
        ?string $platform,
        array $weatherContext = [],
        ?string $openAiExtractedClaim = null,
    ): array {
        return $this->liveVerificationEvidenceService->enrich($analysis, [
            'source_url' => $sourceUrl,
            'caption_text' => $captionText,
            'notes' => $notes,
            'media_type' => $mediaType,
            'platform' => $platform,
            'openai_extracted_claim' => $openAiExtractedClaim,
        ] + $weatherContext);
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

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function markVerificationPolicyVersion(array $analysis): array
    {
        $signals = is_array($analysis['signals'] ?? null) ? $analysis['signals'] : [];
        $signals['verification_policy'] = [[
            'label' => 'Trusted source rating reconciliation applied.',
            'weight' => 1,
            'version' => self::VERIFICATION_POLICY_VERSION,
        ]];
        $analysis['signals'] = $signals;

        return $analysis;
    }

    private function hasCurrentVerificationPolicyVersion(Detection $detection): bool
    {
        $signals = $detection->signals ?? [];

        if (! is_array($signals)) {
            return false;
        }

        return collect($signals['verification_policy'] ?? [])
            ->filter(fn ($signal) => is_array($signal))
            ->contains(fn (array $signal): bool => ($signal['version'] ?? null) === self::VERIFICATION_POLICY_VERSION);
    }

    private function persistUploadedFile(?UploadedFile $uploadedFile): ?string
    {
        if (! $uploadedFile || ! $uploadedFile->isValid()) {
            return null;
        }

        $sourcePath = $this->resolveUploadedFilePath($uploadedFile);

        if ($sourcePath === null) {
            return null;
        }

        $targetPath = 'detections/'.$uploadedFile->hashName();
        $stream = fopen($sourcePath, 'rb');

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $stored = Storage::disk('public')->writeStream($targetPath, $stream);
        } finally {
            fclose($stream);
        }

        return $stored ? $targetPath : null;
    }

    private function resolveUploadedFilePath(UploadedFile $uploadedFile): ?string
    {
        foreach ([$uploadedFile->getRealPath(), $uploadedFile->getPathname()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizePlatform(mixed $value): ?string
    {
        $platform = strtolower(trim((string) $value));

        if ($platform === '' || $platform === 'auto') {
            return null;
        }

        return $platform;
    }

    private function detectMediaType(?UploadedFile $uploadedFile, ?string $sourceUrl): string
    {
        if ($uploadedFile) {
            $mimeType = (string) $uploadedFile->getMimeType();

            if (str_starts_with($mimeType, 'image/')) {
                return 'image';
            }

            if (str_starts_with($mimeType, 'video/')) {
                return 'video';
            }

            if ($mimeType === 'application/pdf') {
                return 'document';
            }
        }

        if ($sourceUrl) {
            $path = strtolower((string) parse_url($sourceUrl, PHP_URL_PATH));
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                return 'image';
            }

            if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v'], true)) {
                return 'video';
            }

            if ($extension === 'pdf') {
                return 'document';
            }
        }

        return 'unknown';
    }

    private function detectPlatform(?string $sourceUrl, ?string $captionText, ?string $notes): ?string
    {
        if ($sourceUrl) {
            $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));

            return match (true) {
                Str::contains($host, 'facebook.com'), Str::contains($host, 'fb.com') => 'facebook',
                Str::contains($host, 'instagram.com') => 'instagram',
                Str::contains($host, 'tiktok.com') => 'tiktok',
                Str::contains($host, 'twitter.com'), Str::contains($host, 'x.com') => 'x',
                Str::contains($host, 'youtube.com'), Str::contains($host, 'youtu.be') => 'youtube',
                $host !== '' => 'web',
                default => null,
            };
        }

        $context = strtolower(trim(($captionText ?? '').' '.($notes ?? '')));

        return match (true) {
            $context !== '' && Str::contains($context, ['facebook', 'fb post']) => 'facebook',
            $context !== '' && Str::contains($context, ['instagram', 'ig story']) => 'instagram',
            $context !== '' && Str::contains($context, ['tiktok', 'tik tok']) => 'tiktok',
            $context !== '' && Str::contains($context, ['twitter', 'tweet', 'x post']) => 'x',
            $context !== '' && Str::contains($context, ['youtube', 'shorts']) => 'youtube',
            default => null,
        };
    }

    /**
     * @return array<string, array<int, array<string, int|string>>>
     */
    private function buildSignals(
        ?UploadedFile $uploadedFile,
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        string $mediaType,
    ): array {
        $signals = [
            'visual' => [],
            'textual' => [],
            'contextual' => [],
        ];

        $fileName = strtolower((string) $uploadedFile?->getClientOriginalName());
        $sourceContext = strtolower(trim(($sourceUrl ?? '').' '.$fileName));
        $textContext = strtolower(trim(($captionText ?? '').' '.($notes ?? '')));

        foreach ([
            ['keywords' => ['ai', 'deepfake', 'synthetic', 'generated', 'midjourney', 'stable diffusion', 'face swap'], 'label' => 'AI-generation terms found in source metadata', 'weight' => 30],
            ['keywords' => ['edited', 'photoshopped', 'render', 'concept art'], 'label' => 'Editing-oriented terms found in source metadata', 'weight' => 18],
        ] as $rule) {
            if ($sourceContext !== '' && Str::contains($sourceContext, $rule['keywords'])) {
                $signals['visual'][] = [
                    'label' => $rule['label'],
                    'weight' => $rule['weight'],
                ];
            }
        }

        if ($mediaType === 'video') {
            $signals['visual'][] = [
                'label' => 'Video input requires stronger frame-level verification',
                'weight' => 8,
            ];
        }

        if ($sourceUrl && preg_match('/bit\\.ly|tinyurl|t\\.co|shorturl/i', $sourceUrl) === 1) {
            $signals['contextual'][] = [
                'label' => 'Shortened source link can hide original provenance',
                'weight' => 10,
            ];
        }

        if ($sourceUrl && ! preg_match('/^https?:\\/\\//i', $sourceUrl)) {
            $signals['contextual'][] = [
                'label' => 'Source link is missing a stable protocol',
                'weight' => 12,
            ];
        }

        foreach ([
            ['keywords' => ['breaking', 'urgent', 'share now', 'viral', 'must watch', 'confirmed!', 'exposed'], 'label' => 'Caption uses high-urgency language', 'weight' => 14],
            ['keywords' => ['they do not want you to know', 'mainstream media', 'cover up', 'hidden truth'], 'label' => 'Caption includes conspiracy-style framing', 'weight' => 12],
            ['keywords' => ['according to a friend', 'unverified', 'rumor', 'not sure if real'], 'label' => 'Caption openly signals weak sourcing', 'weight' => 10],
        ] as $rule) {
            if ($textContext !== '' && Str::contains($textContext, $rule['keywords'])) {
                $signals['textual'][] = [
                    'label' => $rule['label'],
                    'weight' => $rule['weight'],
                ];
            }
        }

        if ($textContext !== '' && Str::contains($textContext, ['flood', 'typhoon', 'weather', 'storm', 'rainfall', 'earthquake', 'eruption'])) {
            $signals['contextual'][] = [
                'label' => 'Claim references an event that benefits from official incident cross-checking',
                'weight' => 6,
            ];
        }

        if ($textContext !== '' && Str::contains($textContext, ['election', 'vote', 'president', 'mayor', 'senator'])) {
            $signals['contextual'][] = [
                'label' => 'Claim references a civic or political event that needs source validation',
                'weight' => 7,
            ];
        }

        if ($textContext === '' && $sourceContext === '') {
            $signals['contextual'][] = [
                'label' => 'Very limited context is available for verification',
                'weight' => 5,
            ];
        }

        return $signals;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $signals
     * @param  array<string, mixed>  $classification
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function attachLocalNlpSignal(array $signals, array $classification): array
    {
        $confidence = (float) ($classification['confidence'] ?? 0.0);
        $fakeProbability = (float) ($classification['fake_probability'] ?? 0.0);
        $rawVerdict = (string) ($classification['raw_verdict'] ?? 'Uncertain');
        $displayConfidence = (string) round($confidence * 100);
        $weight = match ((string) ($classification['verdict'] ?? 'review')) {
            'fake' => max(22, min(52, (int) round($fakeProbability * 52))),
            'real' => 1,
            default => 0,
        };

        $signals['local_nlp'][] = [
            'label' => "Filipino article classifier rated the text as {$rawVerdict} ({$displayConfidence}% confidence)",
            'weight' => $weight,
            'verdict' => (string) ($classification['verdict'] ?? 'review'),
            'predicted_label' => (string) ($classification['predicted_label'] ?? ''),
            'real_probability' => round((float) ($classification['real_probability'] ?? 0.0), 4),
            'fake_probability' => round($fakeProbability, 4),
            'word_count' => (int) ($classification['word_count'] ?? 0),
            'reason' => (string) ($classification['reason'] ?? ''),
        ];

        return $signals;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $signals
     * @param  array<string, mixed>  $classification
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function attachDeepfakeCnnSignal(array $signals, array $classification): array
    {
        $fakeProbability = max(0.0, min(1.0, (float) ($classification['fake_probability'] ?? 0.0)));
        $threshold = max(0.0, min(1.0, (float) ($classification['threshold'] ?? 0.5)));
        $screeningScore = max(0, min(100, (int) ($classification['screening_score'] ?? round($fakeProbability * 100))));
        $label = (string) ($classification['label'] ?? ($fakeProbability >= $threshold ? 'fake' : 'real'));
        $mediaType = (string) ($classification['media_type'] ?? 'image');
        $modelLabel = $classification['model_name'] ?? ($mediaType === 'video' ? 'Video CNN' : 'AI-origin detector');
        $displayProbability = number_format($fakeProbability, 6);
        $displayThreshold = number_format($threshold, 6);
        $weight = match (true) {
            $fakeProbability >= max(0.85, $threshold) => max(55, min(80, (int) round($fakeProbability * 80))),
            $fakeProbability >= $threshold => max(34, min(58, (int) round($fakeProbability * 70))),
            $fakeProbability >= max(0.0, $threshold - 0.10) => 14,
            default => 1,
        };

        $signals['deepfake_cnn'][] = [
            'model_version' => $classification['model_version'] ?? null,
            'label' => "{$modelLabel} returned class {$label}, raw synthetic-class score {$displayProbability} (uncalibrated)",
            'weight' => $weight,
            'verdict' => $label,
            'fake_probability' => round($fakeProbability, 4),
            'threshold' => round($threshold, 4),
            'media_type' => $mediaType,
            'aggregation_method' => (string) ($classification['aggregation_method'] ?? ''),
            'frames_used' => (int) ($classification['frames_used'] ?? 0),
            'screening_score' => $screeningScore,
            'summary' => (string) ($classification['summary'] ?? "The detector's decision threshold was {$displayThreshold}."),
        ];

        return $signals;
    }

    /**
     * @param  array<string, mixed>|null  $classification
     */
    private function applyLocalNlpRiskFloor(int $fakeScore, ?array $classification): int
    {
        if ($classification === null || ($classification['verdict'] ?? null) !== 'fake') {
            return $fakeScore;
        }

        $confidence = (float) ($classification['confidence'] ?? 0.0);
        $highConfidenceThreshold = (float) config('services.filipino_news_classifier.high_confidence_threshold', 0.85);

        if ($confidence < $highConfidenceThreshold) {
            return $fakeScore;
        }

        $floor = max(45, min(99, (int) config('services.filipino_news_classifier.fake_score_floor', 72)));

        return max($fakeScore, $floor);
    }

    /**
     * @param  array<string, mixed>|null  $classification
     */
    private function applyDeepfakeCnnRiskFloor(int $fakeScore, ?array $classification): int
    {
        if ($classification === null) {
            return $fakeScore;
        }

        $fakeProbability = max(0.0, min(1.0, (float) ($classification['fake_probability'] ?? 0.0)));
        $threshold = max(0.0, min(1.0, (float) ($classification['threshold'] ?? 0.5)));
        $label = (string) ($classification['label'] ?? '');

        if ($label !== 'fake' && $fakeProbability < $threshold) {
            return $fakeScore;
        }

        return max($fakeScore, max(45, min(99, (int) round($fakeProbability * 100))));
    }

    /**
     * @param  array<string, array<int, array<string, int|string>>>  $signals
     */
    private function calculateFakeScore(array $signals): int
    {
        $weight = collect($signals)
            ->flatten(1)
            ->sum(fn (array $signal) => (int) ($signal['weight'] ?? 0));

        return min(99, max(12, 18 + $weight));
    }

    private function determineVerdict(int $fakeScore): string
    {
        return match (true) {
            $fakeScore >= 70 => 'fake',
            $fakeScore >= 45 => 'review',
            default => 'real',
        };
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildVerificationSources(
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        ?string $platform,
    ): array {
        $context = strtolower(trim(($captionText ?? '').' '.($notes ?? '').' '.($sourceUrl ?? '')));
        $factCheckUrl = $this->buildFactCheckExplorerUrl($captionText, $notes, $sourceUrl);

        $sources = new Collection([
            [
                'name' => 'Google Fact Check Explorer',
                'status' => 'ready-for-integration',
                'purpose' => 'Cross-check whether the same claim already appears in public fact-check archives.',
                'url' => $factCheckUrl,
            ],
        ]);

        if ($platform) {
            $sources->push([
                'name' => Str::headline($platform).' source review',
                'status' => 'ready-for-integration',
                'purpose' => 'Preserve source-post metadata, timestamps, and account-level context for the originating platform.',
                'url' => $sourceUrl ?? 'https://www.example.com',
            ]);
        }

        if (Str::contains($context, ['weather', 'typhoon', 'storm', 'flood', 'rainfall'])) {
            $sources->push(
                [
                    'name' => 'PAGASA',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Validate weather alerts, storm paths, and rainfall-related claims against official bulletins.',
                    'url' => 'https://www.pagasa.dost.gov.ph',
                ],
                [
                    'name' => 'OpenWeatherMap',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Compare claimed weather conditions with time-based observation data during system verification.',
                    'url' => 'https://openweathermap.org/api',
                ],
            );
        }

        if (Str::contains($context, ['earthquake', 'eruption', 'volcano', 'ashfall'])) {
            $sources->push(
                [
                    'name' => 'PHIVOLCS',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Verify seismic and volcanic activity claims with official hazard bulletins.',
                    'url' => 'https://www.phivolcs.dost.gov.ph',
                ],
                [
                    'name' => 'NDRRMC',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Check disaster advisories and incident response updates tied to the reported event.',
                    'url' => 'https://ndrrmc.gov.ph',
                ],
            );
        }

        if (Str::contains($context, ['election', 'vote', 'ballot', 'president', 'mayor', 'senator'])) {
            $sources->push([
                'name' => 'COMELEC',
                'status' => 'ready-for-integration',
                'purpose' => 'Validate official election announcements, schedules, and related public advisories.',
                'url' => 'https://comelec.gov.ph',
            ]);
        }

        if (Str::contains($context, ['vaccine', 'health', 'virus', 'outbreak', 'hospital'])) {
            $sources->push(
                [
                    'name' => 'Department of Health',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Cross-check public health guidance and advisories against official notices.',
                    'url' => 'https://doh.gov.ph',
                ],
                [
                    'name' => 'World Health Organization',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Compare broader health and outbreak claims with trusted international guidance.',
                    'url' => 'https://www.who.int',
                ],
            );
        }

        if ($sources->count() === 1) {
            $sources->push([
                'name' => 'Trusted newsroom review',
                'status' => 'ready-for-integration',
                'purpose' => 'Compare the claim with reporting from reputable news organizations before sharing.',
                'url' => 'https://www.reuters.com/fact-check/',
            ]);
        }

        return $sources
            ->unique('name')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildSocialContextSources(
        ?string $sourceUrl,
        ?string $captionText,
        ?string $notes,
        ?string $platform,
    ): array {
        if (! (bool) config('playwright.social_context_enabled', true)) {
            return [];
        }

        $query = $this->buildSocialContextQuery($sourceUrl, $captionText, $notes);

        if ($query === null) {
            return [];
        }

        $originPlatform = Str::lower(trim((string) $platform));
        $maxSources = max(1, (int) config('playwright.social_context_max_sources', 4));

        return collect((array) config('playwright.social_context_sources', []))
            ->filter(fn ($source): bool => is_array($source) && filled($source['url_template'] ?? null))
            ->reject(function (array $source) use ($originPlatform): bool {
                $candidatePlatform = Str::lower(trim((string) ($source['platform'] ?? '')));

                return $originPlatform !== ''
                    && $originPlatform !== 'web'
                    && $candidatePlatform !== ''
                    && $candidatePlatform === $originPlatform;
            })
            ->map(function (array $source) use ($query): ?array {
                $name = trim((string) ($source['name'] ?? 'Social media'));
                $template = trim((string) ($source['url_template'] ?? ''));

                if ($name === '' || $template === '') {
                    return null;
                }

                return [
                    'name' => "{$name} social context",
                    'status' => 'ready-for-social-context',
                    'purpose' => "Search public {$name} posts, videos, reposts, comments, and corrections for this claim before relying on the verdict.",
                    'url' => str_replace('{query}', rawurlencode($query), $template),
                    'summary' => "Use this {$name} search to see whether the same image, video, text, or link appears elsewhere with earlier context, corrections, or conflicting captions.",
                    'source_type' => 'social_context',
                    'label' => 'Social',
                ];
            })
            ->filter()
            ->take($maxSources)
            ->values()
            ->all();
    }

    private function buildSocialContextQuery(?string $sourceUrl, ?string $captionText, ?string $notes): ?string
    {
        $sourceTerms = '';

        if ($sourceUrl && Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            $parts = parse_url($sourceUrl);
            $sourceTerms = is_array($parts)
                ? collect([
                    $parts['host'] ?? null,
                    isset($parts['path']) ? urldecode((string) $parts['path']) : null,
                    isset($parts['query']) ? urldecode((string) $parts['query']) : null,
                ])->filter()->implode(' ')
                : '';
        }

        $text = trim(collect([$captionText, $notes, $sourceTerms])->filter()->implode(' '));

        if ($text === '') {
            return null;
        }

        $stopWords = [
            'about', 'after', 'again', 'against', 'already', 'also', 'always', 'before', 'being', 'claim',
            'claims', 'compare', 'could', 'facebook', 'from', 'have', 'image', 'instagram', 'link', 'looks',
            'news', 'post', 'posts', 'share', 'source', 'that', 'their', 'there', 'these', 'they', 'this',
            'those', 'tiktok', 'twitter', 'upload', 'uploaded', 'video', 'viral', 'what', 'when', 'where',
            'which', 'with', 'would', 'youtube',
        ];
        preg_match_all('/"([^"]{3,80})"/u', $text, $matches);
        $quotedPhrases = collect($matches[1] ?? [])
            ->map(fn ($phrase) => trim((string) $phrase))
            ->filter()
            ->take(2)
            ->values();
        $normalized = preg_replace('/[^a-z0-9\s-]+/i', ' ', Str::lower($text)) ?? Str::lower($text);
        $terms = collect(explode(' ', $normalized))
            ->map(fn (string $word) => trim($word))
            ->filter(function (string $word) use ($stopWords): bool {
                if ($word === '' || in_array($word, $stopWords, true)) {
                    return false;
                }

                if (ctype_digit($word)) {
                    return strlen($word) === 4;
                }

                return strlen($word) >= 4;
            })
            ->unique()
            ->take(8)
            ->values();
        $minimumTerms = max(1, (int) config('playwright.social_context_min_terms', 2));

        if ($quotedPhrases->isEmpty() && $terms->count() < $minimumTerms) {
            return null;
        }

        $queryTerms = $quotedPhrases
            ->merge($terms)
            ->unique()
            ->take(8)
            ->values();
        $query = $queryTerms->isNotEmpty()
            ? $queryTerms->implode(' ')
            : $text;
        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);

        return $query !== ''
            ? Str::limit($query, max(40, (int) config('playwright.social_context_query_length', 120)), '')
            : null;
    }

    private function buildFactCheckExplorerUrl(?string $captionText, ?string $notes, ?string $sourceUrl): string
    {
        $query = trim(collect([$captionText, $notes, $sourceUrl])->filter()->implode(' '));
        $query = preg_replace('/\s+/', ' ', $query) ?? $query;

        if ($query === '') {
            return 'https://toolbox.google.com/factcheck/explorer';
        }

        $query = Str::limit($query, 180, '');

        return 'https://toolbox.google.com/factcheck/explorer/search/'
            .rawurlencode($query)
            .';hl=en';
    }

    private function buildPreprocessingSummary(
        ?UploadedFile $uploadedFile,
        ?string $sourceUrl,
        string $mediaType,
        ?string $platform,
        ?string $captionText,
    ): string {
        $parts = [];

        $parts[] = match ($mediaType) {
            'image' => 'Image input was normalized for metadata-aware scoring.',
            'video' => 'Video input was registered for frame-sensitive scoring.',
            'document' => 'Document input was registered for text-and-layout review.',
            default => 'Input was registered for generic multimedia scoring.',
        };

        if ($uploadedFile) {
            $sizeInMb = number_format($uploadedFile->getSize() / 1024 / 1024, 1);
            $parts[] = "Uploaded file metadata was captured ({$sizeInMb} MB).";
        }

        if ($sourceUrl) {
            $host = parse_url($sourceUrl, PHP_URL_HOST) ?: 'an external source';
            $parts[] = 'Source metadata was preserved from '.$host.'.';
        }

        if ($platform) {
            $parts[] = Str::headline($platform).' was tagged as the origin context for this case.';
        }

        if ($captionText) {
            $parts[] = 'Caption text was included so the system could evaluate accompanying claim language.';
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, array<int, array<string, int|string>>>  $signals
     */
    private function buildAnalysisSummary(array $signals, string $mediaType): string
    {
        $topSignals = collect($signals)
            ->flatten(1)
            ->sortByDesc(fn (array $signal) => (int) ($signal['weight'] ?? 0))
            ->take(3)
            ->pluck('label')
            ->all();

        if ($topSignals === []) {
            return 'TruthGuard did not find strong risk indicators in the submitted media or claim text, so this result depends more on manual source confirmation than on obvious warning signs.';
        }

        $prefix = match ($mediaType) {
            'video' => "TruthGuard reviewed the video's available metadata, caption language, and source context; full frame and audio verification requires extracted frames and manual review of the complete clip.",
            'image' => 'TruthGuard reviewed the image, its surrounding text, and available source context.',
            'document' => 'TruthGuard reviewed the document content, surrounding text, and available source context.',
            default => 'TruthGuard reviewed the submitted content, its text cues, and the available source context.',
        };

        return $prefix.' The strongest indicators were '.implode('; ', $topSignals).', and these had the biggest effect on the risk score.';
    }

    /**
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildVerificationSummary(array $verificationSources): string
    {
        $sourceNames = collect($verificationSources)
            ->pluck('name')
            ->take(3)
            ->implode(', ');

        $count = count($verificationSources);

        return "TruthGuard prepared {$count} verification route".($count === 1 ? '' : 's')." for this case, starting with {$sourceNames}. Use these links to compare the claim against public reporting, official references, or source-trace evidence.";
    }

    /**
     * @param  array<string, array<int, array<string, int|string>>>  $signals
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildManualCheckFocus(string $mediaType): string
    {
        return match ($mediaType) {
            'image' => 'image details, embedded text, posting date, and where the image first appeared',
            'video' => 'clip date, location, full sequence, audio cues, and whether older footage is being reused',
            'document' => 'document title, issuing office, date, quoted lines, and whether they appear in the original file',
            default => 'dates, locations, named people, quoted details, and whether the same claim appears in reliable coverage',
        };
    }

    private function explainSignalForUsers(string $signalLabel): string
    {
        $normalized = strtolower(trim($signalLabel));

        return match (true) {
            str_contains($normalized, 'video cnn') => 'the trained video model sampled frames, focused on face regions, and found visual patterns associated with manipulated or synthetic video content',
            str_contains($normalized, 'image cnn') => 'the trained image model found visual patterns associated with manipulated or synthetic face images',
            str_contains($normalized, 'filipino article classifier') => str_contains($normalized, 'likely fake')
                    ? 'the local Filipino article model found wording patterns closer to fake-source examples in its training data'
                    : 'the local Filipino article model found wording patterns closer to mainstream-source examples in its training data',
            str_contains($normalized, 'limited corroborating context'),
            str_contains($normalized, 'manual corroboration') => 'the post does not yet have enough independent reporting, official confirmation, or source context to fully support the claim',
            str_contains($normalized, 'ai-generation terms') => 'the filename, metadata, or surrounding source wording contains terms commonly associated with synthetic or AI-generated content',
            str_contains($normalized, 'editing-oriented terms') => 'the available source details hint that the content may have been edited, rendered, or altered before posting',
            str_contains($normalized, 'frame-level verification') => 'videos can mislead through cropped sequences, reused clips, or missing context between frames',
            str_contains($normalized, 'shortened source link') => 'short links can hide the original publisher and make provenance harder to confirm',
            str_contains($normalized, 'stable protocol') => 'a malformed or incomplete link weakens source traceability and makes verification less reliable',
            str_contains($normalized, 'high-urgency language') => 'urgent language can pressure people to reshare before checking whether the claim is complete and accurate',
            str_contains($normalized, 'conspiracy-style framing') => 'claims framed around hidden truths or cover-ups need stronger outside evidence before they should be trusted',
            str_contains($normalized, 'weak sourcing') => 'the text itself admits uncertainty or relies on secondhand sourcing instead of a verifiable original record',
            str_contains($normalized, 'official incident cross-checking'),
            str_contains($normalized, 'source validation') => 'event-driven claims are safer to judge when they can be compared with timestamped official bulletins and reporting',
            str_contains($normalized, 'all-caps emphasis') => 'heavy emphasis can amplify emotional pressure without adding verifiable evidence',
            default => 'it affects how easily the claim can be traced, checked, and matched against outside evidence',
        };
    }

    /**
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function joinVerificationTargets(array $verificationSources): string
    {
        $targets = collect($verificationSources)
            ->pluck('name')
            ->filter()
            ->take(2)
            ->values();

        if ($targets->isEmpty()) {
            return 'the linked sources';
        }

        return $targets->implode(' and ');
    }

    /**
     * @param  array<string, array<int, array<string, int|string>>>  $signals
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildExplanationSummary(string $verdict, int $fakeScore, array $signals, array $verificationSources, string $mediaType): string
    {
        $topSignal = collect($signals)
            ->flatten(1)
            ->sortByDesc(fn (array $signal) => (int) ($signal['weight'] ?? 0))
            ->first();

        $topSignalLabel = $topSignal['label'] ?? 'limited corroborating context';
        $leadingSource = $verificationSources[0]['name'] ?? 'the linked verification sources';
        $signalExplanation = $this->explainSignalForUsers($topSignalLabel);
        $manualCheckFocus = $this->buildManualCheckFocus($mediaType);
        $verdictIntro = match ($verdict) {
            'fake' => "TruthGuard rated this case as Likely Fake with a {$fakeScore}% misinformation risk score, which means several signals point to a high chance of manipulation, missing context, or unreliable sourcing.",
            'review' => "TruthGuard rated this case as Needs Review with a {$fakeScore}% misinformation risk score, which means the available evidence is mixed, incomplete, or still needs stronger confirmation.",
            default => "TruthGuard rated this case as Likely Real with a {$fakeScore}% misinformation risk score, which means the current evidence leans authentic but the result is still a risk estimate, not absolute proof.",
        };

        return "{$verdictIntro} The strongest signal was \"{$topSignalLabel}\". In practice, that suggests {$signalExplanation}. Start with {$leadingSource} and compare the post's {$manualCheckFocus} so you can judge whether the claim matches public evidence and original context.";
    }

    /**
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildRecommendation(string $verdict, array $verificationSources, string $mediaType): string
    {
        $verificationTargets = $this->joinVerificationTargets($verificationSources);
        $manualCheckFocus = $this->buildManualCheckFocus($mediaType);

        return match ($verdict) {
            'fake' => "Treat this submission as potentially misleading for now. Open {$verificationTargets} and compare the post's {$manualCheckFocus} before sharing, citing, or reposting it.",
            'review' => "Pause before making a final call. Use {$verificationTargets} to compare the post's {$manualCheckFocus}, then decide whether the claim is supported, incomplete, or presented out of context.",
            default => "This result is lower risk, not a guarantee that every detail is true. Do a quick confirmation in {$verificationTargets} and verify the post's {$manualCheckFocus} before public reuse.",
        };
    }
}
