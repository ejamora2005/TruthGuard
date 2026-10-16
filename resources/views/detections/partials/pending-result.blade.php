<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="pending-result-title"
    x-data="{
        status: @js($selectedDetection->processing_status), stage: @js(data_get($selectedDetection->signals, 'analysis_progress.0.label')), domains: @js(data_get($selectedDetection->signals, 'analysis_progress.0.source_domains', [])), polling: true, timer: null,
        init() { if (this.status !== 'failed') this.timer = setInterval(() => this.refresh(), 5000); },
        async refresh() {
            try {
                const response = await fetch(@js(route('detections.result', $selectedDetection)), { headers: { Accept: 'application/json' } });
                if (!response.ok) { this.polling = false; clearInterval(this.timer); return; }
                const data = await response.json();
                if (data.verdict) { clearInterval(this.timer); window.location.reload(); return; }
                this.status = data.processing_status; this.stage = data.stage;
                this.domains = data.source_domains || [];
                if (this.status === 'failed') clearInterval(this.timer);
            } catch { this.polling = false; clearInterval(this.timer); }
        },
        destroy() { clearInterval(this.timer); }
    }">
    @isset($userReportNumber)
        <p class="mb-3 text-sm text-slate-500">Report reference <strong>{{ 'TG-'.str_pad((string) $userReportNumber, 2, '0', STR_PAD_LEFT) }}</strong></p>
    @endisset
    <h1 id="pending-result-title" class="text-xl font-bold text-slate-900" x-text="status === 'failed' ? 'Assessment could not be completed' : 'Your assessment is in progress'">Assessment status</h1>
    <p class="mt-3 text-slate-600" role="status" x-text="status === 'failed' ? 'No verdict was produced. Please submit again.' : (status === 'queued' ? 'Waiting for an analysis worker. You can return to this report later.' : (stage || 'Analyzing the submitted content.'))">{{ $selectedDetection->processing_status === 'failed' ? 'No verdict was produced. Please submit again.' : 'Waiting for the submitted content to be assessed.' }}</p>
    <div class="mt-3 flex flex-wrap gap-2" x-show="domains.length" x-cloak aria-label="Retrieved source websites">
        <template x-for="domain in domains" :key="domain"><span class="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600" x-text="domain"></span></template>
    </div>
    <p class="mt-3 text-slate-600" x-show="!polling" x-cloak>Status updates are unavailable. Refresh this page to check again.</p>
    <a href="{{ route('detections.create') }}" class="mt-5 inline-flex min-h-11 items-center font-semibold text-blue-700">Back to Fact Check</a>
</section>
