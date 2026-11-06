<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Describe validated findings without another model call or a new factual inference. */
final class EvidenceSummary
{
    public static function segments(array $result): array
    {
        $findings = [];
        $unresolved = [];
        $sources = collect($result['sources'] ?? [])->keyBy('id');
        foreach ($result['claims'] ?? [] as $claim) {
            if (($claim['kind'] ?? '') !== 'factual') {
                continue;
            }
            $literal = Str::squish((string) ($claim['text'] ?? ''));
            $text = Str::limit($literal, 240, '…');
            if ($text === '') {
                continue;
            }
            $outcome = $claim['outcome'] ?? 'insufficient';
            if (in_array($outcome, ['supported', 'refuted'], true)) {
                foreach ($claim['relationships'] ?? [] as $row) {
                    $source = $sources->get($row['source_id'] ?? '');
                    if (! $source || ($row['superseded'] ?? false)
                        || ! in_array($row['stance'] ?? '', ['supporting', 'contradicting'], true)
                        || blank($row['explanation'] ?? null)) {
                        continue;
                    }
                    $explanation = implode(' ', array_slice(SubmittedText::sentences($row['explanation']), 0, 2));
                    $explanation = ReportAnswer::attributeSource($explanation, $source['name'] ?? '');
                    if (ResultContract::publicDetail($explanation) === null) {
                        // The relationship is validated independently of its
                        // prose. Omit internal narration without losing the
                        // concrete assertion or its authenticated citation.
                        $explanation = 'The reviewed record '.($outcome === 'supported' ? 'supports' : 'contradicts').' the claim “'.$literal.'”.';
                    }
                    $findings[] = ['text' => $explanation,
                        'source_ids' => [$source['id']]];
                    break;
                }
            } elseif ($outcome === 'conflicting') {
                $ids = array_values(array_intersect(array_column($claim['relationships'] ?? [], 'source_id'), $sources->keys()->all()));
                $unresolved[] = ['text' => 'The reviewed sources disagree about “'.$text.'”; no explicit correction resolves that conflict.', 'source_ids' => $ids];
            } else {
                $reason = $outcome === 'needs_context' ? trim((string) ($claim['context_reason'] ?? '')) : '';
                $unresolved[] = ['text' => 'The claim “'.$text.'” remains unverified.'
                    .($reason !== '' ? ' '.$reason : ' The reviewed passages do not directly establish or refute this assertion.'), 'source_ids' => []];
            }
        }

        return array_slice(array_merge($findings, $unresolved), 0, 4);
    }
}
