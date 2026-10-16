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
</section>
