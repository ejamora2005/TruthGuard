<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Password changes use the profile form; no starter-kit confirmation page exists. */
class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unregistered_password_confirmation_cannot_set_confirmation_state(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/confirm-password')->assertNotFound();
        $this->post('/confirm-password', ['password' => 'password'])->assertNotFound();
        $this->assertFalse(session()->has('auth.password_confirmed_at'));
    }
}
