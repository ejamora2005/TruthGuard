<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Usage\OpenAiCostMonitor;
use App\Services\Usage\OpenAiUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OpenAiUsageMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 16)->setTime(9, 0));
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.openai.admin_key' => 'fixture-admin-key', 'services.openai.usage_project_id' => null,
            'services.openai.usage_api_key_id' => null, 'services.openai.monthly_dollar_budget' => 6,
            'services.openai.token_warning_threshold' => 80, 'firebase.enabled' => false]);
    }

    public function test_all_response_calls_record_provider_counts_without_saving_content_or_credentials(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_fixture', 'model' => 'fixture-model', 'status' => 'incomplete',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 30, 'total_tokens' => 230,
                'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens_details' => ['reasoning_tokens' => 10]],
            'output' => [['type' => 'web_search_call'], ['type' => 'message', 'content' => 'private content']],
        ], 200, ['x-request-id' => 'req_fixture'])]);
        // Independent of the report pipeline, including responses whose output is unusable.
        Http::withToken('secret-fixture')->post('https://api.openai.com/v1/responses', ['input' => 'private claim']);
        Http::withToken('secret-fixture')->post('https://api.openai.com/v1/responses', ['input' => 'private claim']);
        $this->assertDatabaseCount('openai_request_usages', 1);
        $this->assertDatabaseHas('openai_request_usages', ['response_id' => 'resp_fixture', 'input_tokens' => 200, 'output_tokens' => 30, 'total_tokens' => 230,
            'cached_input_tokens' => 100, 'reasoning_tokens' => 10, 'web_search_calls' => 1, 'http_status' => 200]);
        $saved = json_encode(DB::table('openai_request_usages')->first());
        $this->assertStringNotContainsString('secret-fixture', $saved);
        $this->assertStringNotContainsString('private', $saved);
        $summary = app(OpenAiUsageRecorder::class)->monthlySummary();
        $this->assertSame(230, $summary['totalTokens']); // Cached/reasoning counts are subsets, not added again.
        $this->assertSame(1, $summary['requests']);
        $this->assertSame(0, $summary['unmeasuredRequests']);
    }

    public function test_missing_usage_stays_unknown_and_non_openai_requests_are_not_recorded(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429)]);
        Http::post('https://api.openai.com/v1/responses');
        Http::post('https://example.com/v1/responses');
        Http::get('https://api.openai.com/v1/organization/costs');
        $this->assertDatabaseCount('openai_request_usages', 1);
        $this->assertDatabaseHas('openai_request_usages', ['http_status' => 429, 'total_tokens' => null, 'quota_exhausted' => false]);
        $this->assertSame(1, app(OpenAiUsageRecorder::class)->monthlySummary()['unmeasuredRequests']);
    }

    public function test_dollar_warning_and_limit_alerts_are_admin_only_and_deduplicated(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['is_admin' => false]);
        Http::fakeSequence()->push($this->costs(4.79))->push($this->costs(4.80))->push($this->costs(5.20))->push($this->costs(6.00))->push($this->costs(7.00));
        $monitor = app(OpenAiCostMonitor::class);
        $this->assertTrue($monitor->sync());
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertTrue($monitor->sync());
        $this->assertTrue($monitor->sync());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('OpenAI budget running low', $admin->notifications()->first()->data['title']);
        $this->assertTrue($monitor->sync());
        $this->assertTrue($monitor->sync());
        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame(0, $user->notifications()->count());
        $this->assertSame('/admin/ai-usage', $admin->notifications()->first()->data['action_url']);
        $this->assertSame('$0.00', $monitor->summary()['remainingLabel']);
        $this->assertSame(116.7, $monitor->summary()['usagePercent']);
        $this->assertDatabaseCount('openai_request_usages', 0); // Billing reads are not inference requests.
    }

    public function test_all_cost_pages_and_tool_charges_are_summed_with_scope_filters(): void
    {
        config(['services.openai.usage_api_key_id' => 'key_fixture', 'services.openai.usage_project_id' => 'proj_fixture']);
        Http::fakeSequence()->push($this->costs(4.2, true, 'next'))->push([
            'data' => [['results' => [['amount' => ['value' => '0.60', 'currency' => 'usd'], 'line_item' => 'web_search']]]], 'has_more' => false,
        ]);
        $this->assertTrue(app(OpenAiCostMonitor::class)->sync());
        $this->assertSame('$4.80', app(OpenAiCostMonitor::class)->summary()['usedLabel']);
        $this->assertSame('Configured API key', app(OpenAiCostMonitor::class)->summary()['scopeLabel']);
        Http::assertSent(fn ($request) => $request['api_key_ids'] === ['key_fixture'] && $request['project_ids'] === ['proj_fixture'] && ($request->data()['page'] ?? null) === 'next');
        Http::assertSentCount(2);
    }

    public function test_failed_or_partial_sync_keeps_last_good_amount_and_marks_it_stale(): void
    {
        Http::fakeSequence()->push($this->costs(4.0))->push($this->costs(100, true, 'next'))->push(['error' => ['message' => 'secret provider detail']], 403);
        $monitor = app(OpenAiCostMonitor::class);
        $this->assertTrue($monitor->sync());
        $this->assertFalse($monitor->sync());
        $summary = $monitor->summary();
        $this->assertSame('$4.00', $summary['usedLabel']);
        $this->assertSame('stale', $summary['status']);
        $this->assertSame('http_403', $summary['lastSyncError']);
        $this->assertStringNotContainsString('secret', json_encode($summary));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_admin_is_warned_when_billing_monitoring_loses_access_without_duplicate_notices(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'private detail']], 401)]);
        $monitor = app(OpenAiCostMonitor::class);
        $this->assertFalse($monitor->sync());
        $this->assertFalse($monitor->sync());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('OpenAI billing monitor needs attention', $admin->notifications()->first()->data['title']);
        $this->assertNull($monitor->summary()['used']);
    }

    public function test_invalid_currency_and_broken_pagination_never_report_zero_spend(): void
    {
        Http::fakeSequence()->push(['data' => [['results' => [['amount' => ['value' => 5, 'currency' => 'eur']]]]], 'has_more' => false])
            ->push($this->costs(1, true, 'repeat'))->push($this->costs(1, true, 'repeat'));
        $monitor = app(OpenAiCostMonitor::class);
        $this->assertFalse($monitor->sync());
        $this->assertNull($monitor->summary()['used']);
        $this->assertFalse($monitor->sync());
        $this->assertSame('Not synced', $monitor->summary()['usedLabel']);
        $this->assertSame('invalid_pagination', $monitor->summary()['lastSyncError']);
    }

    public function test_new_month_scope_and_admin_credentials_do_not_reuse_old_cost_amounts(): void
    {
        Http::fakeSequence()->push($this->costs(5));
        $monitor = app(OpenAiCostMonitor::class);
        $this->assertTrue($monitor->sync());
        $this->travel(1)->month();
        $this->assertNull($monitor->summary()['used']);
        $this->travelBack();
        config(['services.openai.usage_api_key_id' => 'key_another']);
        $this->assertNull($monitor->summary()['used']);
        config(['services.openai.usage_api_key_id' => null, 'services.openai.admin_key' => 'different-organization-key']);
        $this->assertNull($monitor->summary()['used']);
    }

    public function test_missing_admin_key_makes_no_calls_and_reports_setup_required(): void
    {
        config(['services.openai.admin_key' => null]);
        Http::fake();
        $this->assertFalse(app(OpenAiCostMonitor::class)->sync());
        $this->assertTrue(app(OpenAiCostMonitor::class)->summary()['setupRequired']);
        $this->artisan('truthguard:sync-openai-costs')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_quota_errors_notify_admins_once_per_day_without_exposing_error_text(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'credit_balance_exhausted', 'message' => 'private provider text']], 429)]);
        Http::post('https://api.openai.com/v1/responses');
        Http::post('https://api.openai.com/v1/responses');
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('OpenAI credits or quota exhausted', $admin->notifications()->first()->data['title']);
        $this->assertStringNotContainsString('private', json_encode($admin->notifications()->first()->data));
    }

    public function test_existing_notification_preferences_and_device_push_are_respected(): void
    {
        config(['firebase.enabled' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $muted = User::factory()->create(['is_admin' => true]);
        NotificationPreference::create(['user_id' => $admin->id, 'system_notifications' => true, 'push_enabled' => true]);
        NotificationPreference::create(['user_id' => $muted->id, 'system_notifications' => false]);
        PushSubscription::create(['user_id' => $admin->id, 'token' => str_repeat('a', 100), 'token_hash' => hash('sha256', 'fixture')]);
        Http::fakeSequence()->push($this->costs(4.8))->push($this->costs(4.9));
        $this->assertTrue(app(OpenAiCostMonitor::class)->sync());
        $this->assertTrue(app(OpenAiCostMonitor::class)->sync());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $muted->notifications()->count());
        Queue::assertPushed(SendPushNotification::class, 1);
    }

    public function test_admin_page_shows_measured_counts_and_missing_billing_without_live_calls(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['services.openai.admin_key' => null]);
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'resp_admin', 'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12]])]);
        Http::post('https://api.openai.com/v1/responses');
        $this->actingAs($admin)->getJson('/admin/api/ai-usage')->assertOk()
            ->assertJsonPath('tokenUsage.tokensUsed', 12)->assertJsonPath('tokenUsage.usageSourceLabel', 'API response ledger')
            ->assertJsonPath('billing.budgetLabel', '$6.00')->assertJsonPath('billing.used', null)
            ->assertJsonPath('requestUsage.requests', 1)->assertJsonPath('billing.setupRequired', true);
        Http::assertSentCount(1); // Loading or refreshing the admin page never polls billing or inference.
        $regular = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regular)->getJson('/admin/api/ai-usage')->assertRedirect(route('dashboard'));
    }

    private function costs(float $amount, bool $more = false, ?string $cursor = null): array
    {
        return ['data' => [['results' => [['amount' => ['value' => $amount, 'currency' => 'usd']]]]], 'has_more' => $more, 'next_page' => $cursor];
    }
}
