<?php

namespace App\Services\Detections;

class FactCheckResultSchema
{
    public const VERDICTS = ['TRUE', 'FALSE', 'MISLEADING', 'PARTLY_TRUE', 'UNVERIFIABLE', 'OUTDATED'];

    public static function definition(): array
    {
        $string = ['type' => 'string'];
        $nullable = ['type' => ['string', 'null']];
        $strings = ['type' => 'array', 'items' => $string];
        $boolean = ['type' => 'boolean'];

        return self::object([
            'claim' => $string,
            'normalized_claim' => $string,
            'verdict' => ['type' => 'string', 'enum' => self::VERDICTS],
            'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'summary' => $string,
            'key_findings' => $strings,
            'sources' => ['type' => 'array', 'items' => self::object([
                'name' => $string, 'title' => $string, 'url' => $string,
                'type' => ['type' => 'string', 'enum' => FactCheckEvidencePolicy::SOURCE_ORDER],
                'published_at' => $nullable, 'used_for' => $string,
            ])],
            'limitations' => $strings,
            'evidence_cutoff' => $string,
            'source_snapshot_id' => $string,
            'ui' => self::object([
                'verdict_color' => ['type' => 'string', 'enum' => ['green', 'red', 'orange', 'amber', 'gray', 'blue']],
                'show_evidence_warning' => $boolean, 'evidence_warning' => $nullable,
                'show_missing_context' => $boolean, 'missing_context' => $nullable,
                'show_source_conflict' => $boolean, 'source_conflict_message' => $nullable,
                'show_official_verification_notice' => $boolean, 'official_verification_notice' => $nullable,
                'highlight_evidence_cutoff' => $boolean,
                'source_display_order' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => FactCheckEvidencePolicy::SOURCE_ORDER]],
            ]),
        ]);
    }

    private static function object(array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => array_keys($properties)];
    }
}
