@php
    $visibleAnswerSources = array_slice($answerSources, 0, 3);
    $remainingAnswerSources = array_slice($answerSources, 3);
@endphp
<section class="tg-result-card tg-result-verify-sources" aria-labelledby="sources-to-verify">
    <div class="tg-result-section-heading"><h2 id="sources-to-verify">Sources to verify</h2><span class="tg-result-count">{{ count($answerSources) }}</span></div>
    @if($visibleAnswerSources)
        <p class="tg-result-muted">Reviewed documents used as evidence or to explain relevant context. Each card states its role in this assessment.</p>
        <div class="tg-result-source-cards">
            @foreach($visibleAnswerSources as $source)
                @include('detections.partials.result-verify-source', ['sourceNumber' => $sourceNumbersByUrl[$source['url']]])
            @endforeach
        </div>
        @if($remainingAnswerSources)
            <details class="tg-result-more-sources">
                <summary>View all sources ({{ count($answerSources) }})</summary>
                <div class="tg-result-source-cards">
                    @foreach($remainingAnswerSources as $source)
                        @include('detections.partials.result-verify-source', ['sourceNumber' => $sourceNumbersByUrl[$source['url']]])
                    @endforeach
                </div>
            </details>
        @endif
    @else
        <p class="tg-result-empty">{{ $mediaPrimary && !$combined ? 'No external source was used for this media assessment. The detector alone cannot establish the creator or provenance.' : 'No reviewed source established a factual conclusion for this submission. This does not mean the claim is false.' }}</p>
    @endif

    @if(!empty($result['method_references']))
        <h3>Method references</h3>
        <p class="tg-result-muted">Documentation of the detector's training scope and limitations. These references are not evidence about who created the submitted image.</p>
        @foreach($result['method_references'] as $reference)
            <a href="{{ $reference['url'] }}" target="_blank" rel="noopener noreferrer">{{ $reference['title'] }}</a>
        @endforeach
    @endif

    @if(!empty($result['official_references']))
        <h3>Official references</h3>
        <p class="tg-result-muted">These links may help you check the topic, but were not used to establish this verdict.</p>
        @foreach($result['official_references'] as $reference)
            @if($referenceUrl = App\Services\Detections\ReportAnswer::safeUrl($reference['url'] ?? null))
                <a href="{{ $referenceUrl }}" target="_blank" rel="noopener noreferrer">{{ $reference['title'] }}</a>
            @endif
        @endforeach
    @endif

    @if(!empty($result['historical_references']))
        <h3>Historical links</h3>
        <p class="tg-result-muted">These links were saved with an older report. Their relevance and full text were not validated for this assessment, so they are not cited as evidence.</p>
        <div class="tg-result-source-cards">
            @foreach($result['historical_references'] as $reference)
                @if($referenceUrl = App\Services\Detections\ReportAnswer::safeUrl($reference['url'] ?? null))
                    <article class="tg-result-source">
                        <h3>{{ $reference['title'] ?? 'Saved link' }}</h3>
                        @if(filled($reference['name'] ?? null))<p class="tg-result-source-meta">{{ $reference['name'] }}</p>@endif
                        <a class="tg-result-button" href="{{ $referenceUrl }}" target="_blank" rel="noopener noreferrer">Open original source <span aria-hidden="true">&#8599;</span></a>
                    </article>
                @endif
            @endforeach
        </div>
    @endif
</section>
