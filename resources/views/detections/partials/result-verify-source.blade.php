@php
    $sourceUrl = App\Services\Detections\ReportAnswer::safeUrl($source['url'] ?? null);
    $sourceTime = static function (?string $value): ?string {
        if (!$value) return null;
        try {
            $date = Illuminate\Support\Carbon::parse($value);
            return preg_match('/(?:T|\s)\d{2}:\d{2}/', $value) === 1
                ? $date->timezone('Asia/Manila')->format('M j, Y, g:i A').' PHT (UTC+8)'
                : $date->format('M j, Y');
        } catch (Throwable) {
            return null;
        }
    };
    $publishedLabel = $sourceTime($source['published_at'] ?? null);
    $updatedLabel = $sourceTime($source['updated_at'] ?? null);
    $retrievedLabel = $sourceTime($source['retrieved_at'] ?? null);
    $sourcePurpose = trim((string) ($source['used_for'] ?? ''));
    if ($sourcePurpose === '' && ($source['context_only'] ?? false)) {
        $sourcePurpose = trim((string) ($source['explanation'] ?? ''));
    }
    if ($sourcePurpose === '') {
        $sourcePurpose = implode(' ', array_unique(array_filter(array_column($source['relationships'] ?? [], 'explanation'))));
    }
    $relationships = collect($source['relationships'] ?? [])->filter(fn ($row) => is_array($row));
    $currentStances = $relationships->filter(fn ($row) => !($row['superseded'] ?? false))->pluck('stance')->all();
    $sourceRole = match (true) {
        (bool) ($source['context_only'] ?? false) => 'Related context',
        in_array('supporting', $currentStances, true) && in_array('contradicting', $currentStances, true) => 'Mixed evidence',
        in_array('supporting', $currentStances, true) => 'Supports the claim',
        in_array('contradicting', $currentStances, true) => 'Contradicts the claim',
        in_array('insufficient', $currentStances, true) => 'Relevant but inconclusive',
        $relationships->contains(fn ($row) => $row['superseded'] ?? false) => 'Superseded record',
        default => 'Reviewed reference',
    };
    $sourceExcerpts = $relationships->pluck('excerpt')->filter(fn ($excerpt) => is_string($excerpt) && trim($excerpt) !== '')->unique()->take(2)->values()->all();
    if ($sourceExcerpts === [] && is_string($source['excerpt'] ?? null) && trim($source['excerpt']) !== '') {
        $sourceExcerpts = [$source['excerpt']];
    }
@endphp
@if($sourceUrl)
<article class="tg-result-source" id="evidence-source-{{ $sourceNumber }}" tabindex="-1">
    <div class="tg-result-source-meta"><span class="tg-result-source-type">[{{ $sourceNumber }}] {{ $sourceRole }}</span><span>{{ $sourceLabels[$source['type'] ?? ''] ?? 'Reviewed source' }} · {{ $source['name'] ?? 'Publisher not recorded' }}</span></div>
    <h3>{{ filled($source['title'] ?? null) ? $source['title'] : 'Document title unavailable' }}</h3>
    <p class="tg-result-source-domain">{{ parse_url($sourceUrl, PHP_URL_HOST) }}</p>
    @if($publishedLabel)
        <p class="tg-result-source-date">Published / issued: {{ $publishedLabel }}</p>
    @else
        <p class="tg-result-source-date">Publication / issuance date not recorded.</p>
    @endif
    @if($updatedLabel)<p class="tg-result-source-date">Updated: {{ $updatedLabel }}</p>@endif
    @if($retrievedLabel)<p class="tg-result-source-date">Retrieved: {{ $retrievedLabel }}</p>@endif
    <p class="tg-result-source-purpose">{{ $sourcePurpose !== '' ? $sourcePurpose : 'The saved assessment did not record how this document addresses the claim.' }}</p>
    @foreach($sourceExcerpts as $excerpt)
        <blockquote class="tg-result-source-relationship">{{ Illuminate\Support\Str::limit($excerpt, 320) }}</blockquote>
    @endforeach
    @if(!empty($source['possible_copy_of']))<p class="tg-result-muted">May repeat another record; not independent corroboration.</p>@endif
    <a class="tg-result-button" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">Open original source <span aria-hidden="true">&#8599;</span></a>
</article>
@endif
