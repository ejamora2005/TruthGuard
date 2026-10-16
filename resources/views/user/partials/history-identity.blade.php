<div class="tg-history-identity">
    @if ($row['thumbnail'])
        <img class="tg-history-thumb" src="{{ $row['thumbnail'] }}" alt="" loading="lazy">
    @else
        <span class="tg-history-thumb tg-history-thumb-placeholder" aria-hidden="true">{{ mb_substr($row['media_label'], 0, 1) }}</span>
    @endif
    <div class="tg-history-copy">
        <a class="tg-history-title" href="{{ $row['url'] }}" title="{{ $row['case'] }}">{{ $row['case'] }}</a>
        <p class="tg-history-meta">{{ $row['media_label'] }} <span aria-hidden="true">·</span> {{ $row['report_reference'] }} @if ($row['archived']) <span class="tg-history-archived">Archived</span> @endif</p>
    </div>
</div>
