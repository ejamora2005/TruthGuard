<section class="tg-settings-account" aria-label="Your account">
    <div class="tg-settings-account-main">
        <button type="button" class="tg-settings-account-avatar" @click="openSettings('photo')" aria-label="Change profile photo" title="Change profile photo">
            <img src="{{ $profileAvatarUrl }}" :src="profileAvatarUrl" alt="" width="76" height="76">
            <span class="tg-settings-account-camera" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5 6 8H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-2l-2-3Z"/><circle cx="12" cy="14" r="3.5"/></svg>
            </span>
        </button>
        <div class="tg-settings-account-identity">
            <p class="tg-settings-account-label">Account settings</p>
            <h1 x-text="profileName || 'TruthGuard User'">{{ $fullName ?: 'TruthGuard User' }}</h1>
            <p class="tg-settings-account-email" x-text="profileEmail">{{ $user?->email }}</p>
        </div>
        <button type="button" class="tg-settings-account-edit" @click="openSettings('personal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15l-1 6Z"/></svg>
            Edit profile
        </button>
    </div>
    <div class="tg-settings-account-meta">
        <span class="tg-settings-account-role">{{ $roleLabel }}</span>
        <span @class(['tg-settings-account-verification', 'is-verified' => $emailVerified])>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                @if ($emailVerified)
                    <path d="M12 3 5 6v5c0 5 7 10 7 10s7-5 7-10V6l-7-3Z m-3 9 2 2 4-4"/>
                @else
                    <circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 4h.01"/>
                @endif
            </svg>
            {{ $emailVerified ? 'Email verified' : 'Email not verified' }}
        </span>
        <span class="tg-settings-account-member">Member since {{ $createdDate }}</span>
    </div>
</section>
