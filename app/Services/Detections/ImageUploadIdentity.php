<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Http\UploadedFile;

/** Stable upload identity: independent of models, prompt-guide versions and deployment dates. */
class ImageUploadIdentity
{
    public static function forUpload(array $input, ?UploadedFile $file): ?array
    {
        if (! $file || ! str_starts_with((string) $file->getMimeType(), 'image/') || ! $file->getRealPath()) {
            return null;
        }

        return ['checksum' => hash_file('sha256', $file->getRealPath())] + self::scope($input);
    }

    public static function scope(array $input): array
    {
        $request = SubmissionTask::resolve($input + ['media_type' => 'image']);
        $caption = self::withoutGuide($input['caption_text'] ?? null);
        $notes = self::withoutGuide($input['notes'] ?? null);
        $automatic = ($input['analysis_task'] ?? 'auto') === 'auto'
            && blank($input['caption_text'] ?? null) && blank($input['notes'] ?? null);
        $context = [$automatic ? ['auto-content'] : [$request['resolved_task'], $request['tasks']],
            $caption, $notes, trim((string) ($input['source_url'] ?? '')), app()->getLocale(),
            filled($input['source_platform'] ?? null) ? $input['source_platform'] : 'auto',
            ! empty($input['weather_consent']) ? [$input['weather_lat'] ?? null, $input['weather_lon'] ?? null,
                $input['weather_label'] ?? null] : null];

        return ['scope_key' => hash('sha256', json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            // Cross-account reuse requires identical supplied text, including any private notes.
            'private_context_key' => hash('sha256', json_encode([
                trim((string) ($input['caption_text'] ?? '')), trim((string) ($input['notes'] ?? '')),
                trim((string) ($input['source_url'] ?? '')),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'selected_mode' => $input['analysis_task'] ?? 'auto', 'locale' => app()->getLocale(),
            'resolved_task' => $automatic ? null : $request['resolved_task']];
    }

    public static function forDetection(Detection $detection): array
    {
        $stored = data_get($detection->signals, 'upload_reuse');
        if (is_array($stored) && isset($stored['scope_key'], $stored['private_context_key'])) {
            return $stored;
        }

        return self::scope(['caption_text' => $detection->caption_text, 'notes' => $detection->notes,
            'source_url' => $detection->source_url,
            'analysis_task' => data_get($detection->fact_check_result, 'request.selected_mode', 'auto')]);
    }

    private static function withoutGuide(?string $text): string
    {
        $text = trim((string) $text);
        foreach (SubmissionTask::presetInstructions() as $guide) {
            $text = preg_replace('/^'.preg_quote($guide, '/').'(?:[.!?:]+\s*|\s*\R\s*|$)/iu', '', $text);
        }

        // Preserve every actual question, number and qualifier; never fuzzy-match claims.
        return preg_replace('/\s+/u', ' ', trim($text));
    }
}
