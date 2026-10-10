<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GlobalLoaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_and_auth_pages_have_one_global_loader(): void
    {
        foreach (['/', '/login', '/register'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), 'id="truthguard-app-splash"'));
            $response->assertSee('window.TruthGuardLoader =', false);
        }
    }

    public function test_native_login_and_logout_keep_their_redirects(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect(route('login', absolute: false));
        $this->assertGuest();
    }

    public function test_native_registration_returns_to_login_with_feedback(): void
    {
        Notification::fake();
        $this->post('/register', [
            'name' => 'Loader Test',
            'email' => 'loader@example.com',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertDatabaseHas('users', ['email' => 'loader@example.com']);
    }
}
