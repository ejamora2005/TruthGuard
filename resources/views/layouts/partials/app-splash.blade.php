@php
    $truthguardTransparentLogoPath = 'images/truthguard-logo-transparent.png';
    $truthguardSplashLogoPath = is_file(public_path($truthguardTransparentLogoPath))
        ? $truthguardTransparentLogoPath
        : ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $truthguardSplashLogoUrl = is_file(public_path($truthguardSplashLogoPath))
        ? asset($truthguardSplashLogoPath)
        : '';
@endphp

<div
    id="truthguard-app-splash"
    class="truthguard-app-splash"
    data-truthguard-splash
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
        </div>
    </div>
</div>
