@once
<div id="truthguard-app-splash" class="tg-loader" data-truthguard-splash role="status" aria-live="polite" aria-hidden="true" aria-label="Loading TruthGuard" tabindex="-1">
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
        </div>
        <p>TruthGuard</p>
        <span class="tg-loader-status">Loading&hellip;</span>
        <div class="tg-loader-progress" aria-hidden="true"><span></span></div>
    </div>
</div>
@endonce
