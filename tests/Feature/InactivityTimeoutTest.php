<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionTimeoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactivityTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('session.auth_timeout_user_minutes', 30);
        config()->set('session.auth_timeout_admin_minutes', 15);
        config()->set('session.auth_timeout_warning_seconds', 60);
    }

    public function test_regular_user_session_expires_after_thirty_idle_minutes(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->subMinutes(31)->timestamp,
            ])
            ->get(route('history'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_error', SessionTimeoutManager::EXPIRED_MESSAGE);

        $this->assertGuest();
    }

    public function test_admin_session_expires_after_fifteen_idle_minutes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this
            ->actingAs($admin)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->subMinutes(16)->timestamp,
            ])
            ->get(route('admin.dashboard'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_error', SessionTimeoutManager::EXPIRED_MESSAGE);

        $this->assertGuest();
    }

    public function test_keep_alive_refreshes_the_session_timer(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $previousActivity = now()->subMinutes(20)->timestamp;

        $response = $this
            ->actingAs($user)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => $previousActivity,
            ])
            ->postJson(route('auth.session.keep-alive'));

        $response
            ->assertOk()
            ->assertJsonPath('timeoutSeconds', 1800)
            ->assertJsonPath('warningSeconds', 60);

        $this->assertGreaterThan($previousActivity, session(SessionTimeoutManager::LAST_ACTIVITY_KEY));
        $this->assertAuthenticatedAs($user);
    }

    public function test_background_ajax_requests_do_not_refresh_last_activity(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $previousActivity = now()->subMinutes(4)->timestamp;

        $response = $this
            ->actingAs($admin)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => $previousActivity,
            ])
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/admin/api/dashboard');

        $response->assertOk();
        $this->assertSame($previousActivity, session(SessionTimeoutManager::LAST_ACTIVITY_KEY));
    }

    public function test_timeout_modal_is_rendered_on_authenticated_pages(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->timestamp,
            ])
            ->get(route('history'));

        $response
            ->assertOk()
            ->assertSee('Session expiring')
            ->assertSee('Stay Signed In')
            ->assertSee('Log Out')
            ->assertSee(route('auth.session.keep-alive', absolute: false), false)
            ->assertSee(route('auth.session.expire', absolute: false), false);
    }

    public function test_expire_endpoint_logs_out_and_redirects_with_message(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->timestamp,
            ])
            ->post(route('auth.session.expire'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_error', SessionTimeoutManager::EXPIRED_MESSAGE);

        $this->assertGuest();
    }
}
