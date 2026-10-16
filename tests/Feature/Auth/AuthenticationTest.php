<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        $this->mock(GoogleFactCheckFeedService::class)
            ->shouldReceive('latest')->andReturn(['items' => [], 'configured' => true]);
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertViewIs('auth.login')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_navigation_menu_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->get('/dashboard');

        $response
            ->assertOk()
            ->assertViewIs('user.dashboard')
            ->assertSee('action="'.route('logout', absolute: false).'"', false);
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }
}
