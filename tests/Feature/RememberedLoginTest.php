<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\RememberedLogin;
use App\Services\Auth\SessionTimeoutManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RememberedLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_remembered_user_survives_idle_timeout_but_not_absolute_deadline(): void
    {
        $user = User::factory()->create();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $response->assertRedirect()->assertCookie(RememberedLogin::KEY);
        $deadline = session(RememberedLogin::KEY)['until'];
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $deadline, 2);
        $this->assertEqualsWithDelta($deadline, $response->getCookie(Auth::guard()->getRecallerName())->getExpiresTime(), 2);
        $this->withSession([SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->subHours(2)->timestamp])
            ->postJson(route('auth.session.keep-alive'))->assertOk()->assertJsonPath('expiresAt', $deadline)->assertJsonPath('remembered', true);
        $this->travel(31)->days();
        $this->postJson(route('auth.session.keep-alive'))->assertStatus(419);
        $this->assertGuest();
    }

    public function test_recaller_restores_lost_session_only_with_valid_device_deadline(): void
    {
        $user = User::factory()->create();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $recaller = Auth::guard()->getRecallerName();
        $cookies = [$recaller => $response->getCookie($recaller)->getValue(), RememberedLogin::KEY => $response->getCookie(RememberedLogin::KEY)->getValue()];
        session()->flush();
        Auth::forgetGuards();
        $this->withCredentials()->withCookies($cookies)->postJson(route('auth.session.keep-alive'))->assertOk()->assertJsonPath('remembered', true);
        $this->assertAuthenticatedAs($user);
        session()->flush();
        Auth::forgetGuards();
        $this->withCookie(RememberedLogin::KEY, 'invalid')->postJson(route('auth.session.keep-alive'))->assertStatus(419);
        $this->assertGuest();
    }

    public function test_password_change_invalidates_remembered_session(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $user->forceFill(['password' => bcrypt('a-new-password')])->save();
        Auth::setUser($user->fresh());
        $this->postJson(route('auth.session.keep-alive'))->assertStatus(419);
        $this->assertGuest();
    }

    public function test_app_launch_restores_login_after_session_loss_and_logout_blocks_reuse(): void
    {
        $user = User::factory()->create();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $recaller = Auth::guard()->getRecallerName();
        $cookies = [$recaller => $response->getCookie($recaller)->getValue(), RememberedLogin::KEY => $response->getCookie(RememberedLogin::KEY)->getValue()];

        $this->travel(29)->days();
        session()->flush();
        Auth::forgetGuards();
        // Login redirects a restored user to dashboard instead of asking for credentials.
        $this->withCredentials()->withCookies($cookies)->get('/login')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->postJson(route('auth.session.keep-alive'))->assertOk()->assertJsonPath('remembered', true);

        $this->post('/logout')->assertRedirect('/login');
        session()->flush();
        Auth::forgetGuards();
        $this->withCookies($cookies)->get('/dashboard?source=pwa')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_cannot_opt_out_of_idle_timeout_by_remembering(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertSessionMissing(RememberedLogin::KEY);
        $this->withSession([SessionTimeoutManager::LAST_ACTIVITY_KEY => now()->subMinutes(16)->timestamp])
            ->postJson(route('auth.session.keep-alive'))->assertStatus(419);
        $this->assertGuest();
    }

    public function test_unchecked_option_keeps_short_session_and_logout_revokes_remembered_access(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionMissing(RememberedLogin::KEY);
        $this->post('/logout');
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $recaller = Auth::guard()->getRecallerName();
        $this->withCookie($recaller, $response->getCookie($recaller)->getValue());
        $token = $user->fresh()->remember_token;
        $this->post('/logout')->assertCookieExpired(RememberedLogin::KEY)->assertCookieExpired(Auth::guard()->getRecallerName());
        $this->assertNotEquals($token, $user->fresh()->remember_token);
        $this->assertGuest();
    }
}
