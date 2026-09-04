<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use App\Services\Automation\RelatedPostScraper;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\DetectionRetentionService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\TrustedFactCheckVerdictResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DetectionController extends Controller
{
    public function create(Request $request, DetectionRetentionService $retentionService): View|RedirectResponse
    {
        if (! $request->user()->isAdmin()) {
            $retentionService->archiveExpired($request->user()->id);
        }

        $selectedDetectionId = $request->integer('detection');

        if ($selectedDetectionId > 0) {
            $selectedDetectionQuery = Detection::query()->whereKey($selectedDetectionId);

            if (! $request->user()->isAdmin()) {
                $selectedDetectionQuery->where('user_id', $request->user()->id);
                $retentionService->applyRetentionWindow($selectedDetectionQuery);
            }

            $selectedDetection = $selectedDetectionQuery->first();

            if ($selectedDetection) {
                return redirect()->route('detections.result', $selectedDetection);
            }
        }

        return view('detections.create', [
            'selectedDetection' => null,
        ]);
    }

    public function result(
        Request $request,
        Detection $detection,
        DetectionRetentionService $retentionService,
        LiveVerificationEvidenceService $liveVerificationEvidenceService,
        OpenAiDetectionReportService $openAiDetectionReportService,
        GoogleFactCheckFeedService $factCheckFeedService,
    ): View {
        if (! $request->user()->isAdmin()) {
            $retentionService->archiveExpired($request->user()->id);
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

        $weatherContext = $request->session()->pull('weather_context', []);

        if (
            $weatherContext !== []
            || (bool) config('services.live_verification.refresh_on_view', false)
            || $this->needsSourceReconciliationRefresh($selectedDetection)
        ) {
            $this->refreshDetectionEvidence($selectedDetection, $liveVerificationEvidenceService, $openAiDetectionReportService, $weatherContext);
        }

        return view('detections.result', [
            'selectedDetection' => $selectedDetection,
            'latestFactChecks' => $factCheckFeedService->latest(6),
        ]);
    }

    public function store(
        Request $request,
        DetectionRetentionService $retentionService,
        DetectionPipeline $pipeline,
        RelatedPostScraper $relatedPostScraper,
    ): RedirectResponse {
        if (! $request->user()->isAdmin()) {
            $retentionService->archiveExpired($request->user()->id);
        }

        $request->merge($this->normalizeDetectionInputs($request));
        $mediaMaxMb = (int) config('truthguard.uploads.media_max_mb', 20);
        $mediaMaxKb = (int) config('truthguard.uploads.media_max_kb', $mediaMaxMb * 1024);
        $claimMaxCharacters = (int) config('truthguard.claims.max_characters', 4000);

        $validated = $request->validate([
            'source_url' => ['nullable', 'url', 'max:2048'],
            'media_file' => ['nullable', 'file', "max:{$mediaMaxKb}", 'mimes:jpg,jpeg,png,webp,mp4,mov,webm,m4v,pdf'],
            'source_platform' => ['nullable', 'string', 'in:auto,facebook,instagram,tiktok,x,youtube,web,other'],
            'caption_text' => ['nullable', 'string', "max:{$claimMaxCharacters}"],
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

        if (! $uploadedFile && blank($validated['source_url'] ?? null) && blank($validated['caption_text'] ?? null)) {
            return back()
                ->withErrors([
                    'media_file' => 'Add a file, link, or context before analyzing.',
                ])
                ->withInput();
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

        $status = "Detection complete: {$detection->verdict} ({$detection->fake_score}% risk score).";

        return redirect()
            ->route('detections.result', $detection)
            ->with('status', $status);
    }

    /**
     * @return array<string, string|null>
     */
    private function normalizeDetectionInputs(Request $request): array
    {
        $sourceUrl = trim((string) $request->input('source_url', ''));
        $captionText = trim((string) $request->input('caption_text', ''));

        if ($sourceUrl === '' && $captionText !== '') {
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
        $uploadedImage = $this->uploadedImageForRefresh($detection);
        $openAiExtractedClaim = $this->extractClaimTextForRefresh($detection, $openAiDetectionReportService, $uploadedImage);
        $analysis = [
            'platform' => $detection->platform,
            'media_type' => $detection->media_type,
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

        $refreshed = $liveVerificationEvidenceService->enrich($analysis, $context);
        $refreshed = $openAiDetectionReportService->enhance($refreshed, $context, $uploadedImage);

        $updates = [
            'fake_score' => (int) ($refreshed['fake_score'] ?? $detection->fake_score),
            'verdict' => (string) ($refreshed['verdict'] ?? $detection->verdict),
            'analysis_summary' => (string) ($refreshed['analysis_summary'] ?? $detection->analysis_summary),
            'verification_summary' => (string) ($refreshed['verification_summary'] ?? $detection->verification_summary),
            'explanation_summary' => (string) ($refreshed['explanation_summary'] ?? $detection->explanation_summary),
            'recommendation' => (string) ($refreshed['recommendation'] ?? $detection->recommendation),
            'signals' => $refreshed['signals'] ?? ($detection->signals ?? []),
            'verification_sources' => $refreshed['verification_sources'] ?? ($detection->verification_sources ?? []),
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
        if ($detection->media_type !== 'image' || blank($detection->media_path)) {
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
