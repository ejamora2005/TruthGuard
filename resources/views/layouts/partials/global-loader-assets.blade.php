@once
    <link rel="preload" as="image" href="{{ asset('images/truthguard-logo-transparent.png') }}">
    <style data-truthguard-loader-style>{!! file_get_contents(resource_path('css/global-loader.css')) !!}</style>
    <script data-navigate-once>{!! file_get_contents(resource_path('js/global-loader.js')) !!}</script>
@endonce
