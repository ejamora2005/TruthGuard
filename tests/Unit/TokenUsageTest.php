<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\TokenUsage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TokenUsageTest extends TestCase
{
    public function test_usage_includes_reported_tokens_on_retried_and_invalid_responses_without_double_counting_cached_input(): void
    {
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'fact_check.openai_retry.max_retries' => 1, 'fact_check.openai_retry.max_delay_seconds' => 0]);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12]], 503, ['Retry-After' => '0'])
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                'input_tokens_details' => ['cached_tokens' => 60], 'output_tokens_details' => ['reasoning_tokens' => 5]],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"wrong":true}']]]]]);
        $model = app(StructuredAnalysis::class);
        $this->assertNull($model->ask('usage_probe', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        $this->assertSame(['input_tokens' => 110, 'output_tokens' => 22, 'total_tokens' => 132], $model->usage);
        $this->assertSame(60, $model->stageUsage['usage_probe']['cached_input_tokens']);
        $this->assertSame(5, $model->stageUsage['usage_probe']['reasoning_tokens']);
        $this->assertSame(2, $model->stageUsage['usage_probe']['requests']);
        $this->assertSame(1, $model->stageUsage['usage_probe']['retries']);
        $this->assertSame('failed_validation_or_request', $model->stages['usage_probe']);
        $model->reset();
        $this->assertSame([], $model->stageUsage);
        $this->assertSame(0, $model->usage['total_tokens']);
        Http::assertSentCount(2);
    }

    public function test_provider_subtotals_cannot_exceed_their_containing_token_counts(): void
    {
        $row = TokenUsage::measure(['input_tokens' => 10, 'output_tokens' => 5,
            'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens_details' => ['reasoning_tokens' => 80]]);
        $this->assertSame(10, $row['cached_input_tokens']);
        $this->assertSame(5, $row['reasoning_tokens']);
        $this->assertSame(15, $row['total_tokens']);
    }
}
