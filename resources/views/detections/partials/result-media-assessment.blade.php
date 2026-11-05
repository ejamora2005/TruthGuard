<section class="tg-result-card tg-result-component" id="media-model-contribution" aria-labelledby="media-detector-title">
    <div class="tg-result-section-heading"><h2 id="media-detector-title">{{ ucfirst($selectedDetection->media_type) }} analysis</h2></div>
    @if($media['origin_inconclusive'] ?? false)
        <p class="tg-result-muted">The primary detector's signal was not decisive enough to establish an origin finding.</p>
    @elseif(($media['status'] ?? '') === 'completed' && in_array($media['label'] ?? '', ['real', 'fake'], true))
        <p class="tg-result-muted">{{ ($media['label'] ?? '') === 'fake' ? 'The detector found signals consistent with AI generation.' : 'The detector did not find a strong AI-generation signal.' }} This result does not establish the media's original creator, prove it was never edited, or verify a factual claim shown in it.</p>
    @elseif(($media['status'] ?? '') === 'not_requested')
        <p class="tg-result-muted">AI-generation screening was not requested for this submission.</p>
    @else
        <p class="tg-result-muted">The media check did not produce a usable finding for this saved report.</p>
    @endif
    @if(!empty($media['supporting_assessments']['image_cnn']))
        @php($additionalMedia = $media['supporting_assessments']['image_cnn'])
        <h3>Additional image screening</h3>
        <p class="tg-result-muted">{{ match($additionalMedia['label'] ?? '') { 'fake' => 'The additional image classifier flagged possible synthetic content.', 'real' => 'The additional image classifier did not flag synthetic content. This does not establish human origin.', default => 'The additional image classifier could not complete.' } }}</p>
        @if($media['detector_disagreement'] ?? false)<p class="tg-result-muted">The classifiers disagree. Check the original source or creation record before relying on the finding.</p>@endif
    @endif
    @if($selectedDetection->getKey())
        <form method="POST" action="{{ route('detections.retry-media', $selectedDetection) }}" class="tg-result-assessment-actions" x-data="{ retrying: false }" @submit="retrying = true">
            @csrf
            <button class="tg-result-button" type="submit" :disabled="retrying" x-text="retrying ? 'Analyzing original upload…' : {{ Illuminate\Support\Js::from($mediaPrimary ? 'Retry media analysis' : 'Recheck submission') }}">{{ $mediaPrimary ? 'Retry media analysis' : 'Recheck submission' }}</button>
        </form>
    @endif
    @if (($result['media_claim_notice']['status'] ?? '') === 'claim_detected')
        <div class="tg-result-notice">
            <h3>Potential factual claim detected</h3>
            <p>{{ $result['media_claim_notice']['text'] }}</p>
            <p class="tg-result-muted">{{ $result['media_claim_notice']['message'] }}</p>
            <form method="POST" action="{{ route('detections.fact-check-media-claim', $selectedDetection) }}" class="tg-result-assessment-actions">
                @csrf
                <button class="tg-result-button inline-flex min-h-12 items-center gap-2 rounded-xl bg-gradient-to-r from-blue-700 to-cyan-600 px-4 py-3 font-bold text-white shadow-lg shadow-blue-600/20 transition hover:-translate-y-0.5 hover:shadow-xl" type="submit">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4a7 7 0 1 0 4.9 12L20 20m-8-11v6m-3-3h6" /></svg>
                    <span>Fact-check the visible claim</span>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
                </button>
            </form>
        </div>
    @endif
</section>
