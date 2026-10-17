@php
    $scoreValue = $assessment['score'] ?? null;
    $scoreText = App\Services\Detections\AssessmentScore::display($scoreValue);
    $scoreExplanation = $scoreValue['explanation'] ?? 'This historical report has no traceable, validated percentage. Its original recorded result is preserved.';
@endphp
<div class="tg-result-score" aria-label="Assessment score">
    <strong title="{{ $scoreExplanation }}">{{ ($scoreValue['type'] ?? 'unavailable') === 'unavailable' ? 'Confidence not available' : $scoreText }}</strong>
    <span>{{ match($scoreValue['type'] ?? '') { 'evidence_strength' => 'Provisional evidence heuristic', 'model_score' => ($scoreValue['validation'] ?? '') === 'calibrated_benchmark' ? 'Calibrated on benchmark images' : 'Uncalibrated model output', 'confidence' => 'Calibrated on validation data', default => 'No defensible percentage' } }}</span>
</div>
