@php($claimResult = $component['result'] ?? null)
<section class="tg-result-card tg-result-component" id="optional-claim-assessment" aria-labelledby="optional-claim-title">
    <div class="tg-result-section-heading"><h2 id="optional-claim-title">Factual claim assessment</h2></div>
    <p class="tg-result-muted">The truth of a claim and the origin of an image are separate questions.</p>
    @if($claimResult)
        <h3>{{ $claimResult['assessment_label'] ?? 'Claim assessment' }}</h3>
        @if(filled($claimResult['claim'] ?? null))
            @include('detections.partials.result-prose', ['prose' => $claimResult['claim'], 'proseId' => 'optional-claim-text', 'proseClass' => 'tg-result-claim-text', 'expandLabel' => 'Read full claim'])
        @endif
        @include('detections.partials.result-answer', ['answer' => $claimResult])
        @if(!empty($claimResult['key_findings']))
            <ul>@foreach($claimResult['key_findings'] as $finding)@if(is_string($finding) && trim($finding) !== '')<li>{{ $finding }}</li>@endif @endforeach</ul>
        @endif
        @if(!empty($claimResult['limitations']))
            <h3>Limitations</h3>
            <ul>@foreach($claimResult['limitations'] as $limitation)@if(is_string($limitation) && trim($limitation) !== '')<li>{{ $limitation }}</li>@endif @endforeach</ul>
        @endif
    @else
        <p class="tg-result-muted">No factual finding was available for this submission.</p>
    @endif
</section>
