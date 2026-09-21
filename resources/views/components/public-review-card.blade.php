@props(['item'])
@php
    $publisher = $item['publisher'] ?? 'Publisher';
    $rating = \App\Services\Detections\FactCheckRatingNormalizer::shortLabel(
        $item['rating'] ?? 'Reviewed', (string) ($item['headline'] ?? ''), (string) ($item['claim'] ?? ''),
    );
    $tone = \App\Services\Detections\FactCheckRatingNormalizer::toneFor($rating);
    $url = $item['url'] ?? '';
    $hasLink = in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
@endphp
<article class="tg-public-review">
    <div class="tg-public-review-media">
        @if (!empty($item['image_url']))
            <img src="{{ $item['image_url'] }}" alt="Claim reviewed by {{ $publisher }}" loading="lazy" width="640" height="400">
        @elseif (!empty($item['logo_url']))
            <img src="{{ $item['logo_url'] }}" alt="{{ $publisher }}" loading="lazy" class="tg-public-review-placeholder" width="96" height="96">
        @else
            <span class="tg-public-review-placeholder">{{ $publisher }}</span>
        @endif
    </div>
    <div class="tg-public-review-copy">
        <div class="tg-public-review-meta">
            <span class="tg-public-review-publisher">
                @if (!empty($item['logo_url']))
                    <img src="{{ $item['logo_url'] }}" alt="" width="20" height="20" loading="lazy">
                @endif
                {{ $publisher }}
            </span>
            <span class="tg-public-review-rating" data-tone="{{ $tone }}">{{ $rating }}</span>
        </div>
        <h3>{{ $item['headline'] ?? 'Public claim review' }}</h3>
        <p>{{ $item['claim'] ?? '' }}</p>
        <div class="tg-public-review-footer">
            <span>{{ $item['date_label'] ?? 'Date unavailable' }}</span>
            @if ($hasLink)
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="Read {{ $publisher }} review: {{ $item['headline'] ?? 'Public claim review' }} (opens in a new tab)">Read review <span aria-hidden="true">&nearr;</span></a>
            @endif
        </div>
    </div>
</article>
