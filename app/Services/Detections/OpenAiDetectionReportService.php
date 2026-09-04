<?php

namespace App\Services\Detections;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpenAiDetectionReportService
{
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
                    'message' => (string) data_get($response->json(), 'error.message', 'OpenAI returned an unsuccessful response.'),
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
                'message' => $exception->getMessage(),
            ]);

            return $analysis;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function extractClaimText(array $context, ?UploadedFile $uploadedFile = null): ?string
    {
        if (! $this->isEnabled() || ! (bool) config('services.openai.claim_extraction_enabled', true)) {
            return null;
        }

        $imageInput = $this->buildImageInput($uploadedFile);

        if ($imageInput === null) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.openai.timeout', 40))
                ->post('https://api.openai.com/v1/responses', $this->claimExtractionPayload($context, $imageInput));

            if (! $response->successful()) {
                Log::warning('OpenAI claim extraction request failed; continuing without image text.', [
                    'status' => $response->status(),
                    'message' => (string) data_get($response->json(), 'error.message', 'OpenAI returned an unsuccessful response.'),
                ]);

                return null;
            }

            $data = $response->json();
            $report = $this->extractStructuredReport(is_array($data) ? $data : []);

            if ($report === null) {
                return null;
            }

            $claimText = trim((string) ($report['claim_text'] ?? ''));
            $visibleText = trim((string) ($report['visible_text'] ?? ''));

            return $this->limitText($claimText !== '' ? $claimText : $visibleText, 500) ?: null;
        } catch (\Throwable $exception) {
            Log::warning('OpenAI claim extraction request failed; continuing without image text.', [
                'message' => $exception->getMessage(),
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

        $imageInput = $this->buildImageInput($uploadedFile);

        if ($imageInput !== null) {
            $content[] = $imageInput;
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

        if ($this->shouldSearchSocialWeb($context)) {
            $payload['tools'] = [[
                'type' => 'web_search',
                'filters' => [
                    'allowed_domains' => $this->socialSearchDomains(),
                ],
                'search_context_size' => (string) config('services.openai.social_search_context_size', 'medium'),
            ]];
            $payload['tool_choice'] = 'required';
            $payload['include'] = ['web_search_call.action.sources'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $imageInput
     * @return array<string, mixed>
     */
    private function claimExtractionPayload(array $context, array $imageInput): array
    {
        return [
            'model' => (string) config('services.openai.model', 'gpt-5.4'),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => implode("\n", [
                                'You extract claim text from uploaded evidence for a fact-checking workflow.',
                                'Read visible text, names, dates, places, and the main factual claim from the image.',
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
                        $imageInput,
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
            'max_output_tokens' => 500,
        ];
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'You are TruthGuard, an AI fact-checking assistant for a cybersecurity and misinformation review platform.',
            'Write a careful user-facing report using only the provided basis: computed risk score, signals, claim text, source URL, and verification sources.',
            'Do not invent sources, dates, people, locations, quotes, or certainty that is not present in the provided basis.',
            'Preserve the supplied verdict and risk score because trusted-source reconciliation has already been applied upstream. If evidence is limited, say that clearly.',
            'Use social-context sources to discuss where else the uploaded image, video, text, or link appears, including reposts, comments, corrections, or conflicting captions. Do not treat social posts as authoritative proof unless a trusted fact-check or official source also supports that conclusion.',
            'When web search is available, search the submitted claim across public Facebook, Instagram, TikTok, X, YouTube, Reddit, and Threads pages. Return only social matches that were actually found, with their real source URL and a concise description of the related content. Never invent a social post or claim that a private or inaccessible post was reviewed.',
            'If verification sources include a public fact-check rating, lead with the source and rating, for example: "Based on the source, this claim is AI-generated, misinformation." Preserve specific ratings such as Missing context, Miscaptioned, Manipulated media, AI-generated, misinformation, Misleading, No official confirmation found, False, or Confirmed when provided. Do not collapse a specific rating to only "False" unless that is the only supplied rating. Do not contradict a supplied trusted fact-check rating.',
            'For a source-backed fact-check match, ground the first sentence in the fact-check source headline, claim summary, and rating. Do not restate extracted image text as the exact claim.',
            'Treat extracted image text as OCR-style search context that can be imperfect. When a trusted fact-check headline or summary describes the matched claim more clearly, use the source wording instead of restating imperfect extracted text.',
            'Use plain language and explain why the system reached the result. Keep the answer concise and professional.',
            'Return only valid JSON that matches the requested schema.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     */
    private function buildUserPrompt(array $analysis, array $context): string
    {
        $hasSourceBackedFactCheck = collect($analysis['verification_sources'] ?? [])
            ->filter(fn ($source) => is_array($source))
            ->contains(fn (array $source): bool => ($source['source_type'] ?? null) === 'fact_check'
                && filled($source['rating'] ?? null));
        $signalsForPrompt = $analysis['signals'] ?? [];

        if ($hasSourceBackedFactCheck && is_array($signalsForPrompt)) {
            unset($signalsForPrompt['content_extraction']);
        }

        $basis = [
            'claim_text' => $this->limitText(collect([
                $context['caption_text'] ?? null,
                $context['notes'] ?? null,
            ])->filter()->implode(' '), 1200),
            'extracted_image_text_for_search' => $hasSourceBackedFactCheck
                ? ''
                : $this->limitText((string) ($context['openai_extracted_claim'] ?? ''), 800),
            'source_url' => $this->limitText((string) ($context['source_url'] ?? ''), 500),
            'platform' => $context['platform'] ?? null,
            'media_type' => $context['media_type'] ?? null,
            'verdict' => $analysis['verdict'] ?? 'review',
            'risk_score' => (int) ($analysis['fake_score'] ?? 0),
            'current_summaries' => [
                'preprocessing_summary' => $this->limitText((string) ($analysis['preprocessing_summary'] ?? ''), 500),
                'analysis_summary' => $this->limitText((string) ($analysis['analysis_summary'] ?? ''), 700),
                'verification_summary' => $this->limitText((string) ($analysis['verification_summary'] ?? ''), 700),
                'explanation_summary' => $this->limitText((string) ($analysis['explanation_summary'] ?? ''), 900),
                'recommendation' => $this->limitText((string) ($analysis['recommendation'] ?? ''), 600),
            ],
            'signals' => $this->summarizeSignals($signalsForPrompt),
            'verification_sources' => $this->summarizeSources($analysis['verification_sources'] ?? []),
        ];

        return "Search for public social-media posts related to the submitted claim, then create the final TruthGuard AI report from this basis. Keep social matches as context rather than authoritative proof:\n"
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
                'social_matches' => [
                    'type' => 'array',
                    'description' => 'Public social-media pages actually found by web search that contain related posts, reposts, corrections, comments, videos, or conflicting captions.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'platform' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'platform', 'url', 'summary'],
                    ],
                ],
            ],
            'required' => [
                'analysis_summary',
                'verification_summary',
                'explanation_summary',
                'recommendation',
                'basis',
                'limitations',
                'social_matches',
            ],
        ];
    }

    private function buildImageInput(?UploadedFile $uploadedFile): ?array
    {
        if (! (bool) config('services.openai.image_context_enabled', true) || ! $uploadedFile) {
            return null;
        }

        $mime = (string) $uploadedFile->getMimeType();

        if (! Str::startsWith($mime, 'image/')) {
            return null;
        }

        $maxBytes = max(0, (int) config('services.openai.image_max_bytes', 4194304));

        if ($maxBytes > 0 && $uploadedFile->getSize() > $maxBytes) {
            return null;
        }

        $path = $uploadedFile->getRealPath() ?: $uploadedFile->getPathname();

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return [
            'type' => 'input_image',
            'image_url' => 'data:'.$mime.';base64,'.base64_encode($contents),
        ];
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

        $socialSources = $this->extractSocialWebSources($responseData, $report['social_matches'] ?? []);

        if ($socialSources !== []) {
            $existingSources = collect(is_array($analysis['verification_sources'] ?? null) ? $analysis['verification_sources'] : [])
                ->reject(fn ($source): bool => is_array($source)
                    && ($source['source_type'] ?? null) === 'social_context'
                    && in_array(($source['status'] ?? null), ['ready-for-social-context', 'Cross-platform social search'], true));

            $analysis['verification_sources'] = $existingSources
                ->merge($socialSources)
                ->filter(fn ($source): bool => is_array($source) && filled($source['url'] ?? null))
                ->unique(fn (array $source): string => Str::lower((string) $source['url']))
                ->values()
                ->all();
        }

        if ($this->hasSyntheticMediaFinding($analysis, $report, $basis) && ! $this->hasSourceBackedFactCheckRating($analysis)) {
            $analysis = $this->applySyntheticMediaVerdict($analysis, $signals);
            $signals = $analysis['signals'];
        }

        $analysis['signals'] = $signals;

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldSearchSocialWeb(array $context): bool
    {
        if (! (bool) config('services.openai.social_search_enabled', true)) {
            return false;
        }

        return collect([
            $context['caption_text'] ?? null,
            $context['notes'] ?? null,
            $context['openai_extracted_claim'] ?? null,
            $context['source_url'] ?? null,
        ])->contains(fn ($value): bool => filled($value));
    }

    /**
     * @return list<string>
     */
    private function socialSearchDomains(): array
    {
        return collect(config('services.openai.social_search_domains', []))
            ->map(fn ($domain): string => Str::lower(trim((string) $domain)))
            ->filter()
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @param  mixed  $reportedMatches
     * @return array<int, array<string, string>>
     */
    private function extractSocialWebSources(array $responseData, mixed $reportedMatches): array
    {
        $matches = collect(is_array($reportedMatches) ? $reportedMatches : [])
            ->filter(fn ($match): bool => is_array($match) && filled($match['url'] ?? null))
            ->keyBy(fn (array $match): string => $this->comparableUrl((string) $match['url']));

        return collect($responseData['output'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && ($item['type'] ?? null) === 'web_search_call')
            ->flatMap(fn (array $item): array => (array) data_get($item, 'action.sources', []))
            ->filter(fn ($source): bool => is_array($source) && $this->isAllowedSocialUrl((string) ($source['url'] ?? '')))
            ->map(function (array $source) use ($matches): array {
                $url = trim((string) $source['url']);
                $match = $matches->get($this->comparableUrl($url), []);
                $platform = trim((string) ($match['platform'] ?? '')) ?: $this->socialPlatformForUrl($url);
                $title = trim((string) ($match['title'] ?? $source['title'] ?? 'Related public post'));
                $summary = trim((string) ($match['summary'] ?? ''));

                if ($summary === '') {
                    $summary = $title !== ''
                        ? "Open this public {$platform} result to review the related content and its original context."
                        : "A related public result was found on {$platform}.";
                }

                return [
                    'name' => $platform,
                    'title' => Str::limit($title ?: "Related {$platform} content", 180, ''),
                    'status' => 'OpenAI web-search match',
                    'purpose' => Str::limit($summary, 320, ''),
                    'url' => $url,
                    'summary' => Str::limit($summary, 420, ''),
                    'source_type' => 'social_context',
                    'label' => 'Social match',
                ];
            })
            ->unique('url')
            ->take(max(1, (int) config('services.openai.social_search_max_results', 4)))
            ->values()
            ->all();
    }

    private function isAllowedSocialUrl(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        return collect($this->socialSearchDomains())
            ->contains(fn (string $domain): bool => $host === $domain || Str::endsWith($host, '.'.$domain));
    }

    private function socialPlatformForUrl(string $url): string
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            Str::contains($host, ['facebook.com', 'fb.com']) => 'Facebook',
            Str::contains($host, 'instagram.com') => 'Instagram',
            Str::contains($host, 'tiktok.com') => 'TikTok',
            Str::contains($host, ['twitter.com', 'x.com']) => 'X',
            Str::contains($host, 'youtube.com') => 'YouTube',
            Str::contains($host, 'reddit.com') => 'Reddit',
            Str::contains($host, 'threads.net') => 'Threads',
            default => 'Social media',
        };
    }

    private function comparableUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return Str::lower(trim($url));
        }

        return Str::lower(trim(($parts['host'] ?? '').($parts['path'] ?? ''), '/'));
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
     * @param  mixed  $signals
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
     * @param  mixed  $sources
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
