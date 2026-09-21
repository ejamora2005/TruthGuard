@php
    // Fallback keeps static analyzers and direct-view previews from flagging undefined variable.
    $logoUrl = isset($logoUrl) ? (string) $logoUrl : '';

    $featureCards = [
        [
            'title' => 'Image & Video Checks',
            'description' => 'Upload media to check for signs of AI generation or manipulation, then review the result and its supporting context.',
            'icon' => 'M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-3Z M9.5 12l1.8 1.8 3.2-3.2',
        ],
        [
            'title' => 'Claims & Source Links',
            'description' => 'Describe a claim or paste a source link into the Fact Check workspace to start a review.',
            'icon' => 'M5 7h4v4H5z M15 7h4v4h-4z M10 13h4v4h-4z M9 9h6 M7 11v2 M17 11v2',
        ],
        [
            'title' => 'Public Claim Reviews',
            'description' => 'Browse recent fact checks from independent publishers, with source links and the publisher\'s rating.',
            'icon' => 'M8 8a2.5 2.5 0 1 0 0.001 0 M16 8a2.5 2.5 0 1 0 0.001 0 M12 16a2.5 2.5 0 1 0 0.001 0 M9.8 9.4l2.4 5.2 M14.2 9.4l-2.4 5.2',
        ],
        [
            'title' => 'Your Check History',
            'description' => 'Find previous checks by date, keyword, or result. Reopen a report to review its confidence and evidence.',
            'icon' => 'M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-3Z M12 9v4 M12 15h.01',
        ],
        [
            'title' => 'Activity Notifications',
            'description' => 'Keep track of fact-check updates, news reviews, and account activity in your notification inbox.',
            'icon' => 'M4 12h4l2-6 4 12 2-6h4 M12 4v2 M12 18v2',
        ],
        [
            'title' => 'Personal Settings',
            'description' => 'Manage your profile photo, account information, theme, password, and privacy settings in one place.',
            'icon' => 'M4 7h16 M4 12h16 M4 17h16 M8 5v4 M16 10v4 M10 15v4',
        ],
    ];

    $presentationChapters = [
        [
            'time' => '00:00',
            'title' => 'Start with a claim',
            'description' => 'Upload media, paste a post, or add a source link for review.',
            'status' => 'Input captured',
            'verdict' => 'Ready',
            'accent' => 'blue',
        ],
        [
            'time' => '00:18',
            'title' => 'Analyze the evidence',
            'description' => 'TruthGuard checks media signals, claim text, source context, and risk patterns.',
            'status' => 'Signals scanned',
            'verdict' => 'Analyzing',
            'accent' => 'teal',
        ],
        [
            'time' => '00:42',
            'title' => 'Compare trusted sources',
            'description' => 'Review related reports and source links when evidence is available.',
            'status' => 'Sources matched',
            'verdict' => 'Cross-check',
            'accent' => 'amber',
        ],
        [
            'time' => '01:05',
            'title' => 'Get a clear result',
            'description' => 'The report labels the claim as real, false, misleading, or needing review.',
            'status' => 'Report generated',
            'verdict' => 'Review result',
            'accent' => 'emerald',
        ],
    ];

    $useCases = [
        'customer-service' => [
            'title' => 'Community Fact-Checking',
            'description' => 'Support moderation teams with AI-assisted evidence and consistent verdict summaries.',
            'benefits' => [
                'Standardized review workflow',
                'Reduced manual triage load',
                'Explainable confidence scoring',
                'Case history for accountability',
            ],
            'image' => 'welcome/usecase-community-fact-checking.jpg',
        ],
        'sales' => [
            'title' => 'Election Monitoring',
            'description' => 'Look up public reviews of election-related claims and check the original reporting.',
            'benefits' => [
                'Search published claim reviews',
                'Filter reviews by publisher',
                'Read the publisher\'s rating',
                'Open the original source',
            ],
            'image' => 'welcome/usecase-election-monitoring.jpg',
        ],
        'data-analysis' => [
            'title' => 'Newsroom Verification',
            'description' => 'Validate trending media before publication and reduce false-context reporting.',
            'benefits' => [
                'Pre-publication risk checks',
                'Faster visual authenticity review',
                'Evidence-backed editorial decisions',
                'Consistent score-based triage',
            ],
            'image' => 'welcome/usecase-newsroom-verification.jpg',
        ],
        'hr' => [
            'title' => 'Campus Monitoring',
            'description' => 'Review school-related claims and rumors affecting student safety or services.',
            'benefits' => [
                'Faster rumor verification',
                'Source links for further reading',
                'Reliable case documentation',
                'Reduced false alarm spread',
            ],
            'image' => 'welcome/usecase-campus-monitoring.jpg',
        ],
        'legal' => [
            'title' => 'Everyday Media Literacy',
            'description' => 'Take a closer look at unfamiliar images, videos, and claims before passing them on.',
            'benefits' => [
                'Check media for warning signs',
                'Compare independent sources',
                'Understand confidence indicators',
                'Revisit saved check results',
            ],
            'image' => 'welcome/usecase-policy-review.jpg',
        ],
        'operations' => [
            'title' => 'Public Advisory Checks',
            'description' => 'Review suspicious public advisories and look for supporting source material.',
            'benefits' => [
                'Submit a claim or source link',
                'Read related publisher reviews',
                'Inspect the original evidence',
                'Confirm urgent details with official sources',
            ],
            'image' => 'welcome/usecase-government-response.jpg',
        ],
    ];

    $testimonials = [
        [
            'quote' => 'A confidence score describes the system\'s assessment. It is not proof that a claim is true or false.',
            'author' => 'Understand the score',
            'title' => 'Read the explanation alongside the result.',
        ],
        [
            'quote' => 'Public review ratings belong to the credited publisher. Open their report to see the evidence and context.',
            'author' => 'Read the original source',
            'title' => 'Check publication dates and what was actually reviewed.',
        ],
        [
            'quote' => 'Some claims lack enough evidence for a reliable conclusion. Pause before sharing and seek additional sources.',
            'author' => 'Leave room for uncertainty',
            'title' => 'TruthGuard can make mistakes; review important claims carefully.',
        ],
    ];

    $plans = [
        [
            'name' => 'Free',
            'description' => 'Start with public reviews and a personal account.',
            'monthly' => 'PHP 0',
            'annualMonthly' => 'PHP 0',
            'annualLabel' => 'No subscription charge',
            'features' => [
                'Browse public claim reviews',
                'Search and filter by publisher',
                'Personal Fact Check workspace',
                'Check history and notifications',
            ],
            'cta' => auth()->check() ? 'Open workspace' : 'Create free account',
            'url' => auth()->check() ? route('detections.create') : route('register'),
            'status' => 'Available now',
            'highlighted' => false,
            'enterprise' => false,
        ],
        [
            'name' => 'Plus',
            'description' => 'A planned low-cost option for regular users.',
            'monthly' => 'PHP 49',
            'annualMonthly' => 'PHP 39',
            'annualLabel' => 'Planned annual price: PHP 468/year',
            'features' => [
                'Planned personal subscription',
                'Final usage limits to be announced',
                'No payment collected today',
            ],
            'cta' => 'Ask about Plus',
            'url' => 'mailto:truthguard2026@gmail.com?subject=TruthGuard%20Plus%20plan',
            'status' => 'Planned',
            'highlighted' => true,
            'enterprise' => false,
        ],
        [
            'name' => 'Supporter',
            'description' => 'A planned option for people who want to support TruthGuard.',
            'monthly' => 'PHP 99',
            'annualMonthly' => 'PHP 79',
            'annualLabel' => 'Planned annual price: PHP 948/year',
            'features' => [
                'Planned supporter subscription',
                'Final benefits to be announced',
                'No payment collected today',
            ],
            'cta' => 'Ask about Supporter',
            'url' => 'mailto:truthguard2026@gmail.com?subject=TruthGuard%20Supporter%20plan',
            'status' => 'Planned',
            'highlighted' => false,
            'enterprise' => false,
        ],
    ];
