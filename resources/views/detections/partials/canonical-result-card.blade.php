@php
    $mediaPrimary = in_array($result['primary_task'] ?? '', ['media', 'manipulation', 'clarification'], true);
    $requestedTasks = $result['request']['tasks'] ?? [$result['primary_task'] ?? 'claim'];
    $combined = array_intersect($requestedTasks, ['claim', 'context']) !== [] && array_intersect($requestedTasks, ['media', 'manipulation']) !== [];
    $reportTitle = $combined ? 'Content verification report' : ($mediaPrimary ? 'Media analysis report' : 'Fact-check report');
    $ui = $result['ui'];
    $assessmentLabel = $result['assessment_label'] ?? str_replace('_', ' ', $result['verdict']);
    $additionalLimitations = array_values(array_filter($result['limitations'] ?? [], fn ($text) => is_string($text) && trim($text) !== '' && $text !== ($ui['evidence_warning'] ?? null)));
    $sourceLabels = ['official_government' => 'Official government', 'institution' => 'Institution', 'research' => 'Research', 'fact_check' => 'Fact-check', 'reputable_news' => 'News context'];
    $answerSources = collect(array_merge(
        $result['sources'] ?? [],
        $result['contextual_sources'] ?? [],
        $result['claim_assessment']['result']['sources'] ?? [],
        ...array_map(fn ($a) => $a['sources'] ?? [], $result['secondary_assessments'] ?? [])
    ))
        ->filter(fn ($s) => App\Services\Detections\ReportAnswer::safeUrl($s['url'] ?? null))->unique('url')->values()->all();
    $usedAnswerSources = array_values(array_filter($answerSources, fn ($s) => !($s['context_only'] ?? false)));
    $contextAnswerSources = array_values(array_filter($answerSources, fn ($s) => $s['context_only'] ?? false));
    $sourceNumbersByUrl = [];
    foreach ($answerSources as $index => $source) { $sourceNumbersByUrl[$source['url']] = $index + 1; }
    // Saved historical verdicts remain unchanged; missing score metadata never becomes a percentage.
    $statusClass = match ($result['verdict']) {
        'TRUE', 'VERIFIED' => 'tg-status-verified',
        'FALSE', 'MISLEADING', 'AI_GENERATED', 'LIKELY_AI_GENERATED', 'MANIPULATED', 'LIKELY_MANIPULATED' => 'tg-status-adverse',
        'PARTLY_TRUE', 'PARTIALLY_TRUE', 'NEEDS_CONTEXT', 'MISSING_CONTEXT', 'OUT_OF_CONTEXT', 'CONFLICTING_EVIDENCE' => 'tg-status-context',
        'OUTDATED' => 'tg-status-outdated',
        default => 'tg-status-uncertain',
    };
    $verdictIcons = [
        'TRUE' => 'm7 12 3 3 7-7',
        'FALSE' => 'm8 8 8 8M16 8l-8 8',
        'MISLEADING' => 'M12 7v6m0 4h.01',
        'PARTLY_TRUE' => 'M12 4v16M6.5 12l2 2 3.5-4',
        'UNVERIFIABLE' => 'M9.1 9a3 3 0 0 1 5.8 1c0 2-2.9 2-2.9 4m0 3h.01',
        'OUTDATED' => 'M12 7v5l3 2',
    ];
    $verdictMeanings = [
        'TRUE' => 'The reviewed evidence supports the factual claim.',
        'VERIFIED' => 'The reviewed evidence supports the factual claim.',
        'FALSE' => 'The reviewed evidence contradicts the factual claim.',
        'MISLEADING' => 'The content creates an inaccurate impression, even if some details are true.',
        'PARTLY_TRUE' => 'Some factual components are supported and others are refuted by the reviewed evidence.',
        'PARTIALLY_TRUE' => 'Some parts are supported, while other parts are inaccurate or unproven.',
        'NEEDS_CONTEXT' => 'Important context is needed before the claim can be understood fairly.',
        'MISSING_CONTEXT' => 'Key details are missing and could change how the claim is understood.',
        'OUT_OF_CONTEXT' => 'The content is presented in a setting that changes its meaning.',
        'CONFLICTING_EVIDENCE' => 'The reviewed sources disagree, so the claim cannot be settled confidently.',
        'OUTDATED' => 'The claim relies on information that is no longer current.',
        'UNVERIFIED' => 'The available review did not establish whether the claim is true or false. No matching evidence does not mean the claim is false.',
        'UNVERIFIABLE' => 'There is not enough reliable evidence to confirm or refute the claim.',
        'AI_GENERATED' => 'The media analysis indicates AI generation; it does not establish who created the media.',
        'LIKELY_AI_GENERATED' => 'The detector found signals consistent with AI generation, but this is not proof of origin.',
        'LIKELY_NOT_AI_GENERATED' => 'No strong AI-generation signal was detected. This does not prove the media is authentic.',
        'MANIPULATED' => 'The supported analysis found evidence of media manipulation.',
        'LIKELY_MANIPULATED' => 'The supported analysis found possible manipulation signals, but the result is not conclusive.',
        'NO_MANIPULATION_DETECTED' => 'No manipulation signal was detected; this does not prove the media was never edited.',
        'INCONCLUSIVE' => 'The available analysis cannot determine a reliable answer.',
        'SATIRE_PARODY' => 'The content appears intended as satire or parody rather than a literal factual claim.',
        'OPINION_NOT_FACT_CHECKABLE' => 'This is an opinion or value judgment that cannot be verified as a factual claim.',
        'ANALYSIS_UNAVAILABLE' => 'The requested analysis could not be completed, so no result was established.',
        'UNSUPPORTED_ANALYSIS' => 'The requested analysis is not supported for this submission.',
        'UNREADABLE_CONTENT' => 'There was not enough readable content to assess the submission.',
        'CLARIFICATION_NEEDED' => 'The saved assessment did not resolve the requested check.',
    ];
    $verdictMeaning = $verdictMeanings[$result['verdict']] ?? 'This label summarizes the available evidence. Read the explanation below for its reasons and limits.';
    $notices = array_values(array_filter([
        ['show' => $ui['show_official_verification_notice'], 'title' => 'Verify with the official authority', 'label' => 'Official verification notice', 'message' => $ui['official_verification_notice'], 'type' => 'info'],
        ['show' => $ui['show_evidence_warning'], 'title' => 'Evidence warning', 'label' => 'Evidence warning', 'message' => $ui['evidence_warning'], 'type' => 'status'],
        ['show' => $ui['show_missing_context'], 'title' => 'Missing context', 'label' => 'Missing context', 'message' => $ui['missing_context'], 'type' => 'status'],
        ['show' => $ui['show_source_conflict'], 'title' => 'Sources disagree', 'label' => 'Sources disagree', 'message' => $ui['source_conflict_message'], 'type' => 'status'],
    ], fn ($notice) => $notice['show']));
