<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $avatarMaxMb = (int) config('truthguard.uploads.avatar_max_mb', 5);
        $avatarMaxKb = (int) config('truthguard.uploads.avatar_max_kb', $avatarMaxMb * 1024);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'username' => ['nullable', 'string', 'alpha_dash:ascii', 'max:64', Rule::unique(User::class)->ignore($user->id)],
            'theme_preference' => ['required', 'string', Rule::in(['ocean', 'forest', 'sunset'])],
            'email_updates_enabled' => ['sometimes', 'boolean'],
            'avatar' => ['nullable', 'image', "max:{$avatarMaxKb}", 'mimes:jpg,jpeg,png,webp,gif'],
        ], [
            'avatar.max' => "Profile photos must be {$avatarMaxMb}MB or less.",
        ]);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($request->has('username')) {
            $user->username = filled($validated['username'] ?? null)
                ? Str::lower((string) $validated['username'])
                : null;
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($request->has('email_updates_enabled')) {
            $user->email_updates_enabled = $request->boolean('email_updates_enabled');
        }

        $storedAvatarPath = $user->profile_photo_path;

        if ($request->hasFile('avatar')) {
            $uploadedAvatar = $request->file('avatar');
            $newAvatarPath = $uploadedAvatar instanceof UploadedFile
                ? $this->persistProfilePhoto($uploadedAvatar)
                : null;

            if ($newAvatarPath === null) {
                return back()
                    ->withErrors(['avatar' => 'The avatar failed to upload.'])
                    ->withInput($request->except('avatar'));
            }

            if ($storedAvatarPath) {
                Storage::disk('public')->delete($storedAvatarPath);
            }

            $storedAvatarPath = $newAvatarPath;
        }

        $user->save();
        $user->syncProfilePreferences($storedAvatarPath, $validated['theme_preference']);

        $returnSection = $request->string('return_section')->toString();
        $routeParameters = in_array($returnSection, ['personal', 'photo', 'appearance'], true)
            ? ['section' => $returnSection]
            : [];

        return redirect()
            ->route('profile', $routeParameters)
            ->with('status', 'profile-updated');
    }

    private function persistProfilePhoto(UploadedFile $uploadedFile): ?string
    {
        if (! $uploadedFile->isValid()) {
            return null;
        }

        $sourcePath = $this->resolveUploadedFilePath($uploadedFile);

        if ($sourcePath === null) {
            return null;
        }

        $targetPath = 'profile-photos/'.$uploadedFile->hashName();
        $stream = fopen($sourcePath, 'rb');

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $stored = Storage::disk('public')->writeStream($targetPath, $stream);
        } finally {
            fclose($stream);
        }

        return $stored ? $targetPath : null;
    }

    private function resolveUploadedFilePath(UploadedFile $uploadedFile): ?string
    {
        foreach ([$uploadedFile->getRealPath(), $uploadedFile->getPathname()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
