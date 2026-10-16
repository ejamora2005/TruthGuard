<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** TruthGuard does not expose the removed starter-kit email verification flow. */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unregistered_verification_endpoints_cannot_mark_email_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $this->assertFalse(Route::has('verification.verify'));
        $this->actingAs($user)->get('/verify-email')->assertNotFound();
        $this->get('/verify-email/'.$user->id.'/'.sha1($user->email))->assertNotFound();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
