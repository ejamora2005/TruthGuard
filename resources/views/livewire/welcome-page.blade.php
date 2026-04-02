@php
    // Fallback keeps static analyzers and direct-view previews from flagging undefined variable.
    $logoUrl = isset($logoUrl) ? (string) $logoUrl : '';

    $featureCards = [
        [
            'title' => 'Autonomous Decision Making',
            'description' => 'TruthGuard agents recommend verdicts automatically using risk signals and configured decision thresholds.',
            'icon' => 'M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-3Z M9.5 12l1.8 1.8 3.2-3.2',
        ],
        [
            'title' => 'Complex Workflow Automation',
            'description' => 'Automate multi-step verification processes across extraction, source checks, scoring, and reporting.',
            'icon' => 'M5 7h4v4H5z M15 7h4v4h-4z M10 13h4v4h-4z M9 9h6 M7 11v2 M17 11v2',
        ],
        [
            'title' => 'Multi-Agent Collaboration',
            'description' => 'Run specialized agents for media detection, claim parsing, source verification, and final scoring.',
            'icon' => 'M8 8a2.5 2.5 0 1 0 0.001 0 M16 8a2.5 2.5 0 1 0 0.001 0 M12 16a2.5 2.5 0 1 0 0.001 0 M9.8 9.4l2.4 5.2 M14.2 9.4l-2.4 5.2',
        ],
        [
            'title' => 'Secure and Compliant',
            'description' => 'Keep auditable case history and review traces for accountability and policy-aligned moderation.',
            'icon' => 'M12 3 19 6v5c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-3Z M12 9v4 M12 15h.01',
        ],
        [
            'title' => 'Rapid Integration',
            'description' => 'Connect to verification APIs and data sources quickly using reusable automation components.',
            'icon' => 'M4 12h4l2-6 4 12 2-6h4 M12 4v2 M12 18v2',
        ],
        [
            'title' => 'Customizable Agents',
            'description' => 'Tune scoring logic, labels, and escalation behavior to your team workflow and policy requirements.',
            'icon' => 'M4 7h16 M4 12h16 M4 17h16 M8 5v4 M16 10v4 M10 15v4',
        ],
    ];

    $detectShowcase = [
        [
            'image' => 'welcome/c88713d.png',
            'alt' => 'AI-generated image comparison',
            'label' => 'AI-Generated Image',
        ],
        [
            'image' => 'welcome/5.png',
            'alt' => 'Fake news social media post detection',
            'label' => 'Fake Social Post',
        ],
        [
            'image' => 'welcome/0a60b3ab-4470-43b9-a4e2-7aecac181213-large16x9_fakeweathernews.png',
            'alt' => 'False weather bulletin detection',
            'label' => 'Fake Weather News',
        ],
        [
            'image' => 'welcome/Misleading.jpg',
            'alt' => 'Misleading weather context detection',
            'label' => 'Misleading Context',
        ],
        [
            'image' => 'welcome/istockphoto-2150955168-612x612.jpg',
            'alt' => 'Fact check result for misleading claim',
            'label' => 'Misleading Claim',
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
            'description' => 'Track high-velocity misinformation around campaigns and civic events.',
            'benefits' => [
                'Rapid claim pattern detection',
                'Cross-source consistency checks',
                'Escalation of high-risk narratives',
                'Daily summary reporting',
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
                'Structured escalation path',
                'Reliable case documentation',
                'Reduced false alarm spread',
            ],
            'image' => 'welcome/usecase-campus-monitoring.jpg',
        ],
        'legal' => [
            'title' => 'Policy Review',
            'description' => 'Map flagged content against policy and keep auditable decision trails.',
            'benefits' => [
                'Policy-aligned verdicting',
                'Transparent moderation basis',
                'Lower reviewer variance',
                'Compliance-ready logs',
            ],
            'image' => 'welcome/usecase-policy-review.jpg',
        ],
        'operations' => [
            'title' => 'Government Response Ops',
            'description' => 'Monitor misinformation during emergencies and support public communication teams.',
            'benefits' => [
                'High-risk incident prioritization',
                'Weather/location claim verification',
                'Cross-team response visibility',
                'Quicker public advisory support',
            ],
            'image' => 'welcome/usecase-government-response.jpg',
        ],
    ];

    $testimonials = [
        [
            'quote' => 'TruthGuard reduced our manual verification workload and helped us flag misleading media much faster.',
            'author' => 'Sarah Johnson',
            'title' => 'Content Verification Lead',
            'company' => 'City News Desk',
            'avatar' => 'welcome/testimonial-sarah-johnson.jpg',
        ],
        [
            'quote' => 'The risk scoring and case timeline made moderation decisions more consistent across our response team.',
            'author' => 'Michael Chen',
            'title' => 'Digital Response Manager',
            'company' => 'Public Safety Office',
            'avatar' => 'welcome/testimonial-michael-chen.jpg',
        ],
        [
            'quote' => 'Cross-source checks gave us confidence when debunking recycled images and false-context claims.',
            'author' => 'Elena Rodriguez',
            'title' => 'Fact-Check Coordinator',
            'company' => 'Civic Media Lab',
            'avatar' => 'welcome/testimonial-elena-rodriguez.jpg',
        ],
    ];

    $plans = [
        [
            'name' => 'Starter',
            'description' => 'Perfect for individuals and small projects',
            'monthly' => '$29',
            'annualMonthly' => '$24.17',
            'annualLabel' => 'Billed annually ($290/year)',
            'features' => [
                '2 AI agents',
                '100 agent runs per month',
                'Basic integrations',
                'Email support',
                '7-day history',
            ],
            'cta' => 'Start Free Trial',
            'highlighted' => false,
            'enterprise' => false,
        ],
        [
            'name' => 'Professional',
            'description' => 'Ideal for growing teams and businesses',
            'monthly' => '$99',
            'annualMonthly' => '$82.50',
            'annualLabel' => 'Billed annually ($990/year)',
            'features' => [
                '10 AI agents',
                '1,000 agent runs per month',
                'Advanced integrations',
                'Priority support',
                '30-day history',
                'Custom agent training',
                'Team collaboration',
            ],
            'cta' => 'Start Free Trial',
            'highlighted' => true,
            'enterprise' => false,
        ],
        [
            'name' => 'Enterprise',
            'description' => 'For organizations with advanced needs',
            'features' => [
                'Unlimited AI agents',
                'Custom agent runs',
                'All integrations',
                'Dedicated support',
                'Unlimited history',
                'Advanced security',
                'SLA guarantees',
                'Private deployment options',
            ],
            'cta' => 'Contact Sales',
            'highlighted' => false,
            'enterprise' => true,
        ],
    ];
