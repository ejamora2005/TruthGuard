<?php

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

// Isolated view fixtures. No Detection/User records are created or changed.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set(['session.driver' => 'array', 'services.google_picker' => []]);
$request = Request::create('http://127.0.0.1:8000/detections/1/result');
$request->setLaravelSession(app('session')->driver());
$request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
$app->instance('request', $request);
app('url')->setRequest($request);
auth()->setUser(User::factory()->make(['id' => 0, 'name' => 'UI fixture', 'onboarding_completed_at' => now()]));
app('view')->share('errors', new ViewErrorBag);
$base = [
    'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE', 'claim' => 'This is a synthetic UI fixture, not a verified real-world claim.',
    'confidence' => null, 'summary' => 'The fixture records contradict the demonstration claim. This page exercises the saved answer and citation layout.',
    'score' => AssessmentScore::unavailable('No validated percentage is available for this synthetic UI fixture.'),
    'key_findings' => ['Synthetic finding for the report layout test.'], 'limitations' => ['Synthetic UI fixture; no real claim was verified.'],
    'evidence_cutoff' => '2026-10-14T00:00:00Z', 'ui' => ['verdict_color' => 'red', 'show_evidence_warning' => false, 'evidence_warning' => null,
        'show_official_verification_notice' => false, 'official_verification_notice' => null, 'show_missing_context' => false, 'missing_context' => null,
        'show_source_conflict' => false, 'source_conflict_message' => null, 'highlight_evidence_cutoff' => false],
];
$base['sources'] = array_map(fn ($i) => ['id' => 's'.$i, 'name' => 'Fixture publisher '.$i, 'title' => 'Reviewed source '.$i.' — synthetic UI fixture',
    'url' => 'https://example.com/evidence-'.$i, 'type' => 'reputable_news', 'published_at' => '2026-10-13',
    'used_for' => 'This synthetic record contradicts the demonstration statement. No live evidence claim is made.',
    'relationships' => [['stance' => 'contradicting', 'excerpt' => 'The synthetic review says the demonstration statement is incorrect.',
        'explanation' => 'This is a synthetic UI fixture only.', 'superseded' => false]]], range(1, 4));
$directory = storage_path('tmp-assessment-browser');
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
foreach (['factual', 'media', 'calibrated', 'unverified', 'long', 'missing', 'legacy', 'websearch', 'context', 'satire'] as $variant) {
    $result = $base;
    if (in_array($variant, ['context', 'satire'], true)) {
        $result['primary_task'] = 'context';
        $result['verdict'] = $variant === 'context' ? 'OUT_OF_CONTEXT' : 'SATIRE_PARODY';
        $result['summary'] = $variant === 'context'
            ? 'This synthetic example uses a genuine historical scene with an incorrect present-day caption.'
            : 'The simulated source explicitly identifies this fictional story as satire.';
        $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => ['s1']]];
        $result['secondary_assessments'] = [$base];
    }
    if ($variant === 'unverified') {
        $result['verdict'] = 'UNVERIFIED';
        $result['sources'] = [];
        $result['score'] = AssessmentScore::unavailable('No relevant evidence was found.');
    }
    if ($variant === 'long') {
        $result['summary'] = str_repeat('A long explanation must remain fully readable without clipping on small displays. ', 55).str_repeat('LongWord', 45);
        $result['sources'][0]['title'] = str_repeat('LongSourceTitle', 20).' — synthetic UI fixture';
        $result['sources'][0]['used_for'] = str_repeat('This long source explanation must wrap without clipping. ', 30).str_repeat('LongSourceDetail', 20);
    }
    if ($variant === 'websearch') {
        $result['trained_model'] = ['status' => 'not_requested', 'prediction' => null,
            'contribution' => 'No model output was requested for this synthetic fixture.', 'input_scope' => 'none',
            'disagrees_with_evidence' => false, 'limitation' => 'Synthetic UI fixture only.'];
        $result['extraction'] = ['input_scope' => 'submitted_text', 'status' => 'completed', 'visible_text' => 'DEV_OCR_SENTINEL', 'inferred_claim' => ''];
        $result['diagnostics'] = ['results_found' => 1, 'sources_reviewed' => 0, 'sources_used' => 0,
            'search_requests' => 1, 'page_requests' => 0, 'analysis_elapsed_ms' => 100,
            'providers' => ['openai_web_search' => 'DEV_PROVIDER_SENTINEL'], 'model_stages' => [], 'candidate_records' => [],
            'search_attempts' => [['provider' => 'openai_web_search', 'query' => 'DEV_QUERY_SENTINEL',
                'status' => 'completed', 'results' => 1, 'web_search' => [
                    'search_calls' => [['action' => 'search', 'status' => 'completed']],
                    'consulted_sources' => [['url' => 'https://example.com/synthetic-fixture']],
                    'citation_annotations' => [['url' => 'https://example.com/synthetic-fixture']],
                    'usage' => ['total_tokens' => 123],
                ]]],
        ];
    }
    if (in_array($variant, ['media', 'calibrated'], true)) {
        $result = MediaAnalysisReport::result([
            'status' => 'completed', 'media_type' => 'image', 'label' => 'real', 'fake_probability' => .14, 'threshold' => .475, 'task' => 'AI-generation screening',
            'label_mapping' => ['real', 'fake'], 'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true], 'model_version' => 'synthetic-ui-fixture',
        ], ['status' => 'not_requested', 'result' => null]);
        if ($variant === 'calibrated') {
            // Presentation fixture only: never a live calibration artifact.
            $result['score'] = ['type' => 'confidence', 'value' => .75, 'label' => 'Confidence',
                'validation' => 'calibrated', 'method_version' => 'binary-temperature-v1',
                'explanation' => 'Synthetic UI fixture only; no live calibration claim.'];
            $result['limitations'][0] = $result['score']['explanation'];
        }
    }
    if ($variant === 'legacy') {
        unset($result['score']);
        $result['schema_version'] = 1;
        $result['confidence'] = 99;
        $result['verdict'] = 'UNVERIFIABLE';
    } else {
        $result = ReportAnswer::complete($result);
    }
    $detection = new Detection(['media_type' => 'image', 'media_path' => $variant === 'missing' ? null : 'preview/original.png', 'fact_check_result' => $result]);
    $detection->forceFill(['id' => 1, 'created_at' => '2026-10-14T00:00:00Z']);
    file_put_contents($directory.'/'.$variant.'.html', view('detections.result', ['selectedDetection' => $detection, 'latestFactChecks' => [], 'userReportNumber' => 1])->render());
}
$pending = new Detection(['media_type' => 'image', 'processing_status' => 'processing',
    'signals' => ['analysis_progress' => [['label' => 'Checking dates and official records', 'source_domains' => ['www.comelec.gov.ph', 'verafiles.org']]]]]);
$pending->forceFill(['id' => 1, 'created_at' => now()]);
file_put_contents($directory.'/pending.html', view('detections.result', ['selectedDetection' => $pending, 'latestFactChecks' => []])->render());
echo "Rendered ten assessment fixtures and one pending fixture; database records unchanged.\n";
