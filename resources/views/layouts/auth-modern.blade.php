<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            $faviconPath = ltrim((string) config('app.truthguard_favicon', 'favicon.ico'), '/');
            $faviconFile = public_path($faviconPath);
            $faviconHref = is_file($faviconFile)
                ? asset($faviconPath).'?v='.filemtime($faviconFile)
                : asset('favicon.ico');
        @endphp

        <title>{{ $title ?? config('app.name', 'TruthGuard') }}</title>
        <link rel="icon" href="{{ $faviconHref }}">
        <link rel="shortcut icon" href="{{ $faviconHref }}">
        @include('layouts.partials.pwa')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=poppins:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="truthguard-auth-page min-h-dvh overflow-x-hidden bg-slate-950 font-[Poppins] text-slate-100 antialiased selection:bg-cyan-300/40 selection:text-white">
        @include('layouts.partials.app-splash')

        @isset($slot)
            {{ $slot }}
        @else
            @yield('content')
        @endisset

        @livewireScripts
    </body>
</html>
