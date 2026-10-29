@props(['currentPage', 'lastPage', 'total', 'firstItem', 'lastItem', 'pageUrl', 'label' => 'Pages'])

@if ($total > 0)
    <div class="tg-pagination">
        <p class="tg-pagination-count">
            <span class="sr-only">Showing </span>{{ number_format($firstItem) }}–{{ number_format($lastItem) }}
            <span aria-hidden="true">/</span><span class="sr-only"> of </span>
            {{ number_format($total) }}<span class="sr-only"> results</span>
        </p>

        @if ($lastPage > 1)
            <nav class="tg-pagination-controls" aria-label="{{ $label }}">
                @if ($currentPage > 1)
                    <a class="tg-pagination-arrow" href="{{ $pageUrl($currentPage - 1) }}" rel="prev" aria-label="Previous page" title="Previous page">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14 7-5 5 5 5"/></svg>
                    </a>
                @else
                    <span class="tg-pagination-arrow" aria-disabled="true" aria-label="Previous page">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14 7-5 5 5 5"/></svg>
                    </span>
                @endif

                <div class="tg-pagination-picker">
                    <select class="tg-pagination-select" data-tg-select data-tg-select-trigger-class="tg-pagination-select" aria-label="Go to page" onchange="window.location.assign(this.value)" title="Choose a page">
                        @for ($page = 1; $page <= $lastPage; $page++)
                            <option value="{{ $pageUrl($page) }}" @selected($page === $currentPage)>{{ $page }} / {{ $lastPage }}</option>
                        @endfor
                    </select>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>
                </div>

                @if ($currentPage < $lastPage)
                    <a class="tg-pagination-arrow" href="{{ $pageUrl($currentPage + 1) }}" rel="next" aria-label="Next page" title="Next page">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m10 7 5 5-5 5"/></svg>
                    </a>
                @else
                    <span class="tg-pagination-arrow" aria-disabled="true" aria-label="Next page">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m10 7 5 5-5 5"/></svg>
                    </span>
                @endif
            </nav>
        @endif
    </div>
@endif
