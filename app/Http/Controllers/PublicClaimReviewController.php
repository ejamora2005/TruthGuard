<?php

namespace App\Http\Controllers;

use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicClaimReviewController extends Controller
{
    public function __invoke(Request $request, GoogleFactCheckFeedService $feedService): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'source' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $feed = $feedService->latest(500, 7);
        $items = collect($feed['items'] ?? []);
        $sources = $items->pluck('publisher')->filter()->unique()->sort()->values();
        $search = trim($filters['search'] ?? '');
        $source = $filters['source'] ?? '';
        $items = $items->filter(fn (array $item) =>
            ($source === '' || ($item['publisher'] ?? '') === $source)
            && ($search === '' || Str::contains(Str::lower(implode(' ', [
                $item['headline'] ?? '', $item['claim'] ?? '', $item['publisher'] ?? '',
            ])), Str::lower($search)))
        )->values();
        $factCheckFeed = array_replace($feed, ['items' => $items->all()]);

        return view('public.reviews', compact('factCheckFeed', 'sources', 'search', 'source'));
    }
}