@endphp

<div
    x-data="landingTemplate()"
    x-init="init()"
    data-testimonials-count="{{ count($testimonials) }}"
    class="min-h-screen bg-gradient-to-b from-slate-50 to-white text-slate-900 antialiased"
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

        .detect-slider {
            position: relative;
            height: 15rem;
            overflow: hidden;
            border-radius: 0.5rem;
            border: 1px solid rgb(226 232 240 / 1);
            background: rgb(241 245 249 / 1);
        }

        .detect-slide {
            position: absolute;
            inset: 0;
            opacity: 0;
            animation-name: detectSlideFade;
            animation-duration: 15s;
            animation-timing-function: linear;
            animation-iteration-count: infinite;
            animation-fill-mode: both;
        }

        .detect-slide:first-child {
            opacity: 1;
        }

        .detect-slider .detect-slide:nth-child(1) { animation-delay: 0s; }
        .detect-slider .detect-slide:nth-child(2) { animation-delay: 3s; }
        .detect-slider .detect-slide:nth-child(3) { animation-delay: 6s; }
        .detect-slider .detect-slide:nth-child(4) { animation-delay: 9s; }
        .detect-slider .detect-slide:nth-child(5) { animation-delay: 12s; }
        .detect-slider .detect-slide:nth-child(6) { animation-delay: 15s; }
        .detect-slider .detect-slide:nth-child(7) { animation-delay: 18s; }
        .detect-slider .detect-slide:nth-child(8) { animation-delay: 21s; }

        @media (min-width: 640px) {
            .detect-slider {
                height: 18rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .detect-slide {
                animation: none !important;
                opacity: 0;
            }

            .detect-slide:first-child {
                opacity: 1;
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

        @keyframes detectSlideFade {
            0%,
            18% {
                opacity: 1;
            }
            20%,
            100% {
                opacity: 0;
            }
        }
    </style>
    <header x-ref="siteHeader" class="sticky top-0 z-50 w-full border-b border-slate-200 bg-white/85 backdrop-blur-md">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="#home" @click.prevent="scrollToSection('home')" class="flex shrink-0 items-center gap-3">
                @if ($logoUrl !== '')
                    <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-10 w-10 object-contain">
                @else
                    <span class="inline-flex h-10 w-10 items-center justify-center text-sm font-bold text-blue-600">TG</span>
                @endif
                <span class="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-xl font-bold text-transparent">TruthGuard</span>
            </a>

            <nav class="hidden flex-1 items-center justify-center gap-5 text-sm font-medium text-slate-700 md:flex md:px-6 lg:gap-8 xl:gap-10">
                <a href="#features" @click.prevent="scrollToSection('features')" class="whitespace-nowrap transition hover:text-blue-600">Features</a>
                <a href="#use-cases" @click.prevent="scrollToSection('use-cases')" class="whitespace-nowrap transition hover:text-blue-600">Use Cases</a>
                <a href="#testimonials" @click.prevent="scrollToSection('testimonials')" class="whitespace-nowrap transition hover:text-blue-600">Testimonials</a>
                <a href="#pricing" @click.prevent="scrollToSection('pricing')" class="whitespace-nowrap transition hover:text-blue-600">Pricing</a>
                <a href="#install" @click.prevent="scrollToSection('install')" class="whitespace-nowrap transition hover:text-blue-600">Install App</a>
            </nav>

            <div class="hidden shrink-0 items-center gap-3 md:flex lg:gap-4">
                @auth
                    <a href="{{ route('dashboard') }}" class="whitespace-nowrap rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-400 hover:text-blue-700 lg:px-4">Dashboard</a>
                    <a href="{{ route('detections.create') }}" class="whitespace-nowrap rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-3 py-2 text-sm font-semibold text-white transition hover:from-blue-700 hover:to-violet-700 lg:px-4">New Detection</a>
                @else
                    <a href="{{ route('login') }}" class="whitespace-nowrap rounded-xl border border-slate-300 bg-white px-5 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-400 hover:text-blue-700">Log In</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="whitespace-nowrap rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:from-blue-700 hover:to-violet-700">Register</a>
                    @endif
                @endauth
            </div>

            <button type="button" class="text-slate-700 md:hidden" @click="isMenuOpen = !isMenuOpen" aria-label="Toggle menu">
                <svg x-show="!isMenuOpen" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="isMenuOpen" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div x-show="isMenuOpen" x-cloak x-transition class="border-t border-slate-200 bg-white py-4 md:hidden">
            <div class="container mx-auto flex flex-col space-y-4 px-4">
                <a href="#features" class="py-1 text-slate-700" @click.prevent="scrollToSection('features', true)">Features</a>
                <a href="#use-cases" class="py-1 text-slate-700" @click.prevent="scrollToSection('use-cases', true)">Use Cases</a>
                <a href="#pricing" class="py-1 text-slate-700" @click.prevent="scrollToSection('pricing', true)">Pricing</a>
                <a href="#testimonials" class="py-1 text-slate-700" @click.prevent="scrollToSection('testimonials', true)">Testimonials</a>
                <a href="#install" class="py-1 text-slate-700" @click.prevent="scrollToSection('install', true)">Install App</a>
                <div class="border-t border-slate-200 pt-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="block rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-semibold text-slate-700">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="mt-2 block rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 px-4 py-2 text-center text-sm font-semibold text-white">Register</a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <main>
        <section id="home" class="overflow-hidden pb-16 pt-8 md:pb-24 md:pt-12">
            <div class="mx-auto grid w-full max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:items-center lg:px-8">
                <div>
                    <div class="mb-6 inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-700">
                        TruthGuard 1.0 - Agentic Verification Workflow
                    </div>
                    <h1 class="bg-gradient-to-r from-slate-900 via-blue-800 to-violet-900 bg-clip-text text-4xl font-bold leading-tight text-transparent md:text-5xl lg:text-6xl">
                        Full-Stack AI Verification for Social Misinformation
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-slate-600">
                        Detect fake media, automate claim verification, and produce explainable verdicts with AI-assisted workflows built for real response teams.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
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

                    <p class="mt-4 text-sm text-slate-500">Use TruthGuard as an installed app, not just a browser tab.</p>
                </div>

                <div class="relative reveal">
                    <div class="float-slow rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div class="mb-4 flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 font-semibold text-blue-600">TG</span>
                            <p class="text-sm font-medium text-slate-700">What TruthGuard Detects</p>
                        </div>

                        <div class="mx-auto w-full max-w-xl">
                            <div class="detect-slider">
                                @foreach ($detectShowcase as $slide)
                                    @php
                                        $slidePath = ltrim((string) $slide['image'], '/');
                                        $slideVersion = @filemtime(public_path($slidePath));
                                    @endphp
                                    <figure class="detect-slide">
                                        <img src="/{{ $slidePath }}{{ $slideVersion ? '?v='.$slideVersion : '' }}" alt="{{ $slide['alt'] }}" class="h-full w-full object-cover">
                                        <figcaption class="absolute inset-x-0 bottom-0 bg-slate-900/80 px-4 py-2.5 text-center text-sm font-semibold tracking-wide text-white sm:text-base">
                                            {{ $slide['label'] }}
                                        </figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="absolute -left-2 top-8 -z-10 h-full w-full rounded-2xl bg-gradient-to-r from-blue-600/20 to-violet-600/20 blur-3xl"></div>
                </div>
            </div>
        </section>

        <section id="features" class="reveal bg-slate-50 py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-14 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">Powerful TruthGuard Features</h2>
                    <p class="mt-4 text-lg text-slate-600">Everything needed to detect, verify, and report misinformation with confidence.</p>
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
                    <p class="mt-4 text-lg text-slate-600">Switch between use-case tabs just like the original template flow.</p>
                </div>

                <div class="mb-10 flex flex-wrap justify-center gap-2">
                    @foreach ($useCases as $key => $case)
                        <button
                            type="button"
                            @click="activeUseCase='{{ $key }}'"
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

                                <button class="rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:from-blue-700 hover:to-violet-700">
                                    Learn More
                                </button>
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
                    <h2 class="text-3xl font-bold md:text-4xl">What Teams Say About TruthGuard</h2>
                    <p class="mt-4 text-lg text-slate-600">Template-style testimonial carousel with navigation controls.</p>
                </div>

                <div class="relative mx-auto max-w-4xl">
                    @foreach ($testimonials as $i => $testimonial)
                        <article x-show="currentTestimonial === {{ $i }}" x-cloak x-transition class="rounded-2xl border border-slate-200 bg-white p-8 shadow-lg md:p-12">
                            <p class="mb-8 text-xl italic text-slate-800 md:text-2xl">"{{ $testimonial['quote'] }}"</p>
                            <div class="flex items-center">
                                <img src="{{ asset($testimonial['avatar']) }}" alt="{{ $testimonial['author'] }}" class="mr-4 h-16 w-16 rounded-full border-2 border-blue-100 object-cover">
                                <div>
                                    <h4 class="text-lg font-bold">{{ $testimonial['author'] }}</h4>
                                    <p class="text-slate-600">{{ $testimonial['title'] }}</p>
                                    <p class="text-blue-600">{{ $testimonial['company'] }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach

                    <div class="mt-8 flex justify-center gap-4">
                        <button @click="prevTestimonial(true)" class="rounded-full border border-slate-300 bg-white p-2 text-slate-700 transition hover:bg-slate-100" aria-label="Previous testimonial">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
                            </svg>
                        </button>

                        <div class="flex items-center gap-2">
                            @foreach ($testimonials as $i => $testimonial)
                                <button @click="goToTestimonial({{ $i }})" :class="currentTestimonial === {{ $i }} ? 'bg-blue-600' : 'bg-slate-300'" class="h-3 w-3 rounded-full" aria-label="Go to testimonial {{ $i + 1 }}"></button>
                            @endforeach
                        </div>

                        <button @click="nextTestimonial(true)" class="rounded-full border border-slate-300 bg-white p-2 text-slate-700 transition hover:bg-slate-100" aria-label="Next testimonial">
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
                    <h2 class="text-3xl font-bold md:text-4xl">Simple, Transparent Pricing</h2>
                    <p class="mt-4 text-lg text-slate-600">Monthly and annual pricing toggle just like the original template.</p>

                    <div class="mb-12 mt-8 flex items-center justify-center">
                        <span class="mr-3" :class="isAnnual ? 'text-slate-600' : 'font-medium text-slate-900'">Monthly</span>
                        <button @click="isAnnual = !isAnnual" class="relative inline-flex h-6 w-12 items-center rounded-full bg-slate-200" aria-label="Toggle pricing period">
                            <span :class="isAnnual ? 'translate-x-7' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white transition"></span>
                        </button>
                        <span class="ml-3" :class="isAnnual ? 'font-medium text-slate-900' : 'text-slate-600'">Annual <span class="text-sm font-medium text-green-600">Save 20%</span></span>
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
                                'reveal overflow-hidden rounded-xl',
                                'border-2 border-blue-600 shadow-lg shadow-blue-100' => $isHighlighted,
                                'border border-slate-200 shadow-sm' => ! $isHighlighted,
                            ])
                        >
                            @if ($isHighlighted)
                                <div class="bg-blue-600 py-2 text-center text-sm font-medium text-white">Most Popular</div>
                            @endif

                            <div class="bg-white p-6 md:p-8">
                                <h3 class="text-2xl font-bold">{{ $plan['name'] }}</h3>
                                <p class="mt-2 text-slate-600">{{ $plan['description'] }}</p>
                                <div class="mb-6 mt-6">
                                    @if (! $isEnterprise)
                                        <span class="text-4xl font-bold" x-show="isAnnual">{{ $plan['annualMonthly'] ?? $plan['monthly'] ?? '' }}</span>
                                        <span class="text-4xl font-bold" x-show="!isAnnual" x-cloak>{{ $plan['monthly'] ?? '' }}</span>
                                        <span class="text-slate-600">/month</span>
                                        <div class="mt-1 text-sm text-slate-500" x-show="isAnnual" x-cloak>{{ $plan['annualLabel'] ?? '' }}</div>
                                    @else
                                        <span class="text-2xl font-bold">Custom Pricing</span>
                                    @endif
                                </div>

                                <button
                                    @class([
                                        'w-full rounded-lg px-4 py-2.5 text-sm font-semibold transition',
                                        'bg-gradient-to-r from-blue-600 to-violet-600 text-white hover:from-blue-700 hover:to-violet-700' => $isHighlighted,
                                        'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' => ! $isHighlighted,
                                    ])
                                >
                                    {{ $plan['cta'] }}
                                </button>
                            </div>

                            <div class="border-t border-slate-200 bg-slate-50 p-6 md:p-8">
                                <p class="mb-4 font-semibold">What's included:</p>
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

    <footer class="bg-slate-900 text-slate-300">
        <div class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <a href="#home" @click.prevent="scrollToSection('home')" class="mb-6 flex items-center gap-3">
                        @if ($logoUrl !== '')
                            <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-9 w-9 object-contain">
                        @else
                            <span class="inline-flex h-9 w-9 items-center justify-center text-sm font-bold text-blue-600">TG</span>
                        @endif
                        <span class="text-xl font-bold text-white">TruthGuard</span>
                    </a>
                    <p class="max-w-md">
                        TruthGuard builds AI-powered verification workflows for responsible digital information sharing.
                    </p>
                </div>

                <div>
                    <h3 class="mb-4 font-semibold text-white">Product</h3>
                    <ul class="space-y-3 text-sm">
                        <li><a href="#features" @click.prevent="scrollToSection('features')" class="transition hover:text-white">Features</a></li>
                        <li><a href="#use-cases" @click.prevent="scrollToSection('use-cases')" class="transition hover:text-white">Use Cases</a></li>
                        <li><a href="#pricing" @click.prevent="scrollToSection('pricing')" class="transition hover:text-white">Pricing</a></li>
                        <li><a href="#install" @click.prevent="scrollToSection('install')" class="transition hover:text-white">Install App</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-4 font-semibold text-white">Platform</h3>
                    <ul class="space-y-3 text-sm">
                        <li><a href="#home" @click.prevent="scrollToSection('home')" class="transition hover:text-white">Overview</a></li>
                        <li><a href="#testimonials" @click.prevent="scrollToSection('testimonials')" class="transition hover:text-white">Testimonials</a></li>
                        <li><a href="#" class="transition hover:text-white">API (Coming Soon)</a></li>
                        <li><a href="#" class="transition hover:text-white">Security</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-4 font-semibold text-white">Support</h3>
                    <ul class="space-y-3 text-sm">
                        @auth
                            <li><a href="{{ route('dashboard') }}" class="transition hover:text-white">Dashboard</a></li>
                        @else
                            <li><a href="{{ route('login') }}" class="transition hover:text-white">Log In</a></li>
                            @if (Route::has('register'))
                                <li><a href="{{ route('register') }}" class="transition hover:text-white">Register</a></li>
                            @endif
                        @endauth
                        <li><a href="#" class="transition hover:text-white">Help Center</a></li>
                        <li><a href="#" class="transition hover:text-white">Contact</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-slate-800 pt-8 text-sm md:flex-row">
                <p>&copy; {{ date('Y') }} TruthGuard. All rights reserved.</p>
                <div class="flex gap-6">
                    <a href="#" class="transition hover:text-white">Terms</a>
                    <a href="#" class="transition hover:text-white">Privacy</a>
                    <a href="#" class="transition hover:text-white">Cookies</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        function landingTemplate() {
            return {
                isMenuOpen: false,
                activeUseCase: 'customer-service',
                currentTestimonial: 0,
                totalTestimonials: 1,
                isAnnual: true,
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

        (() => {
            if (window.__truthGuardInstallInit) {
                return;
            }
            window.__truthGuardInstallInit = true;

            const installButtons = Array.from(document.querySelectorAll('.js-install-app'));
            const installHelp = document.getElementById('install-help');
            let deferredPrompt = null;

            if (installButtons.length === 0) {
                return;
            }

            const setButtons = (label, disabled = false) => {
                installButtons.forEach((btn) => {
                    btn.textContent = label;
                    btn.disabled = disabled;
                    btn.classList.toggle('opacity-60', disabled);
                    btn.classList.toggle('cursor-not-allowed', disabled);
                });
            };

            const hasIosStandalone = ('standalone' in window.navigator) && window.navigator['standalone'] === true;
            const isStandalone = (typeof window.matchMedia === 'function' && window.matchMedia('(display-mode: standalone)').matches) || hasIosStandalone;
            if (isStandalone) {
                setButtons('App Installed', true);
                if (installHelp) {
                    installHelp.textContent = 'TruthGuard is already running as an installed app.';
                }
            }

            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                deferredPrompt = event;
                setButtons('Install App', false);
                if (installHelp) {
                    installHelp.textContent = 'Click Install App to add TruthGuard to your device.';
                }
            });

            installButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    if (!deferredPrompt) {
                        if (installHelp) {
                            installHelp.textContent = 'If install is unavailable, open your browser menu and choose "Install App" or "Add to Home Screen".';
                        }
                        return;
                    }

                    deferredPrompt.prompt();
                    const choice = await deferredPrompt.userChoice;

                    if (choice.outcome === 'accepted') {
                        setButtons('Installing...', true);
                    }

                    deferredPrompt = null;
                });
            });

            window.addEventListener('appinstalled', () => {
                setButtons('App Installed', true);
                if (installHelp) {
                    installHelp.textContent = 'Installation complete. You can launch TruthGuard from your apps list.';
                }
            });

            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js').catch(() => {
                        if (installHelp && installHelp.textContent === '') {
                            installHelp.textContent = 'Install is still available, but offline mode could not be enabled.';
                        }
                    });
                });
            }
        })();
    </script>
</div>
