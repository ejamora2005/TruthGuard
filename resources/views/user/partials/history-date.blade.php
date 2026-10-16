<time class="tg-history-date" @if ($row['date_iso']) datetime="{{ $row['date_iso'] }}" @endif title="{{ $row['date'] }} · {{ $row['time'] }} (UTC+8)">
    <span>{{ $row['date_label'] }}</span>
    <span>{{ $row['time'] }}</span>
</time>