@endphp

<div
    x-data="landingTemplate()"
    x-init="init()"
    data-testimonials-count="{{ count($testimonials) }}"
    class="tg-welcome min-h-screen bg-gradient-to-b from-slate-50 to-white text-slate-900 antialiased"
    style="font-family: 'Poppins', sans-serif;"
>
    <style>
        [x-cloak] { display: none !important; }

        :root {
            --landing-scroll-offset: 5.5rem;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: var(--landing-scroll-offset);
        }

        section[id] {
            scroll-margin-top: var(--landing-scroll-offset);
        }

        .reveal {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.7s ease, transform 0.7s ease;
        }

        .reveal[data-reveal-ready="true"] {
            opacity: 0;
            transform: translateY(28px);
        }

        .reveal.show {
            opacity: 1;
            transform: translateY(0);
        }

        .float-slow {
            animation: floatSlow 4s ease-in-out infinite;
        }

        .truthguard-presentation-shell {
            position: relative;
            overflow: hidden;
            max-width: 100%;
            min-width: 0;
            border: 1px solid rgba(191, 219, 254, 0.82);
            border-radius: 1.65rem;
            background:
                linear-gradient(90deg, rgba(37, 99, 235, 0.045) 1px, transparent 1px),
                linear-gradient(rgba(37, 99, 235, 0.045) 1px, transparent 1px),
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.84));
            background-size: 30px 30px, 30px 30px, auto;
            box-shadow: 0 28px 80px rgba(37, 99, 235, 0.16);
        }

        .truthguard-presentation-shell::before {
            content: '';
            position: absolute;
            inset: -24% -10% auto 28%;
            height: 14rem;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(20, 184, 166, 0.18), rgba(37, 99, 235, 0.16));
            filter: blur(38px);
            pointer-events: none;
        }

        .truthguard-presentation-topbar,
        .truthguard-presentation-frame,
        .truthguard-presentation-controls {
            position: relative;
            z-index: 1;
        }

        .truthguard-presentation-topbar {
            display: flex;
            min-width: 0;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border-bottom: 1px solid rgba(191, 219, 254, 0.7);
            padding: 0.8rem 0.95rem;
        }

        .truthguard-window-dots {
            display: inline-flex;
            gap: 0.35rem;
        }

        .truthguard-window-dots span {
            height: 0.58rem;
            width: 0.58rem;
            border-radius: 999px;
            background: #bfdbfe;
        }

        .truthguard-window-dots span:nth-child(1) {
            background: #ef4444;
        }

        .truthguard-window-dots span:nth-child(2) {
            background: #f59e0b;
        }

        .truthguard-window-dots span:nth-child(3) {
            background: #10b981;
        }

        .truthguard-video-pill {
            display: inline-flex;
            flex-shrink: 0;
            align-items: center;
            gap: 0.45rem;
            border: 1px solid rgba(191, 219, 254, 0.82);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.84);
            padding: 0.42rem 0.66rem;
            color: #2563eb;
            font-size: 0.72rem;
            font-weight: 800;
        }

        .truthguard-video-pill::before {
            content: '';
            height: 0.45rem;
            width: 0.45rem;
            border-radius: 999px;
            background: #10b981;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.36);
            animation: truthguardPulseDot 1.8s ease-out infinite;
        }

        .truthguard-presentation-frame {
            aspect-ratio: 16 / 10;
            overflow: hidden;
            border-bottom: 1px solid rgba(191, 219, 254, 0.72);
            background:
                radial-gradient(circle at 16% 18%, rgba(20, 184, 166, 0.16), transparent 28%),
                radial-gradient(circle at 84% 10%, rgba(37, 99, 235, 0.18), transparent 28%),
                #f8fbff;
        }

        .truthguard-presentation-scene {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(13rem, 0.86fr);
            gap: 1rem;
            align-items: center;
            padding: 1.4rem;
            opacity: 0;
            transform: translateY(0.5rem) scale(0.985);
            animation: truthguardPresentationScene 24s ease-in-out infinite;
            animation-delay: var(--scene-delay);
        }

        .truthguard-presentation-scene:first-child {
            opacity: 1;
        }

        .truthguard-presentation-time {
            display: inline-flex;
            width: fit-content;
            border: 1px solid rgba(191, 219, 254, 0.76);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.84);
            padding: 0.3rem 0.58rem;
            color: #2563eb;
            font-size: 0.68rem;
            font-weight: 900;
        }

        .truthguard-presentation-title {
            margin-top: 0.7rem;
            font-size: clamp(1.55rem, 3vw, 2.35rem);
            font-weight: 800;
            line-height: 1.05;
            color: #0f172a;
        }

        .truthguard-presentation-copy {
            margin-top: 0.7rem;
            max-width: 24rem;
            color: #475569;
            font-size: 0.95rem;
            line-height: 1.7;
        }

        .truthguard-presentation-status {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .truthguard-presentation-status span {
            display: inline-flex;
            align-items: center;
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.8);
            padding: 0.44rem 0.72rem;
            color: #334155;
            font-size: 0.72rem;
            font-weight: 800;
        }

        .truthguard-presentation-visual {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(191, 219, 254, 0.82);
            border-radius: 1.25rem;
            background: rgba(255, 255, 255, 0.78);
            padding: 1rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.88);
        }

        .truthguard-presentation-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 6.2rem;
        }

        .truthguard-presentation-logo img {
            height: 5.4rem;
            width: 5.4rem;
            object-fit: contain;
            filter: drop-shadow(0 18px 26px rgba(37, 99, 235, 0.2));
            animation: floatSlow 4s ease-in-out infinite;
        }

        .truthguard-signal-list {
            margin-top: 0.85rem;
            display: grid;
            gap: 0.5rem;
        }

        .truthguard-signal-list span {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            border-radius: 0.75rem;
            background: rgba(248, 250, 252, 0.9);
            padding: 0.58rem 0.68rem;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 800;
        }

        .truthguard-signal-list span::after {
            content: '';
            height: 0.42rem;
            width: 34%;
            border-radius: 999px;
            background: linear-gradient(90deg, #2563eb, #14b8a6);
        }

        .truthguard-verdict-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.45rem;
            margin-top: 0.85rem;
        }

        .truthguard-verdict-strip span {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 0.72rem;
            background: rgba(255, 255, 255, 0.88);
            padding: 0.54rem 0.4rem;
            text-align: center;
            color: #64748b;
            font-size: 0.66rem;
            font-weight: 900;
        }

        .truthguard-verdict-strip span:nth-child(2) {
            border-color: rgba(248, 113, 113, 0.36);
            background: rgba(254, 242, 242, 0.92);
            color: #dc2626;
        }

        .truthguard-presentation-progress {
            position: relative;
            z-index: 2;
            height: 0.32rem;
            overflow: hidden;
            background: rgba(219, 234, 254, 0.86);
        }

        .truthguard-presentation-progress span {
            display: block;
            height: 100%;
            width: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #2563eb, #14b8a6, #10b981);
            transform: translateX(-100%);
            animation: truthguardPresentationProgress 24s linear infinite;
        }

        .truthguard-presentation-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.85rem;
            padding: 0.85rem 0.95rem 1rem;
        }

        .truthguard-play-control {
            display: inline-flex;
            height: 2.4rem;
            width: 2.4rem;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 999px;
            background: #2563eb;
            color: #fff;
            box-shadow: 0 14px 30px rgba(37, 99, 235, 0.28);
        }

        .truthguard-play-control::before {
            content: '';
            margin-left: 0.16rem;
            border-bottom: 0.43rem solid transparent;
            border-left: 0.65rem solid currentColor;
            border-top: 0.43rem solid transparent;
        }

        .truthguard-presentation-caption {
            min-width: 0;
            flex: 1;
        }

        .truthguard-presentation-caption p {
            margin: 0;
            color: #0f172a;
            font-size: 0.82rem;
            font-weight: 900;
        }

        .truthguard-presentation-caption span {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .truthguard-presentation-duration {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            padding: 0.42rem 0.62rem;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 900;
        }

        @media (max-width: 640px) {
            .truthguard-presentation-shell {
                border-radius: 1.35rem;
            }

            .truthguard-presentation-topbar {
                padding: 0.68rem 0.75rem;
            }

            .truthguard-presentation-frame {
                aspect-ratio: auto;
                min-height: 27rem;
            }

            .truthguard-presentation-scene {
                grid-template-columns: 1fr;
                align-content: center;
                gap: 0.75rem;
                padding: 1rem;
            }

            .truthguard-presentation-title {
                font-size: 1.28rem;
            }

            .truthguard-presentation-copy {
                font-size: 0.78rem;
                line-height: 1.45;
            }

            .truthguard-presentation-status {
                display: none;
            }

            .truthguard-presentation-visual {
                padding: 0.78rem;
            }

            .truthguard-presentation-logo {
                height: 3.6rem;
            }

            .truthguard-presentation-logo img {
                height: 3.35rem;
                width: 3.35rem;
            }

            .truthguard-signal-list {
                gap: 0.42rem;
                margin-top: 0.65rem;
            }

            .truthguard-signal-list span {
                padding: 0.46rem 0.55rem;
                font-size: 0.66rem;
            }

            .truthguard-verdict-strip {
                margin-top: 0.65rem;
            }

            .truthguard-verdict-strip span {
                padding: 0.46rem 0.22rem;
                font-size: 0.6rem;
            }

            .truthguard-presentation-controls {
                padding: 0.7rem 0.75rem 0.8rem;
            }

            .truthguard-presentation-duration {
                display: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .truthguard-presentation-scene,
            .truthguard-presentation-progress span,
            .truthguard-video-pill::before {
                animation: none !important;
            }

            .truthguard-presentation-scene {
                opacity: 0;
            }

            .truthguard-presentation-scene:first-child {
                opacity: 1;
                transform: none;
            }

            .truthguard-presentation-progress span {
                transform: translateX(0);
            }
        }

        @keyframes floatSlow {
            0%,
            100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-8px);
            }
        }

        @keyframes truthguardPresentationScene {
            0%,
            20% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
            24%,
            100% {
                opacity: 0;
                transform: translateY(-0.4rem) scale(0.985);
            }
        }

        @keyframes truthguardPresentationProgress {
            to {
                transform: translateX(0);
            }
        }

        @keyframes truthguardPulseDot {
            70% {
                box-shadow: 0 0 0 0.45rem rgba(16, 185, 129, 0);
            }
        }
    </style>
    @include('layouts.partials.public-header')

    <main>
        <section id="home" class="overflow-hidden pb-12 pt-6 md:pb-24 md:pt-12">
            <div class="mx-auto grid min-w-0 w-full max-w-7xl gap-8 px-4 sm:px-6 lg:grid-cols-2 lg:items-center lg:gap-12 lg:px-8">
                <div class="min-w-0">
                    <div class="mb-4 inline-flex max-w-full items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700 sm:mb-6 sm:text-sm">
                        <span class="sm:hidden">TruthGuard workflow</span>
                        <span class="hidden sm:inline">AI-assisted fact checking</span>
                    </div>
                    <h1 class="bg-gradient-to-r from-slate-900 via-blue-800 to-violet-900 bg-clip-text text-[2.15rem] font-bold leading-tight text-transparent sm:text-4xl md:text-5xl lg:text-6xl">
                        TruthGuard
                    </h1>
                    <p class="mt-4 max-w-xl text-base text-slate-600 sm:mt-6 sm:text-lg">
                        Check images, videos, and online claims. Explore independent fact checks, review the evidence, and keep your own results in one place.
                    </p>

                    <div class="mt-6 flex flex-col gap-3 sm:mt-8 sm:flex-row">
                        @auth
                            <a href="{{ route('detections.create') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-blue-200 transition hover:from-blue-700 hover:to-violet-700">
                                Start Verification
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-blue-200 transition hover:from-blue-700 hover:to-violet-700">
                                Access Platform
                            </a>
                        @endauth

                        <button type="button" class="js-install-app inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-base font-semibold text-slate-700 transition hover:border-violet-300 hover:text-violet-700" disabled>
                            Install App
                        </button>
                    </div>

                    <p class="mt-4 text-sm text-slate-500">AI-assisted results can be wrong. Review sources before sharing.</p>
                </div>

                <div class="relative min-w-0 reveal">
                    <div class="truthguard-presentation-shell">
                        <div class="truthguard-presentation-topbar">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="truthguard-window-dots" aria-hidden="true">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </span>
                                <p class="truncate text-xs font-bold text-slate-600 sm:text-sm">
                                    <span class="sm:hidden">Overview</span>
                                    <span class="hidden sm:inline">TruthGuard overview</span>
                                </p>
                            </div>

                            <span class="truthguard-video-pill">Presentation</span>
                        </div>

                        <div class="truthguard-presentation-frame" aria-label="TruthGuard video presentation">
                            @foreach ($presentationChapters as $chapter)
                                <section class="truthguard-presentation-scene" style="--scene-delay: {{ $loop->index * 6 }}s;">
                                    <div class="min-w-0">
                                        <span class="truthguard-presentation-time">{{ $chapter['time'] }}</span>
                                        <h2 class="truthguard-presentation-title">{{ $chapter['title'] }}</h2>
                                        <p class="truthguard-presentation-copy">{{ $chapter['description'] }}</p>

                                        <div class="truthguard-presentation-status">
                                            <span>{{ $chapter['status'] }}</span>
                                            <span>{{ $chapter['verdict'] }}</span>
                                        </div>
                                    </div>

                                    <div class="truthguard-presentation-visual">
                                        <div class="truthguard-presentation-logo">
                                            @if ($logoUrl !== '')
                                                <img src="{{ $logoUrl }}" alt="TruthGuard logo">
                                            @else
                                                <span class="text-2xl font-black text-blue-600">TG</span>
                                            @endif
                                        </div>

                                        <div class="truthguard-signal-list" aria-hidden="true">
                                            <span>Media integrity</span>
                                            <span>Source context</span>
                                            <span>Claim consistency</span>
                                        </div>

                                        <div class="truthguard-verdict-strip" aria-hidden="true">
                                            <span>Real</span>
                                            <span>False</span>
                                            <span>Review</span>
                                        </div>
                                    </div>
                                </section>
                            @endforeach
                        </div>

                        <div class="truthguard-presentation-progress" aria-hidden="true">
                            <span></span>
                        </div>

                        <div class="truthguard-presentation-controls">
                            <span class="truthguard-play-control" aria-hidden="true"></span>
                            <div class="truthguard-presentation-caption">
                                <p>How TruthGuard checks content</p>
                                <span>Evidence, sources, risk signals, and verdicts in one workflow.</span>
                            </div>
                            <span class="truthguard-presentation-duration">01:18</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="reveal bg-slate-50 py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-14 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">What You Can Do With TruthGuard</h2>
                    <p class="mt-4 text-lg text-slate-600">From a suspicious post to a saved review, keep the evidence within reach.</p>
                </div>

                <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featureCards as $feature)
                        <article class="reveal rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                            <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-6 w-6">
                                    @foreach (explode(' M', $feature['icon']) as $i => $path)
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $i === 0 ? $path : 'M'.$path }}" />
                                    @endforeach
                                </svg>
                            </div>
                            <h3 class="mb-2 text-xl font-semibold">{{ $feature['title'] }}</h3>
                            <p class="text-slate-600">{{ $feature['description'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="use-cases" class="reveal py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-14 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">TruthGuard Use Cases</h2>
                    <p class="mt-4 text-lg text-slate-600">Verification for the claims your team encounters every day.</p>
                </div>

                <div class="tg-welcome-tabs mb-10 flex flex-wrap justify-center gap-2">
                    @foreach ($useCases as $key => $case)
                        <button
                            type="button"
                            @click="activeUseCase='{{ $key }}'"
                            :aria-pressed="activeUseCase === '{{ $key }}'"
                            :class="activeUseCase === '{{ $key }}' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-slate-700 border-slate-300'"
                            class="rounded-lg border px-4 py-2 text-sm font-medium transition"
                        >
                            {{ $case['title'] }}
                        </button>
                    @endforeach
                </div>

                @foreach ($useCases as $key => $case)
                    <div x-show="activeUseCase === '{{ $key }}'" x-cloak x-transition class="reveal rounded-xl border border-slate-200 bg-white shadow-lg">
                        <div class="flex flex-col lg:flex-row">
                            <div class="p-8 lg:w-1/2 lg:p-12">
                                <h3 class="mb-4 text-2xl font-bold">{{ $case['title'] }}</h3>
                                <p class="mb-6 text-slate-600">{{ $case['description'] }}</p>

                                <h4 class="mb-3 text-lg font-semibold">Key Benefits:</h4>
                                <ul class="mb-8 space-y-2">
                                    @foreach ($case['benefits'] as $benefit)
                                        <li class="flex items-start gap-2">
                                            <span class="mt-1 inline-flex h-4 w-4 items-center justify-center rounded-full bg-blue-100 text-[10px] font-bold text-blue-600">OK</span>
                                            <span class="text-slate-700">{{ $benefit }}</span>
                                        </li>
                                    @endforeach
                                </ul>

                                <a href="{{ route('reviews.index') }}" class="inline-flex min-h-11 items-center rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700">Explore claim reviews</a>
                            </div>
                            <div class="flex items-center justify-center bg-slate-100 p-8 lg:w-1/2">
                                <img src="{{ asset($case['image']) }}" alt="{{ $case['title'] }}" class="h-auto max-w-full rounded-lg shadow-md">
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section id="testimonials" class="reveal bg-gradient-to-b from-white to-slate-50 py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-14 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">Before You Share</h2>
                    <p class="mt-4 text-lg text-slate-600">Three habits for a more careful review.</p>
                </div>

                <div class="relative mx-auto max-w-4xl">
                    @foreach ($testimonials as $i => $testimonial)
                        <article x-show="currentTestimonial === {{ $i }}" x-cloak x-transition class="rounded-2xl border border-slate-200 bg-white p-8 shadow-lg md:p-12">
                            <p class="mb-8 text-lg leading-relaxed text-slate-800 md:text-xl">{{ $testimonial['quote'] }}</p>
                            <div class="flex items-center">
                                <span class="mr-4 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 font-bold text-blue-700">{{ $i + 1 }}</span>
                                <div>
                                    <h4 class="text-lg font-bold">{{ $testimonial['author'] }}</h4>
                                    <p class="text-slate-600">{{ $testimonial['title'] }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach

                    <div class="mt-8 flex justify-center gap-4">
                        <button @click="prevTestimonial(true)" class="rounded-full border border-slate-300 bg-white p-2 text-slate-700 transition hover:bg-slate-100" aria-label="Previous review tip">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
                            </svg>
                        </button>

                        <div class="flex items-center gap-2">
                            @foreach ($testimonials as $i => $testimonial)
                                <button type="button" @click="goToTestimonial({{ $i }})" :aria-current="currentTestimonial === {{ $i }} ? 'true' : 'false'" class="tg-carousel-dot" aria-label="Go to review tip {{ $i + 1 }}"></button>
                            @endforeach
                        </div>

                        <button @click="nextTestimonial(true)" class="rounded-full border border-slate-300 bg-white p-2 text-slate-700 transition hover:bg-slate-100" aria-label="Next review tip">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section id="pricing" class="reveal py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-14 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">Start Free. Keep It Affordable.</h2>
                    <p class="mt-4 text-lg text-slate-600">Prices in Philippine pesos. Paid plans are planned and are not yet available for purchase.</p>

                    <div class="mb-12 mt-8 flex items-center justify-center">
                        <span class="mr-3" :class="isAnnual ? 'text-slate-600' : 'font-medium text-slate-900'">Monthly</span>
                        <button type="button" @click="isAnnual = !isAnnual" role="switch" :aria-checked="isAnnual" class="tg-pricing-switch relative inline-flex items-center rounded-full" aria-label="Annual billing">
                            <span :class="isAnnual ? 'translate-x-7' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white transition"></span>
                        </button>
                        <span class="ml-3" :class="isAnnual ? 'font-medium text-slate-900' : 'text-slate-600'">Annual <span class="text-sm font-medium text-green-600">Save about 20%</span></span>
                    </div>
                </div>

                <div class="grid gap-8 md:grid-cols-3">
                    @foreach ($plans as $plan)
                        @php
                            $isHighlighted = (bool) ($plan['highlighted'] ?? false);
                            $isEnterprise = (bool) ($plan['enterprise'] ?? false);
                        @endphp

                        <article
                            @class([
                                'tg-plan-card reveal flex flex-col overflow-hidden rounded-lg',
                                'border-2 border-blue-600 shadow-lg shadow-blue-100' => $isHighlighted,
                                'border border-slate-200 shadow-sm' => ! $isHighlighted,
                            ])
                        >
                            <div @class(['px-4 py-2 text-center text-sm font-medium', 'bg-blue-600 text-white' => $isHighlighted, 'bg-blue-50 text-blue-700' => ! $isHighlighted])>{{ $plan['status'] }}</div>

                            <div class="bg-white p-6 md:p-8">
                                <h3 class="text-2xl font-bold">{{ $plan['name'] }}</h3>
                                <p class="tg-plan-description mt-2 text-slate-600">{{ $plan['description'] }}</p>
                                <div class="tg-plan-price-block mb-6 mt-6">
                                    @if (! $isEnterprise)
                                        <span class="tg-plan-price text-4xl font-bold" x-show="isAnnual">{{ str_replace('PHP ', "\u{20B1}", $plan['annualMonthly'] ?? $plan['monthly'] ?? '') }}</span>
                                        <span class="tg-plan-price text-4xl font-bold" x-show="!isAnnual" x-cloak>{{ str_replace('PHP ', "\u{20B1}", $plan['monthly'] ?? '') }}</span>
                                        <span class="text-slate-600">/month</span>
                                        <div class="mt-1 text-sm text-slate-500" x-show="isAnnual" x-cloak>{{ str_replace('PHP ', "\u{20B1}", $plan['annualLabel'] ?? '') }}</div>
                                    @else
                                        <span class="text-2xl font-bold">Custom Pricing</span>
                                    @endif
                                </div>

                                <a href="{{ $plan['url'] }}"
                                    @class([
                                        'inline-flex min-h-11 w-full items-center justify-center rounded-lg px-4 py-2.5 text-center text-sm font-semibold transition',
                                        'bg-blue-600 text-white hover:bg-blue-700' => $isHighlighted,
                                        'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' => ! $isHighlighted,
                                    ])
                                >
                                    {{ $plan['cta'] }}
                                </a>
                            </div>

                            <div class="flex-1 border-t border-slate-200 bg-slate-50 p-6 md:p-8">
                                <p class="mb-4 font-semibold">{{ $plan['status'] === 'Planned' ? 'Plan status' : 'Available features' }}</p>
                                <ul class="space-y-3">
                                    @foreach ($plan['features'] as $includedFeature)
                                        <li class="flex items-start gap-2 text-sm text-slate-700">
                                            <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-[11px] font-bold text-emerald-600">OK</span>
                                            <span>{{ $includedFeature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="install" class="reveal py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-2xl bg-gradient-to-r from-blue-600 to-violet-600">
                    <div class="relative px-6 py-14 md:p-16">
                        <div class="absolute inset-0 opacity-10">
                            <div class="absolute -left-24 -top-24 h-64 w-64 rounded-full bg-white blur-3xl"></div>
                            <div class="absolute -bottom-24 -right-24 h-64 w-64 rounded-full bg-white blur-3xl"></div>
                        </div>

                        <div class="relative z-10 flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 class="text-3xl font-bold text-white md:text-4xl">Install TruthGuard As an App</h2>
                                <p class="mt-3 max-w-2xl text-lg text-blue-100">Launch faster and work in app mode on desktop or mobile with the same TruthGuard dashboard experience.</p>
                            </div>
                            <div class="flex flex-col gap-3 sm:flex-row">
                                <button type="button" class="js-install-app rounded-xl bg-white px-6 py-3 text-base font-semibold text-indigo-700 transition hover:bg-blue-50" disabled>
                                    Install TruthGuard App
                                </button>
                                @auth
                                    <a href="{{ route('dashboard') }}" class="rounded-xl border border-white/40 px-6 py-3 text-center text-base font-semibold text-white transition hover:bg-white/10">Go to Dashboard</a>
                                @else
                                    <a href="{{ route('login') }}" class="rounded-xl border border-white/40 px-6 py-3 text-center text-base font-semibold text-white transition hover:bg-white/10">Log In</a>
                                @endauth
                            </div>
                        </div>
                        <p id="install-help" class="relative z-10 mt-4 text-sm text-blue-100"></p>
                    </div>
                </div>
            </div>
        </section>


        <button
            type="button"
            x-show="showBackTop"
            x-cloak
            @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
            class="fixed bottom-16 right-4 rounded-full bg-blue-600 p-3 text-white shadow-lg transition hover:bg-blue-700"
            aria-label="Back to top"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
            </svg>
        </button>
    </main>

    @include('layouts.partials.public-footer')

    <script>
        function landingTemplate() {
            return {
                isMenuOpen: false,
                activeUseCase: 'customer-service',
                currentTestimonial: 0,
                totalTestimonials: 1,
                isAnnual: false,
                autoRotateId: null,
                showBackTop: false,
                scrollHandler: null,
                resizeHandler: null,
                hashHandler: null,
                init() {
                    const testimonialsCount = parseInt((this.$el && this.$el.dataset && this.$el.dataset.testimonialsCount) ? this.$el.dataset.testimonialsCount : '1', 10);
                    this.totalTestimonials = Number.isFinite(testimonialsCount) && testimonialsCount > 0 ? testimonialsCount : 1;
                    this.updateScrollOffset();
                    this.observeReveals();
                    this.startAutoRotate();
                    this.scrollHandler = () => {
                        this.showBackTop = window.scrollY > 420;
                    };
                    this.resizeHandler = () => {
                        this.updateScrollOffset();
                    };
                    this.hashHandler = () => {
                        const rawHash = window.location.hash.replace('#', '').trim();
                        let id = rawHash;
                        try {
                            id = decodeURIComponent(rawHash);
                        } catch (error) {
                            id = rawHash;
                        }
                        if (id !== '') {
                            this.scrollToSection(id, false, false);
                        }
                    };
                    this.$watch('isMenuOpen', () => {
                        requestAnimationFrame(() => this.updateScrollOffset());
                    });
                    this.scrollHandler();
                    window.addEventListener('scroll', this.scrollHandler, { passive: true });
                    window.addEventListener('resize', this.resizeHandler, { passive: true });
                    window.addEventListener('hashchange', this.hashHandler);

                    // Correct browser native hash jump on first load after precise header height is known.
                    requestAnimationFrame(() => requestAnimationFrame(() => this.hashHandler()));
                },
                updateScrollOffset() {
                    const offset = this.getScrollOffset();
                    document.documentElement.style.setProperty('--landing-scroll-offset', `${offset}px`);
                },
                getScrollOffset() {
                    const headerHeight = this.$refs.siteHeader ? this.$refs.siteHeader.offsetHeight : 0;
                    return Math.max(56, headerHeight);
                },
                scrollToSection(sectionId, closeMenu = false, updateHash = true) {
                    const applyScroll = () => {
                        const target = document.getElementById(sectionId);
                        if (!target) {
                            return;
                        }

                        this.updateScrollOffset();
                        const offset = this.getScrollOffset();
                        const targetTop = window.scrollY + target.getBoundingClientRect().top - offset;
                        window.scrollTo({
                            top: Math.max(0, targetTop),
                            behavior: 'smooth',
                        });

                        if (updateHash && window.history && typeof window.history.replaceState === 'function') {
                            window.history.replaceState(null, '', `#${sectionId}`);
                        }
                    };

                    if (closeMenu && this.isMenuOpen) {
                        this.isMenuOpen = false;
                        requestAnimationFrame(() => requestAnimationFrame(applyScroll));
                        return;
                    }

                    applyScroll();
                },
                observeReveals() {
                    const items = document.querySelectorAll('.reveal');
                    if (!('IntersectionObserver' in window)) {
                        return;
                    }

                    items.forEach((el) => {
                        el.setAttribute('data-reveal-ready', 'true');
                    });

                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach((entry) => {
                            if (entry.isIntersecting) {
                                entry.target.classList.add('show');
                                observer.unobserve(entry.target);
                            }
                        });
                    }, { threshold: 0.15 });

                    items.forEach((el) => observer.observe(el));
                },
                startAutoRotate() {
                    this.stopAutoRotate();
                    this.autoRotateId = setInterval(() => {
                        this.currentTestimonial = (this.currentTestimonial + 1) % this.totalTestimonials;
                    }, 6500);
                },
                stopAutoRotate() {
                    if (this.autoRotateId) {
                        clearInterval(this.autoRotateId);
                        this.autoRotateId = null;
                    }
                },
                nextTestimonial(manual = false) {
                    this.currentTestimonial = (this.currentTestimonial + 1) % this.totalTestimonials;
                    if (manual) this.startAutoRotate();
                },
                prevTestimonial(manual = false) {
                    this.currentTestimonial = (this.currentTestimonial - 1 + this.totalTestimonials) % this.totalTestimonials;
                    if (manual) this.startAutoRotate();
                },
                goToTestimonial(index) {
                    this.currentTestimonial = index;
                    this.startAutoRotate();
                },
                destroy() {
                    this.stopAutoRotate();
                    if (this.scrollHandler) {
                        window.removeEventListener('scroll', this.scrollHandler);
                        this.scrollHandler = null;
                    }
                    if (this.resizeHandler) {
                        window.removeEventListener('resize', this.resizeHandler);
                        this.resizeHandler = null;
                    }
                    if (this.hashHandler) {
                        window.removeEventListener('hashchange', this.hashHandler);
                        this.hashHandler = null;
                    }
                },
            };
        }

    </script>
</div>
