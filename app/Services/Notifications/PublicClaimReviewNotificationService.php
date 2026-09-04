<?php

namespace App\Services\Notifications;

use App\Models\PublicClaimReviewAnnouncement;
use App\Models\PublicClaimReviewEmailDelivery;
use App\Models\User;
use App\Notifications\PublicClaimReviewPublished;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PublicClaimReviewNotificationService
{
    public function __construct(
        private readonly GoogleFactCheckFeedService $feedService,
    ) {
    }

    /**
     * @return array{enabled: bool, checked: int, seeded: int, announced: int, notified: int, existing_notified: int}
     */
    public function announceLatest(int $limit = 5, bool $dryRun = false, ?bool $notifyExisting = null): array
    {
        $limit = max(1, min($limit, 10));
        $notifyExisting ??= (bool) config('services.google_fact_check.feed_news_notifications_notify_existing', false);

        if (! (bool) config('services.google_fact_check.feed_news_notifications_enabled', true)
            || ! Schema::hasTable('public_claim_review_announcements')) {
            return [
                'enabled' => false,
                'checked' => 0,
                'seeded' => 0,
                'announced' => 0,
                'notified' => 0,
                'existing_notified' => 0,
            ];
        }

        $feed = $this->feedService->latest(max(15, $limit));
        $items = collect($feed['items'] ?? [])
            ->filter(fn (array $item): bool => filled($item['id'] ?? null) && filled($item['headline'] ?? null))
            ->sortBy(fn (array $item): int => (int) ($item['timestamp'] ?? 0))
            ->values();

        $seeded = 0;
        $announced = 0;
        $notified = 0;
        $existingNotified = 0;
        $shouldSeedBaseline = (bool) config('services.google_fact_check.feed_news_notifications_seed_baseline', true)
            && PublicClaimReviewAnnouncement::query()->doesntExist();

        if ($shouldSeedBaseline) {
            foreach ($items as $item) {
                $feedItemId = $this->feedItemId($item);

                if ($feedItemId === null) {
                    continue;
                }

                if (! $dryRun) {
                    PublicClaimReviewAnnouncement::query()->firstOrCreate(
                        ['feed_item_id' => $feedItemId],
                        $this->attributesFor($feedItemId, $item),
                    );
                }

                $seeded++;
            }

            return [
                'enabled' => true,
                'checked' => $items->count(),
                'seeded' => $seeded,
                'announced' => 0,
                'notified' => 0,
                'existing_notified' => 0,
            ];
        }

        foreach ($items as $item) {
            if ($announced >= $limit) {
                break;
            }

            $feedItemId = $this->feedItemId($item);

            if ($feedItemId === null) {
                continue;
            }

            if ($dryRun) {
                if (PublicClaimReviewAnnouncement::query()->where('feed_item_id', $feedItemId)->exists()) {
                    continue;
                }

                $announced++;

                continue;
            }

            $announcement = PublicClaimReviewAnnouncement::query()->firstOrCreate(
                ['feed_item_id' => $feedItemId],
                $this->attributesFor($feedItemId, $item),
            );

            if (! $announcement->wasRecentlyCreated) {
                if ($notifyExisting && ! $dryRun) {
                    $existingNotified += $this->notifyActiveUsers($announcement);
                }

                continue;
            }

            $announced++;
            $notified += $this->notifyActiveUsers($announcement);
        }

        return [
            'enabled' => true,
            'checked' => $items->count(),
            'seeded' => $seeded,
            'announced' => $announced,
            'notified' => $notified,
            'existing_notified' => $existingNotified,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function attributesFor(string $feedItemId, array $item): array
    {
        return [
            'feed_item_id' => $feedItemId,
            'publisher' => $this->limited($item['publisher'] ?? null, 120),
            'headline' => $this->limited($item['headline'] ?? null, 180) ?: 'New public claim review',
            'claim' => $this->limited($item['claim'] ?? null, 1000),
            'rating' => $this->limited($item['rating'] ?? null, 80),
            'source_domain' => $this->limited($item['source_domain'] ?? ($item['host'] ?? null), 120),
            'url' => $this->limited($item['url'] ?? null, 2048),
            'image_url' => $this->limited($item['image_url'] ?? null, 2048),
            'published_at' => $this->publishedAt($item),
            'announced_at' => now(),
        ];
    }

    private function notifyActiveUsers(PublicClaimReviewAnnouncement $announcement): int
    {
        $notified = 0;
        $chunkSize = max(25, min((int) config('services.google_fact_check.feed_news_notification_chunk_size', 100), 500));

        if (! Schema::hasTable('public_claim_review_email_deliveries')) {
            return $notified;
        }

        User::query()
            ->where('subscription_status', 'active')
            ->whereNotNull('email')
            ->chunkById($chunkSize, function ($users) use ($announcement, &$notified): void {
                foreach ($users as $user) {
                    if ($this->userAlreadyReceivedAnnouncement($user, $announcement)) {
                        continue;
                    }

                    $delivery = PublicClaimReviewEmailDelivery::query()->createOrFirst([
                        'public_claim_review_announcement_id' => $announcement->id,
                        'user_id' => $user->id,
                    ], [
                        'sent_at' => now(),
                    ]);

                    if (! $delivery->wasRecentlyCreated) {
                        continue;
                    }

                    try {
                        $user->notify(new PublicClaimReviewPublished($announcement));
                        $notified++;
                    } catch (Throwable $exception) {
                        $delivery->delete();
                        report($exception);
                    }
                }
            });

        return $notified;
    }

    private function userAlreadyReceivedAnnouncement(User $user, PublicClaimReviewAnnouncement $announcement): bool
    {
        if (! Schema::hasTable('public_claim_review_email_deliveries')) {
            return true;
        }

        return PublicClaimReviewEmailDelivery::query()
            ->where('public_claim_review_announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function feedItemId(array $item): ?string
    {
        $id = trim((string) ($item['id'] ?? ''));

        if ($id !== '') {
            return Str::limit($id, 80, '');
        }

        $url = trim((string) ($item['url'] ?? ''));

        return $url !== '' ? substr(hash('sha256', $url), 0, 24) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function publishedAt(array $item): ?Carbon
    {
        $timestamp = (int) ($item['timestamp'] ?? 0);

        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function limited(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }
}
