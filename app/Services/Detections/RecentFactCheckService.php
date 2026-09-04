<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RecentFactCheckService
{
    public function __construct(
        private readonly DetectionRetentionService $retentionService,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forUser(int $userId, int $limit = 3): Collection
    {
        $limit = max(1, min(10, $limit));

        return $this->retentionService
            ->retainedQuery($userId)
            ->latest('analyzed_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Detection $detection): array => $this->present($detection));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Detection $detection): array
    {
        $presentation = DetectionVerdictPresenter::forDetection($detection);
        [$status, $statusKey] = $this->statusFor($detection, $presentation['category_key']);
        $analyzedAt = $detection->analyzed_at ?? $detection->created_at;
        $claim = trim((string) (
            $detection->caption_text
            ?: $detection->notes
            ?: $detection->analysis_summary
            ?: $detection->source_url
            ?: 'Fact check result'
        ));

        return [
            'id' => $detection->id,
            'claim' => Str::limit($claim, 120),
            'thumbnail' => $detection->media_type === 'image' ? $detection->media_url : null,
            'status' => $status,
            'status_key' => $statusKey,
            'input_type' => $this->inputTypeFor($detection),
            'sources_count' => collect($detection->verification_sources ?? [])
                ->filter(fn ($source): bool => is_array($source))
                ->count(),
            'timestamp' => $analyzedAt?->toIso8601String(),
            'relative_time' => $analyzedAt?->diffForHumans() ?? 'Pending',
            'url' => route('detections.result', $detection),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusFor(Detection $detection, string $categoryKey): array
    {
        $processingStatus = Str::lower((string) $detection->processing_status);

        if ($processingStatus !== '' && $processingStatus !== 'completed') {
            return Str::contains($processingStatus, ['fail', 'error', 'cancel'])
                ? ['Failed', 'failed']
                : ['Processing', 'processing'];
        }

        return match ($categoryKey) {
            'confirmed', 'low_risk' => ['Verified', 'verified'],
            'false_claim' => ['False', 'false'],
            'manipulated', 'ai_generated', 'miscaptioned', 'misleading', 'likely_misleading', 'partly_false' => ['Misleading', 'misleading'],
            'missing_context', 'no_confirmation', 'needs_review' => ['Needs Context', 'needs_context'],
            default => match ((string) $detection->verdict) {
                'real' => ['Verified', 'verified'],
                'fake' => ['False', 'false'],
                default => ['Unverified', 'unverified'],
            },
        };
    }

    private function inputTypeFor(Detection $detection): string
    {
        return match ((string) $detection->media_type) {
            'image' => 'Image',
            'video' => 'Video',
            'document' => 'Document',
            default => filled($detection->source_url) ? 'Link' : 'Text',
        };
    }
}
