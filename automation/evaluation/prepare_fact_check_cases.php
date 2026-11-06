<?php

// Read-only candidate export. Published metadata is not an independent label audit.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\PublicClaimReview;
use App\Models\ReviewedClaim;
use App\Services\Detections\FactCheckRatingNormalizer;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ReviewedClaimLookup;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

$options = getopt('', ['output:', 'google-pages::']);
$output = $options['output'] ?? '';
if (! $output || file_exists($output) || ! is_dir(dirname($output))) {
    fwrite(STDERR, "Provide --output in an existing directory; existing exports cannot be overwritten.\n");
    exit(1);
}
$cutoff = now()->utc()->toIso8601String();
$cases = [];
$seen = [];
$add = function (string $text, ?string $rating, string $url, string $status, string $publisher) use (&$cases, &$seen, $cutoff): void {
    $key = hash('sha256', ReviewedClaimLookup::normalize($text));
    if (isset($seen[$key]) || trim($text) === '') {
        return;
    }
    $seen[$key] = true;
    $normalized = mb_strtolower(FactCheckRatingNormalizer::normalize($rating ?? ''));
    $expected = match ($normalized) {
        'confirmed', 'true' => 'TRUE', 'false' => 'FALSE', 'misleading' => 'MISLEADING',
        'partly true' => 'PARTLY_TRUE', 'missing context' => 'MISSING_CONTEXT',
        default => null,
    };
    $cases[] = ['case_id' => 'claim-'.substr($key, 0, 16), 'task' => 'factual', 'text' => $text,
        'evaluation_cutoff' => $cutoff, 'event_group' => 'unreviewed-'.substr($key, 0, 16),
        'group_reviewed' => false, 'split' => 'development', 'expected_verdict' => $expected,
        'label_status' => $status, 'human_reviewed' => false, 'independent_of_development' => false,
        'reference_publisher' => $publisher, 'reference_rating' => $rating,
        'evidence_urls' => ReportAnswer::safeUrl($url) ? [$url] : [],
        'reviewer' => null, 'reviewed_at' => null, 'review_basis' => null];
};

foreach (PublicClaimReview::orderBy('id')->cursor() as $record) {
    if ($record->published_at && $record->published_at->gt($cutoff)) {
        continue;
    }
    $rating = data_get($record->source_payload, 'source_rating');
    $add((string) data_get($record->source_payload, 'full_claim', $record->claim), $rating,
        $record->url, $rating ? 'publisher_claim_review' : 'needs_scope_review', $record->publisher);
}
foreach (ReviewedClaim::where('active', true)->cursor() as $record) {
    if ($record->expires_at && $record->expires_at->lte(now())) {
        continue;
    }
    $add($record->claim, $record->result['verdict'] ?? null, $record->result['sources'][0]['url'] ?? '',
        'stored_answer', 'Stored reviewed answer');
}

// Optional bounded collection from the configured public Fact Check API. No
// enrichment/model calls, feed refresh, database writes or private data exports.
$pages = (int) ($options['google-pages'] ?? 0);
if ($pages < 0 || $pages > 10) {
    fwrite(STDERR, "--google-pages must be between 0 and 10 per publisher.\n");
    exit(1);
}
$collection = [];
foreach ($pages ? ['rappler.com', 'verafiles.org', 'fullfact.org'] : [] as $publisher) {
    $token = null;
    for ($page = 0; $page < $pages; $page++) {
        try {
            $params = ['key' => config('services.google_fact_check.key'), 'reviewPublisherSiteFilter' => $publisher,
                'pageSize' => 100, 'languageCode' => 'en'];
            if (! $params['key']) {
                throw new RuntimeException('API key missing.');
            }
            if ($token) {
                $params['pageToken'] = $token;
            }
            $response = Http::acceptJson()->connectTimeout(5)->timeout(20)
                ->get('https://factchecktools.googleapis.com/v1alpha1/claims:search', $params);
            $collection[] = ['publisher' => $publisher, 'page' => $page + 1, 'http_status' => $response->status()];
            if (! $response->successful()) {
                break;
            }
            foreach ($response->json('claims', []) as $claim) {
                foreach ($claim['claimReview'] ?? [] as $review) {
                    if (empty($review['reviewDate']) || strtotime($review['reviewDate']) === false
                        || strtotime($review['reviewDate']) > strtotime($cutoff)) {
                        continue;
                    }
                    $add($claim['text'] ?? '', $review['textualRating'] ?? null, $review['url'] ?? '',
                        'publisher_claim_review', $review['publisher']['name'] ?? $publisher);
                }
            }
            $token = $response->json('nextPageToken');
            if (! $token) {
                break;
            }
        } catch (Throwable $error) {
            // Never print request exception messages containing an API-key URL.
            $collection[] = ['publisher' => $publisher, 'page' => $page + 1, 'error_type' => $error::class];
            break;
        }
    }
}
$handle = fopen($output, 'x');
foreach ($cases as $case) {
    fwrite($handle, json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
fclose($handle);
$manifest = ['status' => 'candidate_pool_requires_independent_scope_and_group_review', 'release_ready' => false,
    'prepared_at' => $cutoff, 'cases' => count($cases), 'sha256' => hash_file('sha256', $output),
    'collection' => $collection, 'human_reviewed_cases' => 0,
    'note' => 'Labels and event groups are candidates. No model predictions or previous TruthGuard answers constitute independent ground truth.'];
file_put_contents($output.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
