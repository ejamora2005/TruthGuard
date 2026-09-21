@component('layouts.landing', ['title' => 'Claim Reviews | TruthGuard'])
    <div class="tg-public-reviews-page">
        @include('layouts.partials.public-header')
        <main class="tg-public-container">
            <div class="tg-public-heading">
                <p class="tg-public-eyebrow">The public record</p>
                <h1>Claim Reviews</h1>
                <p>Browse recent publisher reviews. Sign in to open a review and explore its evidence.</p>
            </div>
            <form method="GET" action="{{ route('reviews.index') }}" class="tg-public-filters">
                <label><span>Search reviews</span><input type="search" name="search" value="{{ $search }}" placeholder="Search a claim or publisher" maxlength="150"></label>
                <label><span>Publisher</span><select name="source" data-tg-select aria-label="Publisher"><option value="">All publishers</option>@foreach ($sources as $publisher)<option value="{{ $publisher }}" @selected($source === $publisher)>{{ $publisher }}</option>@endforeach</select></label>
                <button type="submit" class="tg-public-submit">Search</button>
                @if ($search !== '' || $source !== '')<a href="{{ route('reviews.index') }}" class="tg-public-link">Clear filters</a>@endif
            </form>
            <div class="py-6">
                @if (empty($factCheckFeed['items']))
                    <div class="tg-public-empty"><h2>No reviews found</h2><p>{{ $search !== '' || $source !== '' ? 'Try another search or clear your filters.' : 'New publisher reviews will appear here when available.' }}</p></div>
                @else
                    @include('user.dashboard.partials.news-watch', [
                        'reviewListRoute' => 'reviews.index',
                        'reviewPageSize' => 12,
                        'reviewPageParameter' => 'page',
                        'publicReviewList' => true,
                    ])
                @endif
            </div>
        </main>
        @include('layouts.partials.public-footer')
    </div>
@endcomponent
