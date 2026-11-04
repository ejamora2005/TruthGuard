@php($logoUrl = asset(config('app.truthguard_logo', 'images/truthguard-logo.png')))
    <footer class="truthguard-public-footer bg-slate-900 text-slate-300">
        <div class="mx-auto w-full max-w-7xl px-4 py-7 sm:px-6 sm:py-10 lg:px-8">
            <div class="grid grid-cols-2 gap-x-6 gap-y-6 lg:grid-cols-4 lg:gap-8">
                <div class="col-span-2">
                    <a href="{{ route('home') }}#home" @if(request()->routeIs('home')) @click.prevent="scrollToSection('home')" @endif class="mb-3 flex items-center gap-2">
                        @if ($logoUrl !== '')
                            <img src="{{ $logoUrl }}" alt="TruthGuard logo" class="h-9 w-9 object-contain">
                        @else
                            <span class="inline-flex h-9 w-9 items-center justify-center text-sm font-bold text-blue-600">TG</span>
                        @endif
                        <span class="text-xl font-bold text-white">TruthGuard</span>
                    </a>
                    <p class="max-w-md text-xs leading-6 sm:text-sm">
                        Check claims, review evidence, and share responsibly.
                    </p>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-white">Explore</h3>
                    <ul class="text-xs sm:text-sm">
                        <li><a href="{{ route('home') }}#features" @if(request()->routeIs('home')) @click.prevent="scrollToSection('features')" @endif class="transition hover:text-white">Features</a></li>
                        <li><a href="{{ route('home') }}#use-cases" @if(request()->routeIs('home')) @click.prevent="scrollToSection('use-cases')" @endif class="transition hover:text-white">Use Cases</a></li>
                        <li><a href="{{ route('home') }}#pricing" @if(request()->routeIs('home')) @click.prevent="scrollToSection('pricing')" @endif class="transition hover:text-white">Pricing</a></li>
                        <li><a href="{{ route('home') }}#install" @if(request()->routeIs('home')) @click.prevent="scrollToSection('install')" @endif class="transition hover:text-white">Install App</a></li>
                        <li><a href="{{ route('home') }}#testimonials" @if(request()->routeIs('home')) @click.prevent="scrollToSection('testimonials')" @endif class="transition hover:text-white">Review Tips</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-2 text-sm font-semibold text-white">Support</h3>
                    <ul class="text-xs sm:text-sm">
                        @auth
                            <li><a href="{{ route('dashboard') }}" class="transition hover:text-white">Dashboard</a></li>
                        @else
                            <li><a href="{{ route('login') }}" class="transition hover:text-white">Log In</a></li>
                            @if (Route::has('register'))
                                <li><a href="{{ route('register') }}" class="transition hover:text-white">Register</a></li>
                            @endif
                        @endauth
                        <li><a href="mailto:truthguard2026@gmail.com" class="transition hover:text-white">Contact us</a></li>
                        <li><a href="{{ route('privacy.policy') }}" class="transition hover:text-white">Privacy Policy</a></li>
                    </ul>
                </div>
            </div>

            <div class="mt-5 border-t border-slate-800 pt-4 text-center text-[11px] text-slate-400 sm:text-xs lg:text-left">
                <p>&copy; {{ date('Y') }} TruthGuard. All rights reserved.</p>
            </div>
        </div>
    </footer>
