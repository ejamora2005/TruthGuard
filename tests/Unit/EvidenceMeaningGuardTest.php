<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\EvidenceJudge;
use Tests\TestCase;

class EvidenceMeaningGuardTest extends TestCase
{
    public function test_expired_bulletin_cannot_support_a_current_warning(): void
    {
        $claim = ['id' => 'c1', 'text' => 'The warning is in effect today.', 'kind' => 'factual',
            'requires_context' => false];
        $excerpt = 'The weather bulletin for Eastern Visayas is valid until October 12, 2026.';
        $source = ['id' => 's1', 'url' => 'https://example.gov.ph/bulletin/1', 'text' => $excerpt,
            'published_at' => '2026-10-12T00:00:00+08:00'];
        $relationship = $this->relationship($excerpt);

        $stale = app(EvidenceJudge::class)->validate([$relationship], [$claim], [$source], '2026-10-14T12:00:00+08:00');
        $timely = app(EvidenceJudge::class)->validate([$relationship], [$claim], [$source], '2026-10-12T12:00:00+08:00');

        $this->assertSame('insufficient', $stale[0]['stance']);
        $this->assertStringContainsString('expired bulletin', $stale[0]['validation_limitation']);
        $this->assertSame('supporting', $timely[0]['stance']);
    }

    public function test_general_hazard_does_not_prove_a_specific_earthquake_prediction(): void
    {
        $claim = ['id' => 'c1', 'text' => 'PHIVOLCS predicts a magnitude 7 earthquake in Leyte tomorrow.',
            'kind' => 'factual', 'requires_context' => false];
        $excerpt = 'Leyte faces earthquake hazards from active faults. The map does not predict the time or size of a future earthquake.';
        $source = ['id' => 's1', 'url' => 'https://phivolcs.dost.gov.ph/hazard-map', 'text' => $excerpt,
            'published_at' => '2026-10-01T00:00:00+08:00'];
        $relationship = $this->relationship($excerpt);
        $relationship['matches']['action'] = false;

        $reviewed = app(EvidenceJudge::class)->validate([$relationship], [$claim], [$source], '2026-10-14T12:00:00+08:00');

        $this->assertSame('insufficient', $reviewed[0]['stance']);
        $this->assertSame('The article does not address all material parts of the same claim.', $reviewed[0]['validation_limitation']);
    }

    private function relationship(string $excerpt): array
    {
        return ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'supporting',
            'excerpt' => $excerpt, 'explanation' => 'The document addresses the submitted warning.',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'basis' => 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
    }
}
