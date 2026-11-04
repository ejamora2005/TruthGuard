@php
    $resultUrl = route('detections.result', $detection);
    $progressUrl = route('detections.create', ['pending' => $detection->id]);
    $floatingLogoUrl = asset('images/truthguard-logo-transparent.png');
@endphp

<aside
    class="truthguard-background-analysis"
    aria-live="polite"
    x-show="visible"
    x-cloak
    x-data="{
        visible: true,
        hideTimer: null,
        status: @js($detection->processing_status),
        stage: @js(data_get($detection->signals, 'analysis_progress.0.label')),
        href: @js($progressUrl),
        timer: null,
        showPrompt: false,
        elapsed: 0,
        clock: null,
        checking: false,
        connectionError: false,
        failures: 0,
        message: 'Your upload is saved. Checking its progress.',
        get elapsedLabel() { return Math.floor(this.elapsed / 60) + 'm ' + (this.elapsed % 60) + 's elapsed'; },
        get complete() { return this.status === 'completed'; },
        get failed() { return this.status === 'failed'; },
        dismiss() {
            this.showPrompt = false;
            if (this.complete || this.failed) this.visible = false;
        },
        startPolling() {
            window.clearInterval(this.timer);
            this.timer = window.setInterval(() => this.refresh(), 5000);
        },
        get statusLabel() {
            if (this.complete) return 'Fact check complete — open result';
            if (this.failed) return 'Fact check needs attention — open report';
            return this.stage || (this.status === 'queued' ? 'Fact check queued' : 'Fact check analysis in progress');
        },
        async refresh() {
            if (this.checking) return;
            this.checking = true;
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 12000);
            try {
                const response = await fetch(@js(route('detections.progress', $detection)), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (response.redirected || [401, 403, 404, 419].includes(response.status)) {
                    this.message = 'Your session or report is unavailable. Refresh this page or sign in again.';
                    this.connectionError = true;
                    window.clearInterval(this.timer);
                    this.showPrompt = true;
                    return;
                }
                if (!response.ok) throw new Error('Status unavailable');
                const data = await response.json();
                this.failures = 0;
                this.connectionError = false;
                this.elapsed = data.elapsed_seconds || 0;
                this.message = data.message;
                const nextStatus = data.processing_status || (data.verdict ? 'completed' : this.status);
                const wasPending = !this.complete && !this.failed;
                this.status = nextStatus;
                this.stage = data.stage || this.stage;
                if (this.complete || this.failed) {
                    this.href = @js($resultUrl);
                    window.clearInterval(this.timer);
                    if (wasPending) this.showPrompt = true;
                } else {
                    this.startPolling();
                }
            } catch (error) {
                this.connectionError = true;
                this.message = 'Progress updates are unavailable. Your upload is saved. Check again in a moment.';
                if (++this.failures >= 3) {
                    window.clearInterval(this.timer);
                    this.timer = window.setInterval(() => this.refresh(), 15000);
                    this.showPrompt = true;
                    this.message = 'Connection interrupted. Your upload is saved; reconnecting automatically. You can also use Check status.';
                }
            } finally {
                window.clearTimeout(timeout);
                this.checking = false;
            }
        },
        init() {
            this.refresh();
            this.startPolling();
            this.clock = window.setInterval(() => { if (!this.complete && !this.failed && !this.connectionError) this.elapsed++; }, 1000);
        },
        destroy() {
            window.clearInterval(this.timer);
            window.clearInterval(this.clock);
            window.clearTimeout(this.hideTimer);
        },
    }"
>
    <button type="button"
        class="truthguard-background-analysis-button"
        @click="showPrompt = !showPrompt; if (showPrompt) refresh()"
        :aria-expanded="showPrompt"
        :title="complete ? 'Fact check ready — view result' : (failed ? 'Analysis needs attention — view report' : 'Fact check analyzing — view progress')"
        :aria-label="complete ? 'Fact check ready. View result.' : (failed ? 'Analysis needs attention. View report.' : 'Fact check analyzing. View progress.')"
    >
        <span class="truthguard-background-analysis-icon" aria-hidden="true">
            <img class="truthguard-background-analysis-logo" src="{{ $floatingLogoUrl }}" alt="">
            <svg x-show="!complete && !failed && !connectionError" class="truthguard-background-analysis-spinner" viewBox="0 0 40 40" fill="none">
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
        <span class="truthguard-background-analysis-pulse" x-show="!complete && !failed && !connectionError" aria-hidden="true"></span>
        <span class="truthguard-background-analysis-tooltip" x-text="statusLabel" role="tooltip"></span>
        <span class="sr-only" x-text="complete ? 'Fact check ready. View result.' : (failed ? 'Analysis needs attention. View report.' : 'Fact check analyzing. View progress.')"></span>
    </button>

    <div
        x-show="showPrompt"
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
        <button type="button" class="truthguard-background-analysis-dismiss" @click="dismiss()" aria-label="Dismiss fact-check update">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" d="m7 7 10 10M17 7 7 17"></path>
            </svg>
        </button>
        <span class="truthguard-background-analysis-ready-icon" aria-hidden="true">
            <img x-show="!complete && !failed" src="{{ $floatingLogoUrl }}" alt="" width="32" height="32">
            <svg x-show="complete" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.2 4.2L19 7"></path>
            </svg>
            <svg x-show="failed" x-cloak viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" d="M12 7v6m0 4h.01"></path>
                <circle cx="12" cy="12" r="9"></circle>
            </svg>
        </span>
        <div class="min-w-0">
            <strong x-text="complete ? 'Your fact check is ready' : (failed ? 'Your fact check needs attention' : (connectionError ? 'Progress connection interrupted' : 'Fact check in progress'))"></strong>
            <p x-text="connectionError ? 'Status temporarily unavailable' : statusLabel"></p>
            <p x-text="elapsedLabel"></p>
            <div x-show="!connectionError && !failed" class="truthguard-analysis-progress" :class="{ 'is-running': !complete }" role="status" aria-label="Fact check progress">
                <div class="truthguard-analysis-track" aria-hidden="true"><span :style="'width: ' + (complete ? '100%' : status === 'queued' ? '33.333%' : '66.667%')"></span></div>
                <div class="truthguard-analysis-labels">
                    <span class="is-active" x-text="complete ? 'Done' : status === 'queued' ? 'Uploaded · waiting to analyze' : 'Analyzing'"></span>
                </div>
            </div>
            <p x-text="complete ? 'Open the report to review the verdict and evidence.' : message"></p>
            <a x-show="complete || failed" :href="@js($resultUrl)" @click="visible = false" x-text="complete ? 'Open result' : 'Open report'"></a>
            <button x-show="!complete && !failed" type="button" class="truthguard-progress-refresh" @click="refresh()" :disabled="checking" x-text="checking ? 'Checking…' : 'Check status'"></button>
        </div>
    </div>
</aside>
