<?php

namespace App\Services\Facebook;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\DetectionVerdictPresenter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FacebookWebhookService
{
    public function __construct(
        private readonly DetectionPipeline $detectionPipeline,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        if (($payload['object'] ?? null) !== 'page') {
            Log::info('Facebook webhook ignored non-page payload.', [
                'object' => $payload['object'] ?? null,
            ]);

            return;
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                if (is_array($change)) {
                    $this->handleChange($change);
                }
            }
        }
    }

    /**
     * @return array{detection: Detection, reply: string}
     */
    public function simulateMention(string $message, ?string $sourceUrl = null): array
    {
        $user = $this->systemUser();

        if (! $user) {
            throw new \RuntimeException('No admin/system user exists for Facebook webhook simulation.');
        }

        $detection = $this->detectionPipeline->run($user, [
            'source_url' => $sourceUrl,
            'source_platform' => 'facebook',
            'caption_text' => $this->messageFrom(['message' => $message], []) ?? $message,
            'notes' => 'Created from the Facebook mention simulator because Meta public webhook delivery requires verification.',
            'weather_consent' => false,
        ], null);

        return [
            'detection' => $detection,
            'reply' => $this->replyMessage($detection),
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function handleChange(array $change): void
    {
        $field = (string) ($change['field'] ?? '');
        $value = (array) ($change['value'] ?? []);

        if (! in_array($field, ['mention', 'feed'], true)) {
            return;
        }

        if (($value['verb'] ?? 'add') !== 'add') {
            Log::info('Facebook webhook change ignored because it is not an add event.', [
                'field' => $field,
                'verb' => $value['verb'] ?? null,
            ]);

            return;
        }

        $targetId = $this->targetObjectId($value);

        if ($targetId === null || $this->isDashboardSample($targetId, $value)) {
            Log::info('Facebook webhook dashboard sample ignored.', [
                'field' => $field,
                'target_id' => $targetId,
            ]);

            return;
        }

        if (! $this->markProcessingStarted($field, $targetId, $value)) {
            Log::info('Facebook webhook duplicate ignored.', [
                'field' => $field,
                'target_id' => $targetId,
            ]);

            return;
        }

        if ($this->wasSentByThisPage($value)) {
            Log::info('Facebook webhook self-authored event ignored.', [
                'field' => $field,
                'target_id' => $targetId,
            ]);

            return;
        }

        $facebookObject = $this->fetchFacebookObject($targetId);
        $message = $this->messageFrom($value, $facebookObject);

        if ($message === null) {
            Log::info('Facebook webhook event had no claim text to analyze.', [
                'field' => $field,
                'target_id' => $targetId,
            ]);

            return;
        }

        $user = $this->systemUser();

        if (! $user) {
            Log::warning('Facebook webhook could not run detection because no admin/system user exists.');

            return;
        }

        try {
            $detection = $this->detectionPipeline->run($user, [
                'source_url' => $this->sourceUrlFrom($facebookObject),
                'source_platform' => 'facebook',
                'caption_text' => $message,
                'notes' => "Created from Facebook {$field} webhook event for {$targetId}.",
                'weather_consent' => false,
            ], null);

            Log::info('Facebook webhook detection created.', [
                'field' => $field,
                'target_id' => $targetId,
                'detection_id' => $detection->id,
                'verdict' => $detection->verdict,
                'fake_score' => $detection->fake_score,
            ]);

            $reply = $this->replyMessage($detection);

            if (! (bool) config('services.facebook.auto_reply_enabled', false)) {
                Log::info('Facebook auto-reply prepared but not posted because it is disabled.', [
                    'target_id' => $targetId,
                    'detection_id' => $detection->id,
                    'reply' => $reply,
                ]);

                return;
            }

            $this->postComment($targetId, $reply, $detection);
        } catch (Throwable $exception) {
            Log::error('Facebook webhook processing failed.', [
                'field' => $field,
                'target_id' => $targetId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function targetObjectId(array $value): ?string
    {
        $targetId = $value['comment_id']
            ?? $value['post_id']
            ?? $value['photo_id']
            ?? $value['video_id']
            ?? null;

        $targetId = trim((string) $targetId);

        return $targetId !== '' ? $targetId : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function isDashboardSample(string $targetId, array $value): bool
    {
        return Str::contains($targetId, '44444444')
            || (string) ($value['sender_id'] ?? '') === '44444444'
            || (string) data_get($value, 'from.id', '') === '1067280970047460'
            || (string) ($value['message'] ?? '') === 'Example post content.';
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function wasSentByThisPage(array $value): bool
    {
        $pageId = (string) config('services.facebook.page_id');

        if ($pageId === '') {
            return false;
        }

        return in_array($pageId, [
            (string) ($value['sender_id'] ?? ''),
            (string) data_get($value, 'from.id', ''),
        ], true);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function markProcessingStarted(string $field, string $targetId, array $value): bool
    {
        $key = 'facebook-webhook:'.hash('sha256', json_encode([
            'field' => $field,
            'target_id' => $targetId,
            'verb' => $value['verb'] ?? null,
            'item' => $value['item'] ?? null,
        ], JSON_UNESCAPED_SLASHES));

        return Cache::add($key, now()->toIso8601String(), now()->addHours(12));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFacebookObject(string $targetId): array
    {
        $response = $this->graphRequest($targetId, [
            'fields' => 'message,permalink_url,from,created_time',
        ]);

        if ($response !== []) {
            return $response;
        }

        return $this->graphRequest($targetId, [
            'fields' => 'message,from,created_time',
        ]);
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function graphRequest(string $path, array $query): array
    {
        $pageAccessToken = (string) config('services.facebook.page_access_token');

        if ($pageAccessToken === '') {
            Log::warning('Facebook Graph request skipped because page access token is missing.');

            return [];
        }

        $url = $this->graphUrl($path);
        $response = Http::timeout(15)
            ->acceptJson()
            ->get($url, $query + ['access_token' => $pageAccessToken]);

        if ($response->successful()) {
            return $response->json() ?: [];
        }

        Log::warning('Facebook Graph request failed.', [
            'path' => $path,
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 500),
        ]);

        return [];
    }

    private function graphUrl(string $path): string
    {
        $version = trim((string) config('services.facebook.graph_version', 'v26.0'), '/');

        return "https://graph.facebook.com/{$version}/".ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $facebookObject
     */
    private function messageFrom(array $value, array $facebookObject): ?string
    {
        $message = trim((string) ($value['message'] ?? $facebookObject['message'] ?? ''));
        $message = preg_replace('/@\s*TruthGuard\b/i', '', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $message !== '' ? Str::limit($message, 4000, '') : null;
    }

    /**
     * @param  array<string, mixed>  $facebookObject
     */
    private function sourceUrlFrom(array $facebookObject): ?string
    {
        $url = trim((string) ($facebookObject['permalink_url'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function systemUser(): ?User
    {
        $configuredUserId = (int) config('services.facebook.system_user_id');

        if ($configuredUserId > 0) {
            $configuredUser = User::query()->find($configuredUserId);

            if ($configuredUser) {
                return $configuredUser;
            }
        }

        return User::query()
            ->where('is_admin', true)
            ->orderBy('id')
            ->first();
    }

    private function replyMessage(Detection $detection): string
    {
        $profile = DetectionVerdictPresenter::forDetection($detection);
        $label = Str::headline(Str::lower((string) ($profile['short_label'] ?? 'Needs source review')));
        $summary = trim((string) ($detection->explanation_summary ?: $detection->analysis_summary));
        $summary = Str::limit($summary, 360);
        $recommendation = Str::limit(trim((string) $detection->recommendation), 180);

        return trim(implode("\n\n", array_filter([
            "TruthGuard check: {$label} ({$detection->fake_score}% risk).",
            $summary,
            $recommendation !== '' ? "Next step: {$recommendation}" : null,
            'AI can make mistakes. Review the sources before sharing.',
        ])));
    }

    private function postComment(string $targetId, string $message, Detection $detection): void
    {
        $response = Http::timeout(15)
            ->asForm()
            ->post($this->graphUrl("{$targetId}/comments"), [
                'message' => $message,
                'access_token' => (string) config('services.facebook.page_access_token'),
            ]);

        if ($response->successful()) {
            Log::info('Facebook auto-reply posted.', [
                'target_id' => $targetId,
                'detection_id' => $detection->id,
                'facebook_comment_id' => Arr::get($response->json() ?: [], 'id'),
            ]);

            return;
        }

        Log::warning('Facebook auto-reply failed.', [
            'target_id' => $targetId,
            'detection_id' => $detection->id,
            'status' => $response->status(),
            'body' => Str::limit($response->body(), 500),
        ]);
    }
}
