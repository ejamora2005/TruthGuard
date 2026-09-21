<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_returns_to_selected_review_after_password_login(): void
    {
        $user = User::factory()->create();
        $url = route('dashboard.fact-check', ['factCheck' => 'review-123']);
        $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect($url)->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
    }

    public function test_untrusted_intended_url_is_not_used(): void
    {
        $user = User::factory()->create();
        $this->withSession(['url.intended' => 'https://example.org/dashboard/fact-checks/review-123'])
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
    }
}
