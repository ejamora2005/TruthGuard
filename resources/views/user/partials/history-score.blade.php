<div class="tg-history-score" x-data="{ show: false }" @mouseenter="show = true" @mouseleave="show = false" @click.outside="show = false" x-id="['score-note']">
    <button type="button" class="tg-history-score-label" :aria-describedby="$id('score-note')"
        @focus="show = true" @blur="show = false" @click="show = true" @keydown.escape.stop="show = false; $el.blur()">
        <span>{{ $row['confidence_display'] }}</span>
        @unless ($row['has_score'])
            <span aria-hidden="true" class="tg-history-info">i</span>
        @endunless
    </button>
    @if ($row['score_note'])
        <p class="tg-history-score-note">{{ $row['score_note'] }}</p>
    @endif
    <span class="tg-history-tooltip" role="tooltip" :id="$id('score-note')" x-show="show" x-cloak>{{ $row['score_detail'] }}</span>
</div>
