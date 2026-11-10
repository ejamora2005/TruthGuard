<?php

namespace App\Services\Detections;

use App\Models\Detection;
use App\Services\Detections\Evidence\SubmittedText;

/** Download content assembled from saved evidence without changing its verdict or answer. */
final class FactCheckReportPresenter
{
    public static function build(Detection $detection, ?array $public = null): array
    {
        $saved = is_array($detection->fact_check_result) ? $detection->fact_check_result : [];
        $public ??= $saved !== [] ? ReportAnswer::forPresentation($saved) : ReportAnswer::forLegacyDetection($detection);
        $mediaOnly = in_array($public['primary_task'], ['media', 'manipulation'], true)
            && array_intersect($public['request']['tasks'] ?? [], ['claim', 'context']) === [];
        $factual = $public;
        if (! $mediaOnly && in_array($public['primary_task'], ['media', 'manipulation'], true)) {
            $saved = data_get($saved, 'claim_assessment.result', []);
            $factual = data_get($public, 'claim_assessment.result') ?? $public;
        }
        $sources = collect(array_merge($factual['sources'] ?? [], $factual['contextual_sources'] ?? []))
            ->unique('url')->map(fn ($source) => $source + ['credibility' => self::sourceClass($source['type'] ?? '')])->values()->all();
        $claims = [];
        foreach (! $mediaOnly && ($saved['schema_version'] ?? 0) >= 4 ? ($saved['claims'] ?? []) : [] as $claim) {
            if (! is_array($claim) || ($claim['kind'] ?? '') !== 'factual'
                || ! ($text = ResultContract::text($claim['text'] ?? $claim['quote'] ?? null))) {
                continue;
            }
            $id = ResultContract::text($claim['id'] ?? null);
            $evidence = ['supporting' => [], 'contradicting' => [], 'context' => []];
            foreach ($id ? $sources : [] as $source) {
                foreach ($source['relationships'] ?? [] as $row) {
                    if (($row['claim_id'] ?? '') !== $id || ($row['superseded'] ?? false)
                        || empty($row['excerpt']) || empty($row['explanation'])) {
                        continue;
                    }
                    $stance = ! ($source['context_only'] ?? false) && in_array($row['stance'], ['supporting', 'contradicting'], true)
                        ? $row['stance'] : 'context';
                    $evidence[$stance][] = ['source_id' => $source['id'], 'organization' => $source['name'],
                        'title' => $source['title'], 'url' => $source['url'], 'published_at' => $source['published_at'],
                        'credibility' => $source['credibility'], 'statement' => $row['excerpt'], 'explanation' => $row['explanation']];
                }
            }
            $support = $evidence['supporting'] !== [];
            $contradict = $evidence['contradicting'] !== [];
            $outcome = match (true) {
                $support && $contradict => 'conflicting',
                ($claim['outcome'] ?? '') === 'supported' && $support => 'supported',
                ($claim['outcome'] ?? '') === 'refuted' && $contradict => 'contradicted',
                default => 'unresolved',
            };
            $explanation = match ($outcome) {
                'supported' => 'The cited passages support this claim within their recorded scope.',
                'contradicted' => 'The cited passages contradict this claim within their recorded scope.',
                'conflicting' => 'Supporting and contradicting passages remain in the saved evidence. The disagreement is unresolved.',
                default => ResultContract::publicDetail($claim['context_reason'] ?? null)
                    ?? 'The saved evidence does not establish whether this claim is accurate. Missing evidence does not make it false.',
            };
            $claims[] = ['id' => $id ?? 'claim-'.(count($claims) + 1), 'text' => $text, 'outcome' => $outcome,
                'label' => match ($outcome) { 'supported' => 'Supported', 'contradicted' => 'Contradicted', 'conflicting' => 'Conflicting evidence', default => 'Unresolved' },
                'explanation' => $explanation, 'evidence' => $evidence];
        }
        $counts = ['total' => count($claims), 'supported' => 0, 'contradicted' => 0, 'unresolved' => 0];
        foreach ($claims as $claim) {
            $counts[in_array($claim['outcome'], ['supported', 'contradicted'], true) ? $claim['outcome'] : 'unresolved']++;
        }
        $verdict = $public['verdict'];
        $label = $public['assessment_label'];
        $risk = match (true) {
            $mediaOnly => ['label' => 'Not assessed', 'level' => 'neutral', 'explanation' => 'This media-origin screening does not establish the misinformation risk of an accompanying claim.'],
            $label === 'Unverified', AssessmentVerdict::technical($verdict), in_array($verdict, ['UNVERIFIED', 'UNVERIFIABLE', 'INCONCLUSIVE', 'CONFLICTING_EVIDENCE'], true)
                => ['label' => 'Undetermined', 'level' => 'neutral', 'explanation' => 'The available evidence does not support a settled risk assessment. Lack of evidence is not evidence of falsity.'],
            $verdict === 'TRUE' => ['label' => 'Lower concern', 'level' => 'low', 'explanation' => 'Reviewed evidence supports the assessed claim. Unreviewed details and media authenticity remain outside that finding.'],
            in_array($verdict, ['FALSE', 'MISLEADING', 'PARTLY_TRUE', 'OUT_OF_CONTEXT', 'OUTDATED'], true)
                => ['label' => 'Elevated concern', 'level' => 'high', 'explanation' => 'The saved assessment identifies contradictions or a material problem with the way the information is presented.'],
            default => ['label' => 'Context needed', 'level' => 'caution', 'explanation' => 'Important context is unresolved. Verify the original statement and its scope before relying on it.'],
        };
        $score = $public['score'] ?? [];
        $confidence = ['value' => 'Unavailable', 'explanation' => 'No calibrated model confidence was recorded. This is separate from risk and evidence strength.'];
        $model = ['value' => 'Unavailable', 'explanation' => 'No compatible numerical model output was recorded for this assessment.'];
        if (($score['type'] ?? '') === 'confidence' && ($score['validation'] ?? '') === 'calibrated'
            && AssessmentScore::display($score) !== 'Score unavailable') {
            $confidence = ['value' => AssessmentScore::display($score), 'explanation' => $score['explanation']];
        }
        if (($score['type'] ?? '') === 'model_score' && AssessmentScore::display($score) !== 'Score unavailable') {
            $model = ['value' => AssessmentScore::display($score), 'explanation' => $score['explanation'],
                'status' => ($score['validation'] ?? '') === 'uncalibrated' ? 'Uncalibrated model output' : 'Calibrated on benchmark images'];
        }
        $copy = ResultWording::saved(ResultWording::ensure($public, (string) $detection->recommendation));
        $action = $copy['recommended_action'] ?? ResultContract::publicDetail($detection->recommendation)
            ?? 'Review the cited original records and their dates before sharing this content.';
        $explanation = self::paragraphs($copy['explanation'] ?? $public['summary']);
        return ['result' => $public, 'assessment_code' => $verdict,
            'verdict_label' => $label, 'risk' => $risk, 'risk_percentage' => self::riskPercentage($detection), 'confidence' => $confidence, 'model_score' => $model,
            'claims' => $claims, 'counts' => $counts, 'sources' => $sources, 'show_claims' => ! $mediaOnly,
            'explanation' => $explanation,
            'limitations' => $factual['limitations'] ?? [], 'recommended_action' => $action];
    }

