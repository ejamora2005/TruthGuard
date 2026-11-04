<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Stable, reviewed answers for recurring claims. No model or search request is used. */
final class KnownFactCheckCatalog
{
    public const VERSION = 'november-2026-sources-v2';

    public static function match(string $text): ?array
    {
        $normalized = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();

        if (! str_contains($normalized, 'november 13 2026')
            || (! str_contains($normalized, 'world will end') && ! str_contains($normalized, 'end of the world'))
            || (! str_contains($normalized, 'mathematical prediction') && ! str_contains($normalized, 'scientist'))) {
            return null;
        }

        $explanation = 'There is no scientific evidence that the world will end on November 13, 2026. A 1960 Science paper used that date for a mathematical population model, not a literal apocalypse prediction. The calculation assumed population growth would continue unchanged, but population growth has slowed. Current United Nations projections instead place the global population peak around the mid-2080s.';

        return ReportAnswer::complete([
            'schema_version' => 4,
            'primary_task' => 'claim',
            'verdict' => 'MISLEADING',
            'claim' => 'The world will end on Friday, November 13, 2026, according to a scientist’s mathematical prediction.',
            'summary' => $explanation,
            'key_findings' => [
                'The 1960 paper modeled population growth rather than predicting the physical end of the world.',
                'Its date depended on an unchanged growth-rate assumption that did not continue.',
                'Current United Nations projections place the population peak around the mid-2080s.',
            ],
            'limitations' => [],
            'sources' => [
                [
                    'id' => 'science-doomsday-1960',
                    'name' => 'Science',
                    'title' => 'Doomsday: Friday, 13 November, A.D. 2026',
                    'url' => 'https://www.science.org/doi/10.1126/science.132.3436.1291',
                    'type' => 'primary_source',
                    'used_for' => 'Documents the mathematical population model from which the date was taken.',
                ],
                [
                    'id' => 'un-wpp-2024',
                    'name' => 'United Nations',
                    'title' => 'World Population Prospects 2024',
                    'url' => 'https://www.un.org/development/desa/pd/content/World-Population-Prospects-2024',
                    'type' => 'official_government',
                    'used_for' => 'Provides the current projection that global population will peak in the mid-2080s.',
                ],
                [
                    'id' => 'chequeado-doomsday-review',
                    'name' => 'Chequeado',
                    'title' => 'No, cientificos de la Universidad de Harvard no afirmaron que el mundo se terminara el 13 de noviembre de 2026',
                    'url' => 'https://chequeado.com/verificacionfb/no-cientificos-de-la-universidad-de-harvard-no-afirmaron-que-el-mundo-se-terminara-el-13-de-noviembre-de-2026/',
                    'type' => 'fact_check',
                    'used_for' => 'Reviews a related viral claim and explains that the original population study did not predict the literal end of the world. Article in Spanish.',
                ],
                [
                    'id' => 'climate-fact-checks-doomsday',
                    'name' => 'Climate Fact Checks',
                    'title' => 'Climate Anxiety Is Real. These Viral Doomsday Claims Aren’t',
                    'url' => 'https://climatefactchecks.org/climate-anxiety-is-real-these-viral-doomsday-claims-arent/',
                    'type' => 'fact_check',
                    'used_for' => 'Explains how November 13, 2026 posts remove the context of the 1960 mathematical population model.',
                ],
                [
                    'id' => 'un-wpp-2024-summary',
                    'name' => 'United Nations DESA',
                    'title' => 'World Population Prospects 2024: Summary of Results',
                    'url' => 'https://desapublications.un.org/publications/world-population-prospects-2024-summary-results',
                    'type' => 'primary_source',
                    'used_for' => 'Provides the summary report accompanying the UN projections; this is part of the same UN study, not a separate independent estimate.',
                ],
            ],
            'answer_segments' => [[
                'text' => $explanation,
                'source_ids' => ['science-doomsday-1960', 'un-wpp-2024', 'chequeado-doomsday-review', 'climate-fact-checks-doomsday', 'un-wpp-2024-summary'],
            ]],
            'generated_wording' => [
                'version' => ResultWording::VERSION,
                'verdict' => 'MISLEADING',
                'explanation' => $explanation,
                'result_summary' => 'The date came from an outdated population-growth model and was misrepresented as a literal prediction that the world would end.',
                'recommended_action' => 'Do not share the image as a scientific apocalypse prediction; consult the original paper and current population projections.',
                'source_ids' => ['science-doomsday-1960', 'un-wpp-2024', 'chequeado-doomsday-review', 'climate-fact-checks-doomsday', 'un-wpp-2024-summary'],
            ],
            'diagnostics' => [
                'known_fact_check' => true,
                'known_fact_check_version' => self::VERSION,
                'canonical_cache_hit' => true,
                'api_usage' => ['total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0],
                'outcome_reason' => 'known_fact_check_match',
            ],
        ]);
    }
}
