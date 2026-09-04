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

        <title>TRUTHGUARD | Admin</title>
        <link rel="icon" href="{{ $faviconHref }}">
        <link rel="shortcut icon" href="{{ $faviconHref }}">
        @include('layouts.partials.pwa')
        <link rel="stylesheet" href="{{ asset('vendor/tailadmin/admin.css') }}">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        @vite('resources/js/admin.jsx')
    </head>
    <body class="truthguard-admin-app">
        @include('layouts.partials.app-splash')

        <script>
            window.TruthGuardAdmin = @json($adminUserConfig);
        </script>

        <div id="admin-root"></div>

        @include('auth.partials.session-timeout-modal')
    </body>
</html>
