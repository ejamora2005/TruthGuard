@php
    $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo-transparent.png'), '/');
    $logoUrl = asset(is_file(public_path($logoPath)) ? $logoPath : 'images/truthguard-logo-transparent.png');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title>{{ $code }} · {{ $title }} · TruthGuard</title>
    <link rel="icon" href="{{ asset(config('app.truthguard_favicon', 'favicon.ico')) }}">
    {{-- Error pages remain usable without Vite, JavaScript frameworks, or a CDN. --}}
    <style>@include('errors.partials.styles')</style>
</head>
<body>
    <div class="error-page">
        <div class="background" aria-hidden="true">
            <svg viewBox="0 0 1440 900" preserveAspectRatio="none"><path class="wave-blue" d="M0 460C160 380 120 720 370 740S660 900 680 900H0Z"/><path class="wave-mint" d="M1440 130c-250 50-100 430-320 470s-140 240-50 300h370Z"/></svg>
            <span class="accent-dot dot-one"></span><span class="accent-dot dot-two"></span><span class="accent-plus">+</span>
        </div>
        <header class="page-header">
            <a class="brand" href="{{ url('/') }}" aria-label="TruthGuard home">
                <img src="{{ $logoUrl }}" width="40" height="40" alt="">
                <span>TruthGuard</span>
            </a>
            @if ($code !== '503')
                <a class="header-home" href="{{ url('/') }}">@include('errors.partials.icon', ['icon' => 'home'])<span>Back to Home</span></a>
            @endif
        </header>
        <main id="main-content" aria-labelledby="error-title">
            <div class="illustration" aria-hidden="true">@include('errors.partials.illustration')</div>
            <div class="error-code" aria-hidden="true">{{ $code }}</div>
            <div class="error-copy">
                <h1 id="error-title"><span class="sr-only">Error {{ $code }}: </span>{{ $title }}</h1>
                <p>{{ $description }}</p>
            </div>
            <div class="actions">
                @foreach ($actions as $action)
                    @php
                        $type = $action['type'];
                        $primary = $action['primary'] ?? $type !== 'back';
                    @endphp
                    @if (in_array($type, ['refresh', 'retry', 'back'], true))
                        <button type="button" class="button {{ $primary ? 'primary' : 'secondary' }}" data-action="{{ $type }}">
                            @include('errors.partials.icon', ['icon' => $type === 'back' ? 'back' : 'refresh'])
                            {{ $action['label'] }}
                        </button>
                    @else
                        <a class="button {{ $primary ? 'primary' : 'secondary' }}" href="{{ $action['url'] }}">
                            @include('errors.partials.icon', ['icon' => 'home'])
                            {{ $action['label'] }}
                            <span class="button-arrow" aria-hidden="true">→</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </main>
    </div>
    <script>
        document.querySelectorAll('[data-action]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (button.dataset.action === 'back') {
                    if (window.history.length > 1) window.history.back();
                    else window.location.assign(@json(url('/')));
                } else {
                    // Navigate with GET instead of resubmitting an expired or failed POST.
                    window.location.assign(window.location.href);
                }
            });
        });
    </script>
</body>
</html>
