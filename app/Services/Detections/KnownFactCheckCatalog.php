<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Stable, reviewed answers for recurring claims. No model or search request is used. */
final class KnownFactCheckCatalog
{
    public const VERSION = 'november-2026-context-v4';

    public static function match(string $text): ?array
    {
        $normalized = Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();

        if (! str_contains($normalized, 'november 13 2026')
            || (! str_contains($normalized, 'world will end') && ! str_contains($normalized, 'end of the world'))
            || (! str_contains($normalized, 'mathematical prediction') && ! str_contains($normalized, 'scientist'))) {
            return null;
        }

        $explanation = 'There is no scientific evidence that the world will end on November 13, 2026. The date comes from a 1960 population-growth model, not a prediction of a catastrophe that would destroy Earth. In their Science paper, Heinz von Foerster, Patricia Mora and Lawrence Amiot extended a curve fitted to historical population growth. If that pattern continued, the model would approach an impossible infinite population near that date. This mathematical limit means the growth pattern cannot continue indefinitely; it does not establish an apocalypse date. Population trends have since changed, including a shift toward smaller families. The United Nations World Population Prospects 2024 projects a population peak of about 10.3 billion in the mid-2080s, with uncertainty around future trends. The image takes the original calculation out of context and presents it as an end-of-the-world forecast.';

        return ReportAnswer::complete([
            'schema_version' => 4,
            'primary_task' => 'claim',
            'verdict' => 'MISLEADING',
            'claim' => 'The world will end on Friday, November 13, 2026, according to a scientist’s mathematical prediction.',
            'summary' => $explanation,
            'key_findings' => [
                'The 1960 paper modeled population growth rather than predicting the physical end of the world.',
                'The date was a mathematical limit obtained by extending a historical population-growth pattern, not a demonstrated catastrophe.',
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
                    'used_for' => 'Provides the 2024 projection that global population will peak in the mid-2080s.',
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
