<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Automation\ScrapeOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function store(Request $request, ScrapeOrchestrator $scrapeOrchestrator): JsonResponse
    {
        $validated = $request->validate([
            'target_url' => ['nullable', 'url', 'max:2048'],
            'source_key' => ['nullable', 'string', 'max:100'],
            'post_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'take_screenshot' => ['nullable', 'boolean'],
            'analyze_posts' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'ready_selectors' => ['nullable', 'array', 'max:10'],
            'ready_selectors.*' => ['string', 'max:255'],
        ]);

        $actor = $request->user();

        if (isset($validated['user_id'])) {
            $actor = User::query()->find($validated['user_id']);
        }

        $analyzePosts = array_key_exists('analyze_posts', $validated)
            ? (bool) $validated['analyze_posts']
            : (bool) config('playwright.analyze_posts');

        if ($analyzePosts && ! $actor) {
            return response()->json([
                'message' => 'Provide an authenticated user or user_id before analyze_posts can be enabled.',
            ], 422);
        }

        $scrapeRun = $scrapeOrchestrator->run([
            'target_url' => $validated['target_url'] ?? null,
            'source_key' => $validated['source_key'] ?? config('playwright.default_source'),
            'post_limit' => $validated['post_limit'] ?? config('playwright.post_limit'),
            'take_screenshot' => $validated['take_screenshot'] ?? config('playwright.take_screenshot'),
            'analyze_posts' => $analyzePosts,
            'ready_selectors' => $validated['ready_selectors'] ?? [],
            'trigger_source' => 'api',
        ], $actor);

        return response()->json([
            'message' => 'Playwright scraping completed successfully.',
            'run' => [
                'id' => $scrapeRun->id,
                'status' => $scrapeRun->status,
                'source_key' => $scrapeRun->source_key,
                'target_url' => $scrapeRun->target_url,
                'screenshot_path' => $scrapeRun->screenshot_path,
                'summary' => $scrapeRun->summary,
                'started_at' => $scrapeRun->started_at?->toIso8601String(),
                'finished_at' => $scrapeRun->finished_at?->toIso8601String(),
            ],
            'posts' => $scrapeRun->posts->map(fn ($post) => [
                'id' => $post->id,
                'post_url' => $post->post_url,
                'display_name' => $post->display_name,
                'username' => $post->username,
                'caption_text' => $post->caption_text,
                'posted_at' => $post->posted_at?->toIso8601String(),
                'media_urls' => $post->media_urls,
                'source_links' => $post->source_links,
                'detection_id' => $post->detection_id,
            ])->values(),
        ], 201);
    }
}
