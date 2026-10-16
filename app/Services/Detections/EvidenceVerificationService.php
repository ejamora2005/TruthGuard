<?php

namespace App\Services\Detections;

use App\Models\CanonicalFactCheckResult;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EvidenceVerificationService
{
    public const VERSION = 'evidence-v13';

    public function __construct(
        private readonly SubmissionExtractor $extractor,
        private readonly ClaimPlanner $planner,
        private readonly ArticleSearch $search,
        private readonly EvidenceJudge $judge,
        private readonly StructuredAnalysis $model,
        private readonly FilipinoNewsClassifier $classifier,
        private readonly FactCheckEvidencePolicy $policy,
    ) {}

    public function apply(array $analysis, array $context, ?UploadedFile $file = null, ?callable $progress = null): array
    {
        $context['analysis_id'] = (string) ($context['analysis_id'] ?? Str::uuid());
        $resolved = $context['resolved_request'] ?? SubmissionTask::resolve($context);
        $primary = $resolved['resolved_task'];
        $request = $context;
        $question = $resolved['question'];
        $tasks = $resolved['tasks'];
        $context['caption_text'] = SubmissionTask::claimText($context['caption_text'] ?? null);
        $context['notes'] = SubmissionTask::claimText($context['notes'] ?? null);
        $original = $analysis;
        $started = microtime(true);
        $extractionBudget = new RetrievalBudget;
        // Explicit detector requests do not need OCR, news searches or an article verdict.
        $explicitTasks = SubmissionTask::tasks($request);
        $detectorOnly = ($explicitTasks !== [] && array_intersect($explicitTasks, ['claim', 'context']) === [])
            || ($explicitTasks === [] && in_array($request['analysis_task'] ?? '', ['media', 'manipulation'], true));
        $extractionBudget->freshSources = ! $detectorOnly && ClaimPlanner::isCurrentStatus($question);
        try {
            $progress && $progress('Reading submitted content');
            $extraction = $detectorOnly ? null : $this->extractor->extract($context, $file, $extractionBudget);
            // Only automatic, content-dependent routing may be finalized after OCR.
            if (SubmissionTask::tasks($request) === [] && ($request['analysis_task'] ?? 'auto') === 'auto') {
                $resolved = SubmissionTask::resolve($request, $extraction);
                $primary = $resolved['resolved_task'];
                $tasks = $resolved['tasks'];
            }
            Log::info('Evidence analysis route selected.', ['analysis_id' => $context['analysis_id'],
                'route' => $primary, 'extraction_status' => $extraction['status'] ?? 'skipped',
                'ocr_status' => $extraction['ocr_status'] ?? null]);
            $context['primary_task'] = $primary;
            $context['question'] = $question;
            $context['media_attached'] = $file !== null;
            if ($detectorOnly || $primary === 'clarification') {
                $analysis['claim_assessment'] = ['status' => 'not_requested', 'task' => 'Factual claim verification',
                    'result' => null, 'reason' => 'Factual verification was not requested or the task needs clarification.'];
            } else {
                $analysis = $this->applyClaims($analysis, $context, $file, $progress, $extraction, $extractionBudget->pages);
            }
            $claim = $analysis['claim_assessment'] ?? [
                'status' => 'completed', 'task' => 'Factual claim verification',
                'model' => config('services.openai.model'), 'version' => self::VERSION,
                'failure_reason' => null, 'result' => $analysis['fact_check_result'],
            ];
            $incomplete = array_filter($analysis['fact_check_result']['diagnostics']['model_stages'] ?? [], fn ($status) => $status !== 'completed');
            if ($incomplete !== []) {
                $claim['status'] = 'partial';
                $claim['failure_reason'] = 'Some factual-analysis stages could not complete: '.implode(', ', array_keys($incomplete)).'.';
            }
        } catch (\Throwable $error) {
            if (! in_array($primary, ['media', 'manipulation', 'clarification'], true) && ! in_array('media', $tasks, true)) {
                throw $error;
            }
            Log::warning('Optional claim verification failed.', ['analysis_id' => $context['analysis_id'], 'error_type' => $error::class]);
            $analysis = $original;
            $claim = ['status' => 'unavailable', 'task' => 'Factual claim verification', 'model' => config('services.openai.model'),
                'version' => self::VERSION, 'result' => null, 'failure_reason' => 'Optional text analysis could not finish. The media result is independent.'];
            if (in_array($primary, ['claim', 'context'], true)) {
                $analysis['fact_check_result'] = ReportAnswer::complete([
                    'schema_version' => 4, 'primary_task' => $primary, 'verdict' => 'ANALYSIS_UNAVAILABLE',
                    'summary' => 'Factual verification could not be completed. The media finding is shown separately; recheck the submission to retry the factual assessment.',
                    'score' => AssessmentScore::unavailable('No percentage is available for an incomplete assessment.'),
                ]);
            }
        }
        $claim['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        $claim['assessed_at'] = now()->utc()->toIso8601String();
        $media = $original['media_assessment'] ?? null;
        // Readable labels/watermarks alone do not force a bare photograph into factual verification.
        if ($explicitTasks === [] && ($request['analysis_task'] ?? 'auto') === 'auto' && $question === '' && $media
            && ($analysis['fact_check_result']['verdict'] ?? '') === 'OPINION_NOT_FACT_CHECKABLE') {
            $primary = 'media';
        }
        if (in_array($primary, ['media', 'manipulation', 'clarification'], true)) {
            $media ??= ['status' => 'unavailable', 'media_type' => $context['media_type'], 'task' => 'AI-generation screening',
                'failure_reason' => 'No media detector output was recorded. Retry the analysis.'];
            $result = MediaAnalysisReport::result($media, $claim);
            if ($primary === 'manipulation' || $primary === 'clarification') {
                $result = MediaAnalysisReport::unsupportedTask($primary, $result);
            }
            $analysis['fact_check_result'] = $result;
            $analysis['analysis_summary'] = $analysis['explanation_summary'] = $result['summary'];
            $analysis['verification_summary'] = 'Media screening and optional factual verification are recorded separately.';
            $analysis['recommendation'] = 'Check the original source and provenance before relying on this image.';
            // A low AI-generation signal is not an authenticity or factual "real" verdict.
            $analysis['verdict'] = $result['verdict'] === 'LIKELY_AI_GENERATED' ? 'fake' : 'review';
        } else {
            $analysis['fact_check_result']['primary_task'] = $primary;
            $analysis['fact_check_result']['media_assessment'] = $media;
            $analysis['fact_check_result']['claim_assessment'] = $claim;
        }
        $result = $analysis['fact_check_result'];
        $result['request'] = array_replace($resolved, ['tasks' => $tasks ?: [$primary], 'resolved_task' => $primary]);
        $result['secondary_assessments'] = [];
        if ($primary === 'context' && isset($analysis['context_factual_secondary'])) {
            $result['secondary_assessments'][] = $analysis['context_factual_secondary'];
        }
        foreach ($tasks as $task) {
            if ($task === $primary || ($task === 'claim' && $primary === 'context')) {
                continue;
            }
            $secondary = match ($task) {
                'media' => MediaAnalysisReport::result($media ?? ['status' => 'unavailable'], $claim),
                'manipulation' => MediaAnalysisReport::unsupportedTask('manipulation', MediaAnalysisReport::result($media ?? ['status' => 'unavailable'], $claim)),
                'context' => $this->secondaryContext($claim['result'] ?? null, $question),
                default => $claim['result'] ?? null,
            };
            if ($secondary) {
                $result['secondary_assessments'][] = Arr::only(ReportAnswer::complete($secondary), [
                    'schema_version', 'verdict_version', 'processing_status', 'primary_task', 'verdict', 'assessment_label',
                    'summary', 'score', 'score_display', 'answer_segments', 'sources', 'contextual_sources', 'limitations',
                ]);
            }
        }
        $result = ReportAnswer::complete($result);
        if (isset($result['diagnostics']) && is_array($result['diagnostics'])) {
            $result['diagnostics']['analysis_id'] = $context['analysis_id'];
        }
        $analysis['fact_check_result'] = $result;
        $analysis['analysis_summary'] = $analysis['explanation_summary'] = $result['summary'];

        // Automatic media routing may skip applyClaims after OCR. Keep the
        // actual extraction outcome so API failures and usage remain auditable.
        if (is_array($extraction ?? null)) {
            $analysis['signals']['submission_extraction'] = [$extraction];
        }

        return $analysis;
    }

    private function secondaryContext(?array $claimResult, string $question = ''): ?array
    {
        if (! $claimResult) {
            return null;
        }
        $finding = $this->contextFinding($claimResult['context_assessments'] ?? [], $question);
        $verdict = $finding['label'] ?? 'UNVERIFIED';
        $summary = $finding['explanation'] ?? 'No reviewed passage established that the submitted date, caption, location or reuse claim was accurate or misleading.';
        $sourceIds = $finding ? [$finding['source_id']] : [];

        return ['schema_version' => 4, 'primary_task' => 'context', 'verdict' => $verdict,
            'assessment_label' => AssessmentVerdict::label($verdict), 'summary' => $summary,
            'score' => AssessmentScore::unavailable('No validated percentage is available for this context assessment.'),
            'sources' => array_values(array_filter($claimResult['sources'] ?? [], fn ($source) => in_array($source['id'] ?? null, $sourceIds, true))),
            'answer_segments' => [['text' => $summary, 'source_ids' => $sourceIds]], 'ui' => ['verdict_color' => AssessmentVerdict::color($verdict)]];
    }

    private function contextFinding(array $findings, string $question): ?array
    {
        // Choose among already validated findings; the question cannot create
        // evidence or change the factual verdict. Preserve all other findings.
        $patterns = ['OUT_OF_CONTEXT' => '/out of context|wrong (?:caption|date|location)|misattributed|reused|recycled|konteksto/iu',
            'SATIRE_PARODY' => '/satire|satirical|parody|parodiya/iu',
            'OUTDATED' => '/outdated|current|still valid|expired|luma/iu',
            'MISLEADING' => '/misleading|mapanlinlang/iu'];
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $question) && ($finding = collect($findings)->firstWhere('label', $label))) {
                return $finding;
            }
        }

        return $findings[0] ?? null;
    }

    private function applyClaims(array $analysis, array $context, ?UploadedFile $file, ?callable $progress, array $extraction, int $extractionRequests): array
    {
        $started = microtime(true);
        $this->model->reset();
        $this->judge->reviewScopes = [];
        $this->judge->contextFindings = [];
        $this->judge->contextualSources = [];
        $cutoff = $this->policy->date($context['evidence_cutoff'] ?? null) ?? now()->utc()->toIso8601String();
        $cutoff = ClaimPlanner::cutoff($context['question'] ?? '', $cutoff);
        $historicalCutoff = Carbon::parse($cutoff)->lt(now()->subMinute());
        $budget = new RetrievalBudget;
        if (($context['primary_task'] ?? '') === 'media' && trim($extraction['analysis_text']) === '') {
            $analysis['claim_assessment'] = [
                'status' => $extraction['status'] === 'completed' ? 'not_applicable' : 'unavailable',
                'task' => 'Factual claim verification', 'model' => null, 'version' => self::VERSION, 'result' => null,
                'failure_reason' => $extraction['status'] === 'completed' ? null : 'Optional text extraction was unavailable or incomplete.',
                'reason' => 'No readable factual statement was supplied or extracted. This does not prevent media screening.', 'extraction' => $extraction,
            ];

            return $analysis;
        }
        $progress && $progress('Identifying checkable statements');
        $plan = $this->planner->plan($extraction['analysis_text'], [
            'media_attached' => $file !== null && $file->isValid(), 'media_type' => $context['media_type'] ?? null,
            'input_scope' => $extraction['input_scope'],
            'question' => $context['question'] ?? '', 'requested_task' => $context['primary_task'] ?? 'claim',
            'context_requested' => in_array('context', $context['resolved_request']['tasks'] ?? [], true)
                || ($context['primary_task'] ?? '') === 'context',
        ]);
        $planningLog = ['analysis_id' => $context['analysis_id'], 'claim_count' => count($plan['claims']),
            'factual_claim_count' => count(array_filter($plan['claims'], fn ($claim) => $claim['kind'] === 'factual')),
            'plan_status' => $plan['status'] ?? 'unknown'];
        if (app()->environment('local', 'testing')) {
            $planningLog['claims'] = array_column($plan['claims'], 'text');
        }
        Log::info('Evidence claims planned.', $planningLog);
        if (in_array($extraction['status'], ['uncertain_ocr', 'incomplete', 'input_too_long'], true)) {
            foreach ($plan['claims'] as &$claim) {
                if (str_contains($claim['text'], '[unclear]') || $extraction['status'] === 'input_too_long') {
                    $claim['requires_context'] = true;
                    $claim['context_reason'] = 'Confirm the unreadable words in this statement; they could change its meaning.';
                }
            }
            unset($claim);
        }
        $classifierStarted = microtime(true);
        $model = $this->classifier->inspect($extraction['analysis_text'], $extraction['input_scope']);
        $model['elapsed_ms'] = (int) round((microtime(true) - $classifierStarted) * 1000);
        $records = [];
        $submittedPage = $extraction['source_record'];
        if (is_array($submittedPage) && ($submittedPage['status'] ?? '') === 'retrieved') {
            $key = $this->search->canonicalUrl($submittedPage['url']);
            if ($key) {
                $records[$key] = $this->search->reviewedSubmission($submittedPage, $cutoff);
            }
        }
        $relationships = [];
        // Search budgets exclude extraction/model time. All article requests run sequentially (concurrency 1).
        $budget = new RetrievalBudget;
        $budget->pages = $extractionRequests;
        $budget->historicalCutoff = $historicalCutoff;
        $budget->researchContext = ClaimPlanner::researchContext($plan['claims'], ($context['question'] ?? '') ?: trim(implode(' ', array_filter([
            $extraction['submitted_text'] ?? '', $extraction['submitted_notes'] ?? '',
        ]))), $extraction['analysis_text'], $cutoff);
        $currentStatusQuestion = collect($plan['claims'])->contains(fn ($claim) => ($claim['current_status_question'] ?? false) === true);
        $budget->freshSources = $currentStatusQuestion;
        for ($round = 0; $round < min(2, max(1, (int) config('fact_check.retrieval.max_rounds', 2))); $round++) {
            $queries = $this->planner->queries($plan['claims'], $round);
            if ($queries === []) {
                break;
            }
            $progress && $progress('Searching relevant sources');
            $reserveNextRound = $round === 0 && (int) config('fact_check.retrieval.max_rounds', 2) > 1;
            $candidateCeiling = $reserveNextRound && $currentStatusQuestion
                ? max(count($records), (int) ceil(config('fact_check.retrieval.max_candidates', 40) / 2)) : null;
            $records = $this->search->retrieve($queries, $budget, $cutoff, $records, $reserveNextRound, $candidateCeiling);
            $roundLog = ['analysis_id' => $context['analysis_id'], 'round' => $round,
                'provider_statuses' => $budget->providers, 'search_attempts' => count($budget->attempts),
                'provider_candidates' => $budget->found, 'unique_candidates' => count($records),
                'retrieved_documents' => count(array_filter($records, fn ($record) => ($record['retrieval_status'] ?? '') === 'retrieved'))];
            if (app()->environment('local', 'testing')) {
                $roundLog['queries'] = $queries;
                $roundLog['attempts'] = $budget->attempts;
                $roundLog['page_outcomes'] = $budget->pageOutcomes;
            }
            Log::info('Evidence retrieval round completed.', $roundLog);
            $domains = array_values(array_unique(array_filter(array_map(fn ($record) => ($record['retrieval_status'] ?? '') === 'retrieved'
                ? parse_url($record['url'], PHP_URL_HOST) : null, $records))));
            $progress && $progress('Checking dates and official records', array_slice($domains, 0, 8));
            $reviewStarted = microtime(true);
            $judged = $this->judge->assess($plan['claims'], $records, $cutoff, $round, Arr::only($context, ['question', 'primary_task', 'media_attached']));
            $budget->excludeAnalysisTime(microtime(true) - $reviewStarted);
            // A later search must not erase a conflicting source from the first round.
            $relationships = $this->judge->mergeReviews($relationships, $judged);
            $outcomes = $this->judge->outcomes($plan['claims'], $relationships);
            // Stop on coverage, regardless of whether the evidence supports or refutes the claims.
            $unresolved = array_filter($outcomes, fn ($c) => ($c['kind'] === 'factual'
                || (in_array($c['kind'], ['rhetorical', 'unclear'], true) && ($c['searches'] ?? []) !== []))
                && in_array($c['outcome'], ['insufficient', 'conflicting', 'needs_context'], true));
            $checkLaterStatus = $round === 0 && $currentStatusQuestion;
            if (($unresolved === [] && ! $checkLaterStatus) || $budget->remaining() < 1) {
                break;
            }
        }
        $claims = $this->judge->outcomes($plan['claims'], $relationships);
        $photoQuestion = ($context['media_type'] ?? '') === 'image'
            && SubmissionTask::questionClaim($context['question'] ?? '') !== ''
            && (trim($extraction['visible_text'] ?? '') === ''
                || preg_match('/\b(?:this|that|pictured|shown)\s+(?:old\s+|elderly\s+)?(?:woman|man|lady|person|people)\b/iu', $context['question'] ?? ''));
        if ($photoQuestion) {
            foreach ($claims as &$claim) {
                if (($claim['kind'] ?? '') === 'factual' && ($claim['outcome'] ?? '') === 'needs_context') {
                    // Unknown provenance or an unidentified pictured person is
                    // an unresolved photo claim, not another user question.
                    $claim['outcome'] = 'insufficient';
                }
            }
            unset($claim);
        }
        $relationships = array_merge([], ...array_column($claims, 'relationships'));
        foreach ($this->model->stages as $stage => $status) {
            if ($status !== 'completed') {
                $extraction['limitations'][] = 'Analysis stage '.str_replace('_', ' ', $stage).' was incomplete ('.str_replace('_', ' ', $status).').';
            }
        }
        foreach ($budget->providers as $provider => $status) {
            if (! in_array($status, ['ok', 'cached', 'not_configured'], true)) {
                $extraction['limitations'][] = 'Discovery coverage was limited because '.str_replace('_', ' ', $provider).' reported '.str_replace('_', ' ', $status).'.';
            }
        }
        foreach ($records as &$record) {
            $record = array_replace($record, $this->judge->reviewScopes[$record['id']] ?? []);
            $related = array_values(array_filter($relationships, fn ($r) => $r['source_id'] === $record['id']));
            $contextual = collect($this->judge->contextualSources)->contains(fn ($finding) => $finding['source_id'] === $record['id']);
            $record['assessment_role'] = collect($related)->contains(fn ($r) => $r['stance'] !== 'insufficient' && ! $r['superseded']) ? 'decisive'
                : (collect($related)->contains(fn ($r) => $r['superseded']) ? 'superseded' : ($contextual ? 'related_context' : ($related !== [] ? 'background_or_rejected_match' : 'not_used')));
            $record['relevance_assessments'] = $related;
        }
        unset($record);
        $result = $this->report($claims, $records, $relationships, $extraction, $model, $cutoff, $plan['original_claim_date']);
        $result['media_assessment'] = $analysis['media_assessment'] ?? null;
        $usage = $this->model->usage;
        foreach (array_keys($usage) as $key) {
            $usage[$key] += max(0, (int) ($extraction['usage'][$key] ?? 0));
        }
        $result['diagnostics'] = $budget->diagnostics() + ['model_stages' => $this->model->stages, 'api_usage' => $usage,
            'research_context' => $budget->researchContext,
            'model_request_attempts' => $this->model->requestAttempts,
            'analysis_elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'pipeline_version' => self::VERSION,
            'unique_candidates' => count($records),
            'articles_retrieved' => count(array_filter($records, fn ($r) => ($r['retrieval_status'] ?? '') === 'retrieved')),
            'sources_reviewed' => count(array_unique(array_merge(array_column($relationships, 'source_id'),
                array_column($this->judge->contextualSources, 'source_id')))),
            'sources_used' => count($result['sources']), 'candidate_records' => array_values($records),
            'filter_rejections' => $this->filterRejections($records)];
        $result['official_references'] = array_values(array_map(
            fn ($record) => Arr::only($record, ['title', 'url', 'name']),
            array_filter($records, fn ($record) => ($record['source_type'] ?? '') === 'official_government'
                && ($record['retrieval_status'] ?? '') === 'reference_only'
                && ReportAnswer::safeUrl($record['url'] ?? null) !== null)
        ));
        $result = $this->explainOutcome($result, $plan, $budget);
        if ($result['verdict'] === 'MISLEADING') {
            $finding = collect($result['context_assessments'])->firstWhere('label', 'MISLEADING');
            if ($finding) {
                $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => [$finding['source_id']]]];
            }
        }
        if ($photoQuestion && $result['verdict'] === 'UNVERIFIED') {
            $result['summary'] = 'The event described in your question could not be verified for this photograph. No matching source established the identity or event depicted in the submitted image. This does not prove the claim is false or the image is AI-generated.';
            if (($result['diagnostics']['retrieval_status'] ?? '') === 'partial_failure') {
                $result['summary'] .= ' Some source searches could not complete, so coverage was incomplete.';
            }
            $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => []]];
            $result['evidence_strength']['reason'] = $result['summary'];
        }
        $result['primary_task'] = 'claim';
        $result['request'] = array_replace($context['resolved_request'] ?? [], ['question' => $context['question'] ?? '']);
        $result['score'] = AssessmentScore::evidence($result, $records);
        $progress && $progress('Preparing the answer');
        $result = ReportAnswer::complete($result);
        if (($context['primary_task'] ?? '') === 'context') {
            if (collect($result['claims'])->contains(fn ($claim) => ($claim['kind'] ?? null) === 'factual')
                || in_array($result['verdict'], ['OPINION_NOT_FACT_CHECKABLE', 'MISSING_CONTEXT'], true)) {
                $analysis['context_factual_secondary'] = Arr::only($result,
                    ['primary_task', 'verdict', 'assessment_label', 'summary', 'score', 'score_display', 'answer_segments', 'sources']);
            }
            $result['primary_task'] = 'context';
            $finding = $this->contextFinding($result['context_assessments'] ?? [], $context['question'] ?? '');
            $contextSourceIds = [];
            if ($finding && ! AssessmentVerdict::technical($result['verdict'])) {
                $result['verdict'] = $finding['label'];
                $result['summary'] = $finding['explanation'];
                $contextSourceIds = array_values(array_intersect([$finding['source_id']], array_column($result['sources'], 'id')));
            } elseif (! AssessmentVerdict::technical($result['verdict']) && $result['verdict'] !== 'MISSING_CONTEXT') {
                $result['verdict'] = 'UNVERIFIED';
                $result['summary'] = 'I could not determine whether the submitted content is misleading or out of context. The reviewed sources did not establish its original event, date, location, or reuse context. The factual wording is assessed separately.';
                $result['ui']['show_missing_context'] = false;
                $result['ui']['missing_context'] = null;
                $result['ui']['show_evidence_warning'] = true;
                $result['ui']['evidence_warning'] = $result['summary'];
            }
            $result['score'] = AssessmentScore::unavailable('A factual evidence score does not measure whether the submitted material was reused or presented out of context.');
            $result['evidence_strength'] = ['label' => $finding ? 'Context evidence' : 'Context unverified',
                'reason' => $result['summary'], 'method' => 'Context outcomes require a validated matching passage.'];
            $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => $contextSourceIds]];
            $result = ReportAnswer::complete($result);
        }
        $result['source_snapshot_id'] = hash('sha256', json_encode($records, JSON_THROW_ON_ERROR));
        $key = hash('sha256', json_encode(['version' => self::VERSION, 'pipeline_identity' => EvidenceVerdictCalibration::identity(),
            'verdict_calibration' => config('fact_check.verdict_calibration.enabled', false) ? config('fact_check.verdict_calibration.sha256', '') : 'disabled',
            'score_version' => AssessmentScore::RUBRIC,
            'request' => Arr::only($context, ['primary_task', 'question']), 'claim' => $result['claim'], 'claims' => $claims, 'cutoff' => $cutoff, 'snapshot' => $result['source_snapshot_id'],
            'analysis_model' => config('services.openai.model'), 'trained_model' => Arr::except($model, ['elapsed_ms']),
            'media' => $result['media_assessment']], JSON_THROW_ON_ERROR));
        $currentDiagnostics = $result['diagnostics'];
        $canonical = CanonicalFactCheckResult::firstOrCreate(['canonical_key' => $key], ['result' => $result]);
        $result = $canonical->result;
        $result['diagnostics'] = array_replace($result['diagnostics'] ?? [], $currentDiagnostics,
            ['analysis_id' => $context['analysis_id'], 'canonical_cache_hit' => ! $canonical->wasRecentlyCreated]);
        Log::info('Evidence analysis persisted.', ['analysis_id' => $context['analysis_id'],
            'canonical_cache_hit' => ! $canonical->wasRecentlyCreated,
            'candidate_count' => count($records), 'evidence_record_count' => count($result['sources'] ?? []),
            'verdict' => $result['verdict']]);
        $analysis['fact_check_result'] = $result;
        $analysis['analysis_summary'] = $result['summary'];
        $analysis['explanation_summary'] = $result['summary'];
        $analysis['verification_summary'] = implode(' ', $result['key_findings']);
        $analysis['recommendation'] = $result['ui']['missing_context'] ?? $result['ui']['evidence_warning'] ?? 'Review the quoted evidence and its dates before sharing.';
        $analysis['verdict'] = match ($result['verdict']) {
            'TRUE' => 'real', 'FALSE' => 'fake', default => 'review'
        };
        $analysis['verification_sources'] = array_values($records);
        $analysis['signals']['openai_usage'] = $usage;
        $analysis['signals']['evidence_pipeline'] = [['label' => $result['assessment_label'], 'weight' => 0, 'version' => self::VERSION]];
        $analysis['signals']['model_assessment'] = [$model];
        $analysis['signals']['submission_extraction'] = [$extraction];

        return $analysis;
    }

    private function filterRejections(array $records): array
    {
        $reasons = [];
        foreach ($records as $record) {
            $status = $record['retrieval_status'] ?? 'unknown';
            $reason = match (true) {
                $status !== 'retrieved' => $status,
                ($record['source_type'] ?? '') === 'unapproved' => 'unapproved_publisher',
                ($record['assessment_role'] ?? '') === 'background_or_rejected_match' => 'insufficient_relevance',
                ($record['assessment_role'] ?? '') === 'not_used' => 'not_assessed',
                default => null,
            };
            if ($reason !== null) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        return $reasons;
    }

    private function explainOutcome(array $result, array $plan, RetrievalBudget $budget): array
    {
        $archiveLimited = count(array_filter($budget->attempts, fn ($a) => $a['status'] === 'archive_limited')) > 0;
        $failures = array_filter($budget->attempts, fn ($a) => ! in_array($a['status'], ['ok', 'cached', 'archive_limited'], true));
        $successful = count($budget->attempts) - count($failures);
        $rawFound = array_sum(array_column($budget->attempts, 'raw_results'));
        $notConfigured = $budget->providers !== [] && count(array_filter($budget->providers, fn ($s) => $s === 'not_configured')) === count($budget->providers);
        $disabled = ($budget->providers['discovery'] ?? null) === 'disabled';
        $reviewStages = array_filter($this->model->stages, fn ($stage) => str_starts_with($stage, 'evidence_relationships_'), ARRAY_FILTER_USE_KEY);
        $reviewUnavailable = $reviewStages !== []
            && count(array_filter($reviewStages, fn ($status) => $status !== 'completed')) === count($reviewStages)
            && ($result['diagnostics']['articles_retrieved'] ?? 0) > 0
            && ($result['sources'] ?? []) === [] && ($result['contextual_sources'] ?? []) === [];
        $reason = match (true) {
            trim($result['extraction']['analysis_text'] ?? '') === '' && in_array($result['extraction']['status'], ['unavailable', 'media_extraction_failed', 'unsupported_or_missing_media'], true) => 'extraction_unavailable',
            trim($result['extraction']['analysis_text'] ?? '') === '' => 'unreadable_content',
            ($plan['status'] ?? 'completed') === 'unavailable' => 'analysis_failure',
            $disabled => 'retrieval_disabled',
            $notConfigured => 'no_provider_configured',
            $failures !== [] && $successful === 0 && ($result['sources'] ?? []) === [] && ($result['contextual_sources'] ?? []) === [] => 'api_failure',
            $reviewUnavailable => 'analysis_failure',
            $result['ui']['show_missing_context'] => 'missing_context',
            $result['sources'] !== [] => $result['ui']['show_source_conflict'] ? 'conflicting_evidence' : 'matched_evidence',
            $failures !== [] => 'partial_api_failure',
            $budget->searches === 0 => 'retrieval_not_run',
            $archiveLimited && $rawFound === 0 => 'archive_limited',
            $rawFound === 0 => 'no_search_results',
            $budget->found === 0 || $result['diagnostics']['unique_candidates'] === 0 => 'no_usable_candidates',
            $result['diagnostics']['articles_retrieved'] === 0 => 'source_text_unavailable',
            array_filter($this->model->stages, fn ($s) => $s !== 'completed') !== [] => 'analysis_failure',
            default => 'no_matching_evidence',
        };
        $result['diagnostics']['outcome_reason'] = $reason;
        $result['diagnostics']['retrieval_status'] = match (true) {
            $disabled => 'disabled',
            $notConfigured => 'not_configured',
            $budget->searches === 0 => 'not_run',
            $failures !== [] && $successful === 0 => 'failed',
            $failures !== [] => 'partial_failure',
            $archiveLimited && $rawFound === 0 => 'completed_access_limited',
            $rawFound === 0 => 'completed_no_results',
            $budget->found === 0 || $result['diagnostics']['unique_candidates'] === 0 => 'completed_no_usable_candidates',
            default => 'completed',
        };
        $claimLead = $this->claimLead($result['claims'] ?? []);
        $summary = match ($reason) {
            'extraction_unavailable' => 'I could not read the submitted content because text extraction was unavailable. Retry or supply the statement as text; no factual conclusion was reached.',
            'unreadable_content' => 'No readable factual content could be extracted. Supply a clearer original or paste the statement to verify. This is an input limitation, not a finding that the content is false.',
            'analysis_failure' => 'The factual analysis could not finish because an analysis service failed or returned an unusable response. This is not a finding that the post is false or lacks context.',
            'retrieval_disabled' => 'I could not verify this statement because source discovery is disabled. No factual conclusion was reached.',
            'no_provider_configured' => 'I could not verify this statement because no source-search provider is configured. No factual conclusion was reached.',
            'api_failure' => $claimLead.'I could not verify it because the source-search services failed. Retry verification when those services are available.',
            'partial_api_failure' => $claimLead.'I could not verify it with the evidence retrieved. Some source searches failed, so coverage was incomplete.',
            'archive_limited' => $claimLead.'The searches returned no accessible source documents. GNews reported that its account archive limit removed matching articles; no factual conclusion was reached.',
            'no_search_results' => $claimLead.'The configured source searches completed but returned no indexed results within their available coverage. This does not establish whether the claim is true or false.',
            'no_usable_candidates' => $claimLead.'The search responses contained no usable article links, so no source document was reviewed.',
            'source_text_unavailable' => $claimLead.'The discovered source pages could not be read. Search snippets alone are not sufficient evidence.',
            'no_matching_evidence' => $claimLead.'The reviewed sources did not directly support or refute the complete claim. Related topics alone are not decisive evidence.',
            default => $result['summary'],
        };
        if ($reason === 'missing_context' && $budget->searches > 0) {
            $unclear = collect($result['claims'] ?? [])->first(fn ($claim) => ($claim['outcome'] ?? null) === 'needs_context');
            $wording = Str::limit(Str::squish((string) ($unclear['text'] ?? $result['claim'] ?? '')), 180, '…');
            $missing = trim((string) ($unclear['context_reason'] ?? ''));
            $summary = 'I searched for records about “'.$wording.'”. The submitted wording still needs clarification before a factual outcome can be judged.'
                .($missing !== '' ? ' '.$missing : '');
            $contextual = $result['contextual_sources'][0] ?? null;
            if ($contextual) {
                $date = substr((string) ($contextual['published_at'] ?? $contextual['updated_at']), 0, 10);
                $contextText = 'A related source dated '.$date.' states: “'.$contextual['excerpt'].'”. This provides context for the topic, not a verdict on the submitted headline.';
                $result['answer_segments'] = [
                    ['text' => $summary, 'source_ids' => []],
                    ['text' => $contextText, 'source_ids' => [$contextual['id']]],
                ];
                $summary .= ' '.$contextText;
            }
        }
        if (in_array($reason, ['analysis_failure', 'extraction_unavailable', 'api_failure', 'unreadable_content', 'retrieval_disabled', 'no_provider_configured'], true)) {
            $result['verdict'] = $reason === 'unreadable_content' ? 'UNREADABLE_CONTENT' : 'ANALYSIS_UNAVAILABLE';
            $result['assessment_label'] = AssessmentVerdict::label($result['verdict']);
            $result['evidence_strength']['label'] = 'Unavailable';
            $result['ui']['show_missing_context'] = false;
            $result['ui']['missing_context'] = null;
        }
        if ($reason === 'matched_evidence' && count($result['claims']) === 1) {
            $decisive = array_filter($result['claims'][0]['relationships'], fn ($r) => in_array($r['stance'], ['supporting', 'contradicting'], true) && ! ($r['superseded'] ?? false));
            $explanations = array_filter(array_column($decisive, 'explanation'));
            if ($explanations !== []) {
                $summary = implode(' ', array_slice(SubmittedText::sentences(reset($explanations)), 0, 2));
            }
        }
        $result['summary'] = $summary;
        $result['evidence_strength']['reason'] = $summary;

        return $result;
    }

    private function claimLead(array $claims): string
    {
        $claim = collect($claims)->first(fn ($row) => ($row['kind'] ?? null) === 'factual');
        if (! $claim) {
            return '';
        }
        $text = (string) ($claim['text'] ?? '');
        foreach ($claim['references'] ?? [] as $reference) {
            if (filled($reference['phrase'] ?? null) && filled($reference['referent'] ?? null)) {
                $text = str_replace($reference['phrase'], $reference['referent'], $text);
            }
        }
        $text = rtrim(Str::limit(Str::squish($text), 260, '…'), " .!?\t\r\n");

        return $text === '' ? '' : 'I checked the submitted claim: '.$text.'. ';
    }

    private function report(array $claims, array $records, array $relationships, array $extraction, array $model, string $cutoff, ?string $claimDate): array
    {
        $outcomes = array_column($claims, 'outcome');
        $context = in_array('needs_context', $outcomes, true);
        $conflict = in_array('conflicting', $outcomes, true);
        $insufficient = in_array('insufficient', $outcomes, true);
        $support = in_array('supported', $outcomes, true);
        $refute = in_array('refuted', $outcomes, true);
        [$verdict, $label, $summary] = match (true) {
            $conflict => ['CONFLICTING_EVIDENCE', 'Conflicting Evidence', 'Relevant sources disagree about the same claim. The retrieved records do not resolve that conflict.'],
            $context => ['MISSING_CONTEXT', 'Missing Context', 'The submitted wording does not provide enough context for a specific factual assessment.'],
            $insufficient => ['UNVERIFIED', 'Unverified', 'The reviewed evidence does not establish every factual claim. Missing evidence is not evidence that a claim is false.'],
            $support && $refute => ['PARTLY_TRUE', 'Partly True', 'The evidence supports some specific statements and contradicts others. Review the individual claim findings below.'],
            $support => ['TRUE', 'True', 'The retrieved evidence supports the factual statement'.(count($claims) === 1 ? '' : 's').' assessed below, within the recorded scope and cutoff.'],
            $refute => ['FALSE', 'False', 'The retrieved evidence contradicts the factual statement'.(count($claims) === 1 ? '' : 's').' assessed below, within the recorded scope and cutoff.'],
            default => ['OPINION_NOT_FACT_CHECKABLE', 'Opinion / Not Fact-checkable', 'No checkable factual assertion was identified in the submitted wording. Opinions and satire can still contain factual statements; supply the specific assertion if one was missed.'],
        };
        // An inaccurate impression needs positive contextual evidence. A lack
        // of search hits or an AI-origin signal cannot establish misleadingness.
        $misleading = collect($this->judge->contextFindings)->firstWhere('label', 'MISLEADING');
        if ($misleading && ! $conflict && ! $context) {
            $verdict = 'MISLEADING';
            $label = AssessmentVerdict::label($verdict);
            $summary = $misleading['explanation'];
        }
        $used = [];
        $limitations = $extraction['limitations'];
        foreach ($claims as $claim) {
            if ($claim['requires_context']) {
                $limitations[] = $claim['context_reason'];
            }
            foreach ($claim['relationships'] as $relationship) {
                if (isset($relationship['validation_limitation'])) {
                    $limitations[] = $relationship['validation_limitation'];
                }
            }
        }
        foreach ($relationships as $relationship) {
            if ($relationship['stance'] === 'insufficient' || ($relationship['superseded'] ?? false)) {
                continue;
            }
            $record = collect($records)->firstWhere('id', $relationship['source_id']);
            if (! $record) {
                continue;
            }
            $id = $record['id'];
            $used[$id] ??= Arr::only($record, ['id', 'name', 'title', 'url', 'published_at', 'updated_at', 'retrieved_at', 'possible_copy_of']) + ['type' => $record['source_type'], 'used_for' => '', 'relationships' => []];
            $used[$id]['relationships'][] = Arr::only($relationship, ['claim_id', 'stance', 'excerpt', 'explanation', 'supersedes_source_ids', 'supersession_excerpt']);
            $used[$id]['used_for'] = implode(' ', array_unique(array_column($used[$id]['relationships'], 'explanation')));
            if (! $record['published_at']) {
                $limitations[] = 'A cited article has no available publication date; its historical timing cannot be established.';
            }
            if ($record['text_truncated'] || ($record['review_text_truncated'] ?? false)) {
                $limitations[] = 'A long source was reviewed only within the recorded text limit; its remaining content was not assessed.';
            }
            if (($record['content_type'] ?? '') === 'application/pdf') {
                $limitations[] = 'PDF evidence extraction covers at most the first 30 pages of selectable text; scanned pages and later pages may be missing.';
            }
        }
        foreach ($this->judge->contextFindings as $finding) {
            $record = collect($records)->firstWhere('id', $finding['source_id']);
            if ($record) {
                $used[$record['id']] ??= Arr::only($record, ['id', 'name', 'title', 'url', 'published_at', 'updated_at', 'retrieved_at', 'possible_copy_of'])
                    + ['type' => $record['source_type'], 'used_for' => $finding['explanation'], 'relationships' => []];
            }
        }
        $contextualSources = [];
        foreach ($this->judge->contextualSources as $finding) {
            $record = collect($records)->firstWhere('id', $finding['source_id']);
            if (! $record) {
                continue;
            }
            $contextualSources[$record['id']] = Arr::only($record,
                ['id', 'name', 'title', 'url', 'published_at', 'updated_at', 'retrieved_at']) + [
                    'type' => $record['source_type'], 'claim_id' => $finding['claim_id'],
                    'excerpt' => $finding['excerpt'], 'explanation' => $finding['explanation'],
                    'context_only' => true,
                ];
        }
        if ($model['status'] !== 'completed') {
            $limitations[] = 'The trained article classifier did not contribute a prediction ('.str_replace('_', ' ', $model['status']).').';
        }
        if ($model['prediction'] && ! $model['prediction']['truncation_reported']) {
            $limitations[] = 'The running classifier did not report input coverage or truncation; its prediction cannot be treated as a complete assessment.';
        }
        $prediction = $model['prediction']['verdict'] ?? null;
        $model['disagrees_with_evidence'] = ($verdict === 'TRUE' && $prediction === 'fake') || ($verdict === 'FALSE' && $prediction === 'real');
        if ($model['disagrees_with_evidence']) {
            $limitations[] = 'The article-style classifier disagrees with the retrieved evidence. The factual verdict follows the cited evidence; the model output is retained separately.';
        }
        $limitations = array_values(array_unique(array_filter($limitations)));
        $warning = in_array($verdict, ['UNVERIFIED', 'MISSING_CONTEXT', 'CONFLICTING_EVIDENCE'], true) || $limitations !== [];
        $claimText = $extraction['reconstructed_text'] ?? $extraction['original_text'];
        $official = $this->policy->isSensitive($claimText);
        $strength = match (true) {
            $conflict => 'Conflicting', $context => 'Context needed', $verdict === 'UNVERIFIED' => 'Insufficient', default => 'Direct evidence'
        };
        $contextReason = collect($claims)->where('outcome', 'needs_context')->pluck('context_reason')->filter()->unique()->implode(' ');
        if ($context && $contextReason !== '') {
            $summary .= ' '.$contextReason;
        }

        return [
            'schema_version' => 4, 'claim' => $claimText, 'normalized_claim' => $this->policy->normalizeClaim($claimText),
            'context_assessments' => $this->judge->contextFindings,
            'verdict' => $verdict, 'assessment_label' => $label, 'confidence' => null,
            'evidence_strength' => ['label' => $strength, 'reason' => $summary, 'method' => 'Categorical assessment of validated claim–source relationships; not a calibrated probability or measured accuracy.'],
            'summary' => $summary, 'claims' => $claims, 'sources' => array_values($used),
            'contextual_sources' => array_values($contextualSources),
            'key_findings' => array_map(fn ($c) => ucfirst(str_replace('_', ' ', $c['outcome'])).': '.$c['text'], $claims),
            'limitations' => $limitations, 'evidence_cutoff' => $cutoff, 'assessed_at' => now()->utc()->toIso8601String(),
            'original_claim_date' => $claimDate, 'trained_model' => $model, 'extraction' => $extraction,
            'ui' => ['verdict_color' => match ($verdict) {
                'TRUE' => 'green', 'FALSE' => 'red', 'PARTLY_TRUE' => 'amber', default => 'gray'
            },
                'show_evidence_warning' => $warning, 'evidence_warning' => $warning ? ($limitations[0] ?? $summary) : null,
                'show_missing_context' => $context, 'missing_context' => $context ? ($contextReason ?: 'Provide the exact statement, its original source, and the relevant date so the submitted content can be assessed.') : null,
                'show_source_conflict' => $conflict, 'source_conflict_message' => $conflict ? 'Supporting and contradicting evidence address the same claim; no explicit superseding record resolves the conflict.' : null,
                'show_official_verification_notice' => $official, 'official_verification_notice' => $official ? 'Consult the relevant original authority for decisions that depend on this claim.' : null,
                'highlight_evidence_cutoff' => $this->policy->isTimeSensitive($claimText), 'source_display_order' => FactCheckEvidencePolicy::SOURCE_ORDER],
        ];
    }
}
