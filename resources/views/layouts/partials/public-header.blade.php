@php($logoUrl = asset(config('app.truthguard_logo', 'images/truthguard-logo.png')))
    <header @unless(request()->routeIs('home')) x-data="{ isMenuOpen: false }" @endunless @keydown.escape.window="isMenuOpen = false" x-ref="siteHeader" class="sticky top-0 z-50 w-full border-b border-slate-200 bg-white/85 backdrop-blur-md">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}#home" @if(request()->routeIs('home')) @click.prevent="scrollToSection('home')" @endif class="flex shrink-0 items-center gap-3">
                @if ($logoUrl !== '')
                    <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-10 w-10 object-contain">
                @else
                    <span class="inline-flex h-10 w-10 items-center justify-center text-sm font-bold text-blue-600">TG</span>
                @endif
                <span class="bg-gradient-to-r from-blue-600 to-violet-600 bg-clip-text text-xl font-bold text-transparent">TruthGuard</span>
            </a>

            <nav class="hidden flex-1 items-center justify-center gap-5 text-sm font-medium text-slate-700 xl:flex xl:px-6 xl:gap-8">
                <a href="{{ route('home') }}#features" @if(request()->routeIs('home')) @click.prevent="scrollToSection('features')" @endif class="whitespace-nowrap transition hover:text-blue-600">Features</a>
                <a href="{{ route('reviews.index') }}" class="whitespace-nowrap transition hover:text-blue-600">Claim Reviews</a>
                <a href="{{ route('home') }}#use-cases" @if(request()->routeIs('home')) @click.prevent="scrollToSection('use-cases')" @endif class="whitespace-nowrap transition hover:text-blue-600">Use Cases</a>
                <a href="{{ route('home') }}#testimonials" @if(request()->routeIs('home')) @click.prevent="scrollToSection('testimonials')" @endif class="whitespace-nowrap transition hover:text-blue-600">Review Tips</a>
                <a href="{{ route('home') }}#pricing" @if(request()->routeIs('home')) @click.prevent="scrollToSection('pricing')" @endif class="whitespace-nowrap transition hover:text-blue-600">Pricing</a>
                <a href="{{ route('home') }}#install" @if(request()->routeIs('home')) @click.prevent="scrollToSection('install')" @endif class="whitespace-nowrap transition hover:text-blue-600">Install App</a>
            </nav>

            <div class="hidden shrink-0 items-center gap-3 xl:flex xl:gap-4">
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

            <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg text-slate-700 xl:hidden" @click="isMenuOpen = !isMenuOpen" :aria-expanded="isMenuOpen" aria-label="Toggle menu">
                <svg x-show="!isMenuOpen" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <svg x-show="isMenuOpen" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div x-show="isMenuOpen" x-cloak x-transition class="border-t border-slate-200 bg-white py-4 xl:hidden">
            <div class="container mx-auto flex flex-col space-y-4 px-4">
                <a href="{{ route('home') }}#features" class="py-1 text-slate-700" @if(request()->routeIs('home')) @click.prevent="scrollToSection('features', true)" @endif>Features</a>
                <a href="{{ route('reviews.index') }}" class="py-1 text-slate-700">Claim Reviews</a>
                <a href="{{ route('home') }}#use-cases" class="py-1 text-slate-700" @if(request()->routeIs('home')) @click.prevent="scrollToSection('use-cases', true)" @endif>Use Cases</a>
                <a href="{{ route('home') }}#pricing" class="py-1 text-slate-700" @if(request()->routeIs('home')) @click.prevent="scrollToSection('pricing', true)" @endif>Pricing</a>
                <a href="{{ route('home') }}#testimonials" class="py-1 text-slate-700" @if(request()->routeIs('home')) @click.prevent="scrollToSection('testimonials', true)" @endif>Review Tips</a>
                <a href="{{ route('home') }}#install" class="py-1 text-slate-700" @if(request()->routeIs('home')) @click.prevent="scrollToSection('install', true)" @endif>Install App</a>
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
