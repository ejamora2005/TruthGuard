@php
    $truthGuardLogoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $truthGuardLogoUrl = is_file(public_path($truthGuardLogoPath))
        ? asset($truthGuardLogoPath)
        : asset('images/truthguard-logo.png');
@endphp

<style>
    @keyframes tgCrossScreenRight {
        0% {
            transform: translate3d(-16vw, 0, 0) rotate(-7deg) scale(0.9);
            opacity: 0;
        }
        12% {
            opacity: 0.95;
        }
        88% {
            opacity: 0.95;
        }
        100% {
            transform: translate3d(118vw, var(--drift-y, 0px), 0) rotate(7deg) scale(1.08);
            opacity: 0;
        }
    }

    @keyframes tgCrossScreenLeft {
        0% {
            transform: translate3d(118vw, 0, 0) rotate(7deg) scale(0.9);
            opacity: 0;
        }
        12% {
            opacity: 0.95;
        }
        88% {
            opacity: 0.95;
        }
        100% {
            transform: translate3d(-18vw, var(--drift-y, 0px), 0) rotate(-7deg) scale(1.08);
            opacity: 0;
        }
    }

    @keyframes tgIconBob {
        0%,
        100% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-5px);
        }
    }

    .tg-social-lane {
        position: absolute;
        left: 0;
        right: 0;
        top: var(--top);
    }

    .tg-moving-icon {
        position: absolute;
        left: 0;
        top: 0;
        display: inline-flex;
        color: var(--icon-color, #0f172a);
        filter: drop-shadow(0 10px 20px rgba(15, 23, 42, 0.2));
    }

    .tg-moving-icon svg,
    .tg-moving-icon img {
        width: 3.15rem;
        height: 3.15rem;
        display: block;
        animation: tgIconBob 5.6s ease-in-out infinite;
    }

    .tg-move-right {
        animation: tgCrossScreenRight var(--duration, 20s) linear infinite;
        animation-delay: var(--delay, 0s);
    }

    .tg-move-left {
        animation: tgCrossScreenLeft var(--duration, 20s) linear infinite;
        animation-delay: var(--delay, 0s);
    }

    @media (max-width: 768px) {
        .tg-moving-icon svg,
        .tg-moving-icon img {
            width: 2.45rem;
            height: 2.45rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .tg-move-right,
        .tg-move-left,
        .tg-moving-icon svg,
        .tg-moving-icon img {
            animation: none !important;
            opacity: 0.4;
        }
    }
</style>

<div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
    <div class="tg-social-lane" style="--top: 8%;">
        <span class="tg-moving-icon tg-move-right" style="--icon-color: #1877F2; --duration: 18s; --delay: -2s; --drift-y: 18px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M13.8 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5H17V4c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.3V10H8v3h2.4v8h3.4Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 19%;">
        <span class="tg-moving-icon tg-move-left" style="--icon-color: #111827; --duration: 24s; --delay: -10s; --drift-y: -18px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M18.9 3H22l-6.8 7.8L23.2 21h-6.3l-4.9-6.4L6.4 21H3.3l7.3-8.4L.8 3h6.4l4.4 5.8L18.9 3Zm-1.1 16h1.8L6.2 4.9H4.3L17.8 19Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 30%;">
        <span class="tg-moving-icon tg-move-right" style="--icon-color: #E1306C; --duration: 20s; --delay: -6.5s; --drift-y: -14px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <rect x="4.5" y="4.5" width="15" height="15" rx="4.5"></rect>
                <circle cx="12" cy="12" r="3.2"></circle>
                <circle cx="17.1" cy="6.9" r="1"></circle>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 41%;">
        <span class="tg-moving-icon tg-move-left" style="--icon-color: #0A66C2; --duration: 22s; --delay: -4s; --drift-y: 16px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M6.4 8.5a1.9 1.9 0 1 1 0-3.8 1.9 1.9 0 0 1 0 3.8ZM4.8 9.7h3.1V19H4.8V9.7ZM10.2 9.7h2.9V11h.1c.4-.8 1.4-1.6 2.9-1.6 3.1 0 3.7 2 3.7 4.7V19h-3.1v-4.2c0-1 0-2.3-1.4-2.3s-1.6 1.1-1.6 2.2V19h-3.1V9.7Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 52%;">
        <span class="tg-moving-icon tg-move-right" style="--icon-color: #00B5D8; --duration: 19s; --delay: -12s; --drift-y: 20px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M14.8 5.3v8.1a4.6 4.6 0 1 1-3.7-4.5v2.2a2.4 2.4 0 1 0 1.5 2.3V3h2.2c.6 1.8 2.1 3.1 4 3.4v2.2a6.7 6.7 0 0 1-4-1.3Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 63%;">
        <span class="tg-moving-icon tg-move-left" style="--icon-color: #FF0000; --duration: 21s; --delay: -8.5s; --drift-y: -20px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M23 12c0 6.1-4.9 11-11 11S1 18.1 1 12 5.9 1 12 1s11 4.9 11 11ZM10 8.5v7l6-3.5-6-3.5Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 74%;">
        <span class="tg-moving-icon tg-move-right" style="--icon-color: #111827; --duration: 25s; --delay: -16s; --drift-y: 15px;">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M18.9 3H22l-6.8 7.8L23.2 21h-6.3l-4.9-6.4L6.4 21H3.3l7.3-8.4L.8 3h6.4l4.4 5.8L18.9 3Zm-1.1 16h1.8L6.2 4.9H4.3L17.8 19Z"/>
            </svg>
        </span>
    </div>

    <div class="tg-social-lane" style="--top: 86%;">
        <span class="tg-moving-icon tg-move-left" style="--icon-color: #334155; --duration: 26s; --delay: -11s; --drift-y: -16px;">
            <img src="{{ $truthGuardLogoUrl }}" alt="TruthGuard logo">
        </span>
    </div>
</div>
