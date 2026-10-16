<div class="tg-result-prose" x-data="{
    expanded: false,
    overflowing: false,
    observer: null,
    init() {
        this.observer = new ResizeObserver(() => this.measure());
        this.observer.observe(this.$refs.prose);
        this.$nextTick(() => this.measure());
        document.fonts?.ready.then(() => this.measure());
    },
    measure() {
        const prose = this.$refs.prose;
        if (prose) this.overflowing = this.expanded || prose.scrollHeight > prose.clientHeight + 1;
    },
    destroy() { this.observer?.disconnect(); }
}">
    <p id="{{ $proseId }}" x-ref="prose" class="{{ $proseClass }}" :class="{ 'tg-result-prose-collapsed': !expanded }">{{ $prose }}</p>
    <button type="button" class="tg-result-text-button" x-show="overflowing" x-cloak :aria-expanded="expanded" aria-controls="{{ $proseId }}"
        @click="expanded = !expanded; $nextTick(() => measure())">
        <span x-show="!expanded">{{ $expandLabel }}</span><span x-show="expanded" x-cloak>Show less</span>
        <span aria-hidden="true" x-text="expanded ? '−' : '+'"></span>
    </button>
</div>
