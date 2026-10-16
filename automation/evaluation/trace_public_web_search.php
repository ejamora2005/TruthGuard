<?php

// Opt-in public connectivity trace. Run locally with configured API keys.
// It rolls back all database writes; no private submission is read or sent.

use App\Models\CanonicalFactCheckResult;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$claim = 'NASA launched the uncrewed Artemis I mission on November 16, 2022.';
DB::beginTransaction();
try {
    $canonicalBefore = CanonicalFactCheckResult::count();
    $user = User::factory()->create();
    $detection = app(DetectionPipeline::class)->run($user,
        ['caption_text' => $claim, 'analysis_task' => 'claim'], null);
    $saved = $detection->fresh()->fact_check_result;
    $diagnostics = $saved['diagnostics'] ?? [];
    $markup = view('detections.partials.canonical-result-card', [
        'selectedDetection' => $detection->fresh(), 'result' => $saved, 'userReportNumber' => 1,
    ])->render();
    $dom = new DOMDocument;
    $prior = libxml_use_internal_errors(true);
    $dom->loadHTML($markup);
    libxml_clear_errors();
    libxml_use_internal_errors($prior);
    $xpath = new DOMXPath($dom);
    $citations = $xpath->query('//a[contains(@class,"tg-result-citation")]');
    $resolved = 0;
    foreach ($citations as $citation) {
        $id = ltrim($citation->getAttribute('href'), '#');
        if ($id !== '' && $xpath->query('//*[@id="'.$id.'"]')->length === 1) {
            $resolved++;
        }
    }
    $searchAttempts = array_values(array_filter($diagnostics['search_attempts'] ?? [],
        fn ($row) => ($row['provider'] ?? '') === 'openai_web_search'));
    $web = $searchAttempts[0] ?? [];
    $output = [
        'public_claim' => $claim,
        'analysis_id' => $diagnostics['analysis_id'] ?? null,
        'openai_web_search' => [
            'status' => $web['status'] ?? ($diagnostics['providers']['openai_web_search'] ?? 'not_run'),
            'http_status' => $web['http_status'] ?? null,
            'search_calls' => $web['web_search']['search_calls'] ?? [],
            'consulted_source_count' => count($web['web_search']['consulted_sources'] ?? []),
            'candidate_urls_added' => $web['unique_added'] ?? 0,
            'citation_annotations' => count($web['web_search']['citation_annotations'] ?? []),
        ],
        'provider_statuses' => $diagnostics['providers'] ?? [],
        'search_requests' => $diagnostics['search_requests'] ?? 0,
        'provider_discoveries' => $diagnostics['results_found'] ?? 0,
        'unique_candidates' => $diagnostics['unique_candidates'] ?? 0,
        'documents_retrieved' => $diagnostics['articles_retrieved'] ?? 0,
        'sources_reviewed' => $diagnostics['sources_reviewed'] ?? 0,
        'sources_used' => $diagnostics['sources_used'] ?? 0,
        'document_outcomes' => array_slice($diagnostics['page_outcomes'] ?? [], 0, 12),
        'model_stages' => $diagnostics['model_stages'] ?? [],
        'outcome_reason' => $diagnostics['outcome_reason'] ?? null,
        'verdict' => $saved['verdict'] ?? null,
        'summary' => $saved['summary'] ?? null,
        'sources' => array_map(fn ($source) => [
            'id' => $source['id'], 'title' => $source['title'], 'url' => $source['url'],
            'published_at' => $source['published_at'] ?? null,
        ], $saved['sources'] ?? []),
        'persistence' => ['detection_saved' => $detection->exists && $detection->fresh() !== null,
            'canonical_result_saved' => CanonicalFactCheckResult::count() > $canonicalBefore,
            'reloaded_source_count' => count($detection->fresh()->fact_check_result['sources'] ?? [])],
        'rendered' => ['visible_source_cards' => $xpath->query('//section[contains(@class,"tg-result-verify-sources")]//article[contains(@class,"tg-result-source")]')->length,
            'citation_links' => $citations->length, 'citations_resolving_to_cards' => $resolved,
            'sources_outside_details' => $xpath->query('//*[@id="sources-to-verify"]/ancestor::details')->length === 0],
    ];
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    DB::rollBack();
}
