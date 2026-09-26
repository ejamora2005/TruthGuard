<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($icon)
        @case('home')
            <path d="m3 10 9-7 9 7M5 9v11h5v-6h4v6h5V9"/>
            @break
        @case('back')
            <path d="m10 5-7 7 7 7M3 12h18"/>
            @break
        @case('refresh')
            <path d="M20 8a8 8 0 1 0 0 8M20 3v5h-5"/>
            @break
    @endswitch
</svg>
