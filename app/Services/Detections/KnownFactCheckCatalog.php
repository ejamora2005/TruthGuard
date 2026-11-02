<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Stable, reviewed answers for recurring claims. No model or search request is used. */
final class KnownFactCheckCatalog
{
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
            ],
            'answer_segments' => [[
                'text' => $explanation,
                'source_ids' => ['science-doomsday-1960', 'un-wpp-2024'],
            ]],
            'generated_wording' => [
                'version' => ResultWording::VERSION,
                'verdict' => 'MISLEADING',
                'explanation' => $explanation,
                'result_summary' => 'The date came from an outdated population-growth model and was misrepresented as a literal prediction that the world would end.',
                'recommended_action' => 'Do not share the image as a scientific apocalypse prediction; consult the original paper and current population projections.',
                'source_ids' => ['science-doomsday-1960', 'un-wpp-2024'],
            ],
            'diagnostics' => [
                'known_fact_check' => true,
                'canonical_cache_hit' => true,
                'api_usage' => ['total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0],
                'outcome_reason' => 'known_fact_check_match',
            ],
        ]);
    }
}
