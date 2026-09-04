<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionTimeoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTourTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_is_shown_before_first_onboarding_tour(): void
    {
        $user = User::factory()->create([
            'privacy_policy_accepted_at' => null,
            'privacy_policy_version' => null,
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => null,
            'onboarding_version' => null,
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('privacy.consent'));

        $this
            ->actingAs($user)
            ->post(route('privacy.accept'), [
                'privacy_policy_acceptance' => '1',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this
            ->actingAs($user->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TruthGuard Tour')
            ->assertSee('Start here to see the latest public claim reviews, recent activity, and your TruthGuard overview.')
            ->assertSee('data-tour="dashboard"', false)
            ->assertSee(route('onboarding.complete', absolute: false), false);
    }

    public function test_existing_user_without_current_onboarding_decision_sees_tour_once(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => null,
            'onboarding_version' => null,
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('TruthGuard Tour')
            ->assertSee('Skip Tour');
    }

    public function test_user_with_completed_current_onboarding_does_not_see_tour(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => now(),
            'onboarding_skipped_at' => null,
            'onboarding_version' => config('app.onboarding_version', '2026-07-28'),
        ]);

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('TruthGuard Tour');
    }

    public function test_complete_endpoint_marks_current_onboarding_version(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => null,
            'onboarding_version' => null,
        ]);

        $this
            ->actingAs($user)
            ->postJson(route('onboarding.complete'))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $user->refresh();

        $this->assertNotNull($user->onboarding_completed_at);
        $this->assertNull($user->onboarding_skipped_at);
        $this->assertSame(config('app.onboarding_version', '2026-07-28'), $user->onboarding_version);
        $this->assertFalse($user->needsCurrentOnboarding());
    }

    public function test_skip_endpoint_marks_current_onboarding_version(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => null,
            'onboarding_version' => null,
        ]);

        $this
            ->actingAs($user)
            ->postJson(route('onboarding.skip'))
            ->assertOk()
            ->assertJsonPath('status', 'skipped');

        $user->refresh();

        $this->assertNull($user->onboarding_completed_at);
        $this->assertNotNull($user->onboarding_skipped_at);
        $this->assertSame(config('app.onboarding_version', '2026-07-28'), $user->onboarding_version);
        $this->assertFalse($user->needsCurrentOnboarding());
    }

    public function test_ajax_onboarding_save_does_not_refresh_idle_activity(): void
    {
        $user = User::factory()->create([
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => null,
            'onboarding_version' => null,
        ]);
        $previousActivity = now()->subMinutes(10)->timestamp;

        $this
            ->actingAs($user)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => $previousActivity,
            ])
            ->postJson(route('onboarding.complete'))
            ->assertOk();

        $this->assertSame($previousActivity, session(SessionTimeoutManager::LAST_ACTIVITY_KEY));
    }
}
