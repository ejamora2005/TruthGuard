<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\WelcomeToTruthGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_google_login_returns_to_the_selected_review(): void
    {
        Notification::fake();
        $this->mockGoogleUser();
        $url = route('dashboard.fact-check', ['factCheck' => 'review-123']);

        $this->withSession(['url.intended' => $url])->get(route('google.callback'))
            ->assertRedirect($url)->assertSessionMissing('url.intended');
        $this->assertAuthenticated();
    }

    public function test_verified_google_user_is_created_as_active_regular_user(): void
    {
        Notification::fake();
        config()->set('app.truthguard_admin_emails', ['new-user@example.com']);

        $this->mockGoogleUser([
            'id' => 'google-new-123',
            'email' => 'New-User@Example.com',
            'name' => 'New Google User',
            'avatar' => 'https://lh3.googleusercontent.com/a/new-avatar.png',
        ]);

        $response = $this->get(route('google.callback'));

        $user = User::where('email', 'new-user@example.com')->firstOrFail();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_admin);
        $this->assertSame('active', $user->subscription_status);
        $this->assertSame('google-new-123', $user->google_id);
        $this->assertSame('https://lh3.googleusercontent.com/a/new-avatar.png', $user->google_avatar_url);
        $this->assertNotNull($user->email_verified_at);

        Notification::assertSentTo($user, WelcomeToTruthGuard::class);
    }

    public function test_existing_email_is_linked_to_google_without_duplicate_account(): void
    {
        Notification::fake();

        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'email_verified_at' => null,
            'google_id' => null,
        ]);

        $this->mockGoogleUser([
            'id' => 'google-existing-456',
            'email' => 'existing@example.com',
            'name' => 'Existing Google User',
        ]);

        $response = $this->get(route('google.callback'));

        $existingUser->refresh();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($existingUser);
        $this->assertSame(1, User::count());
        $this->assertSame('google-existing-456', $existingUser->google_id);
        $this->assertNotNull($existingUser->email_verified_at);

        Notification::assertSentTo($existingUser, WelcomeToTruthGuard::class);
    }

    public function test_duplicate_email_with_different_case_is_linked_instead_of_created(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'Person@Example.com',
            'google_id' => null,
        ]);

        $this->mockGoogleUser([
            'id' => 'google-case-789',
            'email' => 'person@example.com',
            'name' => 'Person Google',
        ]);

        $response = $this->get(route('google.callback'));

        $existingUser->refresh();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($existingUser);
        $this->assertSame(1, User::count());
        $this->assertSame('person@example.com', $existingUser->email);
        $this->assertSame('google-case-789', $existingUser->google_id);
    }

    public function test_google_provider_id_logs_in_linked_user_even_when_google_email_changes(): void
    {
        Notification::fake();

        $linkedUser = User::factory()->create([
            'email' => 'old@example.com',
            'google_id' => 'google-stable-999',
        ]);

        $this->mockGoogleUser([
            'id' => 'google-stable-999',
            'email' => 'changed@example.com',
            'name' => 'Stable Google User',
        ]);

        $response = $this->get(route('google.callback'));

        $linkedUser->refresh();

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($linkedUser);
        $this->assertSame(1, User::count());
        $this->assertSame('changed@example.com', $linkedUser->email);
        $this->assertSame('google-stable-999', $linkedUser->google_id);

        Notification::assertSentTo($linkedUser, WelcomeToTruthGuard::class);
    }

    public function test_suspended_google_users_are_denied(): void
    {
        $this->assertBlockedGoogleUserIsDenied('suspended');
    }

    public function test_inactive_google_users_are_denied(): void
    {
        $this->assertBlockedGoogleUserIsDenied('inactive');
    }

    public function test_unverified_google_email_is_denied(): void
    {
        $this->mockGoogleUser([
            'id' => 'google-unverified-222',
            'email' => 'unverified@example.com',
            'verified' => false,
        ]);

        $response = $this->get(route('google.callback'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_error', 'Use a Google account with a verified email address.');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    private function mockGoogleUser(array $overrides = []): void
    {
        $id = (string) ($overrides['id'] ?? 'google-test-123');
        $email = (string) ($overrides['email'] ?? 'google-user@example.com');
        $name = (string) ($overrides['name'] ?? 'Google User');
        $avatar = (string) ($overrides['avatar'] ?? 'https://lh3.googleusercontent.com/a/avatar.png');
        $verified = $overrides['verified'] ?? true;

        $socialUser = (new SocialiteUser)
            ->setRaw([
                'sub' => $id,
                'email' => $email,
                'email_verified' => $verified,
                'verified_email' => $verified,
                'name' => $name,
                'picture' => $avatar,
            ])
            ->map([
                'id' => $id,
                'email' => $email,
                'name' => $name,
                'avatar' => $avatar,
            ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);
    }

    private function assertBlockedGoogleUserIsDenied(string $status): void
    {
        $blockedUser = User::factory()->create([
            'email' => "{$status}@example.com",
            'google_id' => "google-{$status}-111",
            'subscription_status' => $status,
        ]);

        $this->mockGoogleUser([
            'id' => "google-{$status}-111",
            'email' => "{$status}@example.com",
        ]);

        $response = $this->get(route('google.callback'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('auth_error', 'Your account is not active. Please contact TruthGuard support.');

        $this->assertGuest();
        $this->assertSame($status, $blockedUser->refresh()->subscription_status);
    }
}
