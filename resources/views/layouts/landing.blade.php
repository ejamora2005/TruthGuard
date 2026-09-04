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

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="m-0 min-h-screen bg-slate-50 text-slate-900 antialiased selection:bg-blue-100 selection:text-slate-900" style="font-family: 'Poppins', sans-serif;">
        @include('layouts.partials.app-splash')

        {{ $slot }}

        @livewireScripts
    </body>
</html>
