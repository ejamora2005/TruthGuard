@props(['title', 'description'])

<header class="tg-workspace-heading hidden lg:flex" x-show.important="isDesktop" x-cloak>
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
    </div>
    <a
        href="{{ route('detections.create') }}"
        class="tg-workspace-primary hidden lg:inline-flex"
        x-show.important="isDesktop"
        x-cloak
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>
        <span>New fact check</span>
    </a>
</header>
