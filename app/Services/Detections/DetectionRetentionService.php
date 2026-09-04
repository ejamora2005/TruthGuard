<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Database\Eloquent\Builder;

class DetectionRetentionService
{
    public const DAYS_TO_KEEP = 7;

    public const PAGE_SIZE = 15;

    public function retainedQuery(?int $userId = null): Builder
    {
        $query = Detection::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $this->applyRetentionWindow($query);
    }

    public function archivedQuery(?int $userId = null): Builder
    {
        $query = Detection::query()
            ->whereNotNull('archived_at');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query;
    }

    public function applyRetentionWindow(Builder $query): Builder
    {
        $cutoff = now()->subDays(self::DAYS_TO_KEEP);

        return $query
            ->whereNull('archived_at')
            ->where(function (Builder $query) use ($cutoff) {
                $query
                    ->where('analyzed_at', '>=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff) {
                        $query
                            ->whereNull('analyzed_at')
                            ->where('created_at', '>=', $cutoff);
                    });
            });
    }

    public function archiveExpired(?int $userId = null): int
    {
        $archivedAt = now();
        $query = Detection::query()
            ->whereNull('archived_at');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $this->applyExpiredWindow($query)->update([
            'archived_at' => $archivedAt,
            'archive_reason' => 'retention-window',
            'updated_at' => $archivedAt,
        ]);
    }

    public function pruneExpired(?int $userId = null): int
    {
        return $this->archiveExpired($userId);
    }

    private function applyExpiredWindow(Builder $query): Builder
    {
        $cutoff = now()->subDays(self::DAYS_TO_KEEP);

        return $query->where(function (Builder $query) use ($cutoff) {
            $query
                ->where('analyzed_at', '<', $cutoff)
                ->orWhere(function (Builder $query) use ($cutoff) {
                    $query
                        ->whereNull('analyzed_at')
                        ->where('created_at', '<', $cutoff);
            });
        });
    }
}