    /** Recorded screening outputs and observed statement-risk shares are separate from confidence. */
    public static function riskPercentage(Detection $detection): array
    {
        $unavailable = ['display' => 'Unavailable', 'value' => null, 'color' => 'neutral',
            'caption' => 'Risk undetermined',
            'explanation' => 'No sufficiently grounded statement assessment or compatible model output was recorded. Missing evidence is not low risk or proof of falsity; confidence and evidence-strength percentages are not risk scores.'];
        if ($detection->processing_status !== 'completed') {
            return $unavailable;
        }
        $saved = is_array($detection->fact_check_result) ? $detection->fact_check_result : [];
        if ($saved !== []) {
            $public = ReportAnswer::forPresentation($saved);
            $factual = $saved;
            $factualPublic = $public;
            if (in_array($public['primary_task'], ['media', 'manipulation'], true)) {
                $factual = data_get($saved, 'claim_assessment.result', []);
                $factualPublic = data_get($public, 'claim_assessment.result', []);
            }
            if (is_array($factual) && is_array($factualPublic)
                && ($factualRisk = RecordedFactRisk::calculate($factual, $factualPublic)) !== null) {
                return $factualRisk;
            }
        }
        $media = is_array($saved['media_assessment'] ?? null) ? $saved['media_assessment'] : [];
        $score = AssessmentScore::media($media);
        if (($score['type'] ?? '') === 'model_score') {
            $value = (float) $media['fake_probability'] * 100;
            $caption = 'AI-origin screening index';
            $explanation = 'Recorded synthetic-class model output, shown on a 0-100 scale. This is a screening index, not the probability that a claim is false or measured model confidence.';
        } elseif (($media['status'] ?? '') === 'completed' && ($media['media_type'] ?? '') === 'video'
            && is_int($media['frames_used'] ?? null) && $media['frames_used'] > 0
            && (! ($media['video_scope']['face_crop_required'] ?? false) || ($media['video_scope']['face_frames'] ?? 0) > 0)
            && is_numeric($media['screening_score'] ?? null) && is_finite((float) $media['screening_score'])
            && $media['screening_score'] >= 0 && $media['screening_score'] <= 100) {
            $value = (float) $media['screening_score'];
            $caption = 'Sampled-frame screening index';
            $explanation = 'Recorded video frame-screening index, not a whole-video probability or a measure of factual accuracy.';
        } elseif (($saved['schema_version'] ?? 0) < 4 && empty(data_get($detection->signals, 'evidence_pipeline'))
            && is_numeric($detection->fake_score) && is_finite((float) $detection->fake_score)
            && $detection->fake_score >= 0 && $detection->fake_score <= 100) {
            $value = (float) $detection->fake_score;
            $caption = 'Recorded screening index';
            $explanation = 'Saved heuristic screening index on a 0-100 scale. It is not a calibrated probability that the claim is false or model confidence.';
        } else {
            return $unavailable;
        }

        return ['display' => (string) round($value).'%', 'value' => $value,
            'caption' => self::riskLevel((int) round($value)), 'color' => self::riskColor($value), 'basis' => $caption, 'explanation' => $explanation];
    }

    public static function riskLevel(int $value): string
    {
        return match (true) {
            $value <= 20 => 'Very Low Risk', $value <= 40 => 'Low Risk',
            $value <= 60 => 'Moderate Risk', $value <= 80 => 'High Risk', default => 'Critical Risk',
        };
    }

    public static function riskColor(?float $value): string
    {
        if ($value === null || ! is_finite($value) || $value < 0 || $value > 100) return 'neutral';

        return match (self::riskLevel((int) round($value))) {
            'Very Low Risk' => 'green', 'Low Risk' => 'teal', 'Moderate Risk' => 'amber',
            'High Risk' => 'orange', 'Critical Risk' => 'red',
        };
    }

    public static function paragraphs(string $text): array
    {
        return array_map(fn ($sentences) => implode(' ', $sentences), array_chunk(SubmittedText::sentences($text), 3)) ?: [$text];
    }

    public static function sourceClass(string $type): string
    {
        return match ($type) {
            'official_government', 'official', 'weather' => 'Official government record',
            'institution' => 'Institutional source', 'research' => 'Research publication',
            'fact_check' => 'Fact-checking organization', 'reputable_news', 'news' => 'News reporting',
            default => 'Other public source',
        };
    }
}
