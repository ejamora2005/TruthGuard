<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="TruthGuard Agent AI 1.0 - detect fake media and misinformation with AI automation.">

    <title>TruthGuard Agent AI 1.0</title>

    @include('layouts.partials.pwa')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Poppins', sans-serif; }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 6.5rem;
        }

        section[id] {
            scroll-margin-top: 6.5rem;
        }

        @media (max-width: 768px) {
            html {
                scroll-padding-top: 7.25rem;
            }

            section[id] {
                scroll-margin-top: 7.25rem;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-b from-slate-50 to-white text-slate-900 antialiased">
    @include('layouts.partials.app-splash')

    <nav class="sticky top-0 z-50 w-full border-b border-slate-200 bg-white/85 backdrop-blur">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="#home" class="flex items-center gap-3">
                <img src="{{ asset('images/truthguard-logo-transparent.png') }}" alt="TruthGuard logo" class="h-9 w-9 object-contain">
                <span class="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-xl font-bold text-transparent">TruthGuard Agent AI 1.0</span>
            </a>

            <div class="hidden items-center gap-7 text-sm font-medium text-slate-700 md:flex">
                <a href="#features" class="transition hover:text-blue-600">Features</a>
                <a href="#use-cases" class="transition hover:text-blue-600">Use Cases</a>
                <a href="#install" class="transition hover:text-blue-600">Install App</a>
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-300 px-4 py-2 transition hover:border-blue-400 hover:text-blue-700">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg border border-slate-300 px-4 py-2 transition hover:border-blue-400 hover:text-blue-700">Log in</a>
                @endauth
            </div>
        </div>
    </nav>

    <main>
        <section id="home" class="overflow-hidden py-16 md:py-24">
            <div class="mx-auto grid w-full max-w-7xl gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:items-center lg:px-8">
                <div>
                    <div class="mb-6 inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-sm font-medium text-blue-700">
                        TruthGuard Agent AI 1.0
                    </div>
                    <h1 class="bg-gradient-to-r from-slate-900 via-blue-800 to-violet-900 bg-clip-text text-4xl font-bold leading-tight text-transparent md:text-5xl lg:text-6xl">
                        AI Agents That Detect Fake Content Fast
                    </h1>
                    <p class="mt-6 max-w-xl text-lg text-slate-600">
                        Analyze social posts, images, and videos with automated workflows, cross-source checks, and explainable confidence scores.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        @auth
                            <a href="{{ route('detections.create') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-blue-200 transition hover:from-blue-700 hover:to-violet-700">
                                Start Detection
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-6 py-3 text-base font-semibold text-white shadow-lg shadow-blue-200 transition hover:from-blue-700 hover:to-violet-700">
                                Get Started
                            </a>
                        @endauth

                        <button type="button" class="js-install-app inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-6 py-3 text-base font-semibold text-slate-700 transition hover:border-violet-300 hover:text-violet-700" disabled>
                            Install App
                        </button>
                    </div>

                    <p class="mt-4 text-sm text-slate-500">Install TruthGuard as an app so you can use it like mobile/desktop software instead of a normal browser tab.</p>
                </div>

                <div class="relative">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
                        <div class="mb-4 flex items-center gap-2">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 font-semibold text-blue-600">AI</span>
                            <p class="text-sm font-medium text-slate-700">Live Verification Agent</p>
                        </div>
                        <div class="space-y-3">
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Input: "Viral flood image posted today in Metro Manila"
                            </div>
                            <div class="rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 p-4 text-sm text-white">
                                <p class="font-semibold">Analysis Complete</p>
                                <ul class="mt-2 space-y-1 text-blue-100">
                                    <li>- Reverse image match found from older event</li>
                                    <li>- Weather archive mismatch for claim date</li>
                                    <li>- Confidence score: 87% misleading</li>
                                </ul>
                            </div>
                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                Verdict: <span class="font-semibold text-rose-600">Likely False Context</span>
                            </div>
                        </div>
                    </div>
                    <div class="absolute -z-10 left-6 top-6 h-full w-full rounded-2xl bg-gradient-to-r from-blue-600/20 to-violet-600/20 blur-3xl"></div>
                </div>
            </div>
        </section>

        <section id="features" class="bg-slate-50 py-16 md:py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-12 max-w-3xl text-center">
                    <h2 class="text-3xl font-bold md:text-4xl">TruthGuard Features</h2>
                    <p class="mt-4 text-lg text-slate-600">Everything your team needs to identify misinformation and respond quickly.</p>
                </div>

                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">AI Media Authenticity Check</h3>
                        <p class="mt-2 text-slate-600">Detect manipulated, synthetic, or suspicious visual patterns in images and videos.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">Social Post Claim Extraction</h3>
                        <p class="mt-2 text-slate-600">Automatically extract major claims from posts and route them to the verification pipeline.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">Cross-Source Validation</h3>
                        <p class="mt-2 text-slate-600">Compare results against trusted news, fact-check databases, and relevant public datasets.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">Automated Workflow Engine</h3>
                        <p class="mt-2 text-slate-600">Use n8n-style automation to process, score, and label detection cases faster.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">Confidence & Risk Scoring</h3>
                        <p class="mt-2 text-slate-600">Generate transparent confidence scores so reviewers can prioritize high-risk items.</p>
                    </article>
                    <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm transition hover:shadow-md">
                        <h3 class="text-xl font-semibold">Dashboard & Case History</h3>
                        <p class="mt-2 text-slate-600">Track verdicts, review timelines, and monitoring trends in a single dashboard.</p>
                    </article>
                </div>
            </div>
        </section>

        <section id="use-cases" class="py-16 md:py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                    <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <img src="{{ asset('welcome/usecase-newsroom-verification.jpg') }}" alt="Data analysis use case" class="h-48 w-full object-cover">
                        <div class="p-5">
                            <h3 class="text-lg font-semibold">Newsroom Verification</h3>
                            <p class="mt-2 text-sm text-slate-600">Validate viral posts before publishing updates or breaking reports.</p>
                        </div>
                    </article>
                    <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <img src="{{ asset('welcome/usecase-government-response.jpg') }}" alt="Operations use case" class="h-48 w-full object-cover">
                        <div class="p-5">
                            <h3 class="text-lg font-semibold">Government Monitoring</h3>
                            <p class="mt-2 text-sm text-slate-600">Track harmful misinformation related to disasters, health, and public services.</p>
                        </div>
                    </article>
                    <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <img src="{{ asset('welcome/usecase-community-fact-checking.jpg') }}" alt="Community use case" class="h-48 w-full object-cover">
                        <div class="p-5">
                            <h3 class="text-lg font-semibold">Community Fact-Checking</h3>
                            <p class="mt-2 text-sm text-slate-600">Support moderation teams with AI-assisted evidence and explainable verdicts.</p>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <section id="install" class="py-16 md:py-20">
            <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-2xl bg-gradient-to-r from-blue-600 to-violet-600 p-8 text-white md:p-12">
                    <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-3xl font-bold md:text-4xl">Use TruthGuard as an App</h2>
                            <p class="mt-3 max-w-2xl text-blue-100">Install the app for a cleaner, faster workflow without browser clutter. Works on supported desktop and mobile browsers.</p>
                        </div>
                        <button type="button" class="js-install-app inline-flex items-center justify-center rounded-xl bg-white px-6 py-3 text-base font-semibold text-indigo-700 transition hover:bg-blue-50" disabled>
                            Install TruthGuard App
                        </button>
                    </div>
                </div>
                <p id="install-help" class="mt-4 text-sm text-slate-500"></p>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-4 px-4 py-8 text-sm text-slate-500 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
            <p>&copy; {{ date('Y') }} TruthGuard Agent AI 1.0</p>
            <div class="flex gap-4">
                @auth
                    <a href="{{ route('dashboard') }}" class="hover:text-blue-600">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="hover:text-blue-600">Log in</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="hover:text-blue-600">Register</a>
                    @endif
                @endauth
            </div>
        </div>
    </footer>

</body>
</html>
