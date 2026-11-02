@php
    $resultUrl = route('detections.result', $detection);
    $progressUrl = route('detections.create', ['pending' => $detection->id]);
    $floatingLogoUrl = asset('images/truthguard-logo-transparent.png');
@endphp

<aside
    class="truthguard-background-analysis"
    aria-live="polite"
    x-data="{
        status: @js($detection->processing_status),
        stage: @js(data_get($detection->signals, 'analysis_progress.0.label')),
        href: @js($progressUrl),
        timer: null,
        showPrompt: false,
        get complete() { return this.status === 'completed'; },
        get failed() { return this.status === 'failed'; },
        async refresh() {
            try {
                const response = await fetch(@js($resultUrl), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) return;
                const data = await response.json();
                const nextStatus = data.processing_status || (data.verdict ? 'completed' : this.status);
                const wasPending = !this.complete && !this.failed;
                this.status = nextStatus;
                this.stage = data.stage || this.stage;
                if (this.complete || this.failed) {
                    this.href = @js($resultUrl);
                    window.clearInterval(this.timer);
                    if (wasPending) this.showPrompt = true;
                }
            } catch (error) {
                // Keep the saved progress link available during a temporary network failure.
            }
        },
        init() {
            this.refresh();
            this.timer = window.setInterval(() => this.refresh(), 5000);
        },
        destroy() {
            window.clearInterval(this.timer);
        },
    }"
>
    <a
        class="truthguard-background-analysis-button"
        :href="href"
        :title="complete ? 'Fact check ready — view result' : (failed ? 'Analysis needs attention — view report' : 'Fact check analyzing — view progress')"
        :aria-label="complete ? 'Fact check ready. View result.' : (failed ? 'Analysis needs attention. View report.' : 'Fact check analyzing. View progress.')"
    >
        <span class="truthguard-background-analysis-icon" aria-hidden="true">
            <img class="truthguard-background-analysis-logo" src="{{ $floatingLogoUrl }}" alt="">
            <svg x-show="!complete && !failed" class="truthguard-background-analysis-spinner" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="18" stroke="currentColor" stroke-width="2.2" opacity=".25"></circle>
                <path d="M20 2a18 18 0 0 1 18 18" stroke="currentColor" stroke-width="2.8" stroke-linecap="round"></path>
            </svg>
            <svg x-show="complete" x-cloak class="truthguard-background-analysis-state" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.2 4.2L19 7"></path>
            </svg>
            <svg x-show="failed" x-cloak class="truthguard-background-analysis-state truthguard-background-analysis-state--failed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" d="M12 7v6m0 4h.01"></path>
                <circle cx="12" cy="12" r="9"></circle>
            </svg>
        </span>
        <span class="truthguard-background-analysis-pulse" x-show="!complete && !failed" aria-hidden="true"></span>
        <span class="sr-only" x-text="complete ? 'Fact check ready. View result.' : (failed ? 'Analysis needs attention. View report.' : 'Fact check analyzing. View progress.')"></span>
    </a>

    <div
        x-show="showPrompt && (complete || failed)"
        x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-y-2 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-2 opacity-0"
        class="truthguard-background-analysis-prompt"
        role="status"
    >
        <button type="button" class="truthguard-background-analysis-dismiss" @click="showPrompt = false" aria-label="Dismiss fact-check update">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="m7 7 10 10M17 7 7 17"></path>
            </svg>
        </button>
        <span class="truthguard-background-analysis-ready-icon" aria-hidden="true">
            <svg x-show="complete" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.2 4.2L19 7"></path>
            </svg>
            <svg x-show="failed" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" d="M12 7v6m0 4h.01"></path>
                <circle cx="12" cy="12" r="9"></circle>
            </svg>
        </span>
        <div class="min-w-0">
            <strong x-text="complete ? 'Your fact check is ready' : 'Your fact check needs attention'"></strong>
            <p x-text="complete ? 'The analysis has finished. Open the report to review the verdict and evidence.' : 'Open the report to see why the analysis could not finish.'"></p>
            <a :href="href" x-text="complete ? 'Open result' : 'Open report'"></a>
        </div>
    </div>
</aside>
