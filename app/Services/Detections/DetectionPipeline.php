<?php

namespace App\Services\Detections;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DetectionPipeline
{
    private const VERIFICATION_POLICY_VERSION = TrustedFactCheckVerdictResolver::POLICY_VERSION;

    public function __construct(
        private readonly LiveVerificationEvidenceService $liveVerificationEvidenceService,
        private readonly DetectionRetentionService $retentionService,
        private readonly OpenAiDetectionReportService $openAiDetectionReportService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function run(User $user, array $validated, ?UploadedFile $uploadedFile): Detection
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
        $platform = $selectedPlatform ?? $this->detectPlatform($sourceUrl, $captionText, $notes);
        $mediaChecksum = $this->buildMediaChecksum($uploadedFile);
        $requestFingerprint = $this->buildRequestFingerprint(
            $sourceUrl,
            $captionText,
            $notes,
            $mediaType,
            $platform,
            $mediaChecksum,
        );
        $mediaPath = $this->persistUploadedFile($uploadedFile);
        $reusableDetection = $this->findReusableDetection($requestFingerprint);

        if ($reusableDetection) {
            return $this->createReusedDetection(
                $user,
                $reusableDetection,
                $sourceUrl,
                $captionText,
                $notes,
                $mediaPath,
                $mediaChecksum,
                $requestFingerprint,
            );
        }

        $analysis = $this->buildLocalAnalysis($uploadedFile, $sourceUrl, $captionText, $notes, $mediaType, $platform);
        $platform = $this->nullableString($analysis['platform'] ?? null) ?? $platform;
        $mediaType = $this->nullableString($analysis['media_type'] ?? null) ?? $mediaType;

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
        $analysis = $this->openAiDetectionReportService->enhance($analysis, [
            'source_url' => $sourceUrl,
            'caption_text' => $captionText,
            'notes' => $notes,
            'media_type' => $mediaType,
            'platform' => $platform,
            'openai_extracted_claim' => $openAiExtractedClaim,
        ], $uploadedFile);
        $analysis = $this->markVerificationPolicyVersion($analysis);

        return Detection::create([
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
            'verdict' => (string) $analysis['verdict'],
            'notes' => $notes,
            'analyzed_at' => now(),
        ]);
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
    ): array {
        $signals = $this->buildSignals($uploadedFile, $sourceUrl, $captionText, $notes, $mediaType);
        $fakeScore = $this->calculateFakeScore($signals);
        $verdict = $this->determineVerdict($fakeScore);
        $verificationSources = $this->buildVerificationSources($sourceUrl, $captionText, $notes, $platform);

        return [
            'platform' => $platform,
            'media_type' => $mediaType,
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
    ): ?string {
        $payload = [
            'source_url' => $this->normalizeFingerprintString($sourceUrl),
            'caption_text' => $this->normalizeFingerprintString($captionText),
            'notes' => $this->normalizeFingerprintString($notes),
            'media_type' => $this->normalizeFingerprintString($mediaType),
            'platform' => $this->normalizeFingerprintString($platform),
            'media_checksum' => $this->normalizeFingerprintString($mediaChecksum),
        ];

        $hasMeaningfulInput = collect($payload)
            ->reject(fn (?string $value, string $key) => in_array($key, ['media_type', 'platform'], true))
            ->contains(fn (?string $value) => $value !== null);

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

        $normalized = Str::lower(trim($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

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
            ->whereNotNull('analyzed_at');

        $candidate = $this->retentionService
            ->applyRetentionWindow($query)
            ->latest('analyzed_at')
            ->first();

        return $candidate && $this->hasCurrentVerificationPolicyVersion($candidate) ? $candidate : null;
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
            'verdict' => $reusableDetection->verdict,
        ];

        return Detection::create([
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
            'verdict' => (string) $analysis['verdict'],
            'notes' => $notes,
            'analyzed_at' => now(),
        ]);
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
            str_contains($normalized, 'limited corroborating context'),
            str_contains($normalized, 'manual corroboration')
                => 'the post does not yet have enough independent reporting, official confirmation, or source context to fully support the claim',
            str_contains($normalized, 'ai-generation terms')
                => 'the filename, metadata, or surrounding source wording contains terms commonly associated with synthetic or AI-generated content',
            str_contains($normalized, 'editing-oriented terms')
                => 'the available source details hint that the content may have been edited, rendered, or altered before posting',
            str_contains($normalized, 'frame-level verification')
                => 'videos can mislead through cropped sequences, reused clips, or missing context between frames',
            str_contains($normalized, 'shortened source link')
                => 'short links can hide the original publisher and make provenance harder to confirm',
            str_contains($normalized, 'stable protocol')
                => 'a malformed or incomplete link weakens source traceability and makes verification less reliable',
            str_contains($normalized, 'high-urgency language')
                => 'urgent language can pressure people to reshare before checking whether the claim is complete and accurate',
            str_contains($normalized, 'conspiracy-style framing')
                => 'claims framed around hidden truths or cover-ups need stronger outside evidence before they should be trusted',
            str_contains($normalized, 'weak sourcing')
                => 'the text itself admits uncertainty or relies on secondhand sourcing instead of a verifiable original record',
            str_contains($normalized, 'official incident cross-checking'),
            str_contains($normalized, 'source validation')
                => 'event-driven claims are safer to judge when they can be compared with timestamped official bulletins and reporting',
            str_contains($normalized, 'all-caps emphasis')
                => 'heavy emphasis can amplify emotional pressure without adding verifiable evidence',
            default
                => 'it affects how easily the claim can be traced, checked, and matched against outside evidence',
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
