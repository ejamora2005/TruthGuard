<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_notice_discloses_actual_processing_storage_and_contact(): void
    {
        $response = $this->get(route('privacy.policy'))->assertOk();
        foreach (['privacy-overview', 'ai-processing', 'privacy-storage', 'privacy-security', 'privacy-choices', 'privacy-contact'] as $id) {
            $response->assertSee('id="'.$id.'"', false)->assertSee('href="#'.$id.'"', false);
        }
        $response->assertSee('Older records are archived rather than automatically deleted')
            ->assertSee('sampled video frames')
            ->assertSee('Anyone who obtains a file URL may be able to access that file')
            ->assertSee('A reused answer is not a new independent detector assessment')
            ->assertSee(config('app.privacy_contact_email'))
            ->assertDontSee('should be reviewed by a qualified legal professional');
    }

    public function test_old_policy_acceptance_requires_new_acknowledgement_and_keeps_an_exit(): void
    {
        $user = User::factory()->create(['privacy_policy_version' => '2026-07-28', 'privacy_policy_accepted_at' => now()->subDay()]);
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('privacy.consent'));
        $this->get(route('privacy.consent'))->assertOk()->assertSee('Sign out instead')
            ->assertSee('Optional permissions remain my choice.')
            ->assertSee(config('app.privacy_policy_version'));
        $this->post(route('privacy.accept'), [])->assertSessionHasErrors('privacy_policy_acceptance');
        $this->assertSame('2026-07-28', $user->fresh()->privacy_policy_version);
        $this->post(route('privacy.accept'), ['privacy_policy_acceptance' => '1'])->assertRedirect();
        $this->assertSame(config('app.privacy_policy_version'), $user->fresh()->privacy_policy_version);
    }
}
