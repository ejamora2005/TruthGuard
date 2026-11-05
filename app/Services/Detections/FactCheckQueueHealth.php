<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FactCheckQueueHealth
{
    public function summary(): array
    {
        $staleMinutes = max(1, (int) config('fact_check.pending_stale_minutes', 10));
        $pending = 0;
        $oldestSeconds = null;

        if (Schema::hasTable('jobs')) {
            $query = DB::table('jobs')->where('queue', 'evidence');
            $pending = (clone $query)->count();
            $createdAt = (clone $query)->min('created_at');
            $oldestSeconds = $createdAt ? max(0, now()->timestamp - (int) $createdAt) : null;
        }

        $stalledAssessments = Schema::hasTable('detections')
            ? Detection::query()->whereIn('processing_status', ['queued', 'processing'])
                ->where('updated_at', '<', now()->subMinutes($staleMinutes))->count()
            : 0;
        $staleQueue = $oldestSeconds !== null && $oldestSeconds > ($staleMinutes * 60);

        return [
            'status' => ($staleQueue || $stalledAssessments > 0) ? 'attention_required' : 'healthy',
            'pending_evidence_jobs' => $pending,
            'oldest_evidence_job_seconds' => $oldestSeconds,
            'stalled_assessments' => $stalledAssessments,
            'stale_after_minutes' => $staleMinutes,
        ];
    }
}
