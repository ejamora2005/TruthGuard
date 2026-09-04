@php
    $pwaThemeColor = $pwaThemeColor ?? '#2563eb';
@endphp

<meta name="theme-color" content="{{ $pwaThemeColor }}">
<meta name="color-scheme" content="light">
<meta name="application-name" content="TruthGuard">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="TruthGuard">
<meta name="mobile-web-app-capable" content="yes">
<meta name="msapplication-TileColor" content="{{ $pwaThemeColor }}">
<meta name="msapplication-navbutton-color" content="{{ $pwaThemeColor }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('pwa/icon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('pwa/icon-192.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('pwa/apple-touch-icon.png') }}">
