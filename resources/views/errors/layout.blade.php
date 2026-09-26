@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo-transparent.png'), '/');
    $logoFile = public_path($logoPath);
    $logoUrl = is_file($logoFile) ? asset($logoPath) : asset('images/truthguard-logo-transparent.png');
    $actions = $actions ?? [];
    $variant = $variant ?? 'system';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b1224">
    <title>{{ $code }} · {{ $title }} · TruthGuard</title>
    <link rel="icon" href="{{ asset(config('app.truthguard_favicon', 'favicon.ico')) }}">
    @vite(['resources/css/app.css'])
    <style>
        :root {
            color-scheme: light dark;
            --tg-ink: #0f172a;
            --tg-muted: #64748b;
            --tg-blue: #2563eb;
            --tg-indigo: #4f46e5;
            --tg-violet: #7c3aed;
            --tg-canvas: #f7faff;
            --tg-card: rgba(255, 255, 255, 0.82);
            --tg-border: rgba(148, 163, 184, 0.28);
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            background: var(--tg-canvas);
            color: var(--tg-ink);
            font-family: Poppins, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .tg-error-page {
            position: relative;
            isolation: isolate;
            display: grid;
            min-height: 100dvh;
            overflow: hidden;
            place-items: center;
            padding: 32px 20px;
            background:
                radial-gradient(circle at 10% 15%, rgba(96, 165, 250, .19), transparent 29rem),
                radial-gradient(circle at 88% 82%, rgba(124, 58, 237, .14), transparent 31rem),
                linear-gradient(135deg, #f8fbff 0%, #eef4ff 48%, #fbf9ff 100%);
        }
        .tg-error-page::before {
            position: absolute;
            inset: -35%;
            z-index: -2;
            content: "";
            opacity: .48;
            background-image: linear-gradient(rgba(37, 99, 235, .07) 1px, transparent 1px), linear-gradient(90deg, rgba(37, 99, 235, .07) 1px, transparent 1px);
            background-size: 44px 44px;
            transform: rotate(-8deg);
            animation: tg-grid-drift 28s linear infinite;
        }
        .tg-error-page::after {
            position: absolute;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            content: "";
            background: radial-gradient(circle at center, transparent 0 24%, rgba(255,255,255,.4) 70%, rgba(255,255,255,.72) 100%);
        }
        .tg-error-orb { position: absolute; border-radius: 999px; filter: blur(1px); pointer-events: none; }
        .tg-error-orb-one { top: 12%; left: 9%; width: 8px; height: 8px; background: #60a5fa; box-shadow: 0 0 24px 5px rgba(96,165,250,.45); animation: tg-float 8s ease-in-out infinite; }
        .tg-error-orb-two { right: 14%; bottom: 22%; width: 6px; height: 6px; background: #a78bfa; box-shadow: 0 0 22px 4px rgba(167,139,250,.48); animation: tg-float 10s 1s ease-in-out infinite reverse; }
        .tg-error-orb-three { top: 28%; right: 24%; width: 4px; height: 4px; background: #38bdf8; animation: tg-float 7s 2s ease-in-out infinite; }

        .tg-error-wrap { width: min(100%, 980px); animation: tg-enter .72s cubic-bezier(.2,.8,.2,1) both; }
        .tg-error-brand { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 28px; color: #334155; font-size: 15px; font-weight: 800; letter-spacing: -.02em; text-decoration: none; }
        .tg-error-brand img { width: 34px; height: 34px; object-fit: contain; }
        .tg-error-brand span { background: linear-gradient(90deg, var(--tg-blue), var(--tg-violet)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .tg-error-card {
            position: relative;
            display: grid;
            grid-template-columns: minmax(260px, .86fr) minmax(0, 1.14fr);
            gap: clamp(30px, 6vw, 80px);
            align-items: center;
            overflow: hidden;
            border: 1px solid var(--tg-border);
            border-radius: 30px;
            padding: clamp(26px, 5vw, 64px);
            background: var(--tg-card);
            box-shadow: 0 30px 80px rgba(30, 64, 175, .12), inset 0 1px 0 rgba(255,255,255,.86);
            backdrop-filter: blur(18px);
        }
        .tg-error-card::before { position: absolute; top: 0; right: 13%; left: 13%; height: 1px; content: ""; background: linear-gradient(90deg, transparent, rgba(96,165,250,.75), transparent); }
        .tg-error-visual { position: relative; display: grid; min-height: 290px; place-items: center; }
        .tg-error-visual::before { position: absolute; width: 220px; height: 220px; content: ""; border-radius: 50%; background: radial-gradient(circle, rgba(96,165,250,.24), rgba(129,140,248,.08) 42%, transparent 70%); animation: tg-glow 5s ease-in-out infinite; }
        .tg-error-code { position: absolute; top: 50%; left: 50%; color: rgba(37,99,235,.1); font-size: clamp(7rem, 16vw, 11rem); font-weight: 900; letter-spacing: -.1em; line-height: 1; transform: translate(-50%, -57%); user-select: none; animation: tg-breathe 5s ease-in-out infinite; }
        .tg-error-illustration { position: relative; z-index: 1; width: min(100%, 235px); color: var(--tg-blue); filter: drop-shadow(0 18px 25px rgba(37,99,235,.16)); animation: tg-float 7s ease-in-out infinite; }
        .tg-error-illustration svg { display: block; width: 100%; height: auto; overflow: visible; }
        .tg-error-scan { position: absolute; z-index: 2; width: 180px; height: 2px; border-radius: 999px; background: linear-gradient(90deg, transparent, rgba(56,189,248,.85), transparent); box-shadow: 0 0 16px rgba(56,189,248,.65); animation: tg-scan 4.5s ease-in-out infinite; }
        .tg-error-copy { max-width: 490px; }
        .tg-error-kicker { display: inline-flex; align-items: center; gap: 8px; margin: 0 0 15px; color: var(--tg-blue); font-size: 11px; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
        .tg-error-kicker::before { width: 28px; height: 1px; content: ""; background: currentColor; }
        .tg-error-copy h1 { margin: 0; color: var(--tg-ink); font-size: clamp(2rem, 4vw, 3.45rem); font-weight: 800; letter-spacing: -.055em; line-height: 1.08; }
        .tg-error-copy p { max-width: 42ch; margin: 18px 0 0; color: var(--tg-muted); font-size: 15px; line-height: 1.8; }
        .tg-error-actions { display: flex; flex-wrap: wrap; gap: 11px; margin-top: 30px; }
        .tg-error-button { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; gap: 9px; border: 1px solid transparent; border-radius: 13px; padding: 11px 17px; font: inherit; font-size: 13px; font-weight: 800; text-decoration: none; cursor: pointer; transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease, background 180ms ease; }
        .tg-error-button:hover { transform: translateY(-2px); }
        .tg-error-button:focus-visible { outline: 3px solid rgba(37,99,235,.3); outline-offset: 3px; }
        .tg-error-button-primary { color: white; background: linear-gradient(135deg, var(--tg-blue), var(--tg-indigo) 58%, var(--tg-violet)); box-shadow: 0 12px 23px rgba(79,70,229,.22), inset 0 1px 0 rgba(255,255,255,.22); }
        .tg-error-button-primary:hover { box-shadow: 0 16px 28px rgba(79,70,229,.3), inset 0 1px 0 rgba(255,255,255,.22); }
        .tg-error-button-secondary { border-color: rgba(148,163,184,.38); color: #475569; background: rgba(255,255,255,.58); }
        .tg-error-button-secondary:hover { border-color: rgba(96,165,250,.62); color: var(--tg-blue); background: rgba(255,255,255,.9); }
        .tg-error-arrow { font-size: 17px; line-height: 1; transition: transform 180ms ease; }
        .tg-error-button:hover .tg-error-arrow { transform: translateX(3px); }
        .tg-error-status { display: inline-flex; align-items: center; gap: 8px; margin-top: 24px; color: #64748b; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .tg-error-status-dot { width: 7px; height: 7px; border-radius: 50%; background: #34d399; box-shadow: 0 0 0 5px rgba(52,211,153,.12); animation: tg-status-pulse 2.8s ease-in-out infinite; }
        .tg-error-status-dot[data-tone="warning"] { background: #f59e0b; box-shadow: 0 0 0 5px rgba(245,158,11,.12); }

        .tg-illustration-line { fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2.5; }
        .tg-illustration-soft { fill: rgba(37,99,235,.1); stroke: currentColor; stroke-width: 2; }
        .tg-illustration-accent { fill: rgba(124,58,237,.18); stroke: #7c3aed; stroke-width: 2; }
        .tg-illustration-muted { fill: rgba(148,163,184,.14); stroke: #94a3b8; stroke-width: 2; }
        .tg-illustration-dot { fill: #38bdf8; animation: tg-status-pulse 2.5s ease-in-out infinite; }

        @keyframes tg-enter { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes tg-grid-drift { from { transform: rotate(-8deg) translate3d(0, 0, 0); } to { transform: rotate(-8deg) translate3d(44px, 44px, 0); } }
        @keyframes tg-float { 0%, 100% { transform: translate3d(0, 0, 0); } 50% { transform: translate3d(0, -9px, 0); } }
        @keyframes tg-breathe { 0%, 100% { opacity: .72; transform: translate(-50%, -57%) scale(1); } 50% { opacity: 1; transform: translate(-50%, -57%) scale(1.035); } }
        @keyframes tg-glow { 0%, 100% { opacity: .65; transform: scale(.94); } 50% { opacity: 1; transform: scale(1.08); } }
        @keyframes tg-scan { 0%, 100% { opacity: 0; transform: translateY(-90px); } 18%, 78% { opacity: 1; } 50% { transform: translateY(90px); } }
        @keyframes tg-status-pulse { 0%, 100% { opacity: .72; transform: scale(1); } 50% { opacity: 1; transform: scale(1.16); } }

        @media (prefers-color-scheme: dark) {
            :root { --tg-ink: #e5edff; --tg-muted: #a7b5ce; --tg-canvas: #080f20; --tg-card: rgba(15, 23, 42, .78); --tg-border: rgba(148,163,184,.2); }
            .tg-error-page { background: radial-gradient(circle at 10% 15%, rgba(37,99,235,.22), transparent 29rem), radial-gradient(circle at 88% 82%, rgba(124,58,237,.2), transparent 31rem), linear-gradient(135deg, #070d1b, #0d1830 52%, #120f25); }
            .tg-error-page::after { background: radial-gradient(circle at center, transparent 0 24%, rgba(8,15,32,.1) 70%, rgba(8,15,32,.48) 100%); }
            .tg-error-brand, .tg-error-button-secondary { color: #cbd5e1; }
            .tg-error-button-secondary { background: rgba(15,23,42,.54); }
            .tg-error-button-secondary:hover { background: rgba(30,41,59,.8); }
        }
        @media (max-width: 720px) {
            .tg-error-page { padding: 22px 14px; }
            .tg-error-brand { margin-bottom: 18px; }
            .tg-error-card { grid-template-columns: 1fr; gap: 10px; padding: 30px 24px 28px; border-radius: 24px; }
            .tg-error-visual { min-height: 220px; }
            .tg-error-visual::before { width: 180px; height: 180px; }
            .tg-error-illustration { width: 190px; }
            .tg-error-copy { text-align: center; }
            .tg-error-copy p { margin-inline: auto; }
            .tg-error-kicker { justify-content: center; }
            .tg-error-actions { justify-content: center; }
            .tg-error-status { display: flex; justify-content: center; }
        }
        @media (max-width: 390px) {
            .tg-error-actions { display: grid; grid-template-columns: 1fr; }
            .tg-error-button { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; transition-duration: .001ms !important; }
        }
    </style>
</head>
<body>
    <main class="tg-error-page" data-error-variant="{{ $variant }}">
        <span class="tg-error-orb tg-error-orb-one" aria-hidden="true"></span>
        <span class="tg-error-orb tg-error-orb-two" aria-hidden="true"></span>
        <span class="tg-error-orb tg-error-orb-three" aria-hidden="true"></span>

        <div class="tg-error-wrap">
            <a class="tg-error-brand" href="{{ url('/') }}" aria-label="TruthGuard home">
                <img src="{{ $logoUrl }}" alt="">
                <span>TruthGuard</span>
            </a>

            <section class="tg-error-card" aria-labelledby="error-title">
                <div class="tg-error-visual" aria-hidden="true">
                    <span class="tg-error-code">{{ $code }}</span>
                    <span class="tg-error-scan"></span>
                    <div class="tg-error-illustration">
                        @switch($variant)
                            @case('not-found')
                                <svg viewBox="0 0 240 210" role="img"><circle class="tg-illustration-soft" cx="103" cy="99" r="58"/><circle class="tg-illustration-line" cx="103" cy="99" r="37"/><path class="tg-illustration-line" d="m130 127 38 38"/><path class="tg-illustration-line" d="m83 100 12 12 23-26"/><path class="tg-illustration-accent" d="M73 46h87" stroke-dasharray="4 8"/><circle class="tg-illustration-dot" cx="180" cy="57" r="5"/></svg>
                                @break
                            @case('restricted')
                                <svg viewBox="0 0 240 210" role="img"><path class="tg-illustration-soft" d="M120 22 187 48v49c0 39-26 70-67 88-41-18-67-49-67-88V48l67-26Z"/><path class="tg-illustration-line" d="M120 50 160 65v30c0 25-15 44-40 57-25-13-40-32-40-57V65l40-15Z"/><rect class="tg-illustration-accent" x="96" y="91" width="48" height="35" rx="8"/><path class="tg-illustration-line" d="M106 91v-9c0-16 28-16 28 0v9"/><circle class="tg-illustration-dot" cx="120" cy="108" r="4"/></svg>
                                @break
                            @case('session')
                                <svg viewBox="0 0 240 210" role="img"><circle class="tg-illustration-soft" cx="120" cy="104" r="71"/><circle class="tg-illustration-line" cx="120" cy="104" r="48"/><path class="tg-illustration-line" d="M120 75v32l22 13"/><path class="tg-illustration-accent" d="M120 22v-10M120 196v-10M38 104H28M212 104h-10"/><path class="tg-illustration-line" d="M84 52 72 41M156 52l12-11"/><circle class="tg-illustration-dot" cx="120" cy="104" r="5"/></svg>
                                @break
                            @case('rate-limit')
                                <svg viewBox="0 0 240 210" role="img"><path class="tg-illustration-muted" d="M49 164h142"/><path class="tg-illustration-line" d="M69 164v-38M99 164V91M129 164v-59M159 164V68"/><path class="tg-illustration-accent" d="m57 85 35-20 30 12 39-42"/><path class="tg-illustration-line" d="m148 35 13 0-1 13"/><circle class="tg-illustration-dot" cx="69" cy="126" r="5"/><circle class="tg-illustration-dot" cx="99" cy="91" r="5"/><circle class="tg-illustration-dot" cx="129" cy="105" r="5"/><circle class="tg-illustration-dot" cx="159" cy="68" r="5"/></svg>
                                @break
                            @case('maintenance')
                                <svg viewBox="0 0 240 210" role="img"><rect class="tg-illustration-soft" x="45" y="52" width="150" height="112" rx="18"/><path class="tg-illustration-line" d="M45 84h150M76 112h88M76 136h54"/><circle class="tg-illustration-dot" cx="168" cy="113" r="5"/><circle class="tg-illustration-dot" cx="168" cy="137" r="5"/><path class="tg-illustration-accent" d="m78 36 18 18-27 27-18-18 27-27Z"/><path class="tg-illustration-line" d="m89 47 25-25 14 14-25 25M107 38l-9-9"/></svg>
                                @break
                            @default
                                <svg viewBox="0 0 240 210" role="img"><rect class="tg-illustration-soft" x="48" y="52" width="144" height="112" rx="17"/><path class="tg-illustration-line" d="M48 84h144M74 112h92M74 137h54"/><circle class="tg-illustration-dot" cx="166" cy="112" r="5"/><circle class="tg-illustration-dot" cx="166" cy="137" r="5"/><path class="tg-illustration-accent" d="m90 32 18 18-30 30-18-18 30-30Z"/><path class="tg-illustration-line" d="m102 44 24-24 14 14-24 24"/></svg>
                        @endswitch
                    </div>
                </div>

                <div class="tg-error-copy">
                    <p class="tg-error-kicker">TruthGuard system signal</p>
                    <h1 id="error-title">{{ $title }}</h1>
                    <p>{{ $description }}</p>

                    <div class="tg-error-actions">
                        @foreach ($actions as $action)
                            @if (($action['type'] ?? '') === 'back')
                                <button type="button" class="tg-error-button tg-error-button-secondary" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '{{ url('/') }}'; }">
                                    {{ $action['label'] }}
                                </button>
                            @elseif (($action['type'] ?? '') === 'refresh')
                                <button type="button" class="tg-error-button tg-error-button-primary" onclick="window.location.reload()">
                                    {{ $action['label'] }} <span class="tg-error-arrow" aria-hidden="true">↻</span>
                                </button>
                            @elseif (($action['type'] ?? '') === 'retry')
                                <button type="button" class="tg-error-button tg-error-button-primary" onclick="window.location.reload()">
                                    {{ $action['label'] }} <span class="tg-error-arrow" aria-hidden="true">↻</span>
                                </button>
                            @else
                                <a class="tg-error-button {{ ($action['primary'] ?? true) ? 'tg-error-button-primary' : 'tg-error-button-secondary' }}" href="{{ $action['url'] ?? url('/') }}">
                                    {{ $action['label'] }} <span class="tg-error-arrow" aria-hidden="true">→</span>
                                </a>
                            @endif
                        @endforeach
                    </div>

                    <div class="tg-error-status">
                        <span class="tg-error-status-dot" data-tone="{{ $variant === 'maintenance' ? 'warning' : 'ok' }}" aria-hidden="true"></span>
                        <span>{{ $variant === 'maintenance' ? 'Deployment window in progress' : 'TruthGuard remains focused on signal' }}</span>
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
