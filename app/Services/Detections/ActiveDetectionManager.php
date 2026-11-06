<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Support\Facades\DB;

class ActiveDetectionManager
{
    public function stopForUser(int $detectionId, int $userId): ?Detection
    {
        return DB::transaction(function () use ($detectionId, $userId): ?Detection {
            $detection = Detection::whereKey($detectionId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $detection || ! in_array($detection->processing_status, ['queued', 'processing'], true)) {
                return $detection;
            }
            $signals = $detection->signals ?? [];
            unset($signals['analysis_progress']);
            $signals['analysis_cancellation'] = ['reason' => 'user_stopped', 'cancelled_at' => now()->toIso8601String()];
            if ($detection->fact_check_result !== null) {
                // Stopping a retry preserves the original report and its usage.
                $previousStatus = data_get($signals, 'analysis_retry.previous_status', $detection->fact_check_result['processing_status'] ?? 'completed');
                $signals['analysis_retry']['status'] = 'cancelled';
                $detection->forceFill(['processing_status' => in_array($previousStatus, ['completed', 'partial', 'failed', 'unsupported', 'needs_clarification'], true) ? $previousStatus : 'completed',
                    'signals' => $signals])->saveQuietly();
            } else {
                $request = SubmissionTask::resolve(['caption_text' => $detection->caption_text, 'notes' => $detection->notes, 'media_type' => $detection->media_type]);
                $result = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => $request['resolved_task'],
                    'verdict' => 'ANALYSIS_UNAVAILABLE', 'summary' => 'You stopped this fact check before it finished. No conclusion was reached.',
                    'request' => $request, 'sources' => []]);
                $detection->forceFill(['processing_status' => 'failed', 'signals' => $signals,
                    'fact_check_result' => $result, 'analysis_summary' => $result['summary'],
                    'explanation_summary' => $result['summary'], 'verdict' => 'review'])->saveQuietly();
            }

            return $detection;
        });
    }

    /**
     * Close abandoned jobs so an idle Fact Check page cannot look busy forever.
     */
    public function expireStaleForUser(int $userId): int
    {
        $minutes = max(6, (int) config('fact_check.pending_stale_minutes', 10));
        $cutoff = now()->subMinutes($minutes);

        return DB::transaction(function () use ($userId, $cutoff): int {
            $detections = Detection::query()
                ->where('user_id', $userId)
                ->whereIn('processing_status', ['queued', 'processing'])
                ->where(fn ($query) => $query->where('updated_at', '<', $cutoff)
                    ->orWhere(fn ($initial) => $initial->whereNull('fact_check_result')
                        ->where('created_at', '<', now()->subMinutes(15))))
                ->lockForUpdate()
                ->get();

            foreach ($detections as $detection) {
                $signals = is_array($detection->signals) ? $detection->signals : [];
                unset($signals['analysis_progress']);
                $signals['analysis_timeout'] = [
                    'reason' => 'stale_job',
                    'expired_at' => now()->toIso8601String(),
                ];

                // A timed-out retry must not destroy a previously saved report.
                if ($detection->fact_check_result !== null) {
                    if (isset($signals['analysis_retry'])) {
                        $signals['analysis_retry']['status'] = 'failed';
                    }
                    $detection->forceFill(['processing_status' => 'completed', 'signals' => $signals])->saveQuietly();

                    continue;
                }

                $result = ReportAnswer::complete([
                    'schema_version' => 4,
                    'primary_task' => 'claim',
                    'verdict' => 'ANALYSIS_UNAVAILABLE',
                    'claim' => (string) ($detection->caption_text ?? ''),
                    'summary' => 'This assessment stopped because the background analysis did not finish in time. Start a new fact check to try again.',
                    'limitations' => ['The background analysis became inactive before a conclusion was saved.'],
                    'request' => ['question' => (string) ($detection->caption_text ?? ''), 'tasks' => ['claim']],
                    'sources' => [],
                ]);

                $detection->forceFill([
                    'processing_status' => 'failed',
                    'signals' => $signals,
                    'fact_check_result' => $result,
                    'analysis_summary' => $result['summary'],
                    'explanation_summary' => $result['summary'],
                    'verdict' => 'review',
                ])->saveQuietly();
            }

            return $detections->count();
        });
    }

    /**
     * Stop every unfinished assessment owned by a user.
     */
    public function cancelForUser(int $userId, string $reason = 'signed_out'): int
    {
        return DB::transaction(function () use ($userId, $reason): int {
            $detections = Detection::query()
                ->where('user_id', $userId)
                ->whereIn('processing_status', ['queued', 'processing'])
                ->lockForUpdate()
                ->get();

            foreach ($detections as $detection) {
                $signals = is_array($detection->signals) ? $detection->signals : [];
                unset($signals['analysis_progress']);
                $signals['analysis_cancellation'] = [
                    'reason' => $reason,
                    'cancelled_at' => now()->toIso8601String(),
                ];

                $result = ReportAnswer::complete([
                    'schema_version' => 4,
                    'primary_task' => 'claim',
                    'verdict' => 'ANALYSIS_UNAVAILABLE',
                    'claim' => (string) ($detection->caption_text ?? ''),
                    'summary' => 'This assessment was stopped when the signed-in session ended. Start a new fact check if you still want to review this claim.',
                    'limitations' => ['The assessment did not finish and no conclusion was saved.'],
                    'request' => ['question' => (string) ($detection->caption_text ?? ''), 'tasks' => ['claim']],
                    'sources' => [],
                ]);

                $detection->forceFill([
                    'processing_status' => 'failed',
                    'signals' => $signals,
                    'fact_check_result' => $result,
                    'analysis_summary' => $result['summary'],
                    'explanation_summary' => $result['summary'],
                    'verdict' => 'review',
                ])->saveQuietly();
            }

            return $detections->count();
        });
    }
}
