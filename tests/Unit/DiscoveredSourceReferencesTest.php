<?php

namespace Tests\Unit;

use App\Services\Detections\AdditionalSourceReferences;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DiscoveredSourceReferencesTest extends TestCase
{
    public function test_only_related_unused_unreadable_and_unreviewed_sources_are_shown(): void
    {
        Http::preventStrayRequests();
        $records = [
            ['url' => 'https://example.gov.ph/read', 'title' => 'The fictional library opened in 2024.', 'retrieval_status' => 'retrieved', 'assessment_role' => 'not_used'],
            ['url' => 'https://example.org/other-event', 'title' => 'Another event', 'retrieval_status' => 'retrieved', 'assessment_role' => 'irrelevant'],
            ['url' => 'https://example.org/restricted', 'title' => 'Fictional library opening in 2024', 'retrieval_status' => 'http_403'],
            ['url' => 'https://example.org/new', 'title' => 'Fictional library opening in 2024', 'retrieval_status' => 'discovered'],
        ];
        $result = ['primary_task' => 'claim', 'verdict' => 'MISSING_CONTEXT', 'claim' => 'The fictional library opened in 2024.', 'diagnostics' => ['candidate_records' => $records]];
        $before = $result;
        $links = app(AdditionalSourceReferences::class)->discovered($result, [], []);
        $this->assertSame(array_column([$records[0], $records[2], $records[3]], 'url'), array_column($links, 'url'));
        $this->assertSame(['Retrieved during research', 'Page could not be read', 'Found; not reviewed'], array_column($links, 'status_label'));
        $this->assertSame($before, $result);
        $this->assertArrayNotHasKey('rating', $links[0]);
        $this->assertArrayNotHasKey('text', $links[0]);
        Http::assertNothingSent();
    }

    public function test_existing_links_duplicates_private_urls_share_actions_and_raw_diagnostics_are_excluded(): void
    {
        $record = fn ($url) => ['url' => $url, 'title' => 'The fictional library opened in 2024.', 'retrieval_status' => 'retrieved'];
        $records = [$record('https://example.org/used?utm_source=test'),
            $record('https://example.org/other'), $record('https://example.org/other#section'),
            $record('http://127.0.0.1/private'), $record('javascript:alert(1)'),
            $record('https://x.com/intent/post?text=share'), ['url' => 'https://example.org/debug-only']];
        $links = app(AdditionalSourceReferences::class)->discovered(['primary_task' => 'claim', 'claim' => 'The fictional library opened in 2024.'], $records, [['url' => 'https://example.org/used']]);
        $this->assertSame(['https://example.org/other'], array_column($links, 'url'));
        $this->assertSame([], app(AdditionalSourceReferences::class)->discovered(['primary_task' => 'media'], $records, []));
    }

    public function test_the_same_agency_and_year_do_not_make_a_different_event_related(): void
    {
        $records = [
            ['url' => 'https://example.gov.ph/classes', 'title' => 'DILG denies class suspension on August 27, 2025', 'retrieval_status' => 'retrieved'],
            ['url' => 'https://example.gov.ph/trucks', 'title' => 'DILG announced fire truck purchases on August 27, 2025', 'retrieval_status' => 'retrieved'],
            ['url' => 'https://example.gov.ph/old', 'title' => 'DILG announced class suspension on August 27, 2024', 'retrieval_status' => 'retrieved'],
        ];
        $links = app(AdditionalSourceReferences::class)->discovered(['primary_task' => 'claim',
            'claim' => 'DILG announced class suspension on August 27, 2025'], $records, []);
        $this->assertSame(['https://example.gov.ph/classes'], array_column($links, 'url'));
    }
}
