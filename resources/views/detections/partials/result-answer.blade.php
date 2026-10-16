@php
    // Source IDs are local to an assessment. Resolve them through that answer's
    // reviewed records so a second assessment may safely reuse the same ID.
    $answerCitationNumbers = [];
    foreach (array_merge($answer['sources'] ?? [], $answer['contextual_sources'] ?? []) as $answerSource) {
        $safeAnswerUrl = App\Services\Detections\ReportAnswer::safeUrl($answerSource['url'] ?? null);
        if (isset($answerSource['id']) && $safeAnswerUrl !== null && isset($sourceNumbersByUrl[$safeAnswerUrl])) {
            $answerCitationNumbers[$answerSource['id']] = $sourceNumbersByUrl[$safeAnswerUrl];
        }
    }
@endphp
<div class="tg-result-summary tg-result-answer-copy">
    @foreach($answer['answer_segments'] ?? [['text' => $answer['summary'], 'source_ids' => []]] as $segment)
        <p>{{ $segment['text'] }}
            @foreach($segment['source_ids'] ?? [] as $id)
                @if(isset($answerCitationNumbers[$id]))
                    <a class="tg-result-citation" href="#evidence-source-{{ $answerCitationNumbers[$id] }}" aria-label="Source {{ $answerCitationNumbers[$id] }}"
                       @click="const card = document.getElementById('evidence-source-{{ $answerCitationNumbers[$id] }}'); const group = card?.closest('details'); if (group) group.open = true;">[{{ $answerCitationNumbers[$id] }}]</a>
                @endif
            @endforeach
        </p>
    @endforeach
</div>
