<?php

namespace Tests\Unit;

use App\Services\Detections\ResultWording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResultWordingTest extends TestCase
{
    public function test_limited_evidence_fallback_is_short_specific_and_saved_without_a_request(): void
    {
        Http::preventStrayRequests();
        $result = ['verdict' => 'MISSING_CONTEXT', 'primary_task' => 'claim', 'claim' => 'Constitution and elections',
            'summary' => 'I searched for records about the topic. The wording does not identify a specific constitutional change. A related source dated 2026-10-16 states: “A long background quotation.”',
            'sources' => [], 'contextual_sources' => []];
        $stored = ResultWording::ensure($result, 'Confirm which constitutional change the post refers to. Then verify its date.');
        $copy = ResultWording::saved($stored);
        $this->assertNotNull($copy);
        $this->assertStringContainsString($result['claim'], $copy['explanation']);
        $this->assertStringContainsString('does not identify a specific constitutional change', $copy['explanation']);
        $this->assertStringNotContainsString('I searched', $copy['explanation']);
        $this->assertStringNotContainsString('background quotation', $copy['explanation']);
        $this->assertLessThan(80, Str::wordCount($copy['explanation']));
        $this->assertSame('Confirm which constitutional change the post refers to.', $copy['recommended_action']);
        $this->assertSame($stored, ResultWording::ensure($stored, 'A different action.'));
        Http::assertNothingSent();
    }

    public function test_a_later_missing_candidate_cannot_revive_outdated_review_wording(): void
    {
        $result = ['verdict' => 'UNVERIFIED'];
        $candidate = ['verdict' => 'UNVERIFIED', 'explanation' => 'The available record does not establish the claim.',
            'result_summary' => 'The claim remains unverified.', 'recommended_action' => 'Check the original record.', 'source_ids' => []];
        $this->assertArrayHasKey('generated_wording', ResultWording::attach($result, [$candidate]));
        $this->assertSame($result, ResultWording::attach($result, [$candidate, null]));
    }

    public function test_saved_wording_does_not_survive_a_changed_final_verdict(): void
    {
        $stored = ResultWording::ensure(['verdict' => 'INCONCLUSIVE', 'primary_task' => 'media',
            'summary' => 'The image origin could not be resolved.']);
        $this->assertNotNull(ResultWording::saved($stored));
        $stored['verdict'] = 'LIKELY_AI_GENERATED';
        $this->assertNull(ResultWording::saved($stored));
    }

    public function test_reused_result_gets_fresh_wording_without_changing_its_facts(): void
    {
        Http::preventStrayRequests();
        $result = ResultWording::ensure([
            'verdict' => 'TRUE', 'primary_task' => 'claim',
            'summary' => 'The official record confirms that the election was postponed.',
            'sources' => [['id' => 'official-1']], 'contextual_sources' => [],
        ]);
        $original = ResultWording::saved($result);
        $varied = ResultWording::varyForReuse($result, 'second-submission');
        $copy = ResultWording::saved($varied);

        $this->assertNotSame($original['explanation'], $copy['explanation']);
        $this->assertStringContainsString(lcfirst($original['explanation']), $copy['explanation']);
        $this->assertSame($result['verdict'], $varied['verdict']);
        $this->assertSame($result['summary'], $varied['summary']);
        $this->assertSame($result['sources'], $varied['sources']);
        Http::assertNothingSent();
    }
}
