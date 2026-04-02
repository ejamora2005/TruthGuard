<style>
    @keyframes tgAuthFloat {
        0%,
        100% {
            transform: translate3d(0, 0, 0) rotate(0deg);
        }
        50% {
            transform: translate3d(0, -16px, 0) rotate(6deg);
        }
    }

    @keyframes tgAuthDrift {
        0%,
        100% {
            transform: translate3d(0, 0, 0);
        }
        25% {
            transform: translate3d(14px, -10px, 0);
        }
        50% {
            transform: translate3d(4px, 12px, 0);
        }
        75% {
            transform: translate3d(-12px, -6px, 0);
        }
    }

    @keyframes tgAuthSpin {
        from {
            transform: rotate(0deg);
        }
        to {
            transform: rotate(360deg);
        }
    }

    .tg-auth-float {
        animation: tgAuthFloat 8s ease-in-out infinite;
    }

    .tg-auth-drift {
        animation: tgAuthDrift 12s ease-in-out infinite;
    }

    .tg-auth-spin {
        animation: tgAuthSpin 20s linear infinite;
    }

    @media (prefers-reduced-motion: reduce) {
        .tg-auth-float,
        .tg-auth-drift,
        .tg-auth-spin {
            animation: none !important;
        }
    }
</style>

<div class="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
    <span class="tg-auth-float absolute left-[8%] top-[22%] h-10 w-10 rounded-full bg-cyan-300/35 shadow-lg shadow-cyan-200/40"></span>
    <span class="tg-auth-drift absolute left-[18%] top-[66%] h-6 w-6 rounded-full bg-blue-300/40"></span>
    <span class="tg-auth-drift absolute right-[11%] top-[24%] h-12 w-12 rounded-full bg-blue-300/35 shadow-lg shadow-blue-200/40"></span>
    <span class="tg-auth-float absolute right-[19%] top-[70%] h-7 w-7 rounded-full bg-cyan-300/40"></span>
    <span class="tg-auth-drift absolute left-[30%] top-[16%] h-5 w-5 rounded-full bg-sky-200/55"></span>

    <span class="tg-auth-spin absolute left-1/2 top-1/2 h-44 w-44 -translate-x-1/2 -translate-y-1/2 rounded-full border border-cyan-200/50"></span>
    <span class="tg-auth-spin absolute left-1/2 top-1/2 h-32 w-32 -translate-x-1/2 -translate-y-1/2 rounded-full border border-blue-200/45 [animation-direction:reverse]"></span>
</div>
