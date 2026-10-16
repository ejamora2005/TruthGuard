<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\MediaEvidencePreparer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class OpenAiDetectionReportService
{
    private array $extractionRecord = [];

    private array $mediaPreparation = [];

    public function extractionDetails(): array
    {
        return $this->extractionRecord;
    }

    private const SYNTHETIC_MEDIA_RISK_SCORE = 94;

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function enhance(array $analysis, array $context, ?UploadedFile $uploadedFile = null): array
    {
        if (! $this->isEnabled()) {
            return $analysis;
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.timeout', 40))
                ->post('https://api.openai.com/v1/responses', $this->payload($analysis, $context, $uploadedFile));

            if (! $response->successful()) {
                Log::warning('OpenAI detection report request failed; using existing TruthGuard report.', [
                    'status' => $response->status(),
                    'stage' => 'report',
                ]);

                return $analysis;
            }

            $data = $response->json();
            $report = $this->extractStructuredReport(is_array($data) ? $data : []);

            if ($report === null) {
                Log::warning('OpenAI detection report response was not valid JSON; using existing TruthGuard report.');

                return $analysis;
            }

            return $this->mergeReport($analysis, $report, is_array($data) ? $data : []);
        } catch (\Throwable $exception) {
            Log::warning('OpenAI detection report request failed; using existing TruthGuard report.', [
                'error_type' => $exception::class,
            ]);

            return $analysis;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function extractClaimText(array $context, ?UploadedFile $uploadedFile = null): ?string
    {
        $this->extractionRecord = ['status' => 'unavailable', 'failure_reason' => null, 'http_status' => null,
            'configured_model' => (string) config('services.openai.extraction_model', config('services.openai.model', 'gpt-5.4')),
            'visible_text' => '', 'inferred_claim' => '', 'media_note' => ''];
        $this->mediaPreparation = [];
        if (! $this->isEnabled() || ! (bool) config('services.openai.claim_extraction_enabled', true)) {
            $this->extractionRecord['failure_reason'] = 'not_configured';

            return null;
        }

        try {
            $mediaInputs = $this->buildMediaInputs($uploadedFile);
        } catch (\Throwable) {
            $this->extractionRecord['status'] = 'media_extraction_failed';
            $this->extractionRecord['failure_reason'] = 'media_preparation_failed';

            return null;
        }

        $this->extractionRecord['media_preparation'] = $this->mediaPreparation;
        if ($mediaInputs === []) {
            $this->extractionRecord['status'] = $this->mediaPreparation['status'] ?? 'unsupported_or_missing_media';
            $this->extractionRecord['failure_reason'] = 'unsupported_or_missing_media';

            return null;
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.timeout', 40))
                ->post('https://api.openai.com/v1/responses', $this->claimExtractionPayload($context, $mediaInputs));

            // Even an incomplete or unusable response can consume tokens.
            $this->extractionRecord['usage'] = (array) $response->json('usage', []);
            $this->extractionRecord['response_model'] = $response->json('model');

            if (! $response->successful()) {
                $reason = OpenAiExtractionFailure::reason($response);
                $this->extractionRecord['failure_reason'] = $reason;
                $this->extractionRecord['http_status'] = $response->status();
                Log::warning('OpenAI claim extraction request failed; continuing without image text.', [
                    'analysis_id' => $context['analysis_id'] ?? null,
                    'status' => $response->status(), 'reason' => $reason, 'stage' => 'extraction',
                    'configured_model' => $this->extractionRecord['configured_model'],
                ]);

                return null;
            }

            $data = $response->json();
            $report = $this->extractStructuredReport(is_array($data) ? $data : []);

            if ($report === null || ! is_string($report['visible_text'] ?? null)
                || ! is_string($report['claim_text'] ?? null)) {
                $this->extractionRecord['failure_reason'] = 'unexpected_response_format';
                $this->extractionRecord['http_status'] = $response->status();
                Log::warning('OpenAI claim extraction returned an unusable response.', [
                    'analysis_id' => $context['analysis_id'] ?? null,
                    'status' => $response->status(), 'stage' => 'extraction',
                ]);

                return null;
            }

            $claimText = trim((string) ($report['claim_text'] ?? ''));
            $visibleText = trim((string) ($report['visible_text'] ?? ''));
            $this->extractionRecord = [
                'status' => $visibleText === '' ? 'missing_text'
                    : ((($data['status'] ?? 'completed') === 'completed' && ($this->mediaPreparation['status'] ?? '') !== 'partial') ? 'completed' : 'incomplete'),
                'failure_reason' => null, 'http_status' => $response->status(),
                'configured_model' => (string) config('services.openai.extraction_model', config('services.openai.model', 'gpt-5.4')),
                'response_model' => is_string($data['model'] ?? null) ? $data['model'] : null,
                'visible_text' => $visibleText, 'inferred_claim' => $claimText,
                'media_note' => (string) ($report['synthetic_media_note'] ?? ''),
                'ocr_confidence_raw' => $report['confidence'] ?? null,
                'sampled_frames' => count($mediaInputs),
                'media_preparation' => $this->mediaPreparation,
                'usage' => $data['usage'] ?? [],
            ];
            Log::debug('OpenAI claim extraction finished.', [
                'analysis_id' => $context['analysis_id'] ?? null, 'status' => $this->extractionRecord['status'],
                'http_status' => $response->status(), 'visible_characters' => mb_strlen($visibleText),
            ]);

            return $this->limitText($claimText !== '' ? $claimText : $visibleText, 500) ?: null;
        } catch (ConnectionException $exception) {
            $reason = str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'network_failure';
            $this->extractionRecord['failure_reason'] = $reason;
            Log::warning('OpenAI claim extraction request failed; continuing without image text.', [
                'analysis_id' => $context['analysis_id'] ?? null, 'reason' => $reason,
                'error_type' => $exception::class,
            ]);

            return null;
        } catch (\Throwable $exception) {
            $this->extractionRecord['failure_reason'] = 'request_exception';
            Log::warning('OpenAI claim extraction request failed; continuing without image text.', [
                'analysis_id' => $context['analysis_id'] ?? null, 'reason' => 'request_exception',
                'error_type' => $exception::class,
            ]);

            return null;
        }
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.openai.analysis_enabled', true)
            && filled(config('services.openai.key'));
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function payload(array $analysis, array $context, ?UploadedFile $uploadedFile): array
    {
        $content = [
            [
                'type' => 'input_text',
                'text' => $this->buildUserPrompt($analysis, $context),
            ],
        ];

        foreach ($this->buildMediaInputs($uploadedFile) as $mediaInput) {
            $content[] = $mediaInput;
        }

        $payload = [
            'model' => (string) config('services.openai.model', 'gpt-5.4'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt(),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => $content,
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'truthguard_detection_report',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
            'max_output_tokens' => max(400, (int) config('services.openai.max_completion_tokens', 1400)),
        ];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, string>>  $mediaInputs
     * @return array<string, mixed>
     */
    private function claimExtractionPayload(array $context, array $mediaInputs): array
    {
        $model = (string) config('services.openai.extraction_model', config('services.openai.model', 'gpt-5.4'));

        return [
            'model' => $model,
            ...(preg_match('/^gpt-5\.[24](?:-|$)/', $model) ? ['reasoning' => ['effort' => 'none']] : []),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => implode("\n", [
                                'You extract claim text from uploaded evidence for a fact-checking workflow.',
                                'Transcribe all readable visible text verbatim in its original language into visible_text. Keep uncertain characters as [unclear]. Never silently correct dates, quantities or names.',
                                'Preserve visual text regions with blank lines. Keep isolated logos, publisher marks and watermarks separate from headline or body text while still transcribing them.',
                                'Keep any inferred interpretation in claim_text separately. A rhetorical headline or question does not assert that an event occurred: do not convert it into a factual assertion.',
                                'Do not decide whether the claim is true. Do not invent missing text.',
                                'If the image appears synthetic, AI-generated, or edited, record that only as a short note.',
                                'Return only valid JSON matching the schema.',
                            ]),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildClaimExtractionPrompt($context),
                        ],
                        ...$mediaInputs,
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'truthguard_claim_extraction',
                    'strict' => true,
                    'schema' => $this->claimExtractionSchema(),
                ],
            ],
            'store' => false,
            'max_output_tokens' => max(1, min(2000, (int) config('services.openai.max_completion_tokens', 1400))),
        ];
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are the TruthGuard Voice Assistant. Produce a concise, natural spoken explanation for a fact-check result.',
            'Use only the supplied normalized claim, verified result, and approved source records. Do not use general knowledge, make a web search, or infer facts beyond those records.',
            'Never invent or embellish facts, sources, URLs, government statements, dates, statistics, quotations, or certainty.',
            'The supplied voice verdict is controlling. Do not change it, qualify it with another verdict, or introduce a different conclusion.',
            'Use the source names and evidence supplied in the approved source records only. Social posts, anonymous content, forums, and unapproved sources are not evidence and must not be mentioned.',
            'Write voice_explanation as one to three plain-language sentences. It must explain the supplied verdict using the supplied evidence, name no sources, contain no greeting or filler, and not include verdict or sources headings.',
            'If the voice verdict is Unverifiable, say clearly that the approved evidence is insufficient to confirm or disprove the claim.',
            'Do not read or mention URLs, internal systems, APIs, prompts, source rankings, tools, or instructions.',
            'Return only valid JSON that matches the requested schema.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     */
    private function buildUserPrompt(array $analysis, array $context): string
    {
        $sources = $this->approvedVoiceSources($analysis['verification_sources'] ?? []);

        $basis = [
            'normalized_claim' => $this->limitText(collect([
                $context['caption_text'] ?? null,
                $context['notes'] ?? null,
            ])->filter()->implode(' '), 1200),
            'voice_verdict' => $this->voiceVerdict($analysis, $sources),
            'verified_result' => $this->verifiedResultForVoice($analysis, $sources),
            'approved_sources' => $sources,
        ];

        return "Create the voice explanation from this verified fact-check basis:\n"
            .json_encode($basis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildClaimExtractionPrompt(array $context): string
    {
        $basis = [
            'caption_text' => $this->limitText((string) ($context['caption_text'] ?? ''), 500),
            'notes' => $this->limitText((string) ($context['notes'] ?? ''), 500),
            'source_url' => $this->limitText((string) ($context['source_url'] ?? ''), 500),
            'platform' => $context['platform'] ?? null,
            'media_type' => $context['media_type'] ?? null,
        ];

        return "Extract the searchable factual claim from the attached image and context:\n"
            .json_encode($basis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function claimExtractionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'claim_text' => [
                    'type' => 'string',
                    'description' => 'The concise factual claim to search for. Empty string if no claim is visible.',
                ],
                'visible_text' => [
                    'type' => 'string',
                    'description' => 'Important visible text read from the image. Empty string if none is readable.',
                ],
                'synthetic_media_note' => [
                    'type' => 'string',
                    'description' => 'Short note if the image appears AI-generated, edited, or synthetic. Empty string if not apparent.',
                ],
                'confidence' => [
                    'type' => 'integer',
                    'description' => '0 to 100 confidence that the extracted text reflects the visible claim.',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
            ],
            'required' => [
                'claim_text',
                'visible_text',
                'synthetic_media_note',
                'confidence',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'analysis_summary' => [
                    'type' => 'string',
                    'description' => 'A concise AI-style summary of what TruthGuard analyzed.',
                ],
                'verification_summary' => [
                    'type' => 'string',
                    'description' => 'A concise summary of what sources or checks support the report.',
                ],
                'explanation_summary' => [
                    'type' => 'string',
                    'description' => 'The main user-facing AI answer explaining the verdict and risk score.',
                ],
                'recommendation' => [
                    'type' => 'string',
                    'description' => 'Actionable guidance for the user before sharing or trusting the claim.',
                ],
                'voice_explanation' => [
                    'type' => 'string',
                    'description' => 'One to three spoken sentences grounded only in the verified result and approved source records. Do not include source names, a verdict, headings, or URLs.',
                ],
                'basis' => [
                    'type' => 'array',
                    'description' => 'Short bullet points grounded only in supplied signals or sources.',
                    'items' => ['type' => 'string'],
                ],
                'limitations' => [
                    'type' => 'array',
                    'description' => 'Short bullet points for missing context or uncertainty.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'analysis_summary',
                'verification_summary',
                'explanation_summary',
                'recommendation',
                'voice_explanation',
                'basis',
                'limitations',
            ],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function buildMediaInputs(?UploadedFile $uploadedFile): array
    {
        if (! (bool) config('services.openai.image_context_enabled', true) || ! $uploadedFile) {
            return [];
        }

        $mime = (string) $uploadedFile->getMimeType();

        if (Str::startsWith($mime, 'video/')) {
            $prepared = app(MediaEvidencePreparer::class)->prepare($uploadedFile, 'video');
            $this->mediaPreparation = Arr::except($prepared, ['inputs']) + ['backend' => 'opencv'];
            if ($prepared['inputs'] !== []) {
                return $prepared['inputs'];
            }
            // Retain compatibility on hosts that have FFmpeg but not OpenCV.
            try {
                $frames = $this->extractVideoFrameInputs($uploadedFile);
                if ($frames !== []) {
                    $this->mediaPreparation = ['status' => 'completed', 'backend' => 'ffmpeg'];
                }

                return $frames;
            } catch (\Throwable) {
                return [];
            }
        }

        if (! Str::startsWith($mime, 'image/')) {
            return [];
        }

        $maxBytes = max(0, (int) config('services.openai.image_max_bytes', 4194304));

        if ($maxBytes > 0 && $uploadedFile->getSize() > $maxBytes) {
            $prepared = app(MediaEvidencePreparer::class)->prepare($uploadedFile, 'image');
            $this->mediaPreparation = Arr::except($prepared, ['inputs']) + ['backend' => 'pillow'];

            return $prepared['inputs'];
        }

        $path = $uploadedFile->getRealPath() ?: $uploadedFile->getPathname();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return [[
            'type' => 'input_image',
            'image_url' => 'data:'.$mime.';base64,'.base64_encode($contents),
        ]];
    }

    /** @return array<int, array<string, string>> */
    private function extractVideoFrameInputs(UploadedFile $uploadedFile): array
    {
        $path = $uploadedFile->getRealPath() ?: $uploadedFile->getPathname();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return [];
        }

        $durationProbe = new Process([
            'ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        $durationProbe->setTimeout(15);
        $durationProbe->run();
        $duration = $durationProbe->isSuccessful() && is_numeric(trim($durationProbe->getOutput()))
            ? max(0.0, (float) trim($durationProbe->getOutput()))
            : 0.0;
        $seeks = $duration > 2 ? [0.0, round($duration / 2, 2), max(0.0, round($duration - 1, 2))] : [0.0];
        $frames = [];

        foreach (array_values(array_unique($seeks)) as $seek) {
            $output = tempnam(sys_get_temp_dir(), 'truthguard-frame-');

            if ($output === false) {
                continue;
            }

            @unlink($output);
            $output .= '.jpg';

            $process = new Process(['ffmpeg', '-y', '-ss', (string) $seek, '-i', $path, '-frames:v', '1', '-vf', 'scale=1280:-2', $output]);
            $process->setTimeout(30);
            $process->run();
            $contents = $process->isSuccessful() && is_file($output) ? file_get_contents($output) : false;

            if ($contents !== false && $contents !== '') {
                $frames[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:image/jpeg;base64,'.base64_encode($contents),
                ];
            }

            @unlink($output);
        }

        return $frames;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function extractStructuredReport(array $data): ?array
    {
        $text = data_get($data, 'output_text');

        if (! is_string($text) || trim($text) === '') {
            $text = $this->extractOutputText($data);
        }

        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function extractOutputText(array $data): ?string
    {
        foreach ((array) ($data['output'] ?? []) as $outputItem) {
            foreach ((array) data_get($outputItem, 'content', []) as $content) {
                $text = data_get($content, 'text');

                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function mergeReport(array $analysis, array $report, array $responseData): array
    {
        foreach (['analysis_summary', 'verification_summary', 'explanation_summary', 'recommendation'] as $field) {
            $value = trim((string) ($report[$field] ?? ''));

            if ($value !== '') {
                $analysis[$field] = $value;
            }
        }

        $signals = is_array($analysis['signals'] ?? null) ? $analysis['signals'] : [];
        $basis = $this->normalizeBullets($report['basis'] ?? []);
        $limitations = $this->normalizeBullets($report['limitations'] ?? []);

        if ($basis !== []) {
            $signals['ai_basis'] = collect($basis)
                ->map(fn (string $item): array => ['label' => $item, 'weight' => 45])
                ->values()
                ->all();
        }

        if ($limitations !== []) {
            $signals['ai_limitations'] = collect($limitations)
                ->map(fn (string $item): array => ['label' => $item, 'weight' => 35])
                ->values()
                ->all();
        }

        $signals['openai_usage'] = $this->usageTelemetry($responseData);

        if ($this->hasSyntheticMediaFinding($analysis, $report, $basis) && ! $this->hasSourceBackedFactCheckRating($analysis)) {
            $analysis = $this->applySyntheticMediaVerdict($analysis, $signals);
            $signals = $analysis['signals'];
        }

        $analysis['signals'] = $signals;
        $analysis['explanation_summary'] = $this->formatVoiceResponse(
            $analysis,
            (string) ($report['voice_explanation'] ?? $analysis['explanation_summary'] ?? ''),
        );

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function applySyntheticMediaVerdict(array $analysis, array $signals): array
    {
        $riskScore = max(self::SYNTHETIC_MEDIA_RISK_SCORE, (int) ($analysis['fake_score'] ?? 0));

        $signals['ai_media_assessment'] = [[
            'label' => 'OpenAI identified the uploaded evidence as AI-generated or synthetic media.',
            'weight' => 96,
            'rating' => FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION,
        ]];

        $analysis['verdict'] = 'fake';
        $analysis['fake_score'] = $riskScore;
        $analysis['analysis_summary'] = 'TruthGuard identified an AI-generated or synthetic-media finding in the uploaded evidence and treated it as the controlling media-authenticity signal.';
        $analysis['verification_summary'] = 'No stronger trusted-source rating overrode this media-authenticity finding. The uploaded image is treated as AI-generated or synthetic until a reliable original source proves otherwise.';
        $analysis['explanation_summary'] = "Based on the AI media assessment, this uploaded image should not be treated as a real photograph. TruthGuard categorizes it as AI-generated media with a {$riskScore}% misinformation risk score because the evidence indicates synthetic or AI-created content.";
        $analysis['recommendation'] = 'Do not present this image as an authentic photograph. Verify the original source, creator disclosure, and any trusted fact-check match before sharing it as real.';
        $analysis['signals'] = $signals;

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $report
     * @param  list<string>  $basis
     */
    private function hasSyntheticMediaFinding(array $analysis, array $report, array $basis): bool
    {
        $signalLabels = collect($analysis['signals'] ?? [])
            ->filter(fn ($items): bool => is_array($items))
            ->flatten(1)
            ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
            ->pluck('label');

        $text = Str::lower(collect([
            $report['analysis_summary'] ?? null,
            $report['verification_summary'] ?? null,
            $report['explanation_summary'] ?? null,
            $report['recommendation'] ?? null,
            ...$basis,
            ...$signalLabels->all(),
        ])->filter()->implode(' '));

        if ($text === '') {
            return false;
        }

        if (Str::contains($text, [
            'not ai-generated',
            'not ai generated',
            'not synthetic',
            'not a deepfake',
            'no evidence of ai-generated',
            'no evidence of ai generated',
            'no evidence of synthetic',
            'does not appear ai-generated',
            'does not appear to be ai-generated',
            'does not appear ai generated',
            'does not appear to be ai generated',
        ])) {
            return false;
        }

        return Str::contains($text, [
            'ai-generated',
            'ai generated',
            'synthetic image',
            'synthetic media',
            'deepfake',
            'generated by ai',
            'created by ai',
            'not a real photograph',
            'not real photograph',
            'not a real photo',
            'not real photo',
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function hasSourceBackedFactCheckRating(array $analysis): bool
    {
        $hasTrustedSignal = collect(data_get($analysis, 'signals.trusted_fact_check', []))
            ->filter(fn ($signal): bool => is_array($signal))
            ->contains(fn (array $signal): bool => filled($signal['rating'] ?? null));

        if ($hasTrustedSignal) {
            return true;
        }

        return collect($analysis['verification_sources'] ?? [])
            ->filter(fn ($source): bool => is_array($source))
            ->contains(fn (array $source): bool => ($source['source_type'] ?? null) === 'fact_check'
                && filled($source['rating'] ?? null));
    }

    /**
     * @return array<int, array{group: string, label: string, weight: int}>
     */
    private function summarizeSignals(mixed $signals): array
    {
        if (! is_array($signals)) {
            return [];
        }

        $rows = [];

        foreach ($signals as $group => $items) {
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item) || ! isset($item['label'])) {
                    continue;
                }

                $rows[] = [
                    'group' => (string) $group,
                    'label' => $this->limitText((string) $item['label'], 220),
                    'weight' => (int) ($item['weight'] ?? 0),
                ];
            }
        }

        return collect($rows)
            ->sortByDesc('weight')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function summarizeSources(mixed $sources): array
    {
        if (! is_array($sources)) {
            return [];
        }

        return collect($sources)
            ->filter(fn ($source) => is_array($source))
            ->map(fn (array $source): array => [
                'name' => $this->limitText((string) ($source['name'] ?? 'Source'), 120),
                'status' => $this->limitText((string) ($source['status'] ?? ''), 120),
                'rating' => $this->limitText((string) ($source['rating'] ?? ''), 120),
                'source_type' => $this->limitText((string) ($source['source_type'] ?? ''), 80),
                'purpose' => $this->limitText((string) ($source['purpose'] ?? $source['summary'] ?? ''), 320),
                'summary' => $this->limitText((string) ($source['summary'] ?? ''), 420),
                'url' => $this->limitText((string) ($source['url'] ?? ''), 500),
            ])
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Select only source records that the application has classified as usable
     * evidence for a spoken fact-check response. Social context and source
     * traces are intentionally excluded.
     *
     * @return array<int, array<string, string>>
     */
    private function approvedVoiceSources(mixed $sources): array
    {
        if (! is_array($sources)) {
            return [];
        }

        return collect($sources)
            ->filter(fn ($source): bool => is_array($source) && $this->isApprovedVoiceSource($source))
            ->sortBy(fn (array $source): int => $this->voiceSourcePriority((string) ($source['source_type'] ?? '')))
            ->map(fn (array $source): array => [
                'name' => $this->limitText((string) ($source['name'] ?? 'Source'), 120),
                'rating' => $this->limitText((string) ($source['rating'] ?? ''), 120),
                'summary' => $this->limitText((string) ($source['summary'] ?? $source['purpose'] ?? ''), 420),
                'source_type' => $this->limitText((string) ($source['source_type'] ?? ''), 80),
            ])
            ->filter(fn (array $source): bool => $source['name'] !== '' && $source['name'] !== 'Source')
            ->unique(fn (array $source): string => Str::lower($source['name']))
            ->take(3)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $source */
    private function isApprovedVoiceSource(array $source): bool
    {
        return in_array((string) ($source['source_type'] ?? ''), [
            'official',
            'official_record',
            'institution',
            'court',
            'research',
            'weather',
            'fact_check',
            'news',
        ], true);
    }

    private function voiceSourcePriority(string $sourceType): int
    {
        return match ($sourceType) {
            'official', 'official_record', 'institution', 'court', 'research', 'weather' => 1,
            'fact_check' => 3,
            'news' => 4,
            default => 99,
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<int, array<string, string>>  $sources
     */
    private function voiceVerdict(array $analysis, array $sources): string
    {
        $hasPrimaryEvidence = collect($sources)->contains(fn (array $source): bool => in_array(
            $source['source_type'],
            ['official', 'official_record', 'institution', 'court', 'research', 'weather', 'fact_check'],
            true,
        ));

        if (! $hasPrimaryEvidence) {
            return 'Unverifiable';
        }

        $rating = collect($sources)
            ->pluck('rating')
            ->filter()
            ->map(fn (string $value): string => FactCheckRatingNormalizer::normalize($value))
            ->first(fn (string $value): bool => $value !== FactCheckRatingNormalizer::REVIEWED);

        $hasOutdatedRating = collect($sources)
            ->pluck('rating')
            ->contains(fn (string $value): bool => Str::contains(Str::lower($value), 'outdated'));

        if ($hasOutdatedRating) {
            return 'Outdated';
        }

        return match ($rating) {
            FactCheckRatingNormalizer::CONFIRMED => 'True',
            FactCheckRatingNormalizer::MISLEADING,
            FactCheckRatingNormalizer::MISCAPTIONED,
            FactCheckRatingNormalizer::MISSING_CONTEXT => 'Misleading',
            FactCheckRatingNormalizer::PARTLY_FALSE => 'Partly True',
            FactCheckRatingNormalizer::NO_OFFICIAL_CONFIRMATION,
            FactCheckRatingNormalizer::REVIEWED => 'Unverifiable',
            FactCheckRatingNormalizer::FALSE,
            FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION,
            FactCheckRatingNormalizer::MANIPULATED_MEDIA => 'False',
            default => match ((string) ($analysis['verdict'] ?? 'review')) {
                'real' => 'True',
                'fake' => 'False',
                default => 'Unverifiable',
            },
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<int, array<string, string>>  $sources
     * @return array<string, mixed>
     */
    private function verifiedResultForVoice(array $analysis, array $sources): array
    {
        return [
            'application_verdict' => $this->voiceVerdict($analysis, $sources),
            'fact_check_ratings' => collect($sources)
                ->pluck('rating')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $analysis */
    private function formatVoiceResponse(array $analysis, string $explanation): string
    {
        $sources = $this->approvedVoiceSources($analysis['verification_sources'] ?? []);
        $verdict = $this->voiceVerdict($analysis, $sources);
        $explanation = trim(preg_replace('/\s+/', ' ', $explanation) ?? '');
        $explanation = preg_replace('/\b(?:Verdict|Sources)\s*:\s*[^.]*\.?/i', '', $explanation) ?? $explanation;
        $explanation = trim($explanation);

        $sentences = preg_split('/(?<=[.!?])\s+/u', $explanation) ?: [];
        $explanation = collect($sentences)->filter()->take(3)->implode(' ');

        if ($explanation === '' || $verdict === 'Unverifiable') {
            $explanation = $verdict === 'Unverifiable'
                ? 'The approved evidence supplied for this claim is not enough to confirm or disprove it.'
                : 'The verified result and approved evidence support this conclusion.';
        }

        $sourceNames = collect($sources)
            ->pluck('name')
            ->filter()
            ->implode(', ');

        return "Verdict: {$verdict}\n\n{$explanation}\n\nSources: ".($sourceNames !== '' ? $sourceNames.'.' : 'No approved sources supplied.');
    }

    /**
     * @return list<string>
     */
    private function normalizeBullets(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn ($item) => $this->limitText((string) $item, 220))
            ->filter()
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function usageTelemetry(array $responseData): array
    {
        $usage = Arr::wrap($responseData['usage'] ?? []);
        $inputTokens = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        $totalTokens = (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens));

        return [
            'provider' => 'OpenAI',
            'model' => (string) config('services.openai.model', 'gpt-5.4'),
            'prompt_tokens' => $inputTokens,
            'completion_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function limitText(string $text, int $limit): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return Str::limit($normalized, $limit, '');
    }
}