@endphp

@include('detections.partials.result-report-styles')

<article x-data="{}" class="tg-result-report {{ $statusClass }}" aria-labelledby="fact-check-report-title" data-fact-check-verdict="{{ $result['verdict'] }}">
    <header class="tg-result-report-header">
        <div>
            <p class="tg-result-eyebrow tg-result-brand-label">TruthGuard <span aria-hidden="true">/</span> AI-assisted verification</p>
            <h1 id="fact-check-report-title">{{ $reportTitle }}</h1>
            <p class="tg-result-report-subtitle">{{ $mediaPrimary ? 'Submitted media and the requested assessment.' : 'Submitted evidence, assessment, and supporting sources.' }}</p>
        </div>
        @if (isset($userReportNumber))
            <div class="tg-result-report-id"><span>Report reference</span><strong>{{ $userReportNumber }}</strong></div>
        @endif
    </header>

    @include('detections.partials.result-submitted-evidence')

    <section class="tg-result-card tg-result-assessment" aria-labelledby="fact-check-verdict">
        <div class="tg-result-verdict-heading">
            <div>
                <p class="tg-result-eyebrow">{{ match($result['primary_task'] ?? 'claim') { 'media' => 'AI-generation assessment', 'manipulation' => 'Manipulation assessment', 'context' => 'Context assessment', default => 'Fact-check verdict' } }}</p>
                <div class="tg-result-verdict-badge">
                    <span class="tg-result-verdict-help">
                        <button type="button" aria-label="{{ $assessmentLabel }}: {{ $verdictMeaning }}">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="{{ $verdictIcons[$result['verdict']] ?? $verdictIcons['UNVERIFIABLE'] }}"/></svg>
                        </button>
                        <span class="tg-result-verdict-tooltip" aria-hidden="true">{{ $verdictMeaning }}</span>
                    </span>
                    <h3 id="fact-check-verdict">{{ $assessmentLabel }}</h3>
                </div>
            </div>
            @include('detections.partials.result-score', ['assessment' => $result])
        </div>

        <div class="tg-result-explanation" aria-labelledby="ai-explanation">
            <h3 id="ai-explanation">What the evidence shows</h3>
            @include('detections.partials.result-answer', ['answer' => $result])
        </div>

        <div class="tg-result-assessment-actions">
            <div class="tg-result-read-aloud" x-data="{ supported: 'speechSynthesis' in window &amp;&amp; 'SpeechSynthesisUtterance' in window, speaking: false }"
                @pagehide.window="if (supported) { window.speechSynthesis.cancel(); speaking = false; }">
                <button type="button" class="tg-result-button" x-show="supported" x-cloak :aria-pressed="speaking"
                    @click="if (speaking) { window.speechSynthesis.cancel(); speaking = false; } else { window.speechSynthesis.cancel(); const utterance = new SpeechSynthesisUtterance({{ Illuminate\Support\Js::from($assessmentLabel.'. '.$result['summary'].' '.implode(' ', array_map(fn ($a) => $a['assessment_label'].'. '.$a['summary'], $result['secondary_assessments'] ?? []))) }}); utterance.onend = () => speaking = false; utterance.onerror = () => speaking = false; speaking = true; window.speechSynthesis.speak(utterance); }">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m11 5-6 4H2v6h3l6 4V5Zm4 3a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/></svg>
                    <span x-text="speaking ? 'Stop reading' : 'Read result aloud'">Read result aloud</span>
                </button>
            </div>
        </div>
    @foreach($result['secondary_assessments'] ?? [] as $secondary)
        <section class="tg-result-secondary" aria-label="Secondary assessment">
            <p class="tg-result-eyebrow">{{ match($secondary['primary_task'] ?? 'claim') { 'media' => 'AI-generation assessment', 'manipulation' => 'Manipulation assessment', 'context' => 'Context assessment', default => 'Fact-check verdict' } }}</p>
            <div class="tg-result-verdict-heading"><h3>{{ $secondary['assessment_label'] }}</h3>@include('detections.partials.result-score', ['assessment' => $secondary])</div>
            @include('detections.partials.result-answer', ['answer' => $secondary])
        </section>
    @endforeach
    @foreach($result['context_assessments'] ?? [] as $contextAssessment)
        @if($contextAssessment['label'] !== $result['verdict'] && !collect($result['secondary_assessments'] ?? [])->contains(fn ($assessment) => ($assessment['primary_task'] ?? '') === 'context' && ($assessment['verdict'] ?? '') === $contextAssessment['label']))
            @php
                $contextSource = collect(array_merge($result['sources'] ?? [], $result['contextual_sources'] ?? []))->firstWhere('id', $contextAssessment['source_id'] ?? null);
                $contextSourceUrl = App\Services\Detections\ReportAnswer::safeUrl($contextSource['url'] ?? null);
                $contextSourceNumber = $contextSourceUrl ? ($sourceNumbersByUrl[$contextSourceUrl] ?? null) : null;
            @endphp
            <div class="tg-result-secondary">
                <h3>{{ App\Services\Detections\AssessmentVerdict::label($contextAssessment['label']) }}</h3>
                <p>{{ $contextAssessment['explanation'] }}
                    @if($contextSourceNumber)
                        <a class="tg-result-citation" href="#evidence-source-{{ $contextSourceNumber }}" aria-label="Source {{ $contextSourceNumber }}"
                           @click="const card = document.getElementById('evidence-source-{{ $contextSourceNumber }}'); const group = card?.closest('details'); if (group) group.open = true;">[{{ $contextSourceNumber }}]</a>
                    @endif
                </p>
                <p class="tg-result-muted">Score unavailable: no validated percentage for this context label.</p>
            </div>
        @endif
    @endforeach
    </section>

    <details class="tg-result-all-details" id="assessment-details">
        <summary>View details <span>Findings and limitations</span></summary>
        <div class="tg-result-expanded-details">
            @if(filled($result['request']['question'] ?? null) && !App\Services\Detections\SubmissionTask::isPresetInstruction($result['request']['question']))
                <section class="tg-result-card tg-result-claim" aria-labelledby="assessed-question">
                    <h2 id="assessed-question">Question assessed</h2>
                    @include('detections.partials.result-prose', ['prose' => $result['request']['question'], 'proseId' => 'result-question-text', 'proseClass' => 'tg-result-claim-text', 'expandLabel' => 'Read full question'])
                </section>
            @endif
            @if(filled($result['claim'] ?? null) && ! $mediaPrimary)
                <section class="tg-result-card tg-result-claim" aria-labelledby="submitted-claim">
                    <h2 id="submitted-claim">What was assessed</h2>
                    @include('detections.partials.result-prose', ['prose' => $result['claim'], 'proseId' => 'result-claim-text', 'proseClass' => 'tg-result-claim-text', 'expandLabel' => 'Read full claim'])
                </section>
            @endif

            @if ($notices)
                <section class="tg-result-notices" aria-label="Evidence notices">
                    @foreach ($notices as $notice)
                        @include('detections.partials.result-notice', ['notice' => $notice])
                    @endforeach
                </section>
            @endif

            @if(in_array($selectedDetection->media_type, ['image', 'video'], true))
                @include('detections.partials.result-media-assessment', ['media' => $result['media_assessment'] ?? null])
            @endif

            @if($mediaPrimary && isset($result['claim_assessment']))
                @include('detections.partials.result-claim-assessment', ['component' => $result['claim_assessment']])
            @endif

            @if(!empty($result['key_findings']))
                <section class="tg-result-card tg-result-findings-card" aria-labelledby="key-findings">
                    <div class="tg-result-section-heading">
                        <span class="tg-result-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 6 2 2 4-4m-6 9 2 2 4-4m-6 9 2 2 4-4M13 6h8m-8 7h8m-8 7h8"/></svg></span>
                        <h2 id="key-findings">Key findings</h2>
                    </div>
                    <ol class="tg-result-findings tg-result-scroll-region" tabindex="0" aria-label="Key findings">
                        @foreach ($result['key_findings'] as $finding)
                            @if(is_string($finding) && trim($finding) !== '')
                                <li><span class="tg-result-finding-number" aria-hidden="true">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><p>{{ $finding }}</p></li>
                            @endif
                        @endforeach
                    </ol>
                </section>
            @endif

            @if ($additionalLimitations)
                <section class="tg-result-card tg-result-limitations" aria-labelledby="evidence-limitations">
                    <div class="tg-result-section-heading">
                        <span class="tg-result-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/></svg></span>
                        <h2 id="evidence-limitations">Limitations</h2>
                    </div>
                    <ul>@foreach ($additionalLimitations as $limitation)<li>{{ $limitation }}</li>@endforeach</ul>
                </section>
            @endif
        </div>
    </details>

    @include('detections.partials.result-verify-sources')

    <footer class="tg-result-cutoff {{ $ui['highlight_evidence_cutoff'] ? 'tg-result-cutoff-highlight' : '' }}">
        <div>
            @if(isset($result['assessed_at']))<p><strong>Assessed:</strong> {{ Illuminate\Support\Carbon::parse($result['assessed_at'])->timezone('Asia/Shanghai')->format('M j, Y, g:i A') }} UTC+8</p>@endif
            @if(isset($result['original_claim_date']))<p><strong>Original claim date:</strong> {{ Illuminate\Support\Carbon::parse($result['original_claim_date'])->format('M j, Y') }}</p>@endif
            @unless($mediaPrimary)
                @if(!empty($result['evidence_cutoff']))
                    <p><strong>Evidence cutoff:</strong> <time datetime="{{ $result['evidence_cutoff'] }}">{{ Illuminate\Support\Carbon::parse($result['evidence_cutoff'])->timezone('Asia/Shanghai')->format('M j, Y, g:i A') }} UTC+8</time></p>
                    <p class="tg-result-muted">This result reflects the evidence available at that time.</p>
                @else
                    <p class="tg-result-muted">No assessment cutoff was recorded for this saved result.</p>
                @endif
            @endunless
        </div>
        <a href="{{ route('detections.create') }}" class="tg-result-button tg-result-button-primary">Check another claim <span aria-hidden="true">&rarr;</span></a>
    </footer>
</article>
