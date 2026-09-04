<?php

namespace App\Http\Controllers;

use App\Models\FactCheckSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminFactCheckSourceController extends Controller
{
    public function index(): JsonResponse
    {
        $customSources = FactCheckSource::query()
            ->latest()
            ->get()
            ->map(fn (FactCheckSource $source): array => $this->serializeCustomSource($source))
            ->values()
            ->all();

        $builtInSources = collect((array) config('playwright.verification_sources', []))
            ->filter(fn ($source): bool => is_array($source) && filled($source['url_template'] ?? null))
            ->map(fn (array $source): array => $this->serializeBuiltInSource($source))
            ->values()
            ->all();

        $activeCustomCount = collect($customSources)->where('isEnabled', true)->count();
        $activeTotal = $activeCustomCount + count($builtInSources);

        return response()->json([
            'summaryCards' => [
                [
                    'label' => 'Active Sources',
                    'value' => number_format($activeTotal),
                    'note' => 'Built-in and admin-added sources available for verification',
                ],
                [
                    'label' => 'Custom Sources',
                    'value' => number_format(count($customSources)),
                    'note' => number_format($activeCustomCount).' enabled by admins',
                ],
                [
                    'label' => 'Built-In Sources',
                    'value' => number_format(count($builtInSources)),
                    'note' => 'Default sources from system configuration',
                ],
                [
                    'label' => 'Fact-Check Sources',
                    'value' => number_format(collect([...$builtInSources, ...$customSources])->where('category', 'fact_check')->count()),
                    'note' => 'Prioritized before general references',
                ],
            ],
            'categories' => [
                ['value' => 'fact_check', 'label' => 'Fact-check'],
                ['value' => 'news', 'label' => 'Newsroom'],
                ['value' => 'official', 'label' => 'Official'],
                ['value' => 'reference', 'label' => 'Reference'],
            ],
            'customSources' => $customSources,
            'builtInSources' => $builtInSources,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $key = $this->uniqueKey($validated['key'] ?? null, $validated['name']);

        FactCheckSource::query()->create([
            'created_by_user_id' => $request->user()?->id,
            'key' => $key,
            'name' => $validated['name'],
            'domain' => $this->normalizeDomain($validated['domain'] ?? null, $validated['url_template']),
            'category' => $validated['category'],
            'scraper_key' => 'article-search',
            'url_template' => $validated['url_template'],
            'ready_selectors' => $this->selectorList($validated['ready_selectors'] ?? null),
            'article_selectors' => $this->selectorList($validated['article_selectors'] ?? null),
            'exclude_selectors' => $this->selectorList($validated['exclude_selectors'] ?? null),
            'is_enabled' => (bool) ($validated['is_enabled'] ?? true),
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->index();
    }

    public function update(Request $request, FactCheckSource $factCheckSource): JsonResponse
    {
        $validated = $this->validatePayload($request, $factCheckSource);

        $factCheckSource->update([
            'key' => $this->uniqueKey($validated['key'] ?? $factCheckSource->key, $validated['name'], $factCheckSource),
            'name' => $validated['name'],
            'domain' => $this->normalizeDomain($validated['domain'] ?? null, $validated['url_template']),
            'category' => $validated['category'],
            'url_template' => $validated['url_template'],
            'ready_selectors' => $this->selectorList($validated['ready_selectors'] ?? null),
            'article_selectors' => $this->selectorList($validated['article_selectors'] ?? null),
            'exclude_selectors' => $this->selectorList($validated['exclude_selectors'] ?? null),
            'is_enabled' => (bool) ($validated['is_enabled'] ?? false),
            'notes' => $validated['notes'] ?? null,
        ]);

        return $this->index();
    }

    public function destroy(FactCheckSource $factCheckSource): JsonResponse
    {
        $factCheckSource->delete();

        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?FactCheckSource $source = null): array
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'key' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/i'],
            'category' => ['required', 'string', 'in:fact_check,news,official,reference'],
            'domain' => ['nullable', 'string', 'max:160'],
            'url_template' => ['required', 'string', 'max:2048'],
            'ready_selectors' => ['nullable', 'string', 'max:1000'],
            'article_selectors' => ['nullable', 'string', 'max:1000'],
            'exclude_selectors' => ['nullable', 'string', 'max:1000'],
            'is_enabled' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'key.regex' => 'The source key may only contain letters, numbers, dashes, and underscores.',
        ]);

        $validator->after(function ($validator) use ($request, $source): void {
            $urlTemplate = trim((string) $request->input('url_template'));

            if (! str_contains($urlTemplate, '{query}')) {
                $validator->errors()->add('url_template', 'The source URL must include {query}.');
            }

            $testUrl = str_replace('{query}', rawurlencode('sample claim'), $urlTemplate);

            if (! Str::startsWith($testUrl, ['http://', 'https://']) || filter_var($testUrl, FILTER_VALIDATE_URL) === false) {
                $validator->errors()->add('url_template', 'The source URL must be a valid HTTP or HTTPS URL.');
            }

            $key = trim((string) $request->input('key'));

            if ($key !== '') {
                $exists = FactCheckSource::query()
                    ->where('key', Str::lower($key))
                    ->when($source, fn ($query) => $query->where('id', '!=', $source->id))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('key', 'This source key is already in use.');
                }
            }
        });

        return $validator->validate();
    }

    private function uniqueKey(?string $requestedKey, string $name, ?FactCheckSource $source = null): string
    {
        $base = Str::slug(trim((string) ($requestedKey ?: $name))) ?: 'source';
        $key = $base;
        $suffix = 2;

        while (
            FactCheckSource::query()
                ->where('key', $key)
                ->when($source, fn ($query) => $query->where('id', '!=', $source->id))
                ->exists()
        ) {
            $key = "{$base}-{$suffix}";
            $suffix++;
        }

        return $key;
    }

    /**
     * @return array<int, string>
     */
    private function selectorList(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $selector): string => trim($selector))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDomain(?string $domain, string $urlTemplate): ?string
    {
        $domain = Str::of((string) $domain)
            ->trim()
            ->lower()
            ->replaceStart('https://', '')
            ->replaceStart('http://', '')
            ->replaceStart('www.', '')
            ->before('/')
            ->toString();

        return $domain !== '' ? $domain : FactCheckSource::domainFromTemplate($urlTemplate);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCustomSource(FactCheckSource $source): array
    {
        return [
            'id' => $source->id,
            'sourceType' => 'custom',
            'key' => $source->key,
            'name' => $source->name,
            'domain' => $source->displayDomain(),
            'category' => $source->category,
            'categoryLabel' => $source->categoryLabel(),
            'urlTemplate' => $source->url_template,
            'isEnabled' => (bool) $source->is_enabled,
            'readySelectorsText' => implode(', ', $source->ready_selectors ?? []),
            'articleSelectorsText' => implode(', ', $source->article_selectors ?? []),
            'excludeSelectorsText' => implode(', ', $source->exclude_selectors ?? []),
            'notes' => $source->notes,
            'updatedLabel' => $source->updated_at?->format('M d, Y h:i A') ?? 'Not updated',
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function serializeBuiltInSource(array $source): array
    {
        $category = (string) ($source['category'] ?? 'reference');

        return [
            'id' => null,
            'sourceType' => 'built_in',
            'key' => (string) ($source['key'] ?? Str::slug((string) ($source['name'] ?? 'source'))),
            'name' => (string) ($source['name'] ?? 'Configured source'),
            'domain' => FactCheckSource::domainFromTemplate((string) ($source['url_template'] ?? '')),
            'category' => $category,
            'categoryLabel' => match ($category) {
                'news' => 'Newsroom',
                'fact_check' => 'Fact-check',
                'official' => 'Official',
                default => 'Reference',
            },
            'urlTemplate' => (string) ($source['url_template'] ?? ''),
            'isEnabled' => true,
            'readySelectorsText' => implode(', ', (array) ($source['ready_selectors'] ?? [])),
            'articleSelectorsText' => implode(', ', (array) data_get($source, 'extra_selectors.articleContainers', [])),
            'excludeSelectorsText' => implode(', ', (array) data_get($source, 'extra_selectors.excludeSelectors', [])),
            'notes' => 'Built-in system source',
            'updatedLabel' => 'System',
        ];
    }
}
