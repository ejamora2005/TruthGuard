<div class="tg-history-actions">
    <a href="{{ $row['url'] }}" class="tg-history-open">Open result <span aria-hidden="true">↗</span></a>
    @unless ($row['archived'])
        <div class="tg-history-more" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.stop="open = false; $refs.more.focus()">
            <button type="button" class="tg-history-menu-trigger" x-ref="more" @click="open = !open"
                :aria-expanded="open" aria-label="More actions for {{ $row['report_reference'] }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
            </button>
            <div class="tg-history-menu" x-show="open" x-cloak>
                <form method="POST" action="{{ $row['archive_url'] }}">
                    @csrf
                    @method('PATCH')
                    @foreach ($historyFilters as $name => $value)
                        @if (filled($value))
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <button type="submit">Archive report</button>
                </form>
            </div>
        </div>
    @endunless
</div>
