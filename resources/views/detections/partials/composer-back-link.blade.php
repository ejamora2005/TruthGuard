<a href="{{ route('dashboard') }}" class="truthguard-mobile-composer-back ml-2 inline-flex min-h-11 shrink-0 items-center justify-center gap-1 rounded-lg px-2 text-xs font-bold text-blue-700 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500 dark:text-blue-300 dark:hover:bg-slate-800"
    x-init="try {
        const previous = new URL(document.referrer);
        if (previous.origin === window.location.origin && /^\/(?:dashboard(?:\/|$)|history(?:\/|$)|notifications(?:\/|$)|profile(?:\/|$)|detections\/\d+\/result(?:\/|$))/.test(previous.pathname)) $el.href = previous.href;
    } catch (_) {}"
    aria-label="Back to previous workspace page" title="Go back">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m10 6-6 6 6 6M4 12h16" /></svg>
    <span>Back</span>
</a>
