<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response
            ->assertOk()
            ->assertSeeVolt('profile.update-profile-information-form')
            ->assertSeeVolt('profile.update-password-form')
            ->assertSeeVolt('profile.delete-user-form');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'theme_preference' => 'ocean',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->profile);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
            'theme_preference' => 'ocean',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_photo_and_theme_can_be_updated(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => 'Theme Tester',
            'email' => $user->email,
            'theme_preference' => 'forest',
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();
        $storedPath = $user->profile_photo_path;

        $this->assertSame('forest', $user->theme_preference);
        $this->assertNotNull($storedPath);
        Storage::disk('public')->assertExists($storedPath);
        $this->assertSame('/storage/'.$storedPath, $user->profile_photo_url);
        $this->assertNotNull($user->profile);
        $this->assertSame('forest', $user->profile->theme_preference);
        $this->assertSame($storedPath, $user->profile->avatar_path);
        $this->assertSame($storedPath, $user->getRawOriginal('profile_photo_path'));
        $this->assertSame('forest', $user->getRawOriginal('theme_preference'));

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'avatar_path' => $storedPath,
            'theme_preference' => 'forest',
        ]);
    }

    public function test_profile_photo_rejects_files_over_configured_limit(): void
    {
        Storage::fake('public');
        config()->set('truthguard.uploads.avatar_max_mb', 1);
        config()->set('truthguard.uploads.avatar_max_kb', 1024);
        config()->set('truthguard.uploads.avatar_max_bytes', 1024 * 1024);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->from('/profile?section=photo')->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'theme_preference' => 'ocean',
            'return_section' => 'photo',
            'avatar' => UploadedFile::fake()->image('large-avatar.png')->size(1025),
        ]);

        $response
            ->assertRedirect('/profile?section=photo')
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_profile_photo_can_be_removed_and_cleared_from_database(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'profile_photo_path' => 'profile-photos/existing-avatar.png',
            'theme_preference' => 'sunset',
        ]);

        $user->profile()->updateOrCreate([], [
            'avatar_path' => 'profile-photos/existing-avatar.png',
            'theme_preference' => 'sunset',
        ]);

        Storage::disk('public')->put('profile-photos/existing-avatar.png', 'avatar-content');

        $this->actingAs($user);

        Volt::test('profile.update-profile-information-form')
            ->call('removeProfilePhoto')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertNull($user->profile_photo_path);
        $this->assertNull($user->getRawOriginal('profile_photo_path'));
        $this->assertSame('sunset', $user->theme_preference);
        $this->assertSame('sunset', $user->profile?->theme_preference);
        $this->assertNull($user->profile?->avatar_path);
        Storage::disk('public')->assertMissing('profile-photos/existing-avatar.png');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'avatar_path' => null,
            'theme_preference' => 'sunset',
        ]);
    }

    public function test_user_gets_a_database_profile_record_when_created(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->fresh()->profile);
        $this->assertSame('ocean', $user->fresh()->profile->theme_preference);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $component
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $component
            ->assertHasErrors('password')
            ->assertNoRedirect();

        $this->assertNotNull($user->fresh());
    }
}
