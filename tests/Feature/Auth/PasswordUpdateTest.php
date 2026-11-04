<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNotNull($user->password_set_at);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertDontSee('Finish securing your TruthGuard account');
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-password-form')
            ->set('current_password', 'wrong-password')
            ->set('password', 'new-password')
            ->set('password_confirmation', 'new-password')
            ->call('updatePassword');

        $component
            ->assertHasErrors(['current_password'])
            ->assertNoRedirect();
    }
    public function test_current_password_cannot_be_reused_as_new_password(): void
    {
        $user = User::factory()->create();
        $originalHash = $user->password;
        $originalSetAt = $user->password_set_at;
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('updatePassword')
            ->assertHasErrors(['password' => 'different'])
            ->assertNotDispatched('password-updated');

        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertEquals($originalSetAt, $user->fresh()->password_set_at);
    }

}
