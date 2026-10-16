<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HistoryTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $this->travelTo(Carbon::parse('2026-10-16 20:30:00', 'UTC'));
    }

    public function test_history_displays_local_time_without_changing_saved_utc_timestamps(): void
    {
        $user = User::factory()->create();
        $detection = $this->report($user, '2026-10-16 07:34:00');
        $snapshot = $detection->fresh()->getRawOriginal();
        $response = $this->actingAs($user)->get(route('history'))->assertOk()->assertSee('(UTC+8)')
            ->assertViewHas('historyRows', fn ($rows) => $rows->sole()['date'] === 'Oct 16, 2026'
                && $rows->sole()['time'] === '03:34 PM');
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        if (getenv('TRUTHGUARD_HISTORY_BROWSER_FIXTURE')) {
            file_put_contents(storage_path('tmp-history-timezone.html'), $response->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_one_day_filter_uses_local_midnight_boundaries(): void
    {
        $user = User::factory()->create();
        $this->report($user, '2026-10-15 15:59:59');
        $start = $this->report($user, '2026-10-15 16:00:00');
        $end = $this->report($user, '2026-10-16 15:59:59');
        $this->report($user, '2026-10-16 16:00:00');
        $this->actingAs($user)->get(route('history', ['date_from' => '2026-10-16', 'date_to' => '2026-10-16']))->assertOk()
            ->assertViewHas('historyDetections', fn ($rows) => $rows->pluck('id')->all() === [$end->id, $start->id])
            ->assertViewHas('historyRows', fn ($rows) => $rows->pluck('date')->unique()->all() === ['Oct 16, 2026']
                && $rows->pluck('time')->all() === ['11:59 PM', '12:00 AM'])
            ->assertViewHas('historyFilters', fn ($filters) => $filters['date_from'] === '2026-10-16' && $filters['date_to'] === '2026-10-16');
        Http::assertNothingSent();
    }

    public function test_default_range_follows_local_date_after_utc_midnight_rollover(): void
    {
        $user = User::factory()->create();
        $this->report($user, '2026-10-16 20:00:00');
        $this->actingAs($user)->get(route('history'))->assertOk()
            ->assertViewHas('historyFilters', fn ($filters) => $filters['date_from'] === '2026-10-11' && $filters['date_to'] === '2026-10-17')
            ->assertViewHas('historyRows', fn ($rows) => $rows->sole()['date'] === 'Oct 17, 2026' && $rows->sole()['time'] === '04:00 AM');
        Http::assertNothingSent();
    }

    public function test_archived_open_ended_date_filter_uses_the_same_local_boundary(): void
    {
        $user = User::factory()->create();
        $included = $this->report($user, '2026-10-16 15:59:59', true);
        $this->report($user, '2026-10-16 16:00:00', true);
        $this->actingAs($user)->get(route('history', ['status' => 'archived', 'date_to' => '2026-10-16']))->assertOk()
            ->assertViewHas('historyDetections', fn ($rows) => $rows->pluck('id')->all() === [$included->id])
            ->assertViewHas('historyRows', fn ($rows) => $rows->sole()['archived_label'] === 'Oct 17, 2026');
        Http::assertNothingSent();
    }

    public function test_pagination_still_uses_utc_order_after_local_time_conversion(): void
    {
        $user = User::factory()->create();
        $reports = collect(range(1, 16))->map(fn ($offset) => $this->report($user, now()->subMinutes($offset)->format('Y-m-d H:i:s')));
        $this->actingAs($user)->get(route('history'))->assertOk()
            ->assertViewHas('historyDetections', fn ($rows) => $rows->pluck('id')->all() === $reports->take(15)->pluck('id')->all());
        $this->get(route('history', ['page' => 2]))->assertOk()
            ->assertViewHas('historyDetections', fn ($rows) => $rows->pluck('id')->all() === [$reports->last()->id]);
        Http::assertNothingSent();
    }

    private function report(User $user, string $utcTime, bool $archived = false): Detection
    {
        return Detection::create(['user_id' => $user->id, 'media_type' => 'text', 'source_kind' => 'text',
            'caption_text' => 'Timezone test claim', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'analyzed_at' => Carbon::parse($utcTime, 'UTC'), 'archived_at' => $archived ? now() : null]);
    }
}
