<?php

namespace Tests\Unit;

use App\Services\Detections\PublishedFactCheckAnswer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishedFactCheckAnswerTest extends TestCase
{
    public function test_ready_answer_uses_the_published_rating_and_complete_headline_without_network_calls(): void
    {
        Http::preventStrayRequests();
        $item = ['publisher' => 'Example fact-checker', 'headline' => 'Short headline',
            'full_headline' => 'The bridge reopening announcement uses an outdated date',
            'source_rating' => 'Missing context', 'rating' => 'False', 'url' => 'https://example.org/bridge-review'];
        $before = $item;
        $answer = PublishedFactCheckAnswer::fromFeedItem($item);

        $this->assertSame('published_rating', $answer['status']);
        $this->assertStringContainsString('According to Example fact-checker', $answer['paragraphs'][0]);
        $this->assertStringContainsString('“Missing context”', $answer['paragraphs'][0]);
        $this->assertStringContainsString($item['full_headline'], $answer['paragraphs'][1]);
        $this->assertStringNotContainsString('False', implode(' ', $answer['paragraphs']));
        $this->assertSame($item['url'], $answer['source_url']);
        $this->assertSame($before, $item);
        $this->assertArrayNotHasKey('risk', $answer);
        $this->assertArrayNotHasKey('confidence', $answer);
        Http::assertNothingSent();
    }

    public function test_missing_source_rating_is_not_invented_from_a_normalized_rating_or_claim(): void
    {
        $answer = PublishedFactCheckAnswer::fromFeedItem(['publisher' => 'Example fact-checker',
            'headline' => 'A published review of the bridge announcement', 'rating' => 'False',
            'claim' => 'The bridge reopened yesterday.', 'url' => 'https://example.org/bridge-review']);

        $this->assertSame('limited_record', $answer['status']);
        $this->assertStringContainsString('no rating has been inferred', $answer['limitation']);
        $this->assertStringNotContainsString('False', implode(' ', $answer['paragraphs']));
        $this->assertStringNotContainsString('The bridge reopened yesterday', implode(' ', $answer['paragraphs']));
    }

    public function test_missing_or_unsafe_sources_never_produce_a_ready_factual_verdict(): void
    {
        foreach ([[], ['url' => 'javascript:alert(1)', 'source_rating' => 'False'],
            ['url' => 'http://127.0.0.1/private', 'source_rating' => 'True']] as $item) {
            $answer = PublishedFactCheckAnswer::fromFeedItem($item);
            $this->assertSame('limited_record', $answer['status']);
            $this->assertNull($answer['source_url']);
            $this->assertStringContainsString('not contain enough publisher information', $answer['paragraphs'][0]);
        }
    }
}
