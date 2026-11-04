<button
    type="button"
    class="truthguard-appearance-toggle"
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    @truthguard-appearance-updated.window="dark = document.documentElement.classList.contains('dark')"
    @click="window.TruthGuardAppearance.toggle()"
    :aria-pressed="dark"
    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
    :title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
>
    <svg class="truthguard-appearance-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20.9 13a9 9 0 0 1-9.9-9.9A9 9 0 1 0 20.9 13Z"/></svg>
    <svg class="truthguard-appearance-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.4 1.4m11.2 11.2L19 19M5 19l1.4-1.4M17.6 6.4 19 5"/></svg>
    <span class="sr-only">Toggle light and dark mode</span>
</button>
