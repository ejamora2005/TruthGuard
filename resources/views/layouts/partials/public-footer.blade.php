@php($logoUrl = asset(config('app.truthguard_logo', 'images/truthguard-logo.png')))
    <footer class="bg-slate-900 text-slate-300">
        <div class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="grid gap-8 md:grid-cols-2 lg:grid-cols-5">
                <div class="lg:col-span-2">
                    <a href="{{ route('home') }}#home" @if(request()->routeIs('home')) @click.prevent="scrollToSection('home')" @endif class="mb-6 flex items-center gap-3">
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
                        <li><a href="{{ route('home') }}#features" @if(request()->routeIs('home')) @click.prevent="scrollToSection('features')" @endif class="transition hover:text-white">Features</a></li>
                        <li><a href="{{ route('home') }}#use-cases" @if(request()->routeIs('home')) @click.prevent="scrollToSection('use-cases')" @endif class="transition hover:text-white">Use Cases</a></li>
                        <li><a href="{{ route('home') }}#pricing" @if(request()->routeIs('home')) @click.prevent="scrollToSection('pricing')" @endif class="transition hover:text-white">Pricing</a></li>
                        <li><a href="{{ route('home') }}#install" @if(request()->routeIs('home')) @click.prevent="scrollToSection('install')" @endif class="transition hover:text-white">Install App</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="mb-4 font-semibold text-white">Platform</h3>
                    <ul class="space-y-3 text-sm">
                        <li><a href="{{ route('home') }}#home" @if(request()->routeIs('home')) @click.prevent="scrollToSection('home')" @endif class="transition hover:text-white">Overview</a></li>
                        <li><a href="{{ route('home') }}#testimonials" @if(request()->routeIs('home')) @click.prevent="scrollToSection('testimonials')" @endif class="transition hover:text-white">Review Tips</a></li>
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
