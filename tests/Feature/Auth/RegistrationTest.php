<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk()->assertViewIs('auth.register')
            ->assertSee('name="email"', false)->assertSee('name="password"', false);
    }

    public function test_new_users_register_through_the_controller_and_sign_in_separately(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'Test User', 'email' => 'test@example.com',
            'password' => 'Testing-password-482!', 'password_confirmation' => 'Testing-password-482!'])
            ->assertSessionHasNoErrors()->assertRedirect(route('login'));
        $user = User::where('email', 'test@example.com')->sole();
        $this->assertTrue(Hash::check('Testing-password-482!', $user->password));
        $this->assertGuest();
    }

    public function test_mismatched_password_confirmation_cannot_create_an_account(): void
    {
        $this->post('/register', ['name' => 'Test User', 'email' => 'test@example.com',
            'password' => 'Testing-password-482!', 'password_confirmation' => 'another password'])
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
