<?php

namespace App\Services\Detections;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Known file origin is evidence about that exact file, never a detector score. */
class ReviewedMediaLookup
{
    public static function analysis(?string $checksum, string $mediaType, array $request, ?string $sourceUrl = null): ?array
    {
        if ($mediaType !== 'image' || $sourceUrl !== null || ! is_string($checksum)
            || ($request['resolved_task'] ?? null) !== 'media' || ($request['tasks'] ?? []) !== ['media']
            || ! Schema::hasTable('reviewed_media')) {
            return null;
        }
        $record = DB::table('reviewed_media')->where('sha256', $checksum)->where('active', true)->first();
        if (! $record || ! hash_equals($record->sha256, $checksum)) return null;

        $provenance = json_decode($record->provenance, true, 512, JSON_THROW_ON_ERROR);
        $ai = $record->origin === 'ai_generated';
        $sources = [];
        if ($url = ReportAnswer::safeUrl($provenance['source_url'] ?? null)) {
            $sources[] = ['id' => 'reviewed-media-'.$record->id, 'name' => 'Wikimedia Commons',
                'title' => $record->filename, 'url' => $url, 'source_type' => 'institution',
                'used_for' => $record->summary];
        }
        $result = ReportAnswer::complete([
            'schema_version' => 4, 'primary_task' => 'media',
            'verdict' => $ai ? 'AI_GENERATED' : 'LIKELY_NOT_AI_GENERATED',
            'summary' => $record->summary, 'sources' => $sources,
            'answer_segments' => [['text' => $record->summary, 'source_ids' => array_column($sources, 'id')]],
            'request' => $request,
            'score' => AssessmentScore::unavailable('Origin is established by an exact file match to a documented record; no detector probability was calculated.'),
            'limitations' => ['This origin finding applies only to the exact uploaded file. It does not verify any accompanying news claim.',
                'The finding uses recorded provenance, not a new detector prediction.'],
            'diagnostics' => ['match_method' => 'exact_sha256_reviewed_media', 'reviewed_media_id' => $record->id,
                'reviewed_media_slug' => $record->slug, 'origin' => $record->origin, 'provenance' => $provenance,
                'api_usage' => ['total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0]],
        ]);

        return ['fake_score' => 0, 'processing_status' => 'completed',
            'preprocessing_summary' => 'Matched the original file checksum to a documented media-origin record.',
            'analysis_summary' => $record->summary, 'verification_summary' => $record->summary,
            'explanation_summary' => $record->summary,
            'recommendation' => 'Check the image source before sharing; image origin does not establish the truth of a caption.',
            'signals' => ['reviewed_media' => ['id' => $record->id, 'method' => 'exact_sha256', 'origin' => $record->origin]],
            'verification_sources' => $sources, 'fact_check_result' => $result, 'verdict' => $ai ? 'fake' : 'real'];
    }
}
