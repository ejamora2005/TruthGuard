@php
    $ratingNormalizer = \App\Services\Detections\FactCheckRatingNormalizer::class;
    $verdictPresenter = \App\Services\Detections\DetectionVerdictPresenter::class;
    $presentation = $verdictPresenter::forDetection($selectedDetection);
    $publicResult = is_array($selectedDetection->fact_check_result)
        ? \App\Services\Detections\ReportAnswer::forPresentation($selectedDetection->fact_check_result)
        : \App\Services\Detections\ReportAnswer::forLegacyDetection($selectedDetection);
    $presentation['verdict_label'] = $publicResult['assessment_label'];
    $categoryKey = (string) ($presentation['category_key'] ?? 'needs_review');
    $verdict = (string) ($selectedDetection->verdict ?: 'review');
    $riskScore = max(0, min(100, (int) $selectedDetection->fake_score));

    $tone = match ($categoryKey) {
        'confirmed', 'low_risk' => [
            'label' => $presentation['short_label'] ?? 'LOW-RISK CLAIM',
            'risk' => $presentation['risk_label'] ?? 'LOW RISK',
            'summary' => $presentation['summary_lead'] ?? 'The available evidence suggests a lower-risk result.',
            'accent' => '#059669',
            'accentRgb' => '5 150 105',
            'text' => 'text-emerald-700',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'iconBg' => 'bg-emerald-50 text-emerald-700',
        ],
        'ai_generated' => [
            'label' => $presentation['short_label'] ?? 'AI-GENERATED MEDIA',
            'risk' => $presentation['risk_label'] ?? 'SYNTHETIC MEDIA',
            'summary' => $presentation['summary_lead'] ?? 'Synthetic media indicators were found.',
            'accent' => '#7c3aed',
            'accentRgb' => '124 58 237',
            'text' => 'text-violet-700',
            'badge' => 'bg-violet-50 text-violet-700 ring-violet-200',
            'iconBg' => 'bg-violet-50 text-violet-700',
        ],
        'manipulated' => [
            'label' => $presentation['short_label'] ?? 'MANIPULATED MEDIA',
            'risk' => $presentation['risk_label'] ?? 'ALTERED CONTENT',
            'summary' => $presentation['summary_lead'] ?? 'Manipulation indicators were found.',
            'accent' => '#ea580c',
            'accentRgb' => '234 88 12',
            'text' => 'text-orange-700',
            'badge' => 'bg-orange-50 text-orange-700 ring-orange-200',
            'iconBg' => 'bg-orange-50 text-orange-700',
        ],
        'miscaptioned', 'missing_context', 'misleading', 'partly_false', 'no_confirmation', 'needs_review' => [
            'label' => $presentation['short_label'] ?? 'NEEDS SOURCE REVIEW',
            'risk' => $presentation['risk_label'] ?? 'NEEDS REVIEW',
            'summary' => $presentation['summary_lead'] ?? 'Review this content before sharing.',
            'accent' => '#d97706',
            'accentRgb' => '217 119 6',
            'text' => 'text-amber-700',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'iconBg' => 'bg-amber-50 text-amber-700',
        ],
        default => [
            'label' => $presentation['short_label'] ?? 'LIKELY MISLEADING',
            'risk' => $presentation['risk_label'] ?? 'HIGH RISK',
            'summary' => $presentation['summary_lead'] ?? 'High-risk indicators were found.',
            'accent' => '#dc2626',
            'accentRgb' => '220 38 38',
            'text' => 'text-red-700',
            'badge' => 'bg-red-50 text-red-700 ring-red-200',
            'iconBg' => 'bg-red-50 text-red-700',
        ],
    };

    $selectedMediaPath = strtolower((string) $selectedDetection->media_path);
    $selectedIsDocument = $selectedDetection->media_type === 'document'
        || \Illuminate\Support\Str::endsWith($selectedMediaPath, '.pdf');
    $mediaUrl = $selectedDetection->media_url;
    $sourceHost = $selectedDetection->source_url ? parse_url($selectedDetection->source_url, PHP_URL_HOST) : null;
    $sourceLabel = $selectedDetection->platform
        ? \Illuminate\Support\Str::headline((string) $selectedDetection->platform)
        : ($sourceHost ? preg_replace('/^www\./', '', $sourceHost) : 'Direct submission');
    $contentType = \Illuminate\Support\Str::headline($selectedDetection->media_type ?: 'Unknown');
    $isMediaReport = in_array($publicResult['primary_task'], ['media', 'manipulation'], true);
    $submittedMedia = in_array($selectedDetection->media_type, ['image', 'video', 'document'], true);
    $combinedReport = count($publicResult['request']['tasks'] ?? []) > 1;
    $reportTitle = $combinedReport ? 'Content verification report' : ($submittedMedia ? 'Media analysis report' : 'Fact-check report');
    $claimText = trim((string) ($selectedDetection->caption_text ?: $selectedDetection->notes ?: ''));
    $submittedQuestion = $claimText;
    $submittedFileName = $selectedDetection->media_path ? basename(str_replace('\\', '/', $selectedDetection->media_path)) : null;
    $recordedTask = $publicResult['primary_task'];
    $assessmentHeading = match ($recordedTask) {
        'media' => 'AI-generation assessment',
        'manipulation' => 'Manipulation assessment',
        'context' => 'Context assessment',
        default => $categoryKey === 'ai_generated' ? 'AI-generation assessment' : 'Fact-check verdict',
    };
    $savedAssessment = is_array($selectedDetection->fact_check_result) ? $selectedDetection->fact_check_result : $publicResult;
    $presentationAdjusted = $publicResult['primary_task'] !== ($savedAssessment['primary_task'] ?? $publicResult['primary_task'])
        || $publicResult['verdict'] !== ($savedAssessment['verdict'] ?? $publicResult['verdict']);
    $scoreBadge = \App\Services\Detections\AssessmentScore::badge($presentationAdjusted ? $publicResult : $savedAssessment);
    $isUnconfirmed = ! $presentationAdjusted && $verdictPresenter::isNoOfficialConfirmation($selectedDetection);
    $assessment = trim((string) (
        (filled(data_get($selectedDetection->fact_check_result, 'summary')) || ($selectedDetection->fact_check_result['schema_version'] ?? 0) < 4
            ? $publicResult['summary'] : $selectedDetection->analysis_summary)
        ?: $selectedDetection->analysis_summary
        ?: $selectedDetection->explanation_summary
        ?: 'TruthGuard completed the analysis, but no summary was recorded for this case.'
    ));
    $reasoning = trim((string) ($selectedDetection->explanation_summary ?: $assessment));
    if (($selectedDetection->fact_check_result['schema_version'] ?? 0) < 4) {
        $reasoning = $publicResult['summary'];
    }
    $verificationSummary = trim((string) ($selectedDetection->verification_summary ?: 'Available public evidence was compared with the submitted content.'));
    $recommendation = trim((string) ($selectedDetection->recommendation ?: match ($verdict) {
        'fake' => 'Do not share this content until a trusted source confirms it.',
        'review' => 'Review the linked sources before sharing this content.',
        default => 'This result is lower risk, but still check the original source before reuse.',
    }));

    if ($isUnconfirmed) {
        $assessment = 'No official confirmation was found for the exact claim. The available references provide context, but they do not establish that the claim is true or false.';
        $reasoning = 'TruthGuard found no official source or trusted fact-check rating for the exact claim. Social posts and generic search routes are context only, so this result remains unconfirmed until an authoritative source verifies it.';
        $verificationSummary = 'No official source or trusted fact-check rating confirmed the exact claim. Available social context is supporting context only.';
        $recommendation = 'Do not treat this claim as verified. Check the relevant official agency, trusted fact-checkers, and the original source before sharing it.';
    }

    if ($presentationAdjusted) {
        $assessment = $reasoning = $publicResult['summary'];
    }

    $recordedWording = $presentationAdjusted ? null : \App\Services\Detections\ResultWording::saved($savedAssessment);
    $savedWording = $recordedWording
        ? \App\Services\Detections\ResultWording::accept($publicResult, \Illuminate\Support\Arr::except($recordedWording, ['version']))
        : null;
    if ($savedWording) {
        $assessment = $savedWording['explanation'];
    }
    $evidenceParagraphs = collect([$assessment]);
    if (! $savedWording && $recordedTask === 'media'
        && data_get($selectedDetection->fact_check_result, 'verdict') === 'INCONCLUSIVE'
        && \Illuminate\Support\Str::wordCount($assessment) < 100) {
        $recordedLimitations = collect(data_get($selectedDetection->fact_check_result, 'limitations', []))
            ->filter(fn ($note): bool => is_string($note) && trim($note) !== '')
            ->map(fn (string $note): string => trim($note))
            ->unique()
            ->reject(fn (string $note): bool => str_contains($assessment, $note))
            ->take(3);

        if ($recordedLimitations->isNotEmpty()) {
            $evidenceParagraphs->push($recordedLimitations->implode(' '));
        }
    }
    $evidenceText = $evidenceParagraphs->implode("\n\n");
    $interpretationSummary = $savedWording['result_summary'] ?? \App\Services\Detections\ReportAnswer::interpretationSummary($savedAssessment, $reasoning);
    $interpretationAction = $savedWording['recommended_action'] ?? \App\Services\Detections\ReportAnswer::interpretationAction($interpretationSummary, $recommendation);

    $signalGroups = is_array($selectedDetection->signals ?? null) ? $selectedDetection->signals : [];
    $aiBasis = collect($signalGroups['ai_basis'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')->take(4)->values();
    $aiLimitations = collect($signalGroups['ai_limitations'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
        ->unique('label')->take(3)->values();
    $flatSignals = collect($signalGroups)
        ->except(['ai_basis', 'ai_limitations', 'openai_usage', 'verification_policy'])
        ->flatMap(fn ($signals, $group) => collect($signals)
            ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
            ->map(fn (array $signal): array => $signal + ['_group' => (string) $group]))
        ->sortByDesc(fn (array $signal): int => (int) ($signal['weight'] ?? 0))
        ->unique('label')->take(5)->values();

    $signalGroupLabel = function (array $signal): string {
        return match ((string) ($signal['_group'] ?? '')) {
            'trusted_fact_check' => 'Trusted evidence',
            'ai_media_assessment' => 'AI interpretation',
            'content_extraction' => 'OCR / extracted text',
            'visual', 'media_analysis', 'deepfake_cnn' => 'Technical media analysis',
            default => 'Claim or context signal',
        };
    };

    $ocrText = collect($signalGroups['content_extraction'] ?? [])
        ->filter(fn ($signal): bool => is_array($signal))
        ->map(function (array $signal): ?string {
            $label = trim((string) ($signal['label'] ?? ''));

            if (preg_match('/^OpenAI read visible claim text from the image:\s*(.+)$/i', $label, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }

            foreach (['ocr_text', 'extracted_text', 'claim_text'] as $key) {
                if (filled($signal[$key] ?? null)) {
                    return trim((string) $signal[$key]);
                }
            }

            return null;
        })
        ->filter()
        ->first();

    $resultReuse = is_array($signalGroups['result_reuse'] ?? null) ? $signalGroups['result_reuse'] : null;
    $openAiExtraction = data_get($signalGroups, 'submission_extraction.0', []);
    $openAiExtraction = is_array($openAiExtraction) ? $openAiExtraction : [];
    $openAiUsage = $signalGroups['openai_usage'] ?? data_get($selectedDetection->fact_check_result, 'diagnostics.api_usage') ?? $openAiExtraction['usage'] ?? [];
    $openAiUsage = is_array($openAiUsage) ? $openAiUsage : [];
    if ($resultReuse) {
        $openAiUsage = ($resultReuse['original_openai_usage'] ?? []) ?: ($openAiExtraction['usage'] ?? []);
    }
    $openAiUsed = filled($openAiUsage['provider'] ?? null) || filled($ocrText)
        || max((int) ($openAiUsage['total_tokens'] ?? 0), (int) ($openAiUsage['input_tokens'] ?? 0), (int) ($openAiUsage['output_tokens'] ?? 0)) > 0;
    $openAiModel = trim((string) ($openAiUsage['model'] ?? $openAiExtraction['response_model'] ?? $openAiExtraction['configured_model'] ?? ''));
    $openAiStages = collect(data_get($selectedDetection->fact_check_result, 'diagnostics.model_stages', []));
    $openAiFailure = \App\Services\Detections\OpenAiExtractionFailure::recordedReason($openAiExtraction) ?? data_get($signalGroups, 'media_explanation.0.failure_reason')
        ?? ($openAiStages->contains('quota_exhausted') ? 'quota_exhausted' : null);
    $openAiFailureMessage = match ($openAiFailure) {
        'quota_exhausted' => 'OpenAI rejected this request because the API account had no remaining credits or had reached its billing limit. API billing must be restored before a new assessment can use OpenAI.',
        'authentication_failed' => 'OpenAI rejected the configured API credentials for this request. The API configuration needs attention before retrying.',
        'access_denied' => 'OpenAI denied access to the requested operation or model. An administrator must check API key and model permissions before retrying.',
        'model_unavailable' => 'The configured OpenAI model is unavailable to this API project. An administrator must enable it or select an accessible model.',
        'unsupported_region' => 'OpenAI could not accept this request from the deployment region. An administrator must check service availability.',
        'not_configured' => 'OpenAI analysis was disabled or its API key was missing when this report was created.',
        'rate_limited' => 'OpenAI temporarily rate-limited this request. A new assessment can be retried after the limit clears.',
        'request_incomplete', 'request_or_validation_failed', 'input_budget_exhausted' => 'The optional OpenAI explanation could not complete. The saved detector verdict and its original explanation remain available.',
        'timeout', 'network_failure', 'provider_unavailable', 'http_error', 'request_exception' => 'The recorded OpenAI request could not complete. The saved local findings remain available; a new assessment is needed to retry.',
        default => null,
    };
    $openAiPartial = $openAiUsed && ($openAiFailureMessage !== null || $openAiStages->contains(fn ($status) => $status !== 'completed'));
    $openAiStatus = $openAiPartial ? 'Partially completed' : ($openAiUsed ? 'Used for this report' : match ($openAiFailure) {
        'quota_exhausted' => 'API credits exhausted',
        'authentication_failed' => 'API authentication failed',
        'access_denied' => 'API access denied',
        'model_unavailable' => 'Model unavailable',
        'unsupported_region' => 'Service unavailable in this region',
        'not_configured' => 'Not configured',
        default => $openAiFailureMessage ? 'Request unavailable' : 'Not recorded',
    });
    if ($resultReuse && $openAiUsed) {
        $openAiStatus = 'Reused saved assistance';
    }
    $extractionIssue = data_get($savedAssessment, 'diagnostics.outcome_reason') === 'extraction_unavailable'
        && ($savedAssessment['verdict'] ?? '') === 'ANALYSIS_UNAVAILABLE'
        ? \App\Services\Detections\OpenAiExtractionFailure::explanation($openAiExtraction) : null;
    if ($extractionIssue) {
        // Explain recorded service failures without rewriting the historical row
        // or silently running another paid assessment when this page is opened.
        $evidenceParagraphs = collect([$extractionIssue]);
        $evidenceText = $extractionIssue;
        $interpretationSummary = \App\Services\Detections\ReportAnswer::interpretationSummary($savedAssessment, $extractionIssue);
        $interpretationAction = \App\Services\Detections\OpenAiExtractionFailure::action($openAiExtraction);
    }

    $recordedSources = is_array($selectedDetection->fact_check_result)
        ? array_merge($publicResult['sources'] ?? [], $publicResult['contextual_sources'] ?? [], $publicResult['reference_links'] ?? [], $publicResult['method_references'] ?? [], $selectedDetection->verification_sources ?? [])
        : ($selectedDetection->verification_sources ?? []);
    foreach ($publicResult['secondary_assessments'] ?? [] as $secondary) {
        $recordedSources = array_merge($recordedSources, $secondary['sources'] ?? [], $secondary['contextual_sources'] ?? []);
    }
    $sources = collect($recordedSources)
        ->map(fn ($source) => is_array($source) ? array_merge($source, [
            'source_type' => match ($source['source_type'] ?? $source['type'] ?? 'reference') {
                'official_government', 'institution', 'research' => 'official',
                default => $source['source_type'] ?? $source['type'] ?? 'reference',
            },
            'summary' => $source['summary'] ?? $source['used_for'] ?? '',
        ]) : $source)
        ->filter(fn ($source): bool => is_array($source) && \App\Services\Detections\ReportAnswer::safeUrl($source['url'] ?? null) !== null)
        ->reject(fn (array $source): bool => ($selectedDetection->fact_check_result['schema_version'] ?? 0) < 4
            && str_contains($source['url'], 'toolbox.google.com/factcheck/explorer'))
        ->unique('url')
        ->sortBy(fn (array $source): int => match ($source['source_type'] ?? 'reference') {
            'fact_check' => 1,
            'official', 'weather' => 2,
            'news' => 3,
            'source_trace' => 4,
            'social_context' => 5,
            default => 6,
        })->values();
    $primarySources = $sources->reject(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')->take(6)->values();
    $socialSources = $sources->filter(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')->take(4)->values();
    [$trustedSources, $otherSources] = $sources->partition(fn (array $source): bool => in_array(($source['source_type'] ?? null), ['fact_check', 'official', 'weather'], true));
    $trustedSourceCount = $trustedSources->count();
    $sourceCount = $sources->count();
    $topSource = $sources->first();
    $topFactCheck = $sources->first(fn (array $source): bool => ($source['source_type'] ?? null) === 'fact_check');
    $sourceResult = $presentation['source_rating']
        ?: (($presentation['rating'] ?? null) === $ratingNormalizer::NO_OFFICIAL_CONFIRMATION
            ? $ratingNormalizer::NO_OFFICIAL_CONFIRMATION
            : null);
    // A retrieved article body is evidence, not the claim checked by its publisher.
    // Keep explicit source claims separate without implying an established match.
    $sourceClaimText = is_array($topFactCheck)
        ? collect(['reviewed_claim', 'claim', 'claim_text'])->map(fn ($field) => $topFactCheck[$field] ?? null)
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')->map(fn ($text) => trim($text))->first() ?? ''
        : '';
    $claimEvidenceItems = collect([
        $selectedDetection->caption_text ? ['label' => 'User-provided caption', 'text' => trim((string) $selectedDetection->caption_text)] : null,
        $selectedDetection->notes ? ['label' => 'User-provided notes', 'text' => trim((string) $selectedDetection->notes)] : null,
        $ocrText ? ['label' => 'Extracted image text (OCR-style; may be imperfect)', 'text' => $ocrText] : null,
        $sourceClaimText ? ['label' => 'Claim checked by source', 'text' => $sourceClaimText] : null,
    ])->filter()->values();

    $sourceTypeLabel = function (array $source): string {
        return match ($source['source_type'] ?? 'reference') {
            'fact_check' => 'Fact-check',
            'official' => 'Official',
            'weather' => 'Weather',
            'news' => 'News',
            'source_trace' => 'Original source',
            'social_context' => 'Social context',
            default => \Illuminate\Support\Str::headline((string) ($source['label'] ?? 'Reference')),
        };
    };

    $sourceBadgeClass = function (array $source) use ($ratingNormalizer): string {
        $rating = $ratingNormalizer::normalize(
            (string) ($source['rating'] ?? ''),
            (string) ($source['summary'] ?? ''),
            (string) ($source['purpose'] ?? ''),
            (string) ($source['status'] ?? ''),
        );

        return match ($ratingNormalizer::toneFor($rating)) {
            'danger' => 'bg-red-50 text-red-700 ring-red-200',
            'safe' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
    };

    $sourceBadgeText = function (array $source) use ($ratingNormalizer, $sourceTypeLabel): string {
        $rating = $ratingNormalizer::normalize(
            (string) ($source['rating'] ?? ''),
            (string) ($source['summary'] ?? ''),
            (string) ($source['purpose'] ?? ''),
            (string) ($source['status'] ?? ''),
        );

        return $rating !== '' && $rating !== $ratingNormalizer::REVIEWED ? $rating : $sourceTypeLabel($source);
    };

    $relatedPosts = collect($selectedDetection->scrapedPosts ?? [])->take(2)->values();
    $latestFactCheckItems = data_get($latestFactChecks ?? [], 'items', $latestFactChecks ?? []);
    $recentFactChecks = collect($latestFactCheckItems)->filter(fn ($item): bool => is_array($item))->take(3)->values();
@endphp

@once
    <style>
        .tg-ai-report {
            --tg-accent: #2563eb;
            --tg-accent-rgb: 37 99 235;
            color: #0f172a;
        }

        .tg-ai-surface {
            border: 1px solid rgba(226, 232, 240, .9);
            border-radius: 1.5rem;
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 18px 50px rgba(15, 23, 42, .055);
        }

        .tg-ai-hero {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border-color: rgb(var(--tg-accent-rgb) / .18);
            background:
                radial-gradient(circle at 82% 12%, rgb(var(--tg-accent-rgb) / .17), transparent 32%),
                linear-gradient(135deg, #fff 0%, #f8fafc 48%, rgb(var(--tg-accent-rgb) / .055) 100%);
        }

        .tg-ai-hero::before {
            position: absolute;
            inset: 0;
            z-index: -1;
            content: '';
            opacity: .32;
            background-image: linear-gradient(rgba(148,163,184,.12) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,.12) 1px, transparent 1px);
            background-size: 28px 28px;
            mask-image: linear-gradient(to left, #000, transparent 74%);
        }

        .tg-score-ring {
            position: relative;
            display: grid;
            height: 8.5rem;
            width: 8.5rem;
            flex-shrink: 0;
            place-items: center;
            border-radius: 9999px;
            background: conic-gradient(var(--tg-accent) calc(var(--tg-score) * 1%), #e2e8f0 0);
            box-shadow: 0 16px 35px rgb(var(--tg-accent-rgb) / .16);
        }

        .tg-score-ring::after {
            position: absolute;
            inset: .65rem;
            border-radius: inherit;
            background: #fff;
            content: '';
        }

        .tg-score-ring > span { position: relative; z-index: 1; }

        .tg-ai-icon {
            display: inline-flex;
            height: 2.65rem;
            width: 2.65rem;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            border-radius: .9rem;
        }

        .tg-ai-metric {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: rgba(248, 250, 252, .78);
        }

        .tg-ai-media {
            min-height: 13rem;
            max-height: 30rem;
            border-radius: 1.15rem;
            background: #f8fafc;
        }

        .tg-ai-signal-track {
            height: .35rem;
            overflow: hidden;
            border-radius: 9999px;
            background: #e2e8f0;
        }

        .tg-ai-source {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: #fff;
            transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease;
        }

        .tg-ai-source:hover {
            border-color: rgb(var(--tg-accent-rgb) / .35);
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .06);
        }

        .tg-related-image {
            display: block;
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #e2e8f0;
        }

        @media (min-width: 1280px) {
            .tg-ai-sticky { position: sticky; top: 6.5rem; }
        }

        @media (max-width: 640px) {
            .tg-ai-surface { border-radius: 1.15rem; }
            .tg-score-ring { height: 7.25rem; width: 7.25rem; }
            .tg-ai-hero::before { display: none; }
        }

        .tg-ai-report { position: relative; isolation: isolate; overflow-wrap: anywhere; padding-bottom: .5rem; }
        .tg-ai-report * { letter-spacing: 0 !important; }
        .tg-ai-report .tg-ai-surface { border: 1px solid rgba(203, 213, 225, .86); border-radius: 18px; background: linear-gradient(180deg, #fff 0%, #fbfdff 100%); box-shadow: 0 20px 55px rgba(15, 23, 42, .08); }
        .tg-report-overview { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.5rem; padding: 1.25rem; border: 1px solid rgba(203, 213, 225, .86); border-radius: 22px; background: linear-gradient(135deg, #fff 0%, #f8fbff 58%, rgb(var(--tg-accent-rgb) / .05) 100%); box-shadow: 0 24px 70px rgba(15, 23, 42, .09); }
        .tg-report-evidence, .tg-ai-report .tg-ai-hero { border: 1px solid rgba(226, 232, 240, .96); border-radius: 18px; background: linear-gradient(180deg, rgba(255,255,255,.98) 0%, rgba(248,250,252,.88) 100%); box-shadow: inset 0 1px 0 rgba(255,255,255,.9); padding: 1rem; }
        .tg-ai-report .tg-ai-hero { align-self: stretch; display: flex; align-items: center; background: linear-gradient(135deg, #fff 0%, rgb(var(--tg-accent-rgb) / .045) 100%); }
        .tg-ai-report .tg-report-evidence { order: 1; }
        .tg-ai-report .tg-ai-hero { order: 2; }
        .tg-ai-report .tg-ai-hero::before { display: none; }
        .tg-ai-report .tg-ai-icon { box-shadow: inset 0 0 0 1px rgba(255,255,255,.72), 0 8px 22px rgba(15,23,42,.06); }
        .tg-ai-report .tg-ai-media { min-height: 0; max-height: none; border-radius: 16px; background: linear-gradient(135deg, #f8fafc 0%, #eef4fb 100%); box-shadow: inset 0 1px 0 rgba(255,255,255,.85); }
        .tg-ai-report .tg-ai-media img, .tg-ai-report .tg-ai-media video { display: block; width: 100%; height: auto; max-height: 30rem; object-fit: contain; border-radius: 15px; }
        .tg-ai-report .tg-score-ring { box-shadow: 0 18px 44px rgb(var(--tg-accent-rgb) / .17); }
        .tg-ai-report .tg-score-ring::after { box-shadow: inset 0 0 0 1px rgba(226,232,240,.85); }
        .tg-report-metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: rgba(203,213,225,.78); border: 1px solid rgba(203,213,225,.86); border-radius: 18px; overflow: hidden; box-shadow: 0 16px 42px rgba(15,23,42,.06); }
        .tg-report-metrics > div { position: relative; padding: 1rem 1.25rem; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); }
        .tg-report-metrics > div::before { position: absolute; inset: 0 auto 0 0; width: 3px; background: rgb(var(--tg-accent-rgb) / .65); content: ''; }
        .tg-report-metrics dt { font-size: .8125rem; font-weight: 700; color: #64748b; }
        .tg-report-metrics dd { margin-top: .25rem; font-size: 1.5rem; font-weight: 800; color: #0f172a; }
        .tg-report-body { display: flex; flex-direction: column; gap: 1.25rem; }
        .tg-report-main, .tg-report-insights { display: contents; }
        .tg-report-assessment { order: 1; }
        .tg-report-insights > article { order: 3; }
        .tg-report-sources { order: 2; }
        .tg-report-method { order: 4; }
        .tg-report-insights > div { order: 5; }
        .tg-report-process { counter-reset: report-step; }
        .tg-report-process li { counter-increment: report-step; }
        .tg-report-process li::before { content: counter(report-step); display: grid; place-items: center; flex: 0 0 1.5rem; height: 1.5rem; border-radius: 50%; background: #eff6ff; color: #1d4ed8; font-weight: 600; }
        .tg-report-process li > span:first-child { display: none; }
        .tg-report-header { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; padding: .5rem 0 .75rem; }
        .tg-report-title { flex: 1; min-width: 0; }
        .tg-report-kicker { display: flex; align-items: center; flex-wrap: wrap; gap: .65rem; font-size: .6875rem; font-weight: 800; text-transform: uppercase; line-height: 1.6; }
        .tg-report-page-title { margin: .75rem 0 .6rem; font-size: clamp(1.625rem, 2.5vw, 2.125rem); font-weight: 800; line-height: 1.2; color: #0f172a; }
        .tg-report-subtitle { font-size: .875rem; line-height: 1.7; color: #64748b; }
        .tg-report-reference { flex: 0 0 auto; border-left: 1px solid #dbe4ef; padding: .25rem 0 .25rem 1.25rem; color: #475569; }
        .tg-report-reference > span { display: block; font-size: .75rem; line-height: 1.5; }
        .tg-report-reference > strong { display: block; margin-top: .5rem; font-size: 1.5rem; font-weight: 800; line-height: 1.2; }
        .tg-source-jump { box-shadow: 0 8px 20px rgba(15, 23, 42, .055); }
        .tg-source-jump { display: inline-flex; align-items: center; justify-content: center; gap: .375rem; flex: 0 0 auto; min-height: 2.75rem; padding: .5rem .75rem; font-size: .75rem; font-weight: 700; white-space: nowrap; border: 0; background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); }
        .tg-source-jump:hover { box-shadow: 0 10px 22px rgb(var(--tg-accent-rgb) / .11); }
        .tg-source-jump:focus-visible, .tg-image-close:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .tg-ai-report .tg-ai-source { border-color: rgba(203, 213, 225, .82); background: linear-gradient(180deg, #fff 0%, #fbfdff 100%); box-shadow: 0 10px 28px rgba(15,23,42,.045); }
        .tg-ai-report .tg-ai-source:hover { border-color: rgb(var(--tg-accent-rgb) / .42); box-shadow: 0 16px 36px rgb(var(--tg-accent-rgb) / .09); }
        .tg-ai-report .tg-ai-signal-track { height: .45rem; background: #edf2f7; box-shadow: inset 0 1px 2px rgba(15,23,42,.08); }
        .tg-ai-report .tg-ai-metric { border-color: rgba(203,213,225,.82); background: linear-gradient(180deg, #fff 0%, #f8fafc 100%); box-shadow: inset 0 1px 0 rgba(255,255,255,.88); }
        .tg-ai-report details.tg-ai-surface summary { list-style: none; }
        .tg-ai-report details.tg-ai-surface summary::-webkit-details-marker { display: none; }
        .tg-report-method { overflow: hidden; }
        .tg-report-method-summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin: -1.25rem; padding: 1.25rem; border-radius: 18px; transition: background .18s ease, box-shadow .18s ease; }
        .tg-report-method-summary:hover { background: linear-gradient(135deg, #f8fbff 0%, rgb(var(--tg-accent-rgb) / .055) 100%); }
        .tg-report-method-summary:focus-visible { outline: 3px solid rgb(var(--tg-accent-rgb) / .22); outline-offset: -3px; }
        .tg-report-method[open] > .tg-report-method-summary { border-bottom: 1px solid rgba(226, 232, 240, .9); border-radius: 18px 18px 0 0; background: linear-gradient(135deg, #f8fbff 0%, rgb(var(--tg-accent-rgb) / .045) 100%); }
        .tg-report-method-icon { display: inline-grid; width: 2.55rem; height: 2.55rem; flex: 0 0 auto; place-items: center; border-radius: .9rem; background: #eff6ff; color: #2563eb; box-shadow: inset 0 0 0 1px #dbeafe; }
        .tg-report-method-toggle { display: inline-flex; flex: 0 0 auto; align-items: center; gap: .55rem; border-radius: 999px; background: #eff6ff; padding: .45rem .55rem .45rem .8rem; color: #1d4ed8; font-size: .75rem; font-weight: 800; box-shadow: inset 0 0 0 1px #dbeafe; }
        .tg-report-method-chevron { display: inline-grid; width: 1.55rem; height: 1.55rem; place-items: center; border-radius: 999px; background: #fff; transition: transform .18s ease; }
        .tg-report-method[open] > .tg-report-method-summary .tg-report-method-chevron { transform: rotate(180deg); }
        .tg-report-method-body { margin-top: 1.25rem; padding-top: 1rem; }
        .tg-related-image { background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 100%); }
        #source-verification { scroll-margin-top: 6.5rem; }
        #source-verification:target { border-color: rgb(var(--tg-accent-rgb) / .5); box-shadow: 0 0 0 4px rgb(var(--tg-accent-rgb) / .12), 0 20px 55px rgba(15, 23, 42, .1); }
        @media (min-width: 768px) {
            .tg-report-overview { grid-template-columns: minmax(0, 1fr); padding: 0; gap: 1.5rem; border: 0; background: transparent; box-shadow: none; }
            .tg-report-metrics { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .tg-report-body { display: flex; flex-direction: column; }
            .tg-report-assessment, .tg-report-sources, .tg-report-method, .tg-report-insights > div { grid-column: 1 / -1; }
        }

        .truthguard-mobile-result .tg-ai-report { max-width: 1400px; margin-inline: auto; }
        @media (min-width: 768px) {
            .tg-ai-report .tg-report-evidence, .tg-ai-report .tg-ai-hero { padding: 1.5rem; }
        }
        .tg-image-trigger { position: relative; display: block; width: 100%; border: 0; padding: 0; background: transparent; cursor: zoom-in; }
        .tg-image-trigger:focus-visible { outline: 3px solid #2563eb; outline-offset: -3px; border-radius: 15px; }
        .tg-image-expand, .tg-image-close { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid #dbeafe; border-radius: .75rem; background: #fff; color: #2563eb; }
        .tg-image-expand { position: absolute; right: .75rem; bottom: .75rem; border: 0; background: transparent; box-shadow: none; }
        .tg-image-preview { position: fixed; inset: 0; margin: auto; width: min(94vw, 1100px); max-width: 94vw; max-height: 94vh; max-height: 94dvh; padding: 3.75rem 1rem 1rem; border: 1px solid #cbd5e1; border-radius: 18px; background: #fff; }
        .tg-image-preview::backdrop { background: rgba(15,23,42,.8); }
        .tg-image-close { position: absolute; right: .75rem; top: .75rem; cursor: pointer; }
        .tg-image-preview > img { display: block; width: 100%; max-height: calc(94vh - 5rem); max-height: calc(94dvh - 5rem); object-fit: contain; }
        .tg-report-submission { order: 1; display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
        .tg-ai-report .tg-report-submission > article { order: initial; }
        .tg-report-verdict { order: 2; display: flex; flex-direction: column; gap: 1.25rem; min-width: 0; }
        .tg-report-verdict > .tg-ai-hero { order: initial; flex: 1; }
        .tg-report-verdict-content { flex: 1; align-self: stretch; width: 100%; }
        .tg-report-verdict-content > .min-w-0 { display: flex; flex: 1; flex-direction: column; }
        .tg-report-verdict-footer { margin-top: auto; padding-top: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr)); }
        .tg-report-verdict-footer > div { min-width: 0; }
        .tg-report-verdict-footer > div > p:nth-child(2) { font-size: 1rem; line-height: 1.5; }
        .tg-report-verdict-heading { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .tg-report-verdict-heading > p { min-width: 0; margin: 0; }
        .tg-card-eyebrow { font-size: .6875rem; font-weight: 800; line-height: 1.5; text-transform: uppercase; color: #64748b; }
        .tg-evidence-kind { flex-shrink: 0; padding: .25rem .5rem; border: 1px solid #dbe4ef; border-radius: 6px; font-size: .6875rem; font-weight: 600; color: #64748b; background: #f8fafc; }
        .tg-evidence-question { padding: 1rem; border: 1px solid #e2e8f0; border-radius: 12px; background: #f8fafc; }
        .tg-submission-question { margin-top: .5rem; font-size: .875rem; line-height: 1.75; color: #334155; white-space: pre-wrap; }
        .tg-evidence-meta > div { display: flex; align-items: baseline; gap: .5rem; margin-top: .5rem; min-width: 0; }
        .tg-evidence-meta dt { flex-shrink: 0; font-weight: 700; }
        .tg-evidence-meta dd { min-width: 0; overflow-wrap: anywhere; }
        .tg-evidence-meta > div:first-child dd { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tg-verdict-summary { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 1rem; margin-top: .75rem; }
        .tg-report-main-verdict { display: inline-flex; align-items: center; gap: .75rem; width: fit-content; max-width: 100%; margin: 0; padding: .875rem 1rem; border: 1px solid rgb(var(--tg-accent-rgb) / .3); border-radius: 10px; background: rgb(var(--tg-accent-rgb) / .08); color: var(--tg-accent); font-size: clamp(1.125rem, 1.75vw, 1.5rem); font-weight: 800; line-height: 1.35; }
        .tg-report-main-verdict > svg { flex-shrink: 0; }
        .tg-verdict-help { position: relative; display: inline-block; min-width: 0; max-width: 100%; padding-block: .5rem; margin-block: -.5rem; }
        .tg-verdict-help > h2 { margin: 0; }
        .tg-verdict-help .tg-report-main-verdict { cursor: help; text-align: left; }
        .tg-verdict-help .tg-report-main-verdict:focus-visible { outline: 3px solid rgb(var(--tg-accent-rgb) / .45); outline-offset: 3px; }
        .tg-verdict-help-popover { position: absolute; z-index: 20; top: 100%; left: 0; width: min(22rem, calc(100vw - 4rem)); padding: 1rem; border: 1px solid rgb(var(--tg-accent-rgb) / .25); border-radius: 12px; background: #fff; box-shadow: 0 12px 32px rgba(15,23,42,.14); color: #334155; }
        .tg-verdict-help-popover::before { position: absolute; top: -6px; left: 24px; width: 10px; height: 10px; border-top: 1px solid rgb(var(--tg-accent-rgb) / .25); border-left: 1px solid rgb(var(--tg-accent-rgb) / .25); background: #fff; transform: rotate(45deg); content: ''; }
        .tg-verdict-help-popover.is-above { top: auto; bottom: 100%; }
        .tg-verdict-help-popover.is-above::before { top: auto; bottom: -6px; transform: rotate(225deg); }
        .tg-verdict-help-popover > strong { display: block; margin-bottom: .375rem; color: var(--tg-accent); font-size: .75rem; font-weight: 800; line-height: 1.5; }
        .tg-verdict-help-popover > p { font-size: .8125rem; font-weight: 400; line-height: 1.7; }
        .tg-verdict-help-enter { transition: opacity .18s ease, transform .18s ease; }
        .tg-verdict-help-start { opacity: 0; transform: translateY(-.375rem); }
        .tg-verdict-help-end { opacity: 1; transform: translateY(0); }
        .tg-verdict-score { min-width: 0; max-width: 16rem; padding: .75rem 1rem; border: 1px solid #dbe4ef; border-radius: 10px; background: #f1f5f9; color: #475569; }
        .tg-verdict-score strong, .tg-verdict-score span { display: block; }
        .tg-verdict-score strong { font-size: .875rem; line-height: 1.5; }
        .tg-verdict-score span { margin-top: .25rem; font-size: .75rem; line-height: 1.5; }
        .tg-interpretation-header { display: flex; align-items: flex-start; gap: 1rem; padding: 1.5rem; border-bottom: 1px solid #eef2f7; }
        .tg-interpretation-header .tg-card-eyebrow { color: #1d4ed8; }
        .tg-ai-report .tg-interpretation-header h3 { margin-top: .25rem; font-size: 1.25rem; font-weight: 800; line-height: 1.4; color: #0f172a; }
        .tg-interpretation-intro { margin-top: .375rem; font-size: .8125rem; line-height: 1.6; color: #64748b; }
        .tg-interpretation-layout { display: grid; gap: 1.5rem; padding: 1.5rem; }
        .tg-interpretation-layout > * { min-width: 0; }
        .tg-interpretation-reasoning { padding: .25rem 0; }
        .tg-ai-report .tg-interpretation-reasoning > h4 { font-size: .6875rem !important; line-height: 1.5 !important; }
        .tg-interpretation-copy { max-width: 72ch; margin-top: .75rem; font-size: 1rem; font-weight: 400; line-height: 1.85; color: #334155; white-space: pre-line; }
        .tg-interpretation-action { align-self: start; padding: 1.25rem; border: 1px solid #d1fae5; border-radius: 14px; background: #ecfdf5; }
        .tg-interpretation-action-heading { display: flex; align-items: center; gap: .75rem; }
        .tg-interpretation-action-heading > div { min-width: 0; }
        .tg-ai-report .tg-interpretation-action-heading h4 { margin-top: .25rem; font-size: .875rem !important; font-weight: 800; line-height: 1.5 !important; color: #0f172a; }
        .tg-interpretation-recommendation { margin-top: 1rem; font-size: .875rem; font-weight: 600; line-height: 1.8; color: #334155; }
        .tg-interpretation-reference { display: grid; gap: .25rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #a7f3d0; }
        .tg-interpretation-reference > strong { font-size: .8125rem; font-weight: 700; line-height: 1.5; color: #334155; overflow-wrap: anywhere; }
        .tg-interpretation-reference > span:last-child { font-size: .75rem; line-height: 1.5; color: #64748b; }
        .tg-interpretation-link { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2.75rem; max-width: 100%; margin-top: 1rem; padding: .625rem 1rem; border-radius: 10px; background: #0f172a; color: #fff; font-size: .75rem; font-weight: 700; line-height: 1.5; text-decoration: none; }
        .tg-interpretation-link:hover { background: #1d4ed8; }
        .tg-interpretation-link:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .tg-interpretation-link > svg { flex-shrink: 0; width: 16px; height: 16px; }
        @media (min-width: 1100px) {
            .tg-interpretation-layout { grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 2rem; }
            .tg-interpretation-copy { max-width: none; text-align: justify; text-align-last: left; hyphens: auto; }
        }
        @media (max-width: 640px) {
            .tg-interpretation-header, .tg-interpretation-layout { padding: 1rem; }
            .tg-interpretation-header { gap: .75rem; }
            .tg-interpretation-layout { gap: 1.25rem; }
            .tg-interpretation-action { padding: 1rem; }
        }
        .tg-verdict-explanation { margin-top: 1.5rem; }
        .tg-verdict-explanation > h3 { font-size: .875rem; font-weight: 800; line-height: 1.5; color: #334155; }
        .tg-verdict-explanation > p { margin-top: .75rem; font-size: 1rem; line-height: 1.85; color: #334155; white-space: pre-line; overflow-wrap: anywhere; }
        @media (min-width: 1100px) {
            .tg-verdict-explanation > p { text-align: justify; text-align-last: left; hyphens: auto; }
        }
        .tg-verdict-actions { margin-top: auto; padding-top: 1.5rem; }
        .tg-read-result { display: inline-flex; align-items: center; justify-content: flex-start; min-height: 2.75rem; max-width: 100%; padding: .75rem; overflow: hidden; border: 1px solid #dbe4ef; border-radius: 10px; background: #fff; font-size: .75rem; font-weight: 700; color: #475569; cursor: pointer; transition: border-color .2s ease, box-shadow .2s ease; }
        .tg-read-result > svg { flex: 0 0 18px; }
        .tg-read-result.is-talking > svg { color: #2563eb; }
        .tg-read-result.is-talking .tg-speech-wave { transform-origin: 12px 12px; animation: tg-speaking-wave 1s ease-in-out infinite; }
        .tg-read-result.is-talking .tg-speech-wave-outer { animation-delay: .18s; }
        @keyframes tg-speaking-wave {
            0%, 100% { opacity: .25; transform: scale(.9); }
            45% { opacity: 1; transform: scale(1.05); }
        }
        .tg-read-result > span { display: inline-block; min-width: 0; max-width: 0; margin-inline-start: 0; overflow: hidden; white-space: nowrap; opacity: 0; transform: translateX(-.5rem); transition: max-width .32s cubic-bezier(.22, 1, .36, 1), margin-inline-start .32s cubic-bezier(.22, 1, .36, 1), transform .32s cubic-bezier(.22, 1, .36, 1), opacity .2s ease; }
        .tg-read-result.is-expanded > span, .tg-read-result:focus-visible > span { max-width: 10rem; margin-inline-start: .625rem; opacity: 1; transform: translateX(0); }
        .tg-read-result:hover, .tg-read-result[aria-pressed="true"] { border-color: #94a3b8; box-shadow: 0 3px 12px rgba(15,23,42,.06); }
        .tg-read-result:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) {
            .tg-read-result, .tg-read-result > span, .tg-verdict-help-enter { transition: none; transform: none; }
            .tg-read-result.is-talking .tg-speech-wave { animation: none; transform: none; opacity: 1; }
        }
        .tg-report-verdict > .tg-ai-hero { overflow: visible; border-top: 3px solid var(--tg-accent); background: #fff; }
        @media (min-width: 1100px) {
            .tg-report-overview { grid-template-columns: minmax(0, .85fr) minmax(0, 1.4fr); align-items: stretch; }
        }
        /* Follow the submission-to-verdict reading order at every screen size. */
        .tg-report-claim { order: 0; min-width: 0; grid-column: 1 / -1; }
        .tg-report-overview > *, .tg-report-body > *, .tg-report-metrics > div { min-width: 0; }
        .tg-ai-report .tg-ai-hero { align-items: flex-start; }
        .tg-ai-report .tg-report-evidence { display: flex; flex-direction: column; gap: 1rem; align-self: stretch; flex: 1; }
        .tg-evidence-header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
        .tg-evidence-title { display: flex; align-items: center; gap: .75rem; min-width: 0; }
        .tg-evidence-title > div { min-width: 0; }
        .tg-ai-report .tg-evidence-title h3 { font-size: 1.125rem; line-height: 1.4; }
        .tg-evidence-title p { font-size: .75rem; line-height: 1.5; }
        .tg-evidence-meta { padding-top: .75rem; border-top: 1px solid #e2e8f0; font-size: .75rem; line-height: 1.6; }
        .tg-ai-report .tg-ai-media img, .tg-ai-report .tg-ai-media video { max-height: min(50vh, 26rem); }
        @media (max-width: 640px) {
            .tg-report-header { gap: 12px; }
            .tg-report-kicker { gap: .35rem; font-size: .625rem; }
            .tg-report-reference { padding-left: .75rem; max-width: 5rem; }
            .tg-report-reference > span { font-size: .625rem; }
            .tg-report-reference > strong { font-size: 1.25rem; }
            .tg-report-overview { padding: 0; gap: 16px; border: 0; background: transparent; box-shadow: none; }
            .tg-ai-report .tg-report-evidence, .tg-ai-report .tg-ai-hero { padding: 16px; }
            .tg-ai-report h3 { font-size: 18px; line-height: 1.4; }
            .tg-report-metrics > div { padding: 14px; }
            .tg-report-body { gap: 16px; }
        }
        .tg-ai-report .tg-report-evidence .tg-ai-media {
            display: grid;
            place-items: center;
            flex: 1;
            width: 100%;
            min-width: 0;
            margin: 0;
            padding: .75rem;
        }
        .tg-report-evidence .tg-image-trigger { width: fit-content; max-width: 100%; border-radius: 15px; }
        .tg-ai-report .tg-report-evidence .tg-ai-media > .tg-image-trigger > img,
        .tg-ai-report .tg-report-evidence .tg-ai-media > video {
            width: auto;
            max-width: 100%;
            height: auto;
            max-height: min(34vh, 18rem);
            object-fit: contain;
        }
        .tg-ai-report .tg-report-details > .tg-report-method-summary { padding-block: 1.1rem; }
        .tg-details-heading { display: flex; min-width: 0; flex-direction: column; gap: .2rem; }
        .tg-details-heading > strong { font-size: 1rem; font-weight: 800; color: #0f172a; }
        .tg-details-heading > span { font-size: .8125rem; line-height: 1.6; color: #64748b; }
        .tg-report-details > summary > .tg-report-method-chevron { width: 2rem; height: 2rem; flex-shrink: 0; background: #eff6ff; }
        .tg-report-details > .tg-report-method-body { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; padding-top: 1.5rem; }
        .tg-report-details > .tg-report-method-body > * { min-width: 0; margin: 0; order: 0; }
        .tg-report-details .tg-report-verdict-footer { padding-top: 0; }
        .tg-report-details .tg-report-verdict-footer > div { padding: 1.25rem; background: #f8fafc; border-color: #e2e8f0; }
        .tg-report-details .tg-report-metrics { gap: .75rem; border: 0; border-radius: 0; background: transparent; box-shadow: none; }
        .tg-report-details .tg-report-metrics > div { padding: 1.1rem; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; }
        .tg-report-details .tg-report-metrics > div::before { display: none; }
        .tg-report-details .tg-report-metrics dd { font-size: 1.75rem; line-height: 1.4; font-variant-numeric: tabular-nums; }
        .tg-report-details .tg-report-metrics p { margin-top: .5rem; }
        .tg-report-details .tg-details-panel, .tg-report-details .tg-details-claim-content, .tg-report-details .tg-report-method { border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: none; background: #fff; }
        .tg-details-panel { padding: 1.25rem; }
        .tg-ai-report .tg-details-panel h3 { font-size: 1.0625rem; line-height: 1.5; }
        .tg-report-details .tg-details-claim-content { background: #f8fafc; }
        .tg-details-coverage-copy { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; font-size: .875rem; line-height: 1.8; color: #475569; }
        .tg-report-details[open] > .tg-report-method-body { animation: tg-details-reveal .22s ease-out; }
        @keyframes tg-details-reveal { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
        @media (min-width: 1000px) {
            .tg-report-details > .tg-report-method-body { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .tg-report-details > .tg-report-method-body > * { grid-column: 1 / -1; }
            .tg-report-details > .tg-report-method-body > .tg-details-panel { grid-column: auto; }
        }
        @media (max-width: 640px) {
            .tg-report-details > .tg-report-method-body { gap: 1rem; }
            .tg-report-details .tg-report-metrics { gap: .5rem; }
            .tg-report-details .tg-report-metrics > div { padding: .875rem; }
            .tg-report-details .tg-report-verdict-footer > div, .tg-details-panel { padding: 1rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tg-report-details[open] > .tg-report-method-body { animation: none; }
        }
        .tg-source-section-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-bottom: 1.25rem; border-bottom: 1px solid #e2e8f0; }
        .tg-source-section-title { display: flex; align-items: center; gap: .875rem; min-width: 0; }
        .tg-source-section-title > div { min-width: 0; }
        .tg-source-section-title .tg-ai-icon { flex-shrink: 0; }
        .tg-ai-report .tg-source-section-title h3 { font-size: 1.25rem; line-height: 1.4; }
        .tg-source-section-title p { margin-top: .25rem; font-size: .8125rem; line-height: 1.6; color: #64748b; }
        .tg-source-count { display: inline-flex; align-items: baseline; gap: .35rem; flex-shrink: 0; padding: .5rem .75rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; font-size: .75rem; color: #64748b; }
        .tg-source-count strong { font-size: 1rem; color: #334155; font-variant-numeric: tabular-nums; }
        .tg-source-counts { position: relative; display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: .5rem; flex-shrink: 0; }
        .tg-source-count-button { min-height: 2.75rem; align-items: center; cursor: pointer; transition: border-color .18s ease, background .18s ease; }
        .tg-source-count-button:hover, .tg-source-count-button[aria-expanded="true"] { background: #eff6ff; border-color: #bfdbfe; }
        .tg-source-count-button:focus-visible, .tg-source-popover a:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .tg-source-count-button svg { width: .75rem; height: .75rem; margin-left: .125rem; }
        .tg-source-popover { position: absolute; z-index: 30; top: 100%; right: 0; width: min(22rem, calc(100vw - 3rem)); padding-top: .5rem; text-align: left; }
        .tg-source-popover[x-cloak] { display: none; }
        .tg-source-popover-card { padding: 1rem; border: 1px solid #e2e8f0; border-radius: 14px; background: #fff; box-shadow: 0 16px 36px rgba(15,23,42,.13); }
        .tg-source-popover-heading { font-size: .875rem; font-weight: 800; color: #0f172a; }
        .tg-source-popover-note { margin-top: .25rem; font-size: .75rem; line-height: 1.5; color: #64748b; }
        .tg-source-popover ul { max-height: 16rem; overflow-y: auto; overscroll-behavior: contain; margin-top: .75rem; }
        .tg-source-popover li + li { border-top: 1px solid #f1f5f9; }
        .tg-source-popover a, .tg-source-popover-entry { display: block; padding: .625rem .375rem; border-radius: 6px; overflow-wrap: anywhere; }
        .tg-source-popover a:hover { background: #eff6ff; }
        .tg-source-popover strong { display: block; font-size: .8125rem; line-height: 1.5; color: #1e293b; }
        .tg-source-popover span { display: block; margin-top: .125rem; font-size: .75rem; line-height: 1.5; color: #64748b; }
        .tg-source-list { display: grid; grid-template-columns: minmax(0, 1fr); gap: .875rem; margin-top: 1.25rem; }
        .tg-source-group { display: flex; align-items: center; gap: .75rem; grid-column: 1 / -1; padding-block: .375rem; }
        .tg-ai-report .tg-source-group { font-size: .8125rem; font-weight: 700; line-height: 1.5; color: #475569; }
        .tg-source-group::after { content: ''; height: 1px; flex: 1; background: #e2e8f0; }
        .tg-source-group:not(:first-child) { margin-top: .5rem; }
        .tg-ai-report .tg-source-card { display: flex; flex-direction: column; min-width: 0; padding: 1.25rem; background: #fff; border-color: #e2e8f0; box-shadow: 0 3px 12px rgba(15,23,42,.025); }
        .tg-ai-report .tg-source-card:hover { border-color: #bfdbfe; box-shadow: 0 10px 24px rgba(37,99,235,.075); }
        .tg-source-card:focus-visible, .tg-social-source:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .tg-source-card-header { display: flex; align-items: flex-start; gap: .75rem; }
        .tg-source-logo { display: grid; place-items: center; flex: 0 0 2.25rem; height: 2.25rem; overflow: hidden; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #2563eb; font-size: .875rem; font-weight: 800; }
        .tg-source-logo > img { display: block; width: 1.5rem; height: 1.5rem; object-fit: contain; }
        .tg-source-logo > [hidden] { display: none; }
        .tg-source-identity { flex: 1; min-width: 0; }
        .tg-source-publisher { display: block; font-size: .9375rem; font-weight: 800; line-height: 1.5; color: #0f172a; }
        .tg-source-domain { display: block; margin-top: .15rem; font-size: .75rem; line-height: 1.6; color: #64748b; overflow-wrap: anywhere; }
        .tg-source-rating { display: inline-block; max-width: 100%; margin-top: .5rem; border-radius: 7px; padding: .2rem .5rem; font-size: .6875rem; font-weight: 700; line-height: 1.6; }
        .tg-source-card-title { display: block; margin-top: 1rem; font-size: .9375rem; font-weight: 700; line-height: 1.6; color: #1e293b; }
        .tg-source-description { display: block; margin-top: .875rem; margin-bottom: 1.125rem; font-size: .875rem; line-height: 1.8; color: #475569; }
        .tg-source-card-footer { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: auto; padding-top: .875rem; border-top: 1px solid #f1f5f9; }
        .tg-source-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .5rem; min-width: 0; font-size: .6875rem; line-height: 1.6; color: #64748b; }
        .tg-source-meta > span { font-weight: 600; }
        .tg-source-meta > time:not(:first-child)::before { content: '·'; margin-right: .5rem; }
        .tg-source-open { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; flex-shrink: 0; min-height: 2.25rem; padding: .375rem .625rem; border-radius: 8px; background: #eff6ff; font-size: .75rem; font-weight: 700; color: #1d4ed8; transition: background .18s ease; }
        .tg-source-card:hover .tg-source-open { background: #dbeafe; }
        .tg-source-open svg { width: .875rem; height: .875rem; }
        .tg-source-empty { grid-column: 1 / -1; padding: 2rem 1.25rem; border: 1px dashed #cbd5e1; border-radius: 14px; background: #f8fafc; text-align: center; }
        .tg-social-source { min-width: 0; }
        @media (min-width: 1100px) {
            .tg-source-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .tg-source-section-header { flex-direction: column; align-items: stretch; gap: .875rem; }
            .tg-source-section-title { gap: .625rem; }
            .tg-ai-report .tg-source-section-title h3 { font-size: 1rem; }
            .tg-source-section-title p { font-size: .75rem; }
            .tg-source-count { padding: .3rem .5rem; }
            .tg-source-counts { justify-content: flex-start; }
            .tg-source-popover { left: 0; right: auto; width: 100%; }
            .tg-ai-report .tg-source-card { padding: 1rem; }
            .tg-source-card-footer { align-items: flex-end; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tg-source-card, .tg-source-card svg, .tg-social-source, .tg-social-source svg { transition: none !important; transform: none !important; }
        }
        .tg-related-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.125rem; border-bottom: 1px solid #e2e8f0; }
        .tg-ai-report .tg-related-header h3 { font-size: 1.125rem; font-weight: 800; line-height: 1.4; color: #0f172a; }
        .tg-related-header p { margin-top: .25rem; font-size: .8125rem; line-height: 1.7; color: #64748b; }
        .tg-related-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: .75rem; padding: 1rem 1.125rem; }
        .tg-related-group { grid-column: 1 / -1; display: flex; align-items: center; gap: .75rem; }
        .tg-ai-report .tg-related-group { font-size: .8125rem; font-weight: 700; line-height: 1.5; color: #475569; }
        .tg-related-group::after { content: ''; height: 1px; background: #e2e8f0; flex: 1; }
        .tg-related-group:not(:first-child) { margin-top: .5rem; }
        .tg-ai-report .tg-related-card { display: flex; flex-direction: column; min-width: 0; overflow: hidden; border-color: #e2e8f0; background: #fff; box-shadow: 0 3px 12px rgba(15,23,42,.025); }
        .tg-ai-report .tg-related-card:hover { border-color: #bfdbfe; box-shadow: 0 10px 24px rgba(37,99,235,.075); }
        .tg-related-card:focus-visible { outline: 3px solid #2563eb; outline-offset: 3px; }
        .tg-related-media { display: block; position: relative; height: 7.5rem; overflow: hidden; background: #f1f5f9; border-bottom: 1px solid #e2e8f0; }
        .tg-related-media > img { display: block; width: 100%; height: 100%; object-fit: contain; }
        .tg-related-fallback { display: grid; place-items: center; width: 100%; height: 100%; color: #94a3b8; background: linear-gradient(135deg, #eff6ff, #f8fafc); }
        .tg-related-media > [hidden] { display: none; }
        .tg-related-body { display: flex; flex: 1; flex-direction: column; min-width: 0; padding: .875rem; }
        .tg-related-byline { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .tg-related-publisher { min-width: 0; font-size: .8125rem; font-weight: 700; line-height: 1.6; color: #334155; }
        .tg-related-rating { flex-shrink: 0; max-width: 50%; padding: .2rem .5rem; border: 1px solid #e2e8f0; border-radius: 7px; background: #f8fafc; font-size: .6875rem; font-weight: 700; line-height: 1.6; color: #475569; }
        .tg-related-date { display: block; margin-top: .25rem; font-size: .6875rem; line-height: 1.6; color: #64748b; }
        .tg-related-headline { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; margin-top: .625rem; margin-bottom: .625rem; font-size: .8125rem; font-weight: 700; line-height: 1.6; color: #0f172a; }
        .tg-related-card-footer { display: flex; align-items: center; justify-content: flex-end; gap: .75rem; margin-top: auto; padding-top: .5rem; border-top: 1px solid #f1f5f9; }
        .tg-related-kind { display: none; }
        .tg-related-open { display: inline-flex; align-items: center; gap: .375rem; flex-shrink: 0; min-height: 2rem; font-size: .75rem; font-weight: 700; color: #1d4ed8; }
        .tg-related-open svg { width: .875rem; height: .875rem; }
        @media (min-width: 768px) { .tg-related-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .tg-related-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 640px) {
            .tg-related-header, .tg-related-grid { padding: .875rem; }
            .tg-related-header p { display: none; }
            .tg-related-grid { gap: .75rem; }
            .tg-ai-report .tg-related-header h3 { font-size: 1.0625rem; }
            .tg-related-body { padding: .75rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            .tg-related-card, .tg-related-card svg { transition: none !important; transform: none !important; }
        }
        .tg-submission-details { min-width: 0; }
        .tg-submission-details > summary { display: none; }
        @media (max-width: 640px) {
            .tg-ai-report .tg-report-header { align-items: center; gap: .75rem; padding-block: .125rem; }
            .tg-ai-report .tg-report-kicker, .tg-ai-report .tg-report-subtitle, .tg-ai-report .tg-report-reference > span { display: none; }
            .tg-ai-report .tg-report-page-title { margin: 0; font-size: 1.125rem; line-height: 1.4; }
            .tg-ai-report .tg-report-reference { max-width: none; padding-block: .125rem; padding-left: .75rem; }
            .tg-ai-report .tg-report-reference > strong { margin-top: 0; font-size: 1rem; line-height: 1.4; }
            .tg-ai-report .tg-report-evidence { gap: .75rem; }
            .tg-ai-report .tg-report-evidence .tg-ai-media > .tg-image-trigger > img,
            .tg-ai-report .tg-report-evidence .tg-ai-media > video { max-height: min(30svh, 14rem); }
            .tg-submission-details { border-top: 1px solid #e2e8f0; }
            .tg-ai-report .tg-submission-details > summary { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-height: 2.75rem; padding-top: .375rem; font-size: .75rem; font-weight: 600; color: #64748b; cursor: pointer; list-style: none; }
            .tg-submission-details > summary::-webkit-details-marker { display: none; }
            .tg-submission-details > summary > svg { width: .875rem; height: .875rem; flex-shrink: 0; transition: transform .18s ease; }
            .tg-submission-details[open] > summary > svg { transform: rotate(180deg); }
            .tg-submission-details > summary:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; border-radius: 6px; }
            .tg-submission-details .tg-evidence-meta { padding-top: 0; padding-bottom: .25rem; border-top: 0; }
        }
        @media (prefers-reduced-motion: reduce) { .tg-submission-details > summary > svg { transition: none; } }
        @media (max-width: 640px) {
            .tg-ai-report .tg-verdict-summary { grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: .625rem; align-items: stretch; }
            .tg-verdict-summary > .tg-verdict-help { display: flex; align-items: stretch; padding-block: 0; margin-block: 0; }
            .tg-verdict-summary > .tg-verdict-help > h2 { display: flex; width: 100%; }
            .tg-ai-report .tg-verdict-help .tg-report-main-verdict { width: 100%; min-height: 3.5rem; gap: .375rem; padding: .625rem .5rem; font-size: .875rem !important; line-height: 1.5; }
            .tg-verdict-help .tg-report-main-verdict > svg { width: 18px; height: 18px; flex: 0 0 18px; }
            .tg-ai-report .tg-verdict-help .tg-report-main-verdict > span { font-size: inherit !important; line-height: inherit; }
            .tg-ai-report .tg-verdict-score { display: flex; flex-direction: column; justify-content: center; max-width: none; padding: .625rem; }
            .tg-ai-report .tg-verdict-score > strong { font-size: .75rem !important; line-height: 1.45; }
            .tg-ai-report .tg-verdict-score > span { font-size: .625rem !important; line-height: 1.5; }
        }
        @media (max-width: 1023px) {
            body.truthguard-result-route .truthguard-user-content-column main { padding-bottom: calc(7.75rem + env(safe-area-inset-bottom, 0px)) !important; }
        }
    </style>
@endonce

<section
    id="latest-detection-result"
    data-tour="result-reports"
    x-data="{
        scrollToSources(event) {
            if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
            const target = document.getElementById('source-verification');
            if (!target) return;
            event.preventDefault();
            if (window.location.hash !== '#source-verification') window.history.pushState(window.history.state, '', '#source-verification');
            target.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'start' });
            target.focus({ preventScroll: true });
        }
    }"
    class="tg-ai-report truthguard-scroll-target space-y-5 sm:space-y-6"
    data-fact-check-verdict="{{ $publicResult['verdict'] }}"
    style="--tg-accent: {{ $tone['accent'] }}; --tg-accent-rgb: {{ $tone['accentRgb'] }};"
>
    <header class="tg-report-header">
        <div class="tg-report-title">
            <p class="tg-report-kicker text-blue-700">
                <span>TruthGuard</span><span class="text-slate-300" aria-hidden="true">/</span><span>AI-assisted verification</span>
            </p>
            <h1 class="tg-report-page-title">{{ $reportTitle }}</h1>
            <p class="tg-report-subtitle">{{ $isMediaReport ? 'Submitted media and the requested assessment.' : 'Submitted evidence, assessment, and supporting sources.' }}</p>
        </div>
        @isset($userReportNumber)
            <div class="tg-report-reference">
                <span>Report reference</span>
                <strong>{{ 'TG-'.str_pad((string) $userReportNumber, 2, '0', STR_PAD_LEFT) }}</strong>
            </div>
        @endisset
    </header>

    @if ($resultReuse)
        <aside class="rounded-xl border border-blue-100 bg-blue-50 p-3 text-sm leading-6 text-blue-900" aria-label="Saved result reused">
            <strong>Saved result reused.</strong>
            Original assessment: {{ $selectedDetection->analyzed_at?->copy()->timezone('Asia/Shanghai')->format('M j, Y, g:i A') }} UTC+8.
            No new API tokens were used for this analysis.
        </aside>
    @endif

    <div class="tg-report-overview">
        <div class="tg-report-submission">
            <article class="tg-report-evidence min-w-0" x-data="{}">
                <div class="tg-evidence-header">
                    <div class="tg-evidence-title">
                        <span class="tg-ai-icon bg-slate-100 text-slate-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <rect x="4" y="5" width="16" height="14" rx="2" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l3-3" />
                            </svg>
                        </span>
                        <div>
                            <h3 class="text-xl font-black text-slate-950">Submitted Evidence</h3>
                        </div>
                    </div>
                    <span class="tg-evidence-kind">{{ $contentType }}</span>
                </div>

                <div class="tg-ai-media border border-slate-200">
                    @if ($selectedDetection->media_type === 'image' && $mediaUrl)
                        <button type="button" class="tg-image-trigger" aria-label="Enlarge submitted image" aria-haspopup="dialog" aria-controls="evidence-image-{{ $selectedDetection->id }}" @click="$refs.imagePreview.showModal()">
                            <img src="{{ $mediaUrl }}" alt="Analyzed media" class="h-full max-h-[30rem] w-full object-contain">
                            <span class="tg-image-expand" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3H3v5M16 3h5v5M3 16v5h5M21 16v5h-5" /></svg>
                            </span>
                        </button>
                    @elseif ($selectedDetection->media_type === 'video' && $mediaUrl)
                        <video controls preload="metadata" class="h-full max-h-[30rem] w-full bg-slate-950 object-contain">
                            <source src="{{ $mediaUrl }}">
                        </video>
                    @elseif ($selectedIsDocument && $mediaUrl)
                        <div class="flex min-h-56 flex-col items-center justify-center p-6 text-center">
                            <span class="tg-ai-icon bg-red-50 text-red-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linejoin="round" d="M7 3.5h7L18.5 8v12.5H7V3.5Z" /><path d="M14 3.5V8h4.5" />
                                </svg>
                            </span>
                            <p class="mt-3 font-black text-slate-900">Document evidence attached</p>
                            <a href="{{ $mediaUrl }}" target="_blank" rel="noreferrer" class="mt-3 inline-flex items-center gap-2 rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-800">Open PDF</a>
                        </div>
                    @else
                        <div class="flex min-h-52 flex-col justify-center p-5 sm:p-7">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Text or link claim</p>
                            <p class="mt-3 text-base leading-8 text-slate-800">{{ $claimText !== '' ? $claimText : ($selectedDetection->source_url ?: 'No media preview was attached.') }}</p>
                        </div>
                    @endif
                </div>

                @if ($submittedQuestion !== '')
                    <div class="tg-evidence-question">
                        <p class="tg-card-eyebrow">Your question</p>
                        <p class="tg-submission-question">{{ $submittedQuestion }}</p>
                    </div>
                @endif
                <details class="tg-submission-details" open x-data="{ desktop: window.matchMedia('(min-width: 641px)').matches }" x-init="$el.open = desktop"
                    @resize.window.debounce.150ms="const next = window.matchMedia('(min-width: 641px)').matches; if (next !== desktop) { $el.open = next; desktop = next; }">
                    <summary>
                        <span>Submission details</span>
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                    </summary>
                    <dl class="tg-evidence-meta text-slate-500">
                    @if ($submittedFileName)
                        <div><dt>File</dt><dd title="{{ $submittedFileName }}">{{ $submittedFileName }}</dd></div>
                    @endif
                    @if ($selectedDetection->created_at)
                        <div><dt>Submitted</dt><dd><time datetime="{{ $selectedDetection->created_at->toIso8601String() }}">{{ $selectedDetection->created_at->copy()->timezone('Asia/Shanghai')->format('M j, Y, g:i A') }} UTC+8</time></dd></div>
                    @endif
                    </dl>
                </details>
                @if ($selectedDetection->media_type === 'image' && $mediaUrl)
                    <dialog id="evidence-image-{{ $selectedDetection->id }}" x-ref="imagePreview" class="tg-image-preview" aria-label="Enlarged submitted image" @click="if ($event.target === $el) $el.close()">
                        <button type="button" class="tg-image-close" aria-label="Close enlarged image" autofocus @click="$refs.imagePreview.close()">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 6 12 12M6 18 18 6" /></svg>
                        </button>
                        <img src="{{ $mediaUrl }}" alt="Enlarged submitted evidence">
                    </dialog>
                @endif
            </article>

        </div>

        <div class="tg-report-verdict">
            <article class="tg-ai-hero min-w-0" x-data="{ supported: 'speechSynthesis' in window &amp;&amp; 'SpeechSynthesisUtterance' in window, speaking: false, talking: false, speechRequest: 0 }" @pagehide.window="if (supported) { speechRequest++; window.speechSynthesis.cancel(); speaking = false; talking = false; }">
                <div class="tg-report-verdict-content flex flex-col gap-6">
                    <div class="min-w-0">
                        <div class="tg-report-verdict-heading">
                            <p class="tg-card-eyebrow">{{ $assessmentHeading }}</p>
                            <a href="#source-verification" @click="scrollToSources($event)" aria-label="View source verification" title="View source verification" class="tg-source-jump rounded-xl text-slate-600 transition hover:bg-blue-50 hover:text-blue-700">
                                <span>View sources</span>
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m7 10 5 5 5-5" /></svg>
                            </a>
                        </div>
                        <div class="tg-verdict-summary">
                            <div class="tg-verdict-help" x-data="{
                                    helpOpen: false, pinned: false, helpAbove: false, hideTimer: null,
                                    positionHelp() {
                                        const trigger = this.$refs.verdictButton.getBoundingClientRect();
                                        const height = this.$refs.verdictPopover.getBoundingClientRect().height + 12;
                                        const headerBottom = document.querySelector('.truthguard-topbar')?.getBoundingClientRect().bottom ?? 0;
                                        this.helpAbove = window.innerHeight - trigger.bottom &lt; height &amp;&amp; trigger.top - headerBottom > height;
                                    },
                                    showHelp() { clearTimeout(this.hideTimer); this.helpOpen = true; this.$nextTick(() => this.positionHelp()); },
                                    hideHelp() { if (!this.pinned) this.hideTimer = setTimeout(() => { if (!this.pinned) this.helpOpen = false; }, 160); }
                                }"
                                @mouseenter="showHelp()" @mouseleave="hideHelp()"
                                @focusin="showHelp()" @focusout="if (!$el.contains($event.relatedTarget)) { helpOpen = false; pinned = false; }"
                                @resize.window="if (helpOpen) positionHelp()" @scroll.window.capture="if (helpOpen) positionHelp()"
                                @keydown.escape.prevent.stop="helpOpen = false; pinned = false" @click.outside="helpOpen = false; pinned = false">
                                <h2>
                                    <button type="button" x-ref="verdictButton" class="tg-report-main-verdict" :aria-expanded="helpOpen" aria-controls="verdict-help-{{ $selectedDetection->id }}" aria-describedby="verdict-help-{{ $selectedDetection->id }}" @click="pinned = !pinned; if (pinned) showHelp(); else helpOpen = false">
                                        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path stroke-linecap="round" d="M9.5 9a2.5 2.5 0 0 1 5 0c0 1.7-2.5 1.8-2.5 3.5M12 16h.01" /></svg>
                                        <span>{{ $presentation['verdict_label'] ?? 'Needs Review' }}</span>
                                    </button>
                                </h2>
                                <div id="verdict-help-{{ $selectedDetection->id }}" x-ref="verdictPopover" class="tg-verdict-help-popover" :class="{ 'is-above': helpAbove }" role="tooltip" x-show="helpOpen" x-cloak
                                    x-transition:enter="tg-verdict-help-enter" x-transition:enter-start="tg-verdict-help-start" x-transition:enter-end="tg-verdict-help-end"
                                    x-transition:leave="tg-verdict-help-enter" x-transition:leave-start="tg-verdict-help-end" x-transition:leave-end="tg-verdict-help-start">
                                    <strong>What this verdict means</strong>
                                    <p>{{ $presentation['verdict_meaning'] ?? $tone['summary'] }}</p>
                                </div>
                            </div>
                            <div class="tg-verdict-score" aria-label="Assessment score" title="{{ $scoreBadge['detail'] }}">
                                <strong>{{ $scoreBadge['title'] }}</strong>
                                <span>{{ $scoreBadge['description'] }}</span>
                            </div>
                        </div>
                        <div class="tg-verdict-explanation">
                            <h3>What the evidence shows</h3>
                            @foreach ($evidenceParagraphs as $paragraph)
                                <p>{{ $paragraph }}</p>
                            @endforeach
                        </div>
                        <div class="tg-verdict-actions">
                            @if (($extractionIssue || $isMediaReport) && in_array($selectedDetection->media_type, ['image', 'video'], true) && $selectedDetection->media_path)
                                <form method="POST" action="{{ route('detections.retry-media', $selectedDetection) }}" x-data="{ retrying: false }" @submit="retrying = true" class="mb-4">
                                    @csrf
                                    <input type="hidden" name="analysis_task" value="{{ $isMediaReport ? $recordedTask : ($recordedTask === 'context' ? 'context' : 'claim') }}">
                                    <button type="submit" :disabled="retrying" :aria-busy="retrying" class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-60">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5m-3.2-4a7 7 0 0 1 11.5-2L20 9M4 15l2.7 3A7 7 0 0 0 18.2 16" /></svg>
                                        <span x-show="!retrying">{{ $isMediaReport ? 'Retry media analysis' : 'Retry assessment' }}</span><span x-show="retrying" x-cloak>Retrying assessment…</span>
                                    </button>
                                    <p class="mt-2 text-xs text-slate-500">Runs a new assessment; API usage may apply.</p>
                                </form>
                            @endif
                            <button type="button" class="tg-read-result" x-data="{ labelOpen: false, touchReveal: false }" x-show="supported" x-cloak :aria-pressed="speaking" :aria-label="speaking ? 'Stop reading' : 'Read result aloud'" :class="{ 'is-expanded': labelOpen || speaking, 'is-talking': talking }"
                                @mouseenter="labelOpen = true" @mouseleave="labelOpen = false" @focus="labelOpen = true" @blur="labelOpen = false; touchReveal = false"
                                @pointerdown="if ($event.pointerType === 'touch') { touchReveal = !labelOpen &amp;&amp; !speaking; labelOpen = true; } else { touchReveal = false; }"
                                @click="if (touchReveal) { touchReveal = false; return; } window.TruthGuardSpeech?.toggle($data, {{ \Illuminate\Support\Js::from(($presentation['verdict_label'] ?? 'Needs Review').'. '.$evidenceText) }}, document.documentElement.lang || 'en')">
                                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m11 5-6 4H2v6h3l6 4V5Z" />
                                    <path class="tg-speech-wave" stroke-linecap="round" d="M15 8a6 6 0 0 1 0 8" />
                                    <path class="tg-speech-wave tg-speech-wave-outer" stroke-linecap="round" d="M18 5a10 10 0 0 1 0 14" />
                                </svg>
                                <span x-text="speaking ? 'Stop reading' : 'Read result aloud'">Read result aloud</span>
                            </button>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </div>

    <article class="tg-ai-surface tg-report-assessment overflow-hidden" aria-labelledby="result-interpretation-title">
        <header class="tg-interpretation-header">
            <span class="tg-ai-icon bg-blue-50 text-blue-700" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3 14 8.5 19.5 10.5 14 12.5 12 18l-2-5.5L4.5 10.5 10 8.5 12 3Z" />
                        <path stroke-linecap="round" d="m18.5 16 .8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8.8-2.2Z" />
                    </svg>
            </span>
            <div>
                <p class="tg-card-eyebrow text-blue-700">Evidence interpretation</p>
                <h3 id="result-interpretation-title">Why this result?</h3>
                <p class="tg-interpretation-intro">Understand the assessment and what to do next.</p>
            </div>
        </header>
        <div class="tg-interpretation-layout">
            <section class="tg-interpretation-reasoning" aria-labelledby="interpretation-basis-title">
                <h4 id="interpretation-basis-title" class="tg-card-eyebrow">Result summary</h4>
                <p class="tg-interpretation-copy">{{ $interpretationSummary }}</p>
            </section>
            <aside class="tg-interpretation-action" aria-labelledby="interpretation-action-title">
                <div class="tg-interpretation-action-heading">
                    <span class="tg-ai-icon {{ $tone['iconBg'] }}" aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.2 11 14l4-4.4" />
                        </svg>
                    </span>
                    <div>
                        <p class="tg-card-eyebrow">Your next step</p>
                        <h4 id="interpretation-action-title">Recommended action</h4>
                    </div>
                </div>
                <p class="tg-interpretation-recommendation">{{ $interpretationAction }}</p>
                @if (is_array($topSource))
                    <div class="tg-interpretation-reference">
                        <span class="tg-card-eyebrow">Linked reference</span>
                        <strong>{{ $topSource['name'] ?? parse_url($topSource['url'], PHP_URL_HOST) ?? 'Reference' }}</strong>
                        <span>{{ $sourceTypeLabel($topSource) }}</span>
                    </div>
                    <a href="{{ $topSource['url'] }}" target="_blank" rel="noopener noreferrer" class="tg-interpretation-link">
                        Open reference
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                                </svg>
                    </a>
                @else
                    <a href="#source-verification" @click="scrollToSources($event)" class="tg-interpretation-link">
                        View source status
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" />
                                </svg>
                    </a>
                @endif
            </aside>
        </div>
    </article>

    @foreach ($publicResult['secondary_assessments'] ?? [] as $secondary)
        <section class="tg-ai-surface p-5 sm:p-7" aria-label="{{ $secondary['assessment_label'] }}">
            <p class="tg-card-eyebrow">{{ match ($secondary['primary_task']) { 'media' => 'AI-generation assessment', 'manipulation' => 'Manipulation assessment', 'context' => 'Context assessment', default => 'Factual claim assessment' } }}</p>
            <h2 class="mt-2 text-lg font-bold text-slate-900">{{ $secondary['assessment_label'] }}</h2>
            <p class="mt-3 text-slate-600">{{ $secondary['summary'] }}</p>
        </section>
    @endforeach

    <details id="assessment-details" class="tg-ai-surface tg-report-method tg-report-details p-5">
        <summary class="tg-report-method-summary cursor-pointer">
            <span class="tg-details-heading">
                <strong>View details</strong>
                <span>Findings and limitations</span>
            </span>
            <span class="tg-report-method-chevron text-blue-700" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
            </span>
        </summary>
        <p class="mt-4 text-xs text-slate-500">Evidence cutoff: {{ $publicResult['evidence_cutoff'] ?? 'Not recorded' }}</p>
        <div class="tg-report-method-body space-y-5">
            @php
                $recordedMedia = data_get($selectedDetection->fact_check_result, 'media_assessment');
                $additionalMedia = is_array($recordedMedia) ? data_get($recordedMedia, 'supporting_assessments.image_cnn') : null;
            @endphp
            @if (is_array($recordedMedia) && ($recordedMedia['status'] ?? 'not_requested') !== 'not_requested')
                <section class="rounded-2xl border border-slate-200 bg-white/80 p-4" aria-label="Media detector details">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Media detector</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">{{ $recordedMedia['model_name'] ?? 'Trained media detector' }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">Status: {{ \Illuminate\Support\Str::headline($recordedMedia['status'] ?? 'unavailable') }}. This screens for AI-generation signals; it does not verify factual claims.</p>
                    @if (is_array($additionalMedia))
                        <p class="mt-3 text-sm font-bold text-slate-800">Additional image screening</p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">Original image classifier: {{ ($additionalMedia['status'] ?? '') !== 'completed' ? 'Unavailable' : (($additionalMedia['label'] ?? '') === 'fake' ? 'Synthetic-media signal detected' : 'No synthetic-media signal detected; this does not prove authenticity') }}.</p>
                    @endif
                    @if (($recordedMedia['detector_disagreement'] ?? false) === true)
                        <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm leading-6 text-amber-800">The classifiers disagree. Their findings are recorded separately; review the original source before relying on this result.</p>
                    @endif
                    @if (! empty($selectedDetection->fact_check_result['method_references']))
                        <p class="mt-3 text-sm font-bold text-slate-800">Method references</p>
                        @foreach ($selectedDetection->fact_check_result['method_references'] as $methodReference)
                            @if ($methodUrl = \App\Services\Detections\ReportAnswer::safeUrl($methodReference['url'] ?? null))
                                <a href="{{ $methodUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block text-sm font-semibold text-blue-700 underline underline-offset-2">{{ $methodReference['name'] ?? 'Model documentation' }}</a>
                            @endif
                        @endforeach
                    @endif
                </section>
            @endif
            <div class="tg-report-verdict-footer grid gap-3">
                <div class="rounded-2xl border border-slate-200 bg-white/80 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Source result</p>
                    <p class="mt-1 text-lg font-black text-slate-950">
                        {{ $sourceResult ?? 'No source-backed rating' }}
                    </p>
                    @if (is_array($topFactCheck))
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $topFactCheck['name'] ?? 'Matched fact-check source' }}</p>
                    @else
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ $isUnconfirmed ? 'No official or trusted source confirmed this claim.' : 'No authoritative fact-check rating was recorded.' }}</p>
                    @endif
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white/80 p-4">
                    <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Plain-language assessment</p>
                    <p class="mt-1 text-sm font-semibold leading-6 text-slate-800">{{ $tone['summary'] }}</p>
                </div>
            </div>

            <dl class="tg-report-metrics">
                @foreach ([
                    ['label' => $scoreBadge['type'] === 'confidence' ? 'Calibrated confidence' : 'Assessment score', 'value' => $scoreBadge['title'], 'help' => $scoreBadge['detail']],
                    ['label' => 'Risk score', 'value' => $riskScore.'%', 'help' => 'Estimated misinformation or manipulation risk.'],
                    ['label' => 'Sources reviewed', 'value' => $sourceCount, 'help' => 'All references found or checked for this report.'],
                    ['label' => 'Trusted sources', 'value' => $trustedSourceCount, 'help' => 'Official, fact-checking, or otherwise authoritative references.'],
                ] as $metric)
                    <div title="{{ $metric['help'] }}"><dt>{{ $metric['label'] }}</dt><dd>{{ $metric['value'] }}</dd><p class="mt-1 text-xs leading-5 text-slate-500">{{ $metric['help'] }}</p></div>
                @endforeach
            </dl>

            <article class="tg-report-claim">
                <div class="tg-ai-surface tg-details-claim-content p-5 sm:p-7">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-500">Claim being checked</p>
                            <p class="mt-1 text-xs text-slate-500">Submitted text and any explicit claim recorded by a source.</p>
                        </div>
                        @if ($ocrText)
                            <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-black text-amber-800">OCR may be imperfect</span>
                        @endif
                    </div>
                    @forelse ($claimEvidenceItems as $claimEvidence)
                        @php $claimPreview = \Illuminate\Support\Str::limit($claimEvidence['text'], 500); @endphp
                        <div class="mt-4 border-t border-slate-200 pt-3 first:mt-3 first:border-t-0 first:pt-0">
                            <p class="text-xs font-black text-slate-500">{{ $claimEvidence['label'] }}</p>
                            <p class="mt-1 break-words text-sm leading-7 text-slate-800">{{ $claimPreview }}</p>
                            @if ($claimPreview !== $claimEvidence['text'])
                                <details class="tg-claim-full-text mt-3">
                                    <summary class="cursor-pointer text-xs font-bold text-blue-700">View full text</summary>
                                    <p class="mt-2 break-words whitespace-pre-line text-sm leading-7 text-slate-700">{{ $claimEvidence['text'] }}</p>
                                </details>
                            @endif
                        </div>
                    @empty
                        <p class="mt-3 text-sm font-semibold leading-6 text-slate-700">No clear factual claim was detected.</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">TruthGuard cannot assess a specific claim without claim text or a matching source review.</p>
                    @endforelse
                </div>

                @if ($selectedDetection->source_url)
                    <a href="{{ $selectedDetection->source_url }}" target="_blank" rel="noreferrer" class="mt-4 flex items-center gap-3 rounded-2xl border border-slate-200 p-4 transition hover:border-blue-300 hover:bg-blue-50/30">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9.5 14.5 5-5M8 16l-1.3 1.3a3.2 3.2 0 0 1-4.5-4.5L5 10a3.2 3.2 0 0 1 4.5 0M16 8l1.3-1.3a3.2 3.2 0 0 1 4.5 4.5L19 14a3.2 3.2 0 0 1-4.5 0" /></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-black uppercase tracking-[0.12em] text-slate-400">Original link</span>
                            <span class="mt-1 block truncate text-sm font-bold text-blue-700">{{ $selectedDetection->source_url }}</span>
                        </span>
                        <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                    </a>
                @endif
            </article>

            <article class="tg-ai-surface tg-details-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Decision factors</p>
                        <h3 class="mt-1 text-lg font-black text-slate-950">Key signals</h3>
                    </div>
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl {{ $tone['iconBg'] }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M5 17V9M12 17V5M19 17v-4" /></svg>
                    </span>
                </div>

                <div class="mt-5 space-y-4">
                    @forelse ($flatSignals as $signal)
                        @php $weight = max(0, min(100, (int) ($signal['weight'] ?? 0))); @endphp
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-[11px] font-black uppercase tracking-[0.08em] text-slate-400">{{ $signalGroupLabel($signal) }}</p>
                                    <p class="mt-1 text-sm font-semibold leading-5 text-slate-700">{{ $signal['label'] }}</p>
                                </div>
                                <span class="shrink-0 text-xs font-black tabular-nums {{ $tone['text'] }}">{{ $weight }}</span>
                            </div>
                            <div class="tg-ai-signal-track mt-2">
                                <div class="h-full rounded-full" style="width: {{ $weight }}%; background: {{ $tone['accent'] }}"></div>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">The verdict was generated from the available content and source evidence.</p>
                    @endforelse
                </div>
            </article>

            <article class="tg-ai-surface tg-details-panel p-5">
                <p class="text-xs font-black uppercase tracking-[0.14em] text-slate-400">Evidence coverage</p>
                <p class="tg-details-coverage-copy">{{ $verificationSummary }}</p>
            </article>

            @if ($aiBasis->isNotEmpty() || $aiLimitations->isNotEmpty())
                <details class="tg-ai-surface tg-report-method p-5">
                    <summary class="tg-report-method-summary cursor-pointer">
                        <span class="flex min-w-0 items-center gap-3">
                            <span class="tg-report-method-icon" aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 12h7M8.5 15h4.5M8.5 9h7" />
                                </svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xs font-black uppercase tracking-[0.14em] text-slate-400">Transparency</span>
                                <span class="mt-1 block text-base font-black text-slate-950">How TruthGuard Reached This Result</span>
                            </span>
                        </span>
                        <span class="tg-report-method-toggle">
                            Details
                            <span class="tg-report-method-chevron" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                </svg>
                            </span>
                        </span>
                    </summary>
                    <div class="tg-report-method-body">
                        @if ($aiBasis->isNotEmpty())
                            <p class="text-xs font-black uppercase tracking-[0.12em] text-slate-500">Evidence used</p>
                            <ul class="tg-report-process mt-3 space-y-2.5">
                                @foreach ($aiBasis as $basis)
                                    <li class="flex gap-2.5 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-blue-600"></span>
                                        <span>{{ $basis['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($aiLimitations->isNotEmpty())
                            <p class="mt-5 text-xs font-black uppercase tracking-[0.12em] text-amber-700">Limitations</p>
                            <ul class="mt-3 space-y-2.5">
                                @foreach ($aiLimitations as $limitation)
                                    <li class="flex gap-2.5 text-sm leading-6 text-slate-700">
                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                        <span>{{ $limitation['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </details>
            @endif

            <details class="tg-ai-surface tg-report-method p-5">
                <summary class="tg-report-method-summary cursor-pointer">
                    <span class="flex min-w-0 items-center gap-3">
                        <span class="tg-report-method-icon" aria-hidden="true">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Z" />
                                <path stroke-linecap="round" d="M8.5 12h7M12 8.5v7" />
                            </svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-xs font-black uppercase tracking-[0.14em] text-slate-400">AI assistance</span>
                            <span class="mt-1 block text-base font-black text-slate-950">How OpenAI helped</span>
                        </span>
                    </span>
                    <span class="tg-report-method-toggle">
                        Details
                        <span class="tg-report-method-chevron" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" /></svg>
                        </span>
                    </span>
                </summary>
                <div class="tg-report-method-body space-y-4">
                    @if ($openAiUsed)
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-black text-slate-950">AI assistance: OpenAI</span>
                            @if ($openAiModel !== '')
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Model: {{ $openAiModel }}</span>
                            @endif
                            <span class="rounded-full {{ $openAiPartial ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }} px-2.5 py-1 text-xs font-bold">Status: {{ $openAiStatus }}</span>
                        </div>
                        @if ($openAiFailureMessage)
                            <p class="text-sm leading-6 text-slate-700">{{ $openAiFailureMessage }}</p>
                        @endif
                        <p class="text-sm leading-6 text-slate-700">OpenAI may help extract visible claim text, summarize supplied evidence, explain the result in plain language, find related public social context when enabled, and identify limitations or missing context.</p>
                        <p class="text-sm leading-6 text-slate-600">OpenAI is not the final authority and does not replace trusted fact-checkers or official sources. It must not invent sources; the verdict and risk score remain based on TruthGuard's evidence pipeline and trusted-source reconciliation.</p>
                        @if ((int) ($openAiUsage['total_tokens'] ?? 0) > 0)
                            <p class="text-xs font-semibold text-slate-500">{{ $resultReuse ? 'Token usage from the original assessment' : 'Token usage recorded for this report' }}: {{ (int) $openAiUsage['total_tokens'] }}</p>
                        @endif
                    @else
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-black text-slate-950">AI assistance: OpenAI</span>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">Status: {{ $openAiStatus }}</span>
                        </div>
                        <p class="text-sm leading-6 text-slate-700">{{ $openAiFailureMessage ?? 'No OpenAI assistance was recorded for this saved report. This result uses the recorded local analysis and available verification sources.' }}</p>
                        <p class="text-sm leading-6 text-slate-600">When enabled, OpenAI may assist with visible claim text, evidence summaries, plain-language explanations, public social context, and missing-context notes. It is not the final authority and never replaces trusted fact-checkers or official sources.</p>
                    @endif
                </div>
            </details>

            <div class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-xs leading-5 text-blue-950">
                <strong class="font-black">Important:</strong> AI-supported results can be incomplete. Check the original material and cited sources before making consequential decisions.
            </div>
        </div>
    </details>

    <article id="source-verification" tabindex="-1" class="tg-ai-surface tg-report-sources p-5 sm:p-7">
        <div class="tg-source-section-header">
            <div class="tg-source-section-title">
                <span class="tg-ai-icon bg-blue-50 text-blue-700">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.7 10.3a3.45 3.45 0 0 0-4.9 0l-3.25 3.25a3.45 3.45 0 0 0 4.9 4.9l1.05-1.05M10.3 13.7a3.45 3.45 0 0 0 4.9 0l3.25-3.25a3.45 3.45 0 0 0-4.9-4.9L12.5 6.6" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-xl font-black text-slate-950">Source verification</h3>
                    <p>Recorded sources and references for this report.</p>
                </div>
            </div>
            <div class="tg-source-counts" role="group" aria-label="Source classification counts">
                @foreach ([
                    ['key' => 'trusted', 'label' => 'Trusted', 'heading' => 'Trusted sources', 'sources' => $trustedSources, 'note' => 'Recorded as fact-check, official, or weather sources. This does not confirm the submitted claim.'],
                    ['key' => 'other', 'label' => 'Other sources', 'heading' => 'Other sources', 'sources' => $otherSources, 'note' => 'Not classified as trusted in this report. This does not mean these sources are false.'],
                ] as $sourceGroup)
                    <div data-source-group="{{ $sourceGroup['key'] }}" x-data="{ open: false, pinned: false }"
                        @mouseenter="open = true" @mouseleave="if (!pinned) open = false"
                        @focusout="if (!$el.contains($event.relatedTarget)) { open = false; pinned = false }"
                        @click.outside="open = false; pinned = false"
                        @keydown.escape.stop.prevent="open = false; pinned = false; $refs.trigger.focus()">
                        <button type="button" x-ref="trigger" class="tg-source-count tg-source-count-button"
                            @click="pinned = !pinned; open = pinned" :aria-expanded="open" aria-expanded="false"
                            aria-controls="report-{{ $selectedDetection->id }}-{{ $sourceGroup['key'] }}-sources"
                            aria-label="{{ $sourceGroup['sources']->count() }} {{ strtolower($sourceGroup['heading']) }}. Show sources">
                            <strong>{{ $sourceGroup['sources']->count() }}</strong> {{ $sourceGroup['label'] }}
                            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m5 7.5 5 5 5-5" /></svg>
                        </button>
                        <div id="report-{{ $selectedDetection->id }}-{{ $sourceGroup['key'] }}-sources" class="tg-source-popover"
                            x-cloak x-show="open" x-transition.opacity.duration.150ms role="region" aria-label="{{ $sourceGroup['heading'] }}">
                            <div class="tg-source-popover-card">
                                <p class="tg-source-popover-heading">{{ $sourceGroup['heading'] }} · {{ $sourceGroup['sources']->count() }}</p>
                                <p class="tg-source-popover-note">{{ $sourceGroup['note'] }}</p>
                                <ul>
                                    @forelse ($sourceGroup['sources'] as $groupSource)
                                        @php
                                            $groupSourceUrl = \App\Services\Detections\ReportAnswer::safeUrl($groupSource['url'] ?? null);
                                            $groupSourceName = ($groupSource['name'] ?? '') ?: (parse_url($groupSource['url'], PHP_URL_HOST) ?: 'Recorded source');
                                            $groupSourceTitle = trim((string) ($groupSource['title'] ?? ''));
                                        @endphp
                                        <li>
                                            @if ($groupSourceUrl)
                                                <a href="{{ $groupSourceUrl }}" target="_blank" rel="noopener noreferrer">
                                                    <strong>{{ $groupSourceName }}</strong>
                                                    <span>{{ \Illuminate\Support\Str::limit($groupSourceTitle ?: $groupSourceUrl, 110) }}</span>
                                                </a>
                                            @else
                                                <div class="tg-source-popover-entry"><strong>{{ $groupSourceName }}</strong><span>{{ \Illuminate\Support\Str::limit($groupSourceTitle, 110) }}</span></div>
                                            @endif
                                        </li>
                                    @empty
                                        <li><p class="tg-source-popover-note">No {{ strtolower($sourceGroup['heading']) }} recorded.</p></li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    </div>
                @endforeach
                <span class="tg-source-count"><strong>{{ $sourceCount }}</strong> total</span>
            </div>
        </div>

        @if (!$isMediaReport && empty($publicResult['sources']))
            <p class="mb-4 text-sm text-slate-600">No reviewed source established a factual conclusion for this submission. This does not mean the claim is false.</p>
        @endif
        <div class="tg-source-list">
            @forelse ($primarySources as $source)
                @if ($loop->first || ($primarySources[$loop->index - 1]['source_type'] ?? 'reference') !== ($source['source_type'] ?? 'reference'))
                    <h4 class="tg-source-group">{{ match ($source['source_type'] ?? 'reference') {
                        'fact_check' => 'Fact-check sources',
                        'news' => 'News sources',
                        'official', 'weather' => 'Official sources',
                        'source_trace' => 'Original sources',
                        default => 'Additional references',
                    } }}</h4>
                @endif
                @php
                    $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                    $name = $source['name'] ?? $linkHost;
                    $reason = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Related verification source for this result.'));
                    $date = trim((string) ($source['published_at'] ?? $source['date'] ?? ''));
                    $badge = $sourceBadgeText($source);
                    $sourceDomain = parse_url((string) $source['url'], PHP_URL_HOST);
                    $domainLogo = $sourceDomain ? 'https://www.google.com/s2/favicons?domain='.rawurlencode($sourceDomain).'&sz=64' : null;
                    $publisherLogo = trim((string) ($source['logo_url'] ?? ''));
                    $publisherLogo = filter_var($publisherLogo, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($publisherLogo, PHP_URL_SCHEME)), ['http', 'https'], true)
                        ? $publisherLogo : $domainLogo;
                @endphp
                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="tg-ai-source tg-source-card group">
                    <span class="tg-source-card-header">
                        <span class="tg-source-logo" aria-hidden="true">
                            @if ($publisherLogo)
                                <img src="{{ $publisherLogo }}" alt="" width="24" height="24" loading="lazy" referrerpolicy="no-referrer" data-fallback="{{ $domainLogo }}" x-data="{ fallbackTried: false }"
                                    x-on:error="if (!fallbackTried &amp;&amp; $el.dataset.fallback &amp;&amp; $el.src !== $el.dataset.fallback) { fallbackTried = true; $el.src = $el.dataset.fallback; } else { $el.hidden = true; $el.nextElementSibling.hidden = false; }">
                            @endif
                            <span @if ($publisherLogo) hidden @endif>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr((string) $name, 0, 1)) }}</span>
                        </span>
                        <span class="tg-source-identity">
                            <strong class="tg-source-publisher">{{ $name }}</strong>
                            <span class="tg-source-domain">{{ preg_replace('/^www\./', '', (string) $linkHost) }}</span>
                            @if ($badge !== $sourceTypeLabel($source))
                                <span class="tg-source-rating ring-1 {{ $sourceBadgeClass($source) }}">{{ \Illuminate\Support\Str::limit($badge, 30) }}</span>
                            @endif
                        </span>
                    </span>
                    @if (filled($source['title'] ?? $source['headline'] ?? null))
                        <span class="tg-source-card-title">{{ $source['title'] ?? $source['headline'] }}</span>
                    @endif
                    <span class="tg-source-description">{{ \Illuminate\Support\Str::limit($reason, 170) }}</span>
                    <span class="tg-source-card-footer">
                        <span class="tg-source-meta">
                            <span>{{ $sourceTypeLabel($source) }}</span>
                            @if ($date !== '')
                                <time>{{ $date }}</time>
                            @endif
                        </span>
                        <span class="tg-source-open">
                            Open source
                            <svg class="transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                        </span>
                    </span>
                </a>
            @empty
                <div class="tg-source-empty">
                    <p class="text-sm font-bold text-slate-700">No source links were recorded for this scan.</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">Treat this verdict as preliminary and verify it independently.</p>
                </div>
            @endforelse
        </div>

        @if ($socialSources->isNotEmpty())
            <section class="mt-5 border-t border-slate-200 pt-5" aria-labelledby="social-context-title">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path stroke-linecap="round" d="M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4" /></svg>
                    </span>
                    <div>
                        <h4 id="social-context-title" class="text-base font-black text-slate-950">Cross-platform social context ({{ $socialSources->count() }})</h4>
                        <p class="mt-1 text-sm leading-6 text-slate-500">Related public posts and conversations found across social platforms.</p>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @foreach ($socialSources as $source)
                        @php
                            $linkHost = parse_url($source['url'], PHP_URL_HOST) ?: $source['url'];
                            $name = $source['name'] ?? $linkHost;
                            $socialTitle = trim((string) ($source['title'] ?? $source['headline'] ?? $name));
                            $socialSummary = trim((string) ($source['summary'] ?? $source['purpose'] ?? 'Related public content found for this claim.'));
                        @endphp
                        <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer" class="tg-ai-source tg-social-source group flex h-full flex-col p-4">
                            <span class="flex items-center justify-between gap-3">
                                <span class="inline-flex items-center gap-2 text-xs font-black uppercase tracking-[0.1em] text-blue-700">
                                    <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                                    {{ $name }}
                                </span>
                                <span class="truncate text-[11px] font-semibold text-slate-400">{{ preg_replace('/^www\./', '', (string) $linkHost) }}</span>
                            </span>
                            <strong class="mt-3 text-sm font-black leading-6 text-slate-950">{{ \Illuminate\Support\Str::limit($socialTitle, 110) }}</strong>
                            <span class="mt-2 line-clamp-3 text-sm leading-6 text-slate-600">{{ \Illuminate\Support\Str::limit($socialSummary, 190) }}</span>
                            <span class="mt-4 inline-flex items-center gap-2 text-sm font-black text-blue-700">
                                View related content
                                <svg class="h-4 w-4 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </article>

    @if ($relatedPosts->isNotEmpty() || $recentFactChecks->isNotEmpty())
        <article class="tg-ai-surface tg-related-section overflow-hidden">
            <div class="tg-related-header">
                <div>
                    <h3 class="text-lg font-black text-slate-950">Related context</h3>
                    <p>Additional posts and recent public fact checks.</p>
                </div>
                <span class="tg-source-count"><strong>{{ $relatedPosts->count() + $recentFactChecks->count() }}</strong> items</span>
            </div>
            <div class="tg-related-grid">
                @if ($relatedPosts->isNotEmpty())
                    <h4 class="tg-related-group">Related posts</h4>
                @endif
                @foreach ($relatedPosts as $post)
                    @php
                        $postUrl = $post->post_url ?: '#';
                        $postHost = $post->post_url ? parse_url($post->post_url, PHP_URL_HOST) : null;
                        $postSource = $post->display_name ?: \Illuminate\Support\Str::headline((string) ($post->source_key ?: ($postHost ?: 'Related source')));
                        $postImage = collect($post->image_urls ?? [])->filter()->first()
                            ?: collect($post->media_urls ?? [])->filter()->first();
                    @endphp
                    <a href="{{ $postUrl }}" target="_blank" rel="noopener noreferrer" class="tg-ai-source tg-related-card">
                        <span class="tg-related-media">
                            @if ($postImage)
                                <img src="{{ $postImage }}" alt="" loading="lazy" referrerpolicy="no-referrer" x-on:error="$el.hidden = true; $el.nextElementSibling.hidden = false;">
                            @endif
                            <span class="tg-related-fallback" aria-hidden="true" @if ($postImage) hidden @endif>
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m7 15 3-3 2.5 2.5L15 12l3 3" /></svg>
                            </span>
                        </span>
                        <span class="tg-related-body">
                            <span class="tg-related-publisher">{{ $postSource }}</span>
                            <span class="tg-related-date">{{ $post->scraped_at?->diffForHumans() ?? 'Recently checked' }}</span>
                            <span class="tg-related-headline">{{ \Illuminate\Support\Str::limit((string) ($post->caption_text ?: 'Related public content found during source matching.'), 130) }}</span>
                            <span class="tg-related-card-footer">
                                <span class="tg-related-kind">Related post</span>
                                <span class="tg-related-open">View post <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg></span>
                            </span>
                        </span>
                    </a>
                @endforeach

                @if ($recentFactChecks->isNotEmpty())
                    <h4 class="tg-related-group">Recent public fact checks</h4>
                @endif
                @foreach ($recentFactChecks as $item)
                    @php
                        $publisherName = $item['publisher'] ?? 'Fact-check partner';
                        $detailUrl = ! empty($item['id']) && ! auth()->user()?->isAdmin()
                            ? route('dashboard.fact-check', ['factCheck' => $item['id'], 'detection' => $selectedDetection->id])
                            : ($item['url'] ?? route('dashboard'));
                        $rating = $ratingNormalizer::shortLabel((string) ($item['rating'] ?? 'Reviewed'), (string) ($item['headline'] ?? ''), (string) ($item['claim'] ?? ''));
                        $itemImage = $item['image_url'] ?? $item['logo_url'] ?? null;
                    @endphp
                    <a href="{{ $detailUrl }}" target="_blank" rel="noopener noreferrer" class="tg-ai-source tg-related-card">
                        <span class="tg-related-media">
                            @if ($itemImage)
                                <img src="{{ $itemImage }}" alt="" loading="lazy" referrerpolicy="no-referrer" x-on:error="$el.hidden = true; $el.nextElementSibling.hidden = false;">
                            @endif
                            <span class="tg-related-fallback" aria-hidden="true" @if ($itemImage) hidden @endif>
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" /><path stroke-linecap="round" stroke-linejoin="round" d="m7 15 3-3 2.5 2.5L15 12l3 3" /></svg>
                            </span>
                        </span>
                        <span class="tg-related-body">
                            <span class="tg-related-byline">
                                <strong class="tg-related-publisher">{{ $publisherName }}</strong>
                                <span class="tg-related-rating">{{ $rating }}</span>
                            </span>
                            <span class="tg-related-date">{{ $item['date_label'] ?? 'Latest review' }}</span>
                            <span class="tg-related-headline">{{ \Illuminate\Support\Str::limit((string) ($item['headline'] ?? 'Latest public fact-check review.'), 130) }}</span>
                            <span class="tg-related-card-footer">
                                <span class="tg-related-kind">Public fact-check</span>
                                <span class="tg-related-open">Read fact-check <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M8 7h9v9" /></svg></span>
                            </span>
                        </span>
                    </a>
                @endforeach
            </div>
        </article>
    @endif
</section>
