<<<<<<< HEAD
@once
<div id="truthguard-app-splash" class="tg-loader" data-truthguard-splash role="status" aria-live="polite" aria-hidden="true" aria-label="Loading TruthGuard">
    <div class="tg-loader-panel">
        <div class="tg-loader-mark" aria-hidden="true">
            <span class="tg-loader-ring"></span>
            {{-- Original bitmap, with only the eye isolated for animation. --}}
            <svg class="tg-loader-logo" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <clipPath id="tg-loader-eye-clip">
                        <path d="M435 487 Q466 406 552 410 Q647 409 705 487 Q649 562 550 560 Q470 556 435 517 Z" />
                    </clipPath>
                </defs>
                <image href="{{ asset('images/truthguard-logo-transparent.png') }}" width="1024" height="1024" />
                <g clip-path="url(#tg-loader-eye-clip)">
                    <path fill="#153e50" d="M420 400H720V570H420Z" />
                    <g class="tg-loader-eye">
                        <g clip-path="url(#tg-loader-eye-clip)">
                            <image href="{{ asset('images/truthguard-logo-transparent.png') }}" width="1024" height="1024" />
                        </g>
                    </g>
                </g>
            </svg>
=======
@php
    $truthguardTransparentLogoPath = 'images/truthguard-logo-transparent.png';
    $truthguardSplashLogoPath = is_file(public_path($truthguardTransparentLogoPath))
        ? $truthguardTransparentLogoPath
        : ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $truthguardSplashLogoUrl = is_file(public_path($truthguardSplashLogoPath))
        ? asset($truthguardSplashLogoPath)
        : '';
@endphp

@php($truthguardAuthCompleted = session()->has('truthguard_auth_completed'))

<div
    id="truthguard-app-splash"
    class="truthguard-app-splash"
    data-truthguard-splash
    data-auth-complete="{{ $truthguardAuthCompleted ? '1' : '0' }}"
    data-min-duration="1050"
    role="status"
    aria-live="polite"
    aria-label="TruthGuard launch screen"
>
    <div class="truthguard-app-splash-panel">
        <div class="truthguard-app-splash-mark" aria-hidden="true">
            <span class="truthguard-app-splash-ring"></span>
            <span class="truthguard-app-splash-shield">
                @if ($truthguardSplashLogoUrl !== '')
                    <img src="{{ $truthguardSplashLogoUrl }}" alt="">
                @else
                    <span>TG</span>
                @endif
            </span>
        </div>

        <div class="truthguard-app-splash-copy">
            <p class="truthguard-app-splash-kicker">TruthGuard</p>
        </div>

        <div class="truthguard-app-splash-progress" aria-hidden="true">
            <span></span>
>>>>>>> 2c237fa7aebfa114f1b8f82483965b293c91e8e7
        </div>
        <p>TruthGuard</p>
        <span class="tg-loader-status">Loading&hellip;</span>
        <div class="tg-loader-progress" aria-hidden="true"><span></span></div>
    </div>
</div>
@endonce
