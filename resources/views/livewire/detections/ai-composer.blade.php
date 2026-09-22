<?php

use App\Models\Detection;
use App\Services\Detections\DetectionRetentionService;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    public ?int $selectedDetectionId = null;

    /** @var array<int, array<string, mixed>> */
    public array $recentFactChecks = [];

    public bool $recentFactChecksLoaded = false;

    public ?string $recentFactChecksError = null;

    public function mount(?int $selectedDetectionId = null): void
    {
        $this->selectedDetectionId = $selectedDetectionId ? (int) $selectedDetectionId : null;

        if (! $this->selectedDetectionId && Auth::check()) {
            $this->loadRecentFactChecks();
        }
    }

    public function getSelectedDetectionProperty(): ?Detection
    {
        if (! Auth::check() || ! $this->selectedDetectionId) {
            return null;
        }

        return app(DetectionRetentionService::class)
            ->retainedQuery((int) Auth::id())
            ->whereKey($this->selectedDetectionId)
            ->first();
    }

    public function loadRecentFactChecks(): void
    {
        $this->recentFactChecksLoaded = false;
        $this->recentFactChecksError = null;

        try {
            $this->recentFactChecks = collect(app(GoogleFactCheckFeedService::class)->latest(3)['items'] ?? [])
                ->filter(fn ($item): bool => is_array($item))
                ->take(3)
                ->map(fn (array $item): array => $this->presentPublicClaimReview($item))
                ->all();
        } catch (\Throwable $exception) {
            report($exception);
            $this->recentFactChecks = [];
            $this->recentFactChecksError = 'Unable to load public claim reviews.';
        } finally {
            $this->recentFactChecksLoaded = true;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function presentPublicClaimReview(array $item): array
    {
        $rating = trim((string) ($item['rating'] ?? 'Reviewed')) ?: 'Reviewed';
        [$status, $statusKey] = $this->statusForPublicReview($rating, (string) ($item['tone'] ?? 'neutral'));
        $publishedAt = $this->publicReviewDate($item);
        $itemId = trim((string) ($item['id'] ?? ''));
        $externalUrl = trim((string) ($item['url'] ?? ''));
        $publisher = trim((string) ($item['publisher'] ?? 'Fact-check partner')) ?: 'Fact-check partner';
        $headline = trim((string) ($item['headline'] ?? 'Fact-check result')) ?: 'Fact-check result';
        $claim = trim((string) ($item['claim'] ?? 'Public claim reviewed by a fact-checking partner.'));
        $host = trim((string) ($item['source_domain'] ?? ($item['host'] ?? '')));

        return [
            'id' => $itemId !== '' ? $itemId : md5($headline.'|'.$externalUrl),
            'claim' => Str::limit($headline, 120),
            'summary' => Str::limit($claim, 130),
            'thumbnail' => filled($item['image_url'] ?? null)
                ? (string) $item['image_url']
                : ($item['logo_url'] ?? null),
            'thumbnail_fit' => filled($item['image_url'] ?? null) ? 'cover' : 'contain',
            'status' => $status,
            'status_key' => $statusKey,
            'input_type' => Str::limit($publisher, 18, ''),
            'publisher' => $publisher,
            'publisher_logo' => $item['logo_url'] ?? null,
            'host' => $host,
            'sources_count' => $externalUrl !== '' ? 1 : 0,
            'timestamp' => $publishedAt?->toIso8601String() ?? '',
            'relative_time' => $item['date_label'] ?? ($publishedAt?->diffForHumans() ?? 'Latest review'),
            'url' => $itemId !== ''
                ? route('dashboard.fact-check', ['factCheck' => $itemId])
                : ($externalUrl !== '' ? $externalUrl : route('dashboard')),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusForPublicReview(string $rating, string $tone): array
    {
        $text = Str::lower($rating.' '.$tone);
        $label = Str::limit(Str::headline($rating), 24, '') ?: 'Reviewed';

        if (Str::contains($text, ['false', 'fake', 'hoax', 'fabricated', 'danger'])) {
            return [$label, 'false'];
        }

        if (Str::contains($text, ['misleading', 'mixed', 'unsupported', 'unproven'])) {
            return [$label, 'misleading'];
        }

        if (Str::contains($text, ['partly', 'context', 'needs context', 'warning'])) {
            return [$label, 'needs_context'];
        }

        if (Str::contains($text, ['true', 'correct', 'accurate', 'real', 'legitimate', 'safe'])) {
            return [$label, 'verified'];
        }

        return [$label, 'unverified'];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function publicReviewDate(array $item): ?Carbon
    {
        $timestamp = $item['timestamp'] ?? null;

        if (is_numeric($timestamp) && (int) $timestamp > 0) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        $dateLabel = trim((string) ($item['date_label'] ?? ''));

        if ($dateLabel === '' || Str::contains(Str::lower($dateLabel), 'unavailable')) {
            return null;
        }

        try {
            return Carbon::parse($dateLabel);
        } catch (\Throwable) {
            return null;
        }
    }
}; ?>

@php
    $shortcutPrompts = [
        ['label' => 'AI generated?', 'prompt' => 'Check whether this media is AI-generated or manipulated.'],
        ['label' => 'Verify claim', 'prompt' => 'Verify this claim with trusted public sources.'],
        ['label' => 'Source match', 'prompt' => 'Compare this upload with the original source context.'],
    ];
    $truthguardLogoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
    $truthguardLogoFile = public_path($truthguardLogoPath);
    $truthguardLogoUrl = is_file($truthguardLogoFile)
        ? asset($truthguardLogoPath).'?v='.filemtime($truthguardLogoFile)
        : asset('images/truthguard-logo.png');
    $oldMessage = old('caption_text', '');
    $oldSourceUrl = old('source_url', '');
    $composerDraft = trim(collect([$oldSourceUrl, $oldMessage])->filter()->implode("\n\n"));
    $claimMaxCharacters = (int) config('truthguard.claims.max_characters', 4000);
    $mediaUploadMaxMb = (int) config('truthguard.uploads.media_max_mb', 20);
    $mediaUploadMaxBytes = (int) config('truthguard.uploads.media_max_bytes', $mediaUploadMaxMb * 1024 * 1024);
    $submitContext = request()->routeIs('dashboard') && ! auth()->user()?->isAdmin()
        ? 'dashboard'
        : 'detection-center';
    $activeDetection = $this->selectedDetection;
    $initialComposerError = $errors->first('caption_text') ?: $errors->first('source_url');
    $initialUploadError = $errors->first('media_file');
    $googlePickerClientId = trim((string) config('services.google_picker.client_id', ''));
    $googlePickerApiKey = trim((string) config('services.google_picker.key', ''));
    $googlePickerAppId = trim((string) config('services.google_picker.app_id', ''));

    if ($googlePickerAppId === '' && preg_match('/^(\d+)-/', $googlePickerClientId, $googlePickerMatches)) {
        $googlePickerAppId = $googlePickerMatches[1];
    }

    $googleDrivePicker = [
        'enabled' => $googlePickerClientId !== '' && $googlePickerApiKey !== '',
        'clientId' => $googlePickerClientId,
        'apiKey' => $googlePickerApiKey,
        'appId' => $googlePickerAppId,
        'scope' => (string) config('services.google_picker.scope', 'https://www.googleapis.com/auth/drive.readonly'),
    ];
@endphp

@once
    <style>
        .truthguard-detection-theme {
            font-family: 'Poppins', sans-serif;
        }

        .truthguard-detection-shell {
            background: transparent;
            --tg-premium-blue: #2563eb;
            --tg-premium-cyan: #06b6d4;
            --tg-premium-indigo: #4f46e5;
        }

        .truthguard-scroll-locked {
            overflow: hidden !important;
            overscroll-behavior: none;
            height: 100%;
        }

        .truthguard-detection-stage {
            height: auto;
            min-height: 0;
        }

        .truthguard-pre-result-form {
            margin-top: 0;
            margin-bottom: 0;
        }

        .truthguard-pre-result-form .truthguard-composer-footer {
            margin-top: 1.45rem !important;
        }

        .truthguard-pre-result-form .truthguard-ai-disclaimer-copy {
            margin-top: 1.05rem !important;
        }

        .truthguard-pre-result-form .truthguard-mobile-ai-disclaimer {
            margin-top: 1rem !important;
        }

        .truthguard-recent-section {
            animation: truthguard-workspace-composer-arrive 520ms cubic-bezier(0.22, 1, 0.36, 1) 55ms both;
        }

        .truthguard-recent-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(13.5rem, 1fr));
            gap: 0.55rem;
        }

        .truthguard-recent-claim {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .truthguard-recent-skeleton {
            position: relative;
            overflow: hidden;
            min-height: 6.6rem;
            border: 1px solid rgba(219, 234, 254, 0.9);
            border-radius: 0.85rem;
            background: rgba(255, 255, 255, 0.82);
        }

        .truthguard-recent-skeleton::after {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-100%);
            background: linear-gradient(90deg, transparent, rgba(219, 234, 254, 0.54), transparent);
            animation: truthguard-recent-loading 1.45s ease-in-out infinite;
        }

        @keyframes truthguard-recent-loading {
            to {
                transform: translateX(100%);
            }
        }

        .truthguard-detection-composer {
            border: 1px solid rgba(191, 219, 254, 0.92);
            background:
                radial-gradient(circle at 9% -18%, rgba(103, 232, 249, 0.24), transparent 34%),
                radial-gradient(circle at 96% 115%, rgba(129, 140, 248, 0.16), transparent 36%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.9) 100%);
            box-shadow:
                0 26px 68px rgba(15, 23, 42, 0.1),
                0 14px 32px rgba(37, 99, 235, 0.06),
                0 0 0 1px rgba(255, 255, 255, 0.84) inset,
                inset 0 1px 0 rgba(255, 255, 255, 0.92);
            overflow: visible;
            backdrop-filter: blur(22px) saturate(1.12);
            -webkit-backdrop-filter: blur(22px) saturate(1.12);
            transition: border-color 220ms ease, box-shadow 220ms ease, transform 220ms ease;
            animation: truthguard-workspace-composer-arrive 560ms cubic-bezier(0.22, 1, 0.36, 1) 90ms both;
        }

        .truthguard-detection-composer:focus-within {
            border-color: rgba(96, 165, 250, 0.92);
            box-shadow:
                0 30px 76px rgba(15, 23, 42, 0.12),
                0 16px 38px rgba(37, 99, 235, 0.1),
                0 0 0 4px rgba(59, 130, 246, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.94);
        }

        .truthguard-detection-composer::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            border: 1px solid rgba(255, 255, 255, 0.72);
            background:
                repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.028) 0 1px, transparent 1px 18px),
                repeating-linear-gradient(0deg, rgba(14, 165, 233, 0.024) 0 1px, transparent 1px 18px);
            opacity: 0.66;
            mask-image: radial-gradient(circle at 16% 8%, #000, transparent 72%);
            -webkit-mask-image: radial-gradient(circle at 16% 8%, #000, transparent 72%);
            pointer-events: none;
        }

        .truthguard-detection-composer::after {
            content: '';
            position: absolute;
            left: 1.25rem;
            right: 1.25rem;
            bottom: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.72), rgba(59, 130, 246, 0.46), rgba(168, 85, 247, 0.36), transparent);
            pointer-events: none;
        }

        .truthguard-detection-composer > * {
            position: relative;
            z-index: 1;
        }

        .truthguard-workspace-intro {
            filter: drop-shadow(0 12px 24px rgba(37, 99, 235, 0.045));
        }

        .truthguard-workspace-hero {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border: 1px solid rgba(191, 219, 254, 0.74);
            background:
                radial-gradient(circle at 8% 0%, rgba(186, 230, 253, 0.34), transparent 34%),
                radial-gradient(circle at 92% 100%, rgba(199, 210, 254, 0.26), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.88), rgba(248, 250, 252, 0.62));
            box-shadow:
                0 24px 58px rgba(15, 23, 42, 0.075),
                0 12px 30px rgba(37, 99, 235, 0.055),
                inset 0 1px 0 rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(20px) saturate(1.1);
            -webkit-backdrop-filter: blur(20px) saturate(1.1);
            animation: truthguard-workspace-hero-arrive 520ms cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .truthguard-workspace-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            background:
                repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.026) 0 1px, transparent 1px 14px),
                repeating-linear-gradient(0deg, rgba(37, 99, 235, 0.02) 0 1px, transparent 1px 14px);
            mask-image: linear-gradient(90deg, #000, transparent 74%);
            -webkit-mask-image: linear-gradient(90deg, #000, transparent 74%);
        }

        .truthguard-workspace-hero::after {
            content: '';
            position: absolute;
            left: 1.5rem;
            right: 1.5rem;
            bottom: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(14, 165, 233, 0.62), rgba(79, 70, 229, 0.36), transparent);
            pointer-events: none;
        }

        .truthguard-workspace-hero-glint {
            position: absolute;
            inset: -45% auto -45% 36%;
            width: 16rem;
            transform: translate3d(-180%, 0, 0) rotate(18deg);
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.72), transparent);
            filter: blur(18px);
            opacity: 0;
            pointer-events: none;
            animation: truthguard-workspace-glint 7.2s ease-in-out 850ms infinite;
        }

        .truthguard-agent-pill {
            border: 1px solid rgba(147, 197, 253, 0.7);
            background: linear-gradient(135deg, rgba(239, 246, 255, 0.98) 0%, rgba(219, 234, 254, 0.92) 100%);
            color: #1d4ed8;
            box-shadow: 0 12px 26px rgba(37, 99, 235, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .truthguard-agent-pill-icon {
            height: 0.55rem;
            width: 0.55rem;
            border-radius: 9999px;
            background: linear-gradient(135deg, #22d3ee, #2563eb);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1), 0 0 18px rgba(37, 99, 235, 0.32);
            animation: truthguard-agent-pulse 2.8s ease-in-out infinite;
        }

        .truthguard-workspace-title {
            background: linear-gradient(90deg, #0f172a 0%, #1e40af 52%, #6d28d9 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent !important;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 12px 28px rgba(37, 99, 235, 0.12);
        }

        .truthguard-capability-pill {
            border: 1px solid rgba(203, 213, 225, 0.85);
            background:
                linear-gradient(145deg, rgba(255, 255, 255, 0.92), rgba(248, 250, 252, 0.68));
            box-shadow:
                0 10px 22px rgba(15, 23, 42, 0.055),
                inset 0 1px 0 rgba(255, 255, 255, 0.95);
            padding: 0.34rem 0.5rem;
            font-size: 0.68rem;
        }

        .truthguard-capability-row {
            gap: 0.38rem !important;
        }

        .truthguard-capability-pill svg {
            height: 0.78rem;
            width: 0.78rem;
        }

        .truthguard-workspace-orb {
            position: relative;
            height: 8.15rem;
            width: 8.15rem;
            flex: 0 0 auto;
            border-radius: 9999px;
            background:
                radial-gradient(circle at 50% 44%, rgba(255, 255, 255, 0.98), rgba(224, 242, 254, 0.78) 50%, rgba(219, 234, 254, 0.32) 70%, transparent 72%);
            box-shadow:
                0 28px 62px rgba(37, 99, 235, 0.16),
                0 0 44px rgba(14, 165, 233, 0.12),
                inset 0 0 34px rgba(255, 255, 255, 0.78);
            animation: truthguard-workspace-orb-float 4.8s ease-in-out infinite;
        }

        .truthguard-workspace-orb::before {
            content: '';
            position: absolute;
            inset: 0.36rem;
            border-radius: inherit;
            background: conic-gradient(from 24deg, rgba(6, 182, 212, 0.94), transparent 30%, rgba(79, 70, 229, 0.72), transparent 63%, rgba(14, 165, 233, 0.78), transparent 100%);
            padding: 2px;
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            animation: truthguard-workspace-orb-spin 8s linear infinite;
            pointer-events: none;
        }

        .truthguard-workspace-orb::after {
            content: '';
            position: absolute;
            inset: 1.08rem;
            z-index: 1;
            border-radius: inherit;
            background:
                radial-gradient(circle at 36% 26%, rgba(255, 255, 255, 0.98), transparent 28%),
                linear-gradient(135deg, rgba(37, 99, 235, 0.98), rgba(6, 182, 212, 0.86) 52%, rgba(124, 58, 237, 0.8));
            box-shadow:
                0 18px 34px rgba(37, 99, 235, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.46);
            pointer-events: none;
        }

        .truthguard-workspace-orb-core {
            position: absolute;
            inset: 1.78rem;
            z-index: 3;
            display: grid;
            place-items: center;
            border-radius: 9999px;
            background:
                radial-gradient(circle at 35% 26%, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0.46) 34%, rgba(219, 234, 254, 0.34) 100%);
            box-shadow:
                0 14px 32px rgba(15, 23, 42, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }

        .truthguard-workspace-orb-core::before {
            content: '';
            position: absolute;
            inset: -0.55rem;
            border-radius: inherit;
            background: conic-gradient(from 0deg, transparent 0 18%, rgba(255, 255, 255, 0.72) 23%, transparent 31%, transparent 100%);
            filter: blur(1px);
            opacity: 0.8;
            animation: truthguard-workspace-orb-spin 5.8s linear infinite;
            pointer-events: none;
        }

        .truthguard-workspace-orb img {
            position: relative;
            z-index: 2;
            height: 3.7rem;
            width: 3.7rem;
            object-fit: contain;
            filter: drop-shadow(0 10px 18px rgba(15, 23, 42, 0.12));
        }

        @keyframes truthguard-workspace-orb-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes truthguard-workspace-orb-float {
            0%, 100% {
                transform: translate3d(0, 0, 0);
            }
            50% {
                transform: translate3d(0, -0.45rem, 0);
            }
        }

        @keyframes truthguard-workspace-glint {
            0%, 18% {
                opacity: 0;
                transform: translate3d(-180%, 0, 0) rotate(18deg);
            }
            38% {
                opacity: 0.54;
            }
            64%, 100% {
                opacity: 0;
                transform: translate3d(420%, 0, 0) rotate(18deg);
            }
        }

        @keyframes truthguard-agent-pulse {
            0%, 100% {
                box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1), 0 0 14px rgba(37, 99, 235, 0.24);
            }
            50% {
                box-shadow: 0 0 0 6px rgba(34, 211, 238, 0.08), 0 0 22px rgba(37, 99, 235, 0.42);
            }
        }

        @keyframes truthguard-workspace-hero-arrive {
            from {
                opacity: 0;
                transform: translate3d(0, 0.65rem, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }

        @keyframes truthguard-workspace-composer-arrive {
            from {
                opacity: 0;
                transform: translate3d(0, 0.75rem, 0);
            }
            to {
                opacity: 1;
                transform: translate3d(0, 0, 0);
            }
        }

        .truthguard-scroll-target {
            scroll-margin-top: calc(var(--tg-detection-offset, 96px) + 16px);
        }

        .truthguard-composer-field {
            min-height: 4.35rem;
            border-radius: 1.35rem;
            border: 1px solid rgba(191, 219, 254, 0.58);
            background:
                radial-gradient(circle at 3% 12%, rgba(125, 211, 252, 0.24), transparent 34%),
                radial-gradient(circle at 95% 100%, rgba(199, 210, 254, 0.22), transparent 34%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.86), rgba(248, 250, 252, 0.68));
            box-shadow:
                0 1px 0 rgba(255, 255, 255, 0.9) inset,
                0 16px 30px rgba(15, 23, 42, 0.035),
                0 0 0 1px rgba(255, 255, 255, 0.72) inset;
        }

        .truthguard-composer-footer {
            width: 100%;
            min-height: 0;
            margin-left: 0;
            padding: 0.1rem 0.15rem 0;
        }

        .truthguard-input-types {
            scrollbar-width: none;
        }

        .truthguard-input-types::-webkit-scrollbar {
            display: none;
        }

        .truthguard-input-type {
            display: inline-flex;
            min-height: 2.45rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            gap: 0.38rem;
            border: 1px solid rgba(203, 213, 225, 0.92);
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.86);
            padding: 0.45rem 0.7rem;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            line-height: 1;
            transition: border-color 180ms ease, background-color 180ms ease, box-shadow 180ms ease, color 180ms ease;
        }

        .truthguard-input-type:hover {
            border-color: rgba(147, 197, 253, 0.95);
            color: #1d4ed8;
        }

        .truthguard-input-type:focus-visible {
            outline: 0;
            box-shadow: 0 0 0 4px rgba(219, 234, 254, 0.92);
        }

        .truthguard-input-type-active {
            border-color: rgba(96, 165, 250, 0.95);
            background: #eff6ff;
            color: #1d4ed8;
            box-shadow: inset 0 0 0 1px rgba(191, 219, 254, 0.72);
        }

        .truthguard-link-preview {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            margin: 0.65rem 0.35rem 0;
            border: 1px solid rgba(191, 219, 254, 0.78);
            border-radius: 0.9rem;
            background: rgba(248, 250, 252, 0.86);
            padding: 0.55rem 0.65rem;
        }

        .truthguard-attachment-trigger {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border-color: rgba(191, 219, 254, 0.72) !important;
            background:
                radial-gradient(circle at 35% 18%, rgba(255, 255, 255, 0.96), transparent 34%),
                linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(239, 246, 255, 0.82)) !important;
            box-shadow:
                0 12px 26px rgba(37, 99, 235, 0.08),
                0 0 0 1px rgba(255, 255, 255, 0.9) inset !important;
        }

        .truthguard-attachment-trigger::before {
            content: '';
            position: absolute;
            inset: -45%;
            z-index: -1;
            background: conic-gradient(from 120deg, transparent, rgba(34, 211, 238, 0.24), transparent 34%, rgba(99, 102, 241, 0.22), transparent 72%);
            opacity: 0;
            transition: opacity 180ms ease;
        }

        .truthguard-attachment-trigger:hover::before {
            opacity: 1;
        }

        .truthguard-ai-note {
            color: #64748b;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .truthguard-ai-note strong {
            color: #2563eb;
        }

        .truthguard-ai-disclaimer {
            display: inline-flex;
            max-width: 100%;
            min-width: 0;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            color: #64748b;
            white-space: nowrap;
        }

        .truthguard-ai-disclaimer > span:last-child {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .truthguard-ai-disclaimer-icon {
            display: inline-flex;
            height: 1.05rem;
            width: 1.05rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: transparent;
            color: #2563eb;
            box-shadow: none;
            transform: none !important;
            animation: none !important;
            transition: none !important;
        }

        .truthguard-ai-disclaimer-icon svg {
            height: 0.72rem;
            width: 0.72rem;
            transform: none !important;
            animation: none !important;
            transition: none !important;
        }

        @media (max-width: 639px) {
            .truthguard-recent-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .truthguard-workspace-title {
                font-size: clamp(1.72rem, 8.8vw, 2.12rem);
            }

            .truthguard-workspace-hero,
            .truthguard-detection-composer {
                border-radius: 1.45rem;
            }

            .truthguard-composer-field {
                min-height: 4.35rem;
                border-radius: 1.15rem;
            }
        }

        @media (min-width: 640px) {
            .truthguard-capability-row {
                gap: 0.5rem !important;
            }

            .truthguard-capability-pill {
                padding: 0.42rem 0.75rem;
                font-size: 0.75rem;
            }

            .truthguard-capability-pill svg {
                height: 0.875rem;
                width: 0.875rem;
            }
        }

        .truthguard-detection-textarea,
        .truthguard-detection-input {
            appearance: none;
            -webkit-appearance: none;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
            outline: none !important;
        }

        .truthguard-detection-textarea {
            resize: none !important;
            overflow: hidden;
            min-height: 3.3rem !important;
            max-height: 8.25rem !important;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .truthguard-detection-textarea::-webkit-resizer {
            display: none;
        }

        .truthguard-analyze-button {
            min-height: 2.75rem;
            width: auto;
            min-width: 8.4rem;
            padding: 0.55rem 0.9rem;
            border-radius: 0.9rem;
            gap: 0.45rem;
            border: 1px solid rgba(103, 232, 249, 0.28);
            isolation: isolate;
        }

        .truthguard-analyze-button::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -1;
            background: radial-gradient(circle at 50% 0%, rgba(255, 255, 255, 0.24), transparent 48%);
            opacity: 0;
            transition: opacity 180ms ease;
        }

        .truthguard-analyze-button:hover::before {
            opacity: 1;
        }

        .truthguard-analyze-text {
            position: static;
            width: auto;
            height: auto;
            padding: 0;
            margin: 0;
            overflow: visible;
            clip: auto;
            white-space: nowrap;
            border: 0;
        }

        .truthguard-analyze-symbol {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 1.35rem;
            width: 1.35rem;
            flex: 0 0 auto;
        }

        .truthguard-analyze-symbol::before {
            content: '';
            position: absolute;
            inset: -0.22rem;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.58), rgba(103, 232, 249, 0.24) 48%, transparent 72%);
            filter: blur(5px);
            animation: truthguard-scan-lens-blur 2.2s ease-in-out infinite;
        }

        .truthguard-analyze-spinner {
            display: inline-block;
            height: 1rem;
            width: 1rem;
            flex: 0 0 auto;
            border-radius: 9999px;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: #fff;
            animation: truthguard-verify-ai-spin 0.85s linear infinite;
        }

        @media (min-width: 640px) {
            .truthguard-analyze-button {
                height: auto;
                width: auto;
                min-width: 8.75rem;
                padding: 0.56rem 1.08rem;
                border-radius: 18px;
                gap: 0.5rem;
            }

            .truthguard-analyze-text {
                position: static;
                width: auto;
                height: auto;
                padding: 0;
                margin: 0;
                overflow: visible;
                clip: auto;
                border: 0;
            }
        }

        .truthguard-analyze-ready {
            background: linear-gradient(100deg, #1e40af 0%, #2563eb 58%, #3b82f6 100%);
            color: #ffffff;
            box-shadow: 0 18px 38px rgba(37, 99, 235, 0.24), 0 0 26px rgba(59, 130, 246, 0.18);
        }

        .truthguard-analyze-ready:hover {
            background: linear-gradient(100deg, #1d4ed8 0%, #1e40af 58%, #2563eb 100%);
        }

        .truthguard-analyze-empty {
            background: #ffffff;
            color: #334155;
            border-color: rgba(203, 213, 225, 0.95);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        }

        .truthguard-analyze-empty:hover {
            background: #f8fafc;
            border-color: rgba(96, 165, 250, 0.65);
            color: #1d4ed8;
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.12);
        }

        .truthguard-analyze-empty .truthguard-analyze-symbol::before {
            opacity: 0;
            filter: none;
            animation: none;
        }

        .truthguard-input-nudge {
            animation: truthguard-input-nudge 520ms ease both;
        }

        .truthguard-composer-hint {
            border-color: rgba(37, 99, 235, 0.42) !important;
            box-shadow: 0 22px 46px rgba(37, 99, 235, 0.12), 0 0 0 4px rgba(59, 130, 246, 0.08);
        }

        .truthguard-attachment-hint {
            border-color: rgba(37, 99, 235, 0.52) !important;
            background: #eff6ff !important;
            color: #1d4ed8 !important;
        }

        .truthguard-attachment-modal {
            position: fixed !important;
            inset: 0 !important;
            min-height: 100svh;
            width: 100vw;
            isolation: isolate;
        }

        .truthguard-attachment-modal-backdrop {
            background:
                radial-gradient(circle at 22% 20%, rgba(125, 211, 252, 0.16), transparent 34%),
                radial-gradient(circle at 76% 72%, rgba(167, 139, 250, 0.14), transparent 36%),
                rgba(248, 250, 252, 0.52);
            backdrop-filter: blur(10px) saturate(1.06);
            -webkit-backdrop-filter: blur(10px) saturate(1.06);
        }

        .truthguard-attachment-popover {
            display: block;
            width: min(34rem, calc(100vw - 2rem)) !important;
            max-height: min(40rem, calc(100svh - 2rem));
            overflow: hidden !important;
            border-radius: 1.75rem !important;
            border-color: rgba(147, 197, 253, 0.42) !important;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.92)),
                linear-gradient(135deg, rgba(219, 234, 254, 0.62), rgba(255, 255, 255, 0.92) 46%, rgba(238, 242, 255, 0.58));
            box-shadow:
                0 34px 90px rgba(15, 23, 42, 0.2),
                0 22px 48px rgba(37, 99, 235, 0.12),
                0 0 0 1px rgba(255, 255, 255, 0.92) inset,
                inset 0 1px 0 rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px) saturate(1.08);
            -webkit-backdrop-filter: blur(12px) saturate(1.08);
            isolation: isolate;
        }

        .truthguard-attachment-popover::before {
            content: none;
            position: absolute;
            left: 1.2rem;
            top: auto;
            bottom: -0.48rem;
            height: 0.78rem;
            width: 0.78rem;
            transform: rotate(45deg);
            border-radius: 0 0 0.25rem 0;
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            border-right: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 8px 8px 18px rgba(15, 23, 42, 0.03);
        }

        .truthguard-attachment-popover::after {
            content: '';
            position: absolute;
            inset: 0.45rem;
            z-index: -1;
            border-radius: 1.35rem;
            border: 1px solid rgba(255, 255, 255, 0.74);
            pointer-events: none;
        }

        .truthguard-attachment-option {
            display: flex;
            width: 100%;
            align-items: center;
            gap: 0.62rem;
            border-radius: 9999px;
            padding: 0.48rem 0.62rem;
            text-align: left;
            font-size: 0.78rem;
            font-weight: 700;
            color: #334155;
            letter-spacing: 0;
            white-space: nowrap;
            transition: background-color 160ms ease, color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
        }

        .truthguard-attachment-option + .truthguard-attachment-option {
            margin-top: 0.12rem;
        }

        .truthguard-attachment-option:hover {
            background: linear-gradient(135deg, #ffffff, #eff6ff);
            color: #1d4ed8;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.92);
            transform: translateY(-1px);
        }

        .truthguard-attachment-option-icon {
            display: inline-flex;
            height: 1.8rem;
            width: 1.8rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.86), 0 8px 16px rgba(37, 99, 235, 0.08);
        }

        .truthguard-attachment-option:first-of-type .truthguard-attachment-option-icon {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #2563eb;
        }

        .truthguard-attachment-option:last-of-type .truthguard-attachment-option-icon {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            color: #7c3aed;
        }

        .truthguard-attachment-option-icon svg {
            height: 0.88rem;
            width: 0.88rem;
        }

        .truthguard-attachment-popover {
            width: min(34rem, calc(100vw - 2rem)) !important;
            padding: clamp(1rem, 2.2vw, 1.35rem) !important;
        }

        .truthguard-uploader-head {
            display: flex;
            align-items: center;
            gap: 0.82rem;
            padding: 0.36rem 0.25rem 0.9rem;
        }

        .truthguard-uploader-close {
            display: inline-flex;
            height: 2.5rem;
            width: 2.5rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            border: 1px solid rgba(226, 232, 240, 0.86);
            background:
                radial-gradient(circle at 34% 22%, rgba(255, 255, 255, 0.96), transparent 34%),
                linear-gradient(145deg, rgba(255, 255, 255, 0.94), rgba(241, 245, 249, 0.78));
            color: #64748b;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.94);
            transition: transform 160ms ease, border-color 160ms ease, color 160ms ease, box-shadow 160ms ease;
        }

        .truthguard-uploader-close:hover {
            transform: translateY(-1px);
            border-color: rgba(248, 113, 113, 0.42);
            color: #e11d48;
            box-shadow: 0 14px 26px rgba(225, 29, 72, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.96);
        }

        .truthguard-uploader-mark {
            display: inline-flex;
            height: 2.75rem;
            width: 2.75rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 0.9rem;
            background:
                radial-gradient(circle at 30% 20%, rgba(255, 255, 255, 0.92), transparent 34%),
                linear-gradient(135deg, #38bdf8, #2563eb 58%, #7c3aed);
            color: #fff;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.42);
        }

        .truthguard-uploader-dropzone {
            position: relative;
            display: flex;
            min-height: clamp(9.75rem, 18vw, 11.25rem);
            width: 100%;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.72rem;
            overflow: hidden;
            border-radius: 1.18rem;
            border: 1.5px dashed rgba(37, 99, 235, 0.38);
            background:
                radial-gradient(circle at 50% 0%, rgba(186, 230, 253, 0.32), transparent 42%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.78), rgba(239, 246, 255, 0.56));
            color: #1d4ed8;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9), 0 12px 28px rgba(37, 99, 235, 0.07);
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease, background 180ms ease;
        }

        .truthguard-uploader-dropzone::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                repeating-linear-gradient(90deg, rgba(37, 99, 235, 0.032) 0 1px, transparent 1px 14px),
                repeating-linear-gradient(0deg, rgba(14, 165, 233, 0.026) 0 1px, transparent 1px 14px);
            opacity: 0.7;
            mask-image: radial-gradient(circle at center, #000, transparent 74%);
            -webkit-mask-image: radial-gradient(circle at center, #000, transparent 74%);
            pointer-events: none;
        }

        .truthguard-uploader-dropzone:hover {
            transform: translateY(-1px);
            border-color: rgba(37, 99, 235, 0.68);
            background:
                radial-gradient(circle at 50% 0%, rgba(125, 211, 252, 0.38), transparent 42%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(239, 246, 255, 0.72));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.92), 0 18px 38px rgba(37, 99, 235, 0.12);
        }

        .truthguard-uploader-cloud {
            position: relative;
            z-index: 1;
            display: inline-flex;
            height: 4.15rem;
            width: 4.15rem;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background:
                radial-gradient(circle at 35% 24%, rgba(255, 255, 255, 0.94), transparent 34%),
                linear-gradient(135deg, #60a5fa, #2563eb 56%, #7c3aed);
            color: #fff;
            box-shadow: 0 20px 36px rgba(37, 99, 235, 0.24), 0 0 0 9px rgba(219, 234, 254, 0.72);
        }

        .truthguard-uploader-cloud svg,
        .truthguard-uploader-mark svg {
            height: 1.5rem;
            width: 1.5rem;
        }

        .truthguard-upload-type-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.56rem;
            margin-top: 0.72rem;
        }

        .truthguard-upload-type-button {
            display: inline-flex;
            min-width: 0;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            border-radius: 9999px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            background: rgba(255, 255, 255, 0.76);
            padding: 0.64rem 0.56rem;
            color: #475569;
            font-size: 0.74rem;
            font-weight: 800;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.88);
            transition: transform 160ms ease, border-color 160ms ease, background 160ms ease, color 160ms ease;
        }

        .truthguard-upload-type-button:hover {
            transform: translateY(-1px);
            border-color: rgba(96, 165, 250, 0.65);
            background: #eff6ff;
            color: #1d4ed8;
        }

        .truthguard-upload-type-button svg {
            height: 0.88rem;
            width: 0.88rem;
            flex: 0 0 auto;
        }

        .truthguard-upload-type-button-google svg {
            height: 1.05rem;
            width: 1.05rem;
        }

        .truthguard-drive-status {
            margin-top: 0.56rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(191, 219, 254, 0.76);
            background:
                radial-gradient(circle at 0% 0%, rgba(186, 230, 253, 0.32), transparent 42%),
                linear-gradient(135deg, rgba(239, 246, 255, 0.92), rgba(255, 255, 255, 0.86));
            padding: 0.55rem 0.66rem;
            color: #475569;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .truthguard-drive-status-dot {
            display: inline-flex;
            height: 0.5rem;
            width: 0.5rem;
            flex: 0 0 auto;
            border-radius: 9999px;
            background: linear-gradient(135deg, #22d3ee, #2563eb);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08), 0 0 18px rgba(14, 165, 233, 0.24);
        }

        .truthguard-drive-status-error {
            border-color: rgba(251, 113, 133, 0.42);
            background:
                radial-gradient(circle at 0% 0%, rgba(254, 205, 211, 0.34), transparent 42%),
                linear-gradient(135deg, rgba(255, 241, 242, 0.92), rgba(255, 255, 255, 0.86));
            color: #9f1239;
        }

        .truthguard-drive-status-error .truthguard-drive-status-dot {
            background: linear-gradient(135deg, #fb7185, #e11d48);
            box-shadow: 0 0 0 4px rgba(225, 29, 72, 0.08), 0 0 18px rgba(225, 29, 72, 0.18);
        }

        .truthguard-upload-file-row {
            display: flex;
            align-items: center;
            gap: 0.72rem;
            margin-top: 0.7rem;
            overflow: hidden;
            border-radius: 1rem;
            border: 1px solid rgba(191, 219, 254, 0.72);
            background: linear-gradient(135deg, rgba(239, 246, 255, 0.88), rgba(255, 255, 255, 0.82));
            padding: 0.64rem;
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.08), inset 0 1px 0 rgba(255, 255, 255, 0.86);
        }

        .truthguard-upload-file-icon {
            display: inline-flex;
            height: 2.2rem;
            width: 2.2rem;
            flex: 0 0 auto;
            align-items: center;
            justify-content: center;
            border-radius: 0.85rem;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #2563eb;
        }

        .truthguard-upload-file-icon svg {
            height: 1.1rem;
            width: 1.1rem;
        }

        .truthguard-upload-progress {
            position: relative;
            height: 0.28rem;
            overflow: hidden;
            border-radius: 9999px;
            background: rgba(219, 234, 254, 0.86);
        }

        .truthguard-upload-progress::after {
            content: '';
            position: absolute;
            inset: 0 18% 0 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #22d3ee, #2563eb, #8b5cf6);
            box-shadow: 0 0 12px rgba(37, 99, 235, 0.32);
        }

        .truthguard-preview-backdrop {
            background:
                radial-gradient(circle at 25% 18%, rgba(125, 211, 252, 0.26), transparent 34%),
                radial-gradient(circle at 74% 74%, rgba(196, 181, 253, 0.24), transparent 36%),
                rgba(248, 250, 252, 0.76);
            backdrop-filter: blur(26px) saturate(1.18);
            -webkit-backdrop-filter: blur(26px) saturate(1.18);
        }

        .truthguard-preview-background-media {
            filter: blur(30px) saturate(1.08);
            transform: scale(1.12);
            opacity: 0.24;
        }

        .truthguard-preview-anchor {
            position: relative !important;
            left: auto;
            top: auto;
            z-index: 20;
            display: flex;
            width: 100%;
            align-items: center;
            justify-content: flex-start;
            gap: 0.75rem;
            margin-top: 0.65rem;
            margin-bottom: 0.35rem;
            border: 1px solid rgba(191, 219, 254, 0.72);
            border-radius: 0.95rem;
            background: rgba(248, 250, 252, 0.86);
            padding: 0.55rem 0.65rem;
            pointer-events: auto;
        }

        .truthguard-preview-space {
            padding-top: 0;
        }

        .truthguard-preview-space .truthguard-composer-field {
            padding-left: 0.75rem !important;
        }

        @media (min-width: 640px) {
            .truthguard-preview-anchor {
                left: auto;
                top: auto;
                margin-top: 0.75rem;
                margin-bottom: 0.45rem;
                padding: 0.6rem 0.75rem;
            }

            .truthguard-preview-space {
                padding-top: 0;
            }

            .truthguard-preview-space .truthguard-composer-field {
                padding-left: 1rem !important;
            }
        }

        @media (min-width: 768px) {
            .truthguard-preview-space {
                padding-top: 0;
            }
        }

        .truthguard-preview-media-shell {
            overflow: visible !important;
            background: transparent;
            border: 0;
            box-shadow: none;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .truthguard-preview-toolbar {
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(255, 255, 255, 0.86);
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.14);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .truthguard-preview-media {
            display: block;
            transition: transform 180ms ease;
            transform-origin: center center;
            will-change: transform;
        }

        .truthguard-preview-thumb {
            position: relative;
            height: 3.25rem;
            width: 3.25rem;
            flex: 0 0 3.25rem;
            overflow: visible;
        }

        .truthguard-preview-trigger {
            display: block;
            height: 100%;
            width: 100%;
            overflow: hidden;
            border-radius: 0.7rem;
        }

        @media (min-width: 640px) {
            .truthguard-preview-thumb {
                height: 3.5rem;
                width: 3.5rem;
                flex-basis: 3.5rem;
            }

            .truthguard-preview-trigger {
                border-radius: 0.75rem;
            }
        }

        .truthguard-preview-remove {
            position: relative;
            top: auto !important;
            right: auto !important;
            bottom: auto !important;
            left: auto !important;
            z-index: 80;
            display: inline-flex;
            height: 2.25rem;
            width: 2.25rem;
            flex: 0 0 2.25rem;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid rgba(254, 205, 211, 0.9);
            border-radius: 0.65rem;
            background: rgba(255, 255, 255, 0.92);
            color: #e11d48;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
        }

        .truthguard-preview-remove::before {
            content: none;
        }

        .truthguard-preview-remove:hover::before {
            content: none;
        }

        .truthguard-preview-remove:hover {
            background: #fff1f2;
            color: #be123c;
            transform: none;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.22), 0 0 0 1px rgba(255, 255, 255, 0.96);
        }

        @keyframes truthguard-input-nudge {
            0%,
            100% {
                transform: translateX(0);
            }

            18% {
                transform: translateX(-5px);
            }

            36% {
                transform: translateX(5px);
            }

            54% {
                transform: translateX(-3px);
            }

            72% {
                transform: translateX(3px);
            }
        }


        @keyframes truthguard-verify-social-pop {
            0% {
                opacity: 0;
                transform: translate3d(0, 18px, 0) scale(0.72) rotate(var(--start-rotate, -4deg));
            }

            18%,
            76% {
                opacity: var(--icon-opacity, 0.22);
                transform: translate3d(0, 0, 0) scale(1) rotate(var(--end-rotate, 4deg));
            }

            100% {
                opacity: 0;
                transform: translate3d(var(--drift-x, 18px), -26px, 0) scale(0.86) rotate(var(--end-rotate, 4deg));
            }
        }

        @keyframes truthguard-verify-ai-spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes truthguard-verify-ai-spin-reverse {
            to {
                transform: rotate(-360deg);
            }
        }

        @keyframes truthguard-verify-ai-node {
            0%,
            100% {
                opacity: 0.42;
                transform: scale(0.76);
            }

            50% {
                opacity: 1;
                transform: scale(1.16);
            }
        }

        @keyframes truthguard-verify-core-breathe {
            0%,
            100% {
                opacity: 0.6;
                transform: scale(0.92);
            }

            50% {
                opacity: 1;
                transform: scale(1.08);
            }
        }

        @keyframes truthguard-verify-core-float {
            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-0.22rem);
            }
        }

        @keyframes truthguard-verify-radar-turn {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes truthguard-verify-grid-flow {
            to {
                background-position: 72px 72px;
            }
        }

        @keyframes truthguard-verify-aura {
            0%,
            100% {
                opacity: 0.72;
                transform: scale(0.96);
            }

            50% {
                opacity: 1;
                transform: scale(1.05);
            }
        }

        @keyframes truthguard-verify-hologram-wave {
            0% {
                opacity: 0;
                transform: scale(0.48);
            }

            22% {
                opacity: var(--wave-opacity, 0.36);
            }

            76% {
                opacity: 0.14;
            }

            100% {
                opacity: 0;
                transform: scale(1.1);
            }
        }

        @keyframes truthguard-verify-particle-drift {
            0% {
                opacity: 0;
                transform: rotate(var(--particle-angle, 0deg)) translateY(-2.1rem) scale(0.45);
            }

            18% {
                opacity: 0.94;
            }

            62% {
                opacity: 0.62;
            }

            100% {
                opacity: 0;
                transform: rotate(var(--particle-angle, 0deg)) translateY(var(--particle-distance, -6rem)) scale(0.2);
            }
        }

        @keyframes truthguard-verify-dot-matrix {
            0% {
                opacity: 0.22;
                background-position: 0 0, 0.34rem 0.18rem;
            }

            50% {
                opacity: 0.46;
            }

            100% {
                opacity: 0.22;
                background-position: 0.88rem 0.88rem, -0.5rem 0.9rem;
            }
        }

        @keyframes truthguard-verify-sweep {
            0% {
                opacity: 0;
                transform: translateX(-145%) rotate(18deg);
            }

            40%,
            62% {
                opacity: 0.58;
            }

            100% {
                opacity: 0;
                transform: translateX(145%) rotate(18deg);
            }
        }

        @keyframes truthguard-verify-meter {
            0% {
                transform: translateX(-110%) scaleX(0.42);
                transform-origin: left center;
            }

            45% {
                transform: translateX(35%) scaleX(1);
                transform-origin: center;
            }

            100% {
                transform: translateX(225%) scaleX(0.46);
                transform-origin: right center;
            }
        }

        @keyframes truthguard-verify-live {
            0%,
            100% {
                opacity: 0.45;
                transform: scale(0.86);
            }

            50% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes truthguard-verify-source-swap {
            0%,
            13% {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }

            22%,
            100% {
                opacity: 0;
                transform: translateY(-0.65rem) scale(0.78);
                filter: blur(5px);
            }
        }

        @keyframes truthguard-scan-card-float {
            0%,
            100% {
                transform: translate3d(0, 0, 0);
            }

            50% {
                transform: translate3d(0, -0.26rem, 0);
            }
        }

        @keyframes truthguard-scan-card-glow {
            0%,
            100% {
                opacity: 0.42;
                transform: translateX(-32%) scaleX(0.62);
            }

            50% {
                opacity: 0.9;
                transform: translateX(42%) scaleX(1);
            }
        }

        @keyframes truthguard-scan-blur-pass {
            0% {
                opacity: 0;
                transform: translate3d(-42%, -18%, 0) rotate(-18deg) scale(0.72);
            }

            22%,
            68% {
                opacity: 0.72;
            }

            100% {
                opacity: 0;
                transform: translate3d(48%, 22%, 0) rotate(-18deg) scale(1.08);
            }
        }

        @keyframes truthguard-scan-lens-blur {
            0%,
            100% {
                opacity: 0.42;
                transform: scale(0.92);
            }

            50% {
                opacity: 0.86;
                transform: scale(1.1);
            }
        }

        @keyframes truthguard-scan-status-pulse {
            0%,
            100% {
                opacity: 0.46;
                transform: scale(0.72);
            }

            50% {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes truthguard-scan-status-sheen {
            0% {
                transform: translateX(-135%);
            }

            100% {
                transform: translateX(135%);
            }
        }

        @keyframes truthguard-scan-ai-breathe {
            0%,
            100% {
                transform: translateY(0) scale(1);
                box-shadow: 0 14px 30px rgba(14, 165, 233, 0.16), 0 0 0 5px rgba(14, 165, 233, 0.08);
            }

            50% {
                transform: translateY(-0.12rem) scale(1.04);
                box-shadow: 0 18px 38px rgba(14, 165, 233, 0.24), 0 0 0 7px rgba(129, 140, 248, 0.1);
            }
        }

        @keyframes truthguard-scan-ai-ring {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes truthguard-ai-sparkle-breathe {
            0%,
            100% {
                filter: drop-shadow(0 0 7px rgba(14, 165, 233, 0.3));
                transform: rotate(-3deg) scale(0.94);
            }

            50% {
                filter: drop-shadow(0 0 16px rgba(99, 102, 241, 0.42));
                transform: rotate(5deg) scale(1.08);
            }
        }

        @keyframes truthguard-ai-sparkle-pop {
            0%,
            100% {
                opacity: 0.34;
                transform: scale(0.68);
            }

            50% {
                opacity: 1;
                transform: scale(1.12);
            }
        }

        @keyframes truthguard-scan-neural-flow {
            to {
                stroke-dashoffset: -48;
            }
        }

        @keyframes truthguard-scan-neural-node {
            0%,
            100% {
                opacity: 0.35;
                transform: scale(0.8);
            }

            50% {
                opacity: 1;
                transform: scale(1.2);
            }
        }

        @keyframes truthguard-scan-line-run {
            0%,
            100% {
                opacity: 0.4;
                transform: translateX(-0.18rem);
            }

            50% {
                opacity: 1;
                transform: translateX(0.28rem);
            }
        }

        @keyframes truthguard-scan-glass-move {
            0%,
            100% {
                transform: translate3d(-0.18rem, -0.12rem, 0) rotate(-4deg) scale(0.98);
            }

            45% {
                transform: translate3d(0.46rem, 0.2rem, 0) rotate(5deg) scale(1.04);
            }

            72% {
                transform: translate3d(0.08rem, 0.42rem, 0) rotate(1deg) scale(1);
            }
        }

        @keyframes truthguard-scan-data-dot {
            0% {
                opacity: 0;
                transform: translate3d(0.65rem, 0, 0) scale(0.55);
            }

            28% {
                opacity: 0.8;
            }

            100% {
                opacity: 0;
                transform: translate3d(-2.8rem, var(--dot-y, 0), 0) scale(0.25);
            }
        }

        @keyframes truthguard-scan-spark {
            0%,
            100% {
                opacity: 0;
                transform: translate3d(var(--spark-x, 0), var(--spark-y, 0), 0) scale(0.45);
            }

            42% {
                opacity: 0.9;
                transform: translate3d(calc(var(--spark-x, 0) * 0.62), calc(var(--spark-y, 0) * 0.62), 0) scale(1);
            }
        }

        @keyframes truthguard-verify-magnifier-scan {
            0% {
                opacity: 0;
                transform: translate3d(-4.9rem, -3.2rem, 0) rotate(-12deg) scale(0.9);
            }

            10%,
            24% {
                opacity: 1;
                transform: translate3d(-2.1rem, -1.35rem, 0) rotate(-9deg) scale(1);
            }

            42% {
                opacity: 1;
                transform: translate3d(1.85rem, 0.05rem, 0) rotate(7deg) scale(1.03);
            }

            64% {
                opacity: 1;
                transform: translate3d(-0.9rem, 2.05rem, 0) rotate(-5deg) scale(1);
            }

            84% {
                opacity: 1;
                transform: translate3d(2.85rem, -2.4rem, 0) rotate(12deg) scale(0.96);
            }

            100% {
                opacity: 0;
                transform: translate3d(4.6rem, -3.35rem, 0) rotate(14deg) scale(0.86);
            }
        }

        @keyframes truthguard-verify-magnifier-glint {
            0%,
            100% {
                opacity: 0.34;
                transform: translateX(-44%) rotate(-18deg);
            }

            50% {
                opacity: 0.95;
                transform: translateX(46%) rotate(-18deg);
            }
        }

        @keyframes truthguard-verify-signal-drift {
            0%,
            100% {
                opacity: 0.28;
                transform: translateY(0);
            }

            50% {
                opacity: 0.86;
                transform: translateY(-0.24rem);
            }
        }

        @keyframes truthguard-verify-ai-glow {
            0% {
                opacity: 0.16;
                transform: rotate(0deg) scale(0.94);
            }

            50% {
                opacity: 0.42;
                transform: rotate(180deg) scale(1.04);
            }

            100% {
                opacity: 0.16;
                transform: rotate(360deg) scale(0.94);
            }
        }

        @keyframes truthguard-verify-glitter-pop {
            0%,
            100% {
                opacity: 0;
                transform: translate3d(var(--spark-x, 0), var(--spark-y, 0), 0) scale(0.35) rotate(0deg);
            }

            22% {
                opacity: 0.95;
                transform: translate3d(var(--spark-x, 0), var(--spark-y, 0), 0) scale(1.15) rotate(75deg);
            }

            48% {
                opacity: 0.42;
                transform: translate3d(calc(var(--spark-x, 0) * 1.05), calc(var(--spark-y, 0) * 1.05), 0) scale(0.78) rotate(130deg);
            }
        }

        .truthguard-verify-backdrop {
            background:
                radial-gradient(circle at 16% 14%, rgba(14, 165, 233, 0.25), transparent 34%),
                radial-gradient(circle at 86% 78%, rgba(99, 102, 241, 0.2), transparent 38%),
                linear-gradient(180deg, rgba(248, 251, 255, 0.96) 0%, rgba(239, 246, 255, 0.96) 52%, rgba(245, 243, 255, 0.94) 100%);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
        }

        .truthguard-verify-backdrop::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.3;
            background-image:
                linear-gradient(rgba(14, 165, 233, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(99, 102, 241, 0.08) 1px, transparent 1px);
            background-size: 36px 36px;
            animation: truthguard-verify-grid-flow 14s linear infinite;
            mask-image: radial-gradient(circle at center, #000 0%, transparent 72%);
            pointer-events: none;
        }

        .truthguard-verify-social-field {
            position: absolute;
            inset: 0;
            z-index: 1;
            overflow: hidden;
            pointer-events: none;
        }

        .truthguard-verify-social-pop {
            position: absolute;
            display: inline-flex;
            height: var(--icon-size, 3.1rem);
            width: var(--icon-size, 3.1rem);
            align-items: center;
            justify-content: center;
            opacity: 0;
            color: var(--icon-color, #2563eb);
            filter: drop-shadow(0 16px 28px rgba(15, 23, 42, 0.12));
            animation: truthguard-verify-social-pop var(--duration, 7s) ease-in-out infinite;
            animation-delay: var(--delay, 0s);
        }

        .truthguard-verify-social-pop svg,
        .truthguard-verify-social-pop img {
            display: block;
            height: 100%;
            width: 100%;
            object-fit: contain;
        }

        .truthguard-verify-social-pop:nth-child(1) {
            left: 9%;
            top: 12%;
        }

        .truthguard-verify-social-pop:nth-child(2) {
            right: 12%;
            top: 16%;
        }

        .truthguard-verify-social-pop:nth-child(3) {
            left: 16%;
            top: 38%;
        }

        .truthguard-verify-social-pop:nth-child(4) {
            left: 13%;
            bottom: 16%;
        }

        .truthguard-verify-social-pop:nth-child(5) {
            right: 15%;
            bottom: 18%;
        }

        .truthguard-verify-panel {
            width: min(30rem, calc(100vw - 2rem));
            padding: 0;
        }

        .truthguard-verify-loader {
            position: relative;
            display: flex;
            height: 17rem;
            width: 20.5rem;
            align-items: center;
            justify-content: center;
            isolation: isolate;
        }

        .truthguard-verify-loader::before,
        .truthguard-verify-loader::after {
            content: '';
            position: absolute;
            border-radius: 9999px;
            pointer-events: none;
        }

        .truthguard-verify-loader::before {
            inset: 0.1rem -0.8rem 0.2rem;
            z-index: 1;
            background:
                radial-gradient(circle at 66% 62%, rgba(34, 211, 238, 0.52), transparent 42%),
                radial-gradient(circle at 30% 38%, rgba(96, 165, 250, 0.38), transparent 46%),
                radial-gradient(circle at 50% 80%, rgba(167, 139, 250, 0.28), transparent 42%);
            filter: blur(22px);
            animation: truthguard-verify-aura 3.8s ease-in-out infinite;
        }

        .truthguard-verify-loader::after {
            left: 3rem;
            right: 2.15rem;
            bottom: 1.25rem;
            z-index: 0;
            height: 5.4rem;
            border-radius: 45%;
            background: linear-gradient(90deg, rgba(14, 165, 233, 0.28), rgba(129, 140, 248, 0.22), rgba(255, 255, 255, 0.18));
            filter: blur(24px);
            opacity: 0.72;
            animation: truthguard-verify-aura 4.4s ease-in-out infinite;
        }

        .truthguard-scan-document {
            position: relative;
            z-index: 5;
            height: 9.65rem;
            width: 13.55rem;
            overflow: hidden;
            border-radius: 1.24rem;
            border: 1px solid rgba(14, 165, 233, 0.24);
            background:
                radial-gradient(circle at 76% 18%, rgba(103, 232, 249, 0.48), transparent 32%),
                radial-gradient(circle at 8% 100%, rgba(199, 210, 254, 0.7), transparent 42%),
                linear-gradient(160deg, rgba(255, 255, 255, 0.96) 0%, rgba(239, 249, 255, 0.97) 52%, rgba(238, 242, 255, 0.96) 100%);
            box-shadow:
                0 34px 72px rgba(14, 165, 233, 0.2),
                0 0 48px rgba(96, 165, 250, 0.16),
                inset 0 1px 0 rgba(255, 255, 255, 0.92),
                inset 0 -1px 0 rgba(14, 165, 233, 0.18);
            animation: truthguard-scan-card-float 3.9s ease-in-out infinite;
        }

        .truthguard-scan-blur-haze {
            position: absolute;
            inset: -34%;
            z-index: 2;
            background:
                linear-gradient(105deg, transparent 24%, rgba(255, 255, 255, 0.7) 42%, rgba(103, 232, 249, 0.38) 52%, rgba(167, 139, 250, 0.22) 62%, transparent 78%);
            filter: blur(18px);
            mix-blend-mode: screen;
            pointer-events: none;
            animation: truthguard-scan-blur-pass 3.35s ease-in-out infinite;
        }

        .truthguard-scan-neural-map {
            position: absolute;
            inset: 0;
            z-index: 2;
            height: 100%;
            width: 100%;
            opacity: 0.68;
            pointer-events: none;
        }

        .truthguard-scan-neural-link {
            fill: none;
            stroke: rgba(14, 165, 233, 0.34);
            stroke-width: 1.6;
            stroke-linecap: round;
            stroke-dasharray: 8 12;
            animation: truthguard-scan-neural-flow 2.6s linear infinite;
        }

        .truthguard-scan-neural-link:nth-child(2) {
            stroke: rgba(99, 102, 241, 0.26);
            animation-duration: 3.1s;
            animation-direction: reverse;
        }

        .truthguard-scan-neural-link:nth-child(3) {
            stroke: rgba(6, 182, 212, 0.22);
            animation-duration: 3.8s;
        }

        .truthguard-scan-neural-node {
            fill: rgba(6, 182, 212, 0.72);
            filter: drop-shadow(0 0 8px rgba(14, 165, 233, 0.38));
            transform-box: fill-box;
            transform-origin: center;
            animation: truthguard-scan-neural-node 1.8s ease-in-out infinite;
        }

        .truthguard-scan-neural-node:nth-of-type(2) {
            animation-delay: 0.28s;
        }

        .truthguard-scan-neural-node:nth-of-type(3) {
            animation-delay: 0.56s;
        }

        .truthguard-scan-neural-node:nth-of-type(4) {
            animation-delay: 0.84s;
        }

        .truthguard-scan-ai-logo {
            position: absolute;
            right: 1.08rem;
            top: 1.1rem;
            z-index: 8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 2.64rem;
            width: 2.64rem;
            overflow: hidden;
            border-radius: 9999px;
            border: 1px solid rgba(14, 165, 233, 0.22);
            background:
                radial-gradient(circle at 38% 30%, rgba(255, 255, 255, 0.98), rgba(224, 242, 254, 0.8) 48%, rgba(238, 242, 255, 0.72) 100%);
            color: #0f172a;
            padding: 0;
            animation: truthguard-scan-ai-breathe 2.6s ease-in-out infinite;
        }

        .truthguard-scan-ai-logo::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: inherit;
            background: conic-gradient(from 0deg, rgba(6, 182, 212, 0.85), transparent 32%, rgba(99, 102, 241, 0.72), transparent 68%, rgba(14, 165, 233, 0.78));
            opacity: 0.44;
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            padding: 1px;
            pointer-events: none;
            animation: truthguard-scan-ai-ring 4.2s linear infinite;
        }

        .truthguard-scan-ai-logo::after {
            content: '';
            position: absolute;
            inset: 0.42rem;
            z-index: 1;
            border-radius: inherit;
            background: radial-gradient(circle, rgba(103, 232, 249, 0.34), rgba(167, 139, 250, 0.2) 46%, transparent 74%);
            filter: blur(8px);
            animation: truthguard-scan-lens-blur 2.5s ease-in-out infinite;
            pointer-events: none;
        }

        .truthguard-scan-ai-logo svg {
            position: relative;
            z-index: 2;
            height: 1.54rem;
            width: 1.54rem;
        }

        .truthguard-ai-sparkle-mark {
            display: block;
            overflow: visible;
            position: relative;
            z-index: 2;
        }

        .truthguard-ai-sparkle-main {
            transform-box: fill-box;
            transform-origin: center;
            animation: truthguard-ai-sparkle-breathe 2.2s ease-in-out infinite;
        }

        .truthguard-ai-sparkle-small {
            transform-box: fill-box;
            transform-origin: center;
            animation: truthguard-ai-sparkle-pop 1.7s ease-in-out infinite;
        }

        .truthguard-ai-sparkle-small-b {
            animation-delay: 0.34s;
        }

        .truthguard-ai-sparkle-small-c {
            animation-delay: 0.68s;
        }

        .truthguard-scan-document::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, rgba(14, 165, 233, 0.13) 1px, transparent 1px),
                linear-gradient(rgba(99, 102, 241, 0.08) 1px, transparent 1px);
            background-size: 1.24rem 1.24rem;
            opacity: 0.42;
            pointer-events: none;
        }

        .truthguard-scan-document::after {
            content: '';
            position: absolute;
            left: 1.55rem;
            right: 1.35rem;
            bottom: 1.14rem;
            height: 0.18rem;
            border-radius: 9999px;
            background: linear-gradient(90deg, transparent, rgba(6, 182, 212, 0.98), rgba(59, 130, 246, 0.92), rgba(124, 58, 237, 0.68), transparent);
            box-shadow: 0 0 24px rgba(14, 165, 233, 0.46);
            animation: truthguard-scan-card-glow 1.9s ease-in-out infinite;
            pointer-events: none;
        }

        .truthguard-scan-doc-logo {
            position: absolute;
            left: 1.42rem;
            top: 1.32rem;
            z-index: 4;
            display: grid;
            height: 3rem;
            width: 3rem;
            place-items: center;
            overflow: hidden;
            border-radius: 9999px;
            border: 1px solid rgba(14, 165, 233, 0.28);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 0 0 6px rgba(14, 165, 233, 0.08), 0 0 30px rgba(14, 165, 233, 0.24);
        }

        .truthguard-scan-doc-logo .truthguard-verify-source-logo {
            height: 2.02rem;
            width: 2.02rem;
            border-radius: 9999px;
            font-size: 0.76rem;
            letter-spacing: 0;
            box-shadow: none;
        }

        .truthguard-scan-doc-logo .truthguard-verify-source-logo svg,
        .truthguard-scan-doc-logo .truthguard-verify-source-logo img {
            height: 1.48rem;
            width: 1.48rem;
        }

        .truthguard-scan-doc-logo .truthguard-verify-source-logo-wide {
            width: 2.3rem;
            border-radius: 0.56rem;
            font-size: 0.52rem;
            letter-spacing: 0.06em;
        }

        .truthguard-scan-doc-logo .truthguard-verify-source-logo-brand img {
            height: 1.72rem;
            width: 1.72rem;
        }

        .truthguard-scan-doc-lines {
            position: absolute;
            left: 5.34rem;
            right: 1.5rem;
            top: 1.68rem;
            z-index: 3;
            display: grid;
            gap: 0.76rem;
        }

        .truthguard-scan-doc-lines span {
            display: block;
            height: 0.42rem;
            border-radius: 9999px;
            background: linear-gradient(90deg, rgba(37, 99, 235, 0.2), rgba(6, 182, 212, 0.9), rgba(99, 102, 241, 0.28));
            box-shadow: 0 0 18px rgba(14, 165, 233, 0.22);
            animation: truthguard-scan-line-run 1.9s ease-in-out infinite;
        }

        .truthguard-scan-doc-lines span:nth-child(2) {
            width: 82%;
            animation-delay: 0.22s;
        }

        .truthguard-scan-doc-lines span:nth-child(3) {
            width: 64%;
            animation-delay: 0.44s;
        }

        .truthguard-scan-doc-lines span:nth-child(4) {
            width: 74%;
            animation-delay: 0.66s;
        }

        .truthguard-scan-status {
            position: absolute;
            left: 1.36rem;
            bottom: 1.78rem;
            z-index: 6;
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            overflow: hidden;
            border-radius: 9999px;
            border: 1px solid rgba(14, 165, 233, 0.2);
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.86), rgba(224, 242, 254, 0.92), rgba(238, 242, 255, 0.86));
            box-shadow: 0 12px 26px rgba(14, 165, 233, 0.13), inset 0 1px 0 rgba(255, 255, 255, 0.84);
            color: #0e7490;
            font-size: 0.62rem;
            font-weight: 900;
            letter-spacing: 0.14em;
            line-height: 1;
            padding: 0.5rem 0.76rem;
            text-transform: uppercase;
        }

        .truthguard-scan-status::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.76), transparent);
            opacity: 0.72;
            pointer-events: none;
            transform: translateX(-135%);
            animation: truthguard-scan-status-sheen 1.8s ease-in-out infinite;
        }

        .truthguard-scan-status span {
            position: relative;
            z-index: 2;
            height: 0.44rem;
            width: 0.44rem;
            border-radius: 9999px;
            background: #06b6d4;
            box-shadow: 0 0 0 5px rgba(14, 165, 233, 0.12), 0 0 18px rgba(14, 165, 233, 0.36);
            animation: truthguard-scan-status-pulse 1.08s ease-in-out infinite;
        }

        .truthguard-scan-status strong {
            position: relative;
            z-index: 2;
            font: inherit;
        }

        .truthguard-scan-data {
            position: absolute;
            left: 1rem;
            top: 7.05rem;
            z-index: 3;
            height: 3.9rem;
            width: 6.7rem;
            pointer-events: none;
        }

        .truthguard-scan-data span {
            position: absolute;
            right: 0;
            top: var(--dot-top, 50%);
            height: var(--dot-size, 0.18rem);
            width: var(--dot-size, 0.18rem);
            border-radius: 9999px;
            background: rgba(6, 182, 212, 0.96);
            box-shadow: 0 0 14px rgba(14, 165, 233, 0.44), 0 0 26px rgba(99, 102, 241, 0.14);
            animation: truthguard-scan-data-dot var(--dot-speed, 1.9s) linear infinite;
            animation-delay: var(--dot-delay, 0s);
        }

        .truthguard-scan-magnifier {
            position: absolute;
            right: 0.52rem;
            bottom: 0.82rem;
            z-index: 12;
            height: 7.5rem;
            width: 7.5rem;
            color: #0891b2;
            filter: drop-shadow(0 22px 36px rgba(8, 145, 178, 0.34)) drop-shadow(0 0 24px rgba(34, 211, 238, 0.2));
            pointer-events: none;
            animation: truthguard-scan-glass-move 3.5s ease-in-out infinite;
        }

        .truthguard-scan-magnifier::before {
            content: '';
            position: absolute;
            inset: 0.5rem;
            border-radius: 9999px;
            background: radial-gradient(circle, rgba(103, 232, 249, 0.38), rgba(191, 219, 254, 0.18) 48%, transparent 72%);
            filter: blur(10px);
            animation: truthguard-scan-lens-blur 2.4s ease-in-out infinite;
            pointer-events: none;
        }

        .truthguard-scan-magnifier svg {
            position: relative;
            z-index: 2;
            display: block;
            height: 100%;
            width: 100%;
        }

        .truthguard-scan-sparks {
            position: absolute;
            inset: 0;
            z-index: 14;
            pointer-events: none;
        }

        .truthguard-scan-sparks span {
            position: absolute;
            left: 50%;
            top: 50%;
            height: var(--spark-size, 0.34rem);
            width: var(--spark-size, 0.34rem);
            margin-left: calc(var(--spark-size, 0.34rem) / -2);
            margin-top: calc(var(--spark-size, 0.34rem) / -2);
            border-radius: 9999px;
            background: var(--spark-color, #67e8f9);
            box-shadow: 0 0 14px var(--spark-color, #67e8f9);
            animation: truthguard-scan-spark var(--spark-speed, 2.4s) ease-in-out infinite;
            animation-delay: var(--spark-delay, 0s);
        }

        .truthguard-verify-hologram {
            position: absolute;
            inset: -0.65rem;
            z-index: 2;
            border-radius: 9999px;
            pointer-events: none;
        }

        .truthguard-verify-hologram span {
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background:
                radial-gradient(circle, rgba(255, 255, 255, 0) 0 45%, rgba(103, 232, 249, 0.18) 56%, rgba(129, 140, 248, 0.12) 68%, transparent 78%);
            filter: blur(0.8px) drop-shadow(0 0 22px rgba(14, 165, 233, 0.24));
            opacity: 0;
            animation: truthguard-verify-hologram-wave 3.2s ease-out infinite;
            animation-delay: var(--wave-delay, 0s);
        }

        .truthguard-verify-ai-particles {
            position: absolute;
            inset: 0;
            z-index: 13;
            border-radius: 9999px;
            pointer-events: none;
        }

        .truthguard-verify-ai-particles span {
            position: absolute;
            left: 50%;
            top: 50%;
            height: var(--particle-size, 0.28rem);
            width: var(--particle-size, 0.28rem);
            margin-left: calc(var(--particle-size, 0.28rem) / -2);
            margin-top: calc(var(--particle-size, 0.28rem) / -2);
            border-radius: 9999px;
            background: var(--particle-color, #67e8f9);
            box-shadow: 0 0 12px var(--particle-color, #67e8f9), 0 0 24px rgba(255, 255, 255, 0.32);
            opacity: 0;
            animation: truthguard-verify-particle-drift var(--particle-speed, 2.6s) ease-out infinite;
            animation-delay: var(--particle-delay, 0s);
        }

        .truthguard-verify-ai-orbit {
            position: absolute;
            inset: 0.45rem;
            z-index: 4;
            border-radius: 9999px;
            pointer-events: none;
        }

        .truthguard-verify-ai-orbit::before {
            content: '';
            position: absolute;
            border-radius: inherit;
            pointer-events: none;
        }

        .truthguard-verify-ai-orbit::before {
            inset: 0;
            padding: 2px;
            background: conic-gradient(from 35deg, transparent 0deg 28deg, rgba(6, 182, 212, 0.98) 44deg 94deg, transparent 112deg 170deg, rgba(99, 102, 241, 0.92) 192deg 252deg, transparent 270deg 360deg);
            filter: drop-shadow(0 0 18px rgba(14, 165, 233, 0.4));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            animation: truthguard-verify-ai-spin 4.8s linear infinite;
        }

        .truthguard-verify-radar-sweep {
            position: absolute;
            inset: 1.8rem;
            z-index: 3;
            overflow: hidden;
            border-radius: 9999px;
            opacity: 0.62;
            pointer-events: none;
            -webkit-mask-image: radial-gradient(circle, transparent 0 31%, #000 32% 76%, transparent 77%);
            mask-image: radial-gradient(circle, transparent 0 31%, #000 32% 76%, transparent 77%);
        }

        .truthguard-verify-radar-sweep::before {
            content: '';
            position: absolute;
            inset: -16%;
            background: conic-gradient(from 0deg, rgba(103, 232, 249, 0.44), transparent 18%, transparent 72%, rgba(129, 140, 248, 0.22), transparent 100%);
            animation: truthguard-verify-radar-turn 3.7s linear infinite;
        }

        .truthguard-verify-ai-node {
            position: absolute;
            z-index: 5;
            height: 0.48rem;
            width: 0.48rem;
            border-radius: 9999px;
            background: #67e8f9;
            box-shadow: 0 0 0 5px rgba(103, 232, 249, 0.13), 0 0 18px rgba(14, 165, 233, 0.45);
            animation: truthguard-verify-ai-node 1.8s ease-in-out infinite;
        }

        .truthguard-verify-ai-node:nth-child(1) {
            left: 50%;
            top: -0.16rem;
            margin-left: -0.24rem;
        }

        .truthguard-verify-ai-node:nth-child(2) {
            right: 0.85rem;
            top: 1.55rem;
            animation-delay: 0.3s;
        }

        .truthguard-verify-ai-node:nth-child(3) {
            bottom: 1.55rem;
            right: 0.85rem;
            animation-delay: 0.6s;
        }

        .truthguard-verify-ai-node:nth-child(4) {
            bottom: -0.16rem;
            left: 50%;
            margin-left: -0.24rem;
            animation-delay: 0.9s;
        }

        .truthguard-verify-ai-node:nth-child(5) {
            bottom: 1.55rem;
            left: 0.85rem;
            animation-delay: 1.2s;
        }

        .truthguard-verify-ai-node:nth-child(6) {
            left: 0.85rem;
            top: 1.55rem;
            animation-delay: 1.5s;
        }

        .truthguard-verify-logo {
            position: relative;
            z-index: 6;
            display: flex;
            height: 5.85rem;
            width: 5.85rem;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 9999px;
            background:
                radial-gradient(circle at 50% 45%, rgba(255, 255, 255, 0.94), rgba(224, 242, 254, 0.64) 62%, rgba(199, 210, 254, 0.28) 100%);
            box-shadow: 0 22px 58px rgba(37, 99, 235, 0.16), inset 0 0 30px rgba(255, 255, 255, 0.68);
            animation: truthguard-verify-core-float 4.2s ease-in-out infinite;
        }

        .truthguard-verify-logo-stage {
            position: relative;
            z-index: 2;
            display: grid;
            height: 3.85rem;
            width: 3.85rem;
            place-items: center;
            overflow: hidden;
            border-radius: 9999px;
        }

        .truthguard-verify-logo-stage::before {
            content: '';
            position: absolute;
            inset: 0.4rem;
            border-radius: inherit;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.92), rgba(224, 242, 254, 0.34) 58%, transparent 72%);
            opacity: 0.84;
            pointer-events: none;
        }

        .truthguard-verify-logo-stage::after {
            content: '';
            position: absolute;
            inset: 0.22rem;
            z-index: 1;
            border-radius: inherit;
            background-image:
                radial-gradient(circle, rgba(6, 182, 212, 0.42) 0 1px, transparent 1.7px),
                radial-gradient(circle, rgba(129, 140, 248, 0.34) 0 1px, transparent 1.8px);
            background-size: 0.88rem 0.88rem, 1.16rem 1.16rem;
            background-position: 0 0, 0.34rem 0.18rem;
            mix-blend-mode: screen;
            pointer-events: none;
            animation: truthguard-verify-dot-matrix 3.6s linear infinite;
        }

        .truthguard-verify-source-logo {
            position: absolute;
            z-index: 2;
            display: inline-flex;
            height: 2.7rem;
            width: 2.7rem;
            align-items: center;
            justify-content: center;
            border-radius: 9999px;
            background: var(--source-bg, #fff);
            color: var(--source-color, #0f172a);
            font-size: 0.92rem;
            font-weight: 900;
            letter-spacing: -0.02em;
            opacity: 0;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.1);
            animation: truthguard-verify-source-swap 8.4s ease-in-out infinite;
            animation-delay: var(--source-delay, 0s);
        }

        .truthguard-verify-source-logo svg,
        .truthguard-verify-source-logo img {
            height: 1.42rem;
            width: 1.42rem;
        }

        .truthguard-verify-source-logo-wide {
            width: 3.05rem;
            font-size: 0.62rem;
            letter-spacing: 0.08em;
        }

        .truthguard-verify-source-logo-brand {
            height: 3.15rem;
            width: 3.15rem;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 16px 34px rgba(37, 99, 235, 0.13), inset 0 0 18px rgba(224, 242, 254, 0.72);
        }

        .truthguard-verify-source-logo-brand img {
            height: 2.5rem;
            width: 2.5rem;
            object-fit: contain;
        }

        .truthguard-verify-magnifier {
            position: absolute;
            left: 50%;
            top: 50%;
            z-index: 12;
            height: 4.2rem;
            width: 4.2rem;
            margin-left: -2.1rem;
            margin-top: -2.1rem;
            color: #0891b2;
            pointer-events: none;
            filter: drop-shadow(0 18px 24px rgba(8, 145, 178, 0.28));
            animation: truthguard-verify-magnifier-scan 4.6s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        .truthguard-verify-magnifier svg {
            display: block;
            height: 100%;
            width: 100%;
        }

        .truthguard-verify-magnifier::before {
            content: '';
            position: absolute;
            left: 0.62rem;
            top: 0.58rem;
            height: 2.28rem;
            width: 2.28rem;
            border-radius: 9999px;
            background: radial-gradient(circle at 36% 34%, rgba(255, 255, 255, 0.72), rgba(34, 211, 238, 0.2) 46%, transparent 70%);
            mix-blend-mode: screen;
            pointer-events: none;
        }

        .truthguard-verify-magnifier::after {
            content: '';
            position: absolute;
            left: 0.85rem;
            top: 0.62rem;
            height: 2.1rem;
            width: 0.35rem;
            border-radius: 9999px;
            background: linear-gradient(180deg, transparent, rgba(255, 255, 255, 0.85), transparent);
            pointer-events: none;
            animation: truthguard-verify-magnifier-glint 1.45s ease-in-out infinite;
        }

        .truthguard-verify-ai-glitter {
            position: absolute;
            inset: 0.35rem;
            z-index: 14;
            border-radius: 9999px;
            pointer-events: none;
        }

        .truthguard-verify-ai-glitter::before {
            content: '';
            position: absolute;
            inset: 1.05rem;
            border-radius: inherit;
            background: conic-gradient(from 0deg, transparent 0deg 28deg, rgba(103, 232, 249, 0.34) 42deg, transparent 72deg, rgba(167, 139, 250, 0.32) 136deg, transparent 176deg, rgba(255, 255, 255, 0.52) 214deg, transparent 250deg, rgba(14, 165, 233, 0.26) 316deg, transparent 360deg);
            filter: blur(0.5px) drop-shadow(0 0 16px rgba(14, 165, 233, 0.28));
            -webkit-mask-image: radial-gradient(circle, transparent 0 46%, #000 47% 72%, transparent 73%);
            mask-image: radial-gradient(circle, transparent 0 46%, #000 47% 72%, transparent 73%);
            animation: truthguard-verify-ai-glow 5.6s linear infinite;
        }

        .truthguard-verify-ai-glitter span {
            position: absolute;
            left: 50%;
            top: 50%;
            height: var(--spark-size, 0.44rem);
            width: var(--spark-size, 0.44rem);
            margin-left: calc(var(--spark-size, 0.44rem) / -2);
            margin-top: calc(var(--spark-size, 0.44rem) / -2);
            opacity: 0;
            color: var(--spark-color, #67e8f9);
            animation: truthguard-verify-glitter-pop var(--spark-speed, 2.7s) ease-in-out infinite;
            animation-delay: var(--spark-delay, 0s);
        }

        .truthguard-verify-ai-glitter span::before,
        .truthguard-verify-ai-glitter span::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 9999px;
            background: currentColor;
            box-shadow: 0 0 12px currentColor, 0 0 24px rgba(255, 255, 255, 0.4);
        }

        .truthguard-verify-ai-glitter span::before {
            transform: scaleX(0.22);
        }

        .truthguard-verify-ai-glitter span::after {
            transform: scaleY(0.22);
        }

        .truthguard-verify-logo::before {
            content: '';
            position: absolute;
            inset: 0.42rem;
            border-radius: inherit;
            background: conic-gradient(from 120deg, rgba(103, 232, 249, 0.28), transparent 22%, rgba(129, 140, 248, 0.26), transparent 58%, rgba(34, 211, 238, 0.24), transparent);
            filter: blur(8px);
            animation: truthguard-verify-core-breathe 2.8s ease-in-out infinite;
            pointer-events: none;
        }

        .truthguard-verify-logo::after {
            content: '';
            position: absolute;
            top: -24%;
            bottom: -24%;
            left: 44%;
            width: 2.4rem;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.76), transparent);
            filter: blur(1px);
            animation: truthguard-verify-sweep 2.9s ease-in-out infinite;
            pointer-events: none;
        }

        .truthguard-verify-logo img {
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 12px 22px rgba(37, 99, 235, 0.18));
        }

        .truthguard-verify-progress {
            position: relative;
            height: 0.36rem;
            width: min(19rem, 76vw);
            overflow: hidden;
            border-radius: 9999px;
            background: linear-gradient(90deg, rgba(224, 242, 254, 0.36), rgba(219, 234, 254, 0.74), rgba(238, 242, 255, 0.44));
            box-shadow: inset 0 1px 2px rgba(148, 163, 184, 0.12), 0 10px 26px rgba(14, 165, 233, 0.12);
        }

        .truthguard-verify-progress::after {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 48%;
            border-radius: inherit;
            background: linear-gradient(90deg, transparent 0%, #67e8f9 16%, #60a5fa 52%, #a78bfa 82%, transparent 100%);
            box-shadow: 0 0 18px rgba(14, 165, 233, 0.42), 0 0 26px rgba(99, 102, 241, 0.2);
            animation: truthguard-verify-meter 1.65s ease-in-out infinite;
        }

        .truthguard-verify-title {
            max-width: min(24rem, calc(100vw - 2rem));
            text-align: center;
            color: #0f172a;
            font-size: 1.2rem;
            font-weight: 800;
            line-height: 1.32;
        }

        .truthguard-verify-copy {
            display: none;
        }

        @media (max-width: 639px) {
            .truthguard-verify-overlay {
                inset: 0 !important;
                align-items: center;
                padding: calc(1rem + env(safe-area-inset-top, 0px)) 0.85rem calc(1.15rem + env(safe-area-inset-bottom, 0px));
            }

            .truthguard-verify-backdrop {
                background:
                    radial-gradient(circle at 20% 14%, rgba(125, 211, 252, 0.34), transparent 32%),
                    radial-gradient(circle at 82% 74%, rgba(196, 181, 253, 0.24), transparent 38%),
                    linear-gradient(180deg, rgba(248, 251, 255, 0.98) 0%, rgba(239, 246, 255, 0.98) 58%, rgba(245, 243, 255, 0.97) 100%);
                backdrop-filter: blur(16px) saturate(1.08);
                -webkit-backdrop-filter: blur(16px) saturate(1.08);
            }

            .truthguard-verify-social-field {
                opacity: 0.42;
            }

            .truthguard-verify-panel {
                width: min(21.25rem, calc(100vw - 1.7rem));
                min-height: auto;
                border: 0;
                border-radius: 0;
                background: transparent;
                padding: 0;
                box-shadow: none;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .truthguard-verify-loader {
                width: min(18.4rem, 100%);
                height: 14.8rem;
            }

            .truthguard-verify-panel .mt-5 {
                margin-top: 0.55rem !important;
                gap: 0.58rem;
            }

            .truthguard-verify-title {
                max-width: min(17.5rem, 100%);
                font-size: 0.9rem;
                font-weight: 900;
                line-height: 1.28;
            }

            .truthguard-verify-copy {
                display: block;
                max-width: min(18rem, 100%);
                text-align: center;
                color: #64748b;
                font-size: 0.72rem;
                font-weight: 700;
                line-height: 1.45;
            }

            .truthguard-verify-progress {
                width: min(13.5rem, 72vw);
                height: 0.34rem;
                opacity: 1;
            }
        }

        @media (min-width: 640px) {
            .truthguard-verify-panel {
                width: min(36rem, calc(100vw - 2rem));
            }

            .truthguard-verify-loader {
                height: 23rem;
                width: 29rem;
            }

            .truthguard-scan-document {
                height: 13rem;
                width: 18.25rem;
                border-radius: 1.55rem;
            }

            .truthguard-scan-doc-logo {
                left: 1.9rem;
                top: 1.72rem;
                height: 4rem;
                width: 4rem;
            }

            .truthguard-scan-doc-logo .truthguard-verify-source-logo {
                height: 2.72rem;
                width: 2.72rem;
                font-size: 0.98rem;
            }

            .truthguard-scan-doc-logo .truthguard-verify-source-logo svg,
            .truthguard-scan-doc-logo .truthguard-verify-source-logo img {
                height: 2rem;
                width: 2rem;
            }

            .truthguard-scan-doc-logo .truthguard-verify-source-logo-wide {
                width: 3.1rem;
                border-radius: 0.72rem;
                font-size: 0.68rem;
            }

            .truthguard-scan-doc-logo .truthguard-verify-source-logo-brand img {
                height: 2.34rem;
                width: 2.34rem;
            }

            .truthguard-scan-doc-lines {
                left: 7.1rem;
                right: 2rem;
                top: 2.3rem;
                gap: 0.98rem;
            }

            .truthguard-scan-doc-lines span {
                height: 0.56rem;
            }

            .truthguard-scan-ai-logo {
                right: 1.52rem;
                top: 1.56rem;
                height: 3.52rem;
                width: 3.52rem;
            }

            .truthguard-scan-ai-logo svg {
                height: 2.22rem;
                width: 2.22rem;
            }

            .truthguard-scan-neural-map {
                opacity: 0.76;
            }

            .truthguard-scan-status {
                left: 1.82rem;
                bottom: 2.26rem;
                gap: 0.56rem;
                font-size: 0.78rem;
                padding: 0.66rem 1rem;
            }

            .truthguard-scan-status span {
                height: 0.56rem;
                width: 0.56rem;
            }

            .truthguard-scan-data {
                left: 1.3rem;
                top: 9.36rem;
                width: 8.8rem;
            }

            .truthguard-scan-magnifier {
                right: 0.95rem;
                bottom: 1rem;
                height: 10rem;
                width: 10rem;
            }

            .truthguard-verify-title {
                font-size: 1.32rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .truthguard-recent-section,
            .truthguard-recent-skeleton::after,
            .truthguard-workspace-hero,
            .truthguard-workspace-hero-glint,
            .truthguard-workspace-orb,
            .truthguard-agent-pill-icon,
            .truthguard-detection-composer,
            .truthguard-analyze-symbol::before,
            .truthguard-analyze-spinner,
            .truthguard-verify-backdrop::before,
            .truthguard-verify-social-pop,
            .truthguard-verify-loader::before,
            .truthguard-verify-loader::after,
            .truthguard-verify-hologram span,
            .truthguard-verify-ai-particles span,
            .truthguard-scan-document,
            .truthguard-scan-document::after,
            .truthguard-scan-blur-haze,
            .truthguard-scan-neural-link,
            .truthguard-scan-neural-node,
            .truthguard-scan-ai-logo,
            .truthguard-scan-ai-logo::before,
            .truthguard-scan-ai-logo::after,
            .truthguard-ai-sparkle-main,
            .truthguard-ai-sparkle-small,
            .truthguard-scan-doc-lines span,
            .truthguard-scan-status::after,
            .truthguard-scan-status span,
            .truthguard-scan-data span,
            .truthguard-scan-magnifier,
            .truthguard-scan-magnifier::before,
            .truthguard-scan-sparks span,
            .truthguard-verify-ai-orbit::before,
            .truthguard-verify-radar-sweep::before,
            .truthguard-verify-ai-node,
            .truthguard-verify-logo,
            .truthguard-verify-logo::before,
            .truthguard-verify-logo::after,
            .truthguard-verify-logo-stage::after,
            .truthguard-verify-source-logo,
            .truthguard-verify-magnifier,
            .truthguard-verify-magnifier::after,
            .truthguard-verify-ai-glitter::before,
            .truthguard-verify-ai-glitter span,
            .truthguard-verify-progress::after {
                animation: none !important;
            }

            .truthguard-detection-composer {
                transition: none !important;
            }
        }

        .truthguard-ripple {
            position: absolute;
            border-radius: 9999px;
            transform: scale(0);
            opacity: 0.5;
            background: rgba(255, 255, 255, 0.6);
            pointer-events: none;
            animation: truthguard-ripple 650ms ease-out forwards;
        }

        @keyframes truthguard-ripple {
            to {
                transform: scale(2.6);
                opacity: 0;
            }
        }
    </style>
@endonce

<div @class([
    'truthguard-detection-theme w-full',
    'truthguard-pre-result-workspace' => ! $activeDetection,
])>
    <section
        class="truthguard-detection-shell relative overflow-visible"
        x-data="{
            menuOpen: false,
            dragActive: false,
            submitting: false,
            previewUrl: null,
            previewName: null,
            previewKind: null,
            previewSizeLabel: null,
            previewExpanded: false,
            previewZoom: 1,
            pasteStatus: null,
            pasteStatusTimer: null,
            uploadMaxMb: @js($mediaUploadMaxMb),
            uploadMaxBytes: @js($mediaUploadMaxBytes),
            uploadError: @js($initialUploadError ?: null),
            composerValue: @js($composerDraft),
            composerMaxCharacters: @js($claimMaxCharacters),
            composerError: @js($initialComposerError ?: null),
            selectedInputType: @js($oldSourceUrl !== '' ? 'link' : null),
            hasFile: false,
            inputNudge: false,
            drivePicker: @js($googleDrivePicker),
            drivePickerLoading: false,
            drivePickerMessage: null,
            drivePickerError: null,
            driveAccessToken: null,
            driveTokenClient: null,
            locationStatus: 'idle',
            locationLat: null,
            locationLon: null,
            locationAccuracy: null,
            locationLabel: 'Current location',
            locationRequestInFlight: false,
            hasResult: @js((bool) $activeDetection),
            updateViewportOffset() {
                const header = document.querySelector('header');
                const main = document.querySelector('main');
                let offset = 0;

                if (header) {
                    offset += header.getBoundingClientRect().height;
                }

                if (main) {
                    const styles = window.getComputedStyle(main);
                    offset += parseFloat(styles.paddingTop || '0') + parseFloat(styles.paddingBottom || '0');
                }

                document.documentElement.style.setProperty('--tg-detection-offset', `${Math.ceil(offset)}px`);
            },
            updateScrollLock() {
                document.documentElement.classList.remove('truthguard-scroll-locked');
                document.body.classList.remove('truthguard-scroll-locked');
            },
            init() {
                this.updateViewportOffset();
                this.updateScrollLock();
                this.$nextTick(() => {
                    this.updateViewportOffset();
                    this.updateScrollLock();
                });

                if (this.drivePicker?.enabled) {
                    this.preloadGooglePicker();
                }

                window.addEventListener('resize', () => this.updateViewportOffset());
                this.$watch('hasFile', () => this.updateScrollLock());
            },
            createRipple(event) {
                const button = event.currentTarget;

                if (!button) {
                    return;
                }

                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height) * 1.2;
                const clientX = Number.isFinite(event.clientX) ? event.clientX : rect.left + rect.width / 2;
                const clientY = Number.isFinite(event.clientY) ? event.clientY : rect.top + rect.height / 2;
                const x = clientX - rect.left - size / 2;
                const y = clientY - rect.top - size / 2;

                const ripple = document.createElement('span');
                ripple.className = 'truthguard-ripple';
                ripple.style.width = `${size}px`;
                ripple.style.height = `${size}px`;
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;

                button.appendChild(ripple);

                window.setTimeout(() => {
                    ripple.remove();
                }, 700);
            },
            canAnalyze() {
                const hasEvidence = !!this.hasFile || ((this.composerValue || '').trim().length > 0);

                return hasEvidence
                    && ! this.isComposerOverLimit()
                    && ! (this.selectedInputType === 'link' && ! this.composerUrl());
            },
            analyzeButtonLabel() {
                return this.submitting ? 'Checking...' : 'Check claim';
            },
            composerCharacterCount() {
                return (this.composerValue || '').length;
            },
            isComposerOverLimit() {
                return this.composerCharacterCount() > this.composerMaxCharacters;
            },
            showCharacterCount() {
                return this.composerCharacterCount() >= Math.floor(this.composerMaxCharacters * 0.8);
            },
            composerUrl() {
                const match = (this.composerValue || '').match(/https?:\/\/[^\s<>]+/i);

                if (! match) {
                    return null;
                }

                try {
                    const url = new URL(match[0]);
                    return ['http:', 'https:'].includes(url.protocol) ? url : null;
                } catch (error) {
                    return null;
                }
            },
            composerDomain() {
                return this.composerUrl()?.hostname?.replace(/^www\./, '') || 'Source link';
            },
            composerUrlLabel() {
                const url = this.composerUrl();

                if (! url) {
                    return '';
                }

                const normalized = url.toString();
                return normalized.length > 72 ? `${normalized.slice(0, 69)}...` : normalized;
            },
            handleComposerInput(event) {
                this.composerValue = event.target.value;

                if (this.composerUrl()) {
                    this.selectedInputType = 'link';
                }

                if (this.isComposerOverLimit()) {
                    this.composerError = 'The claim exceeds the allowed length.';
                } else if (this.selectedInputType === 'link' && this.composerValue.trim() && ! this.composerUrl()) {
                    this.composerError = 'Enter a valid URL.';
                } else if (this.composerError) {
                    this.composerError = null;
                }

                this.resizeComposer();
            },
            selectInputType(type) {
                this.selectedInputType = type;
                this.composerError = null;

                if (type === 'image' || type === 'video') {
                    this.triggerPicker(type);
                    return;
                }

                this.focusComposer();
            },
            clearLinkPreview() {
                this.composerValue = (this.composerValue || '')
                    .replace(/https?:\/\/[^\s<>]+/i, '')
                    .trim();
                this.selectedInputType = null;
                this.composerError = null;

                if (this.$refs.composerInput) {
                    this.$refs.composerInput.value = this.composerValue;
                }

                this.$nextTick(() => {
                    this.resizeComposer();
                    this.$refs.composerInput?.focus();
                });
            },
            validateComposer() {
                if (! this.hasFile && ! (this.composerValue || '').trim()) {
                    this.composerError = 'Enter a claim or attach evidence.';
                    return false;
                }

                if (this.isComposerOverLimit()) {
                    this.composerError = 'The claim exceeds the allowed length.';
                    return false;
                }

                if (this.selectedInputType === 'link' && ! this.composerUrl()) {
                    this.composerError = 'Enter a valid URL.';
                    return false;
                }

                this.composerError = null;
                return true;
            },
            handleAnalyzeClick(event) {
                this.createRipple(event);

                if (this.submitting) {
                    return;
                }

                if (! this.validateComposer()) {
                    this.triggerInputNudge();
                    return;
                }

                const form = event.currentTarget?.form;

                if (!form) {
                    return;
                }

                this.menuOpen = false;
                this.previewExpanded = false;
                this.submitting = true;
                this.submitAfterLoaderPaint(form);
            },
            triggerInputNudge() {
                this.inputNudge = false;
                this.menuOpen = false;

                this.$nextTick(() => {
                    this.inputNudge = true;
                    this.$refs.composerInput?.focus();

                    window.setTimeout(() => {
                        this.inputNudge = false;
                    }, 720);
                });
            },
            submitAfterLoaderPaint(form) {
                const submit = () => this.submitForm(form);

                this.$nextTick(() => {
                    window.requestAnimationFrame(() => {
                        window.requestAnimationFrame(() => {
                            window.setTimeout(submit, 70);
                        });
                    });
                });
            },
            async submitForm(form) {
                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (response.redirected) {
                        window.location.assign(response.url);
                        return;
                    }

                    if (! response.ok) {
                        const contentType = response.headers.get('content-type') || '';
                        const payload = contentType.includes('application/json')
                            ? await response.json()
                            : null;
                        const errors = payload?.errors || {};

                        this.uploadError = errors.media_file?.[0] || null;
                        this.composerError = errors.caption_text?.[0]
                            || errors.source_url?.[0]
                            || errors.media_file?.[0]
                            || 'The fact check could not be submitted. Try again.';
                        this.submitting = false;
                        return;
                    }

                    window.location.assign(response.url || form.action);
                } catch (error) {
                    this.composerError = 'The fact check could not be submitted. Try again.';
                    this.submitting = false;
                }
            },
            requestLocation() {
                if (this.locationRequestInFlight || this.locationStatus === 'requesting') {
                    return Promise.resolve(this.locationStatus === 'granted');
                }

                if (! navigator.geolocation) {
                    this.locationStatus = 'unsupported';
                    return Promise.resolve(false);
                }

                this.locationRequestInFlight = true;
                this.locationStatus = 'requesting';

                return new Promise((resolve) => {
                    navigator.geolocation.getCurrentPosition(
                        (position) => {
                            const coords = position.coords || {};
                            const lat = Number.isFinite(coords.latitude) ? coords.latitude : null;
                            const lon = Number.isFinite(coords.longitude) ? coords.longitude : null;

                            if (lat === null || lon === null) {
                                this.locationStatus = 'error';
                                this.locationLat = null;
                                this.locationLon = null;
                                this.locationAccuracy = null;
                                this.locationRequestInFlight = false;
                                resolve(false);
                                return;
                            }

                            this.locationLat = Number(lat.toFixed(4));
                            this.locationLon = Number(lon.toFixed(4));
                            this.locationAccuracy = Number.isFinite(coords.accuracy) ? Math.round(coords.accuracy) : null;
                            this.locationLabel = 'Current location';
                            this.locationStatus = 'granted';
                            this.locationRequestInFlight = false;
                            resolve(true);
                        },
                        (error) => {
                            this.locationStatus = error?.code === 1 ? 'denied' : 'error';
                            this.locationLat = null;
                            this.locationLon = null;
                            this.locationAccuracy = null;
                            this.locationRequestInFlight = false;
                            resolve(false);
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 2500,
                            maximumAge: 120000,
                        }
                    );
                });
            },
            async handleSubmit(event) {
                event.preventDefault();
                this.menuOpen = false;
                this.previewExpanded = false;

                const form = event.target;

                if (! this.validateComposer()) {
                    this.submitting = false;
                    this.triggerInputNudge();
                    return;
                }

                this.submitting = true;
                this.submitAfterLoaderPaint(form);
            },
            analyzeTitle() {
                if (this.previewKind === 'video') {
                    return 'Video scan in progress';
                }

                if (this.previewKind === 'pdf') {
                    return 'Document scan in progress';
                }

                if (this.previewKind === 'image') {
                    return 'Image scan in progress';
                }

                return 'Content scan in progress';
            },
            analyzeDescription() {
                const draft = (this.$refs.composerInput?.value || '').trim();

                if (this.previewKind === 'video') {
                    return 'TruthGuard is reviewing frames, metadata, and claim context before building your result.';
                }

                if (this.previewKind === 'pdf') {
                    return 'TruthGuard is extracting document cues, checking context, and preparing the verification summary.';
                }

                if (this.previewKind === 'image') {
                    return 'TruthGuard is scanning visual artifacts, source cues, and accompanying claim text.';
                }

                if (draft.includes('http://') || draft.includes('https://')) {
                    return 'TruthGuard is checking the submitted source link and matching it with the provided context.';
                }

                return 'TruthGuard is reviewing the submitted context and preparing the detection result.';
            },
            triggerPicker(type) {
                const acceptMap = {
                    image: '.jpg,.jpeg,.png,.webp,image/*',
                    video: '.mp4,.mov,.webm,.m4v,video/*',
                    pdf: '.pdf,application/pdf',
                };

                this.drivePickerError = null;
                this.drivePickerMessage = null;
                this.resetUploadError();

                if (type === 'image' || type === 'video') {
                    this.selectedInputType = type;
                }

                this.$refs.fileInput.setAttribute('accept', acceptMap[type] || '.jpg,.jpeg,.png,.webp,.mp4,.mov,.webm,.m4v,.pdf');
                this.$refs.fileInput.click();
                this.menuOpen = false;
            },
            uploadLimitMessage() {
                return `The selected file exceeds the ${this.uploadMaxMb}MB upload limit.`;
            },
            resetUploadError() {
                this.uploadError = null;
            },
            rejectOversizedFile(file) {
                if (! file || file.size <= this.uploadMaxBytes) {
                    return false;
                }

                this.clearPreview();
                this.uploadError = `${file.name || 'Selected file'} (${this.formatFileSize(file.size)}) exceeds the ${this.uploadMaxMb}MB upload limit. Choose a smaller file.`;
                this.pasteStatus = null;
                this.drivePickerMessage = null;
                this.dragActive = false;

                window.alert(this.uploadError);
                return true;
            },
            rejectUnsupportedFile(file) {
                if (this.isSupportedFile(file)) {
                    return false;
                }

                this.clearPreview();
                this.uploadError = 'This file type is not supported.';
                this.pasteStatus = null;
                this.drivePickerMessage = null;
                this.dragActive = false;

                return true;
            },
            flashPasteStatus(message) {
                this.pasteStatus = message;

                if (this.pasteStatusTimer) {
                    window.clearTimeout(this.pasteStatusTimer);
                }

                this.pasteStatusTimer = window.setTimeout(() => {
                    this.pasteStatus = null;
                    this.pasteStatusTimer = null;
                }, 2400);
            },
            clipboardFileName(mimeType) {
                const extensionMap = {
                    'image/jpeg': 'jpg',
                    'image/png': 'png',
                    'image/webp': 'webp',
                    'image/gif': 'gif',
                    'application/pdf': 'pdf',
                    'video/mp4': 'mp4',
                    'video/quicktime': 'mov',
                    'video/webm': 'webm',
                };
                const extension = extensionMap[mimeType] || 'png';
                const stamp = new Date()
                    .toISOString()
                    .split('')
                    .filter((character) => ! ['-', ':', 'T', '.', 'Z'].includes(character))
                    .join('')
                    .slice(0, 14);

                return `truthguard-pasted-${stamp}.${extension}`;
            },
            isSupportedFile(file) {
                if (! file) {
                    return false;
                }

                const name = (file.name || '').toLowerCase();
                const mimeType = file.type || '';
                const allowedMimeTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                    'video/mp4',
                    'video/quicktime',
                    'video/webm',
                    'application/pdf',
                ];

                return allowedMimeTypes.includes(mimeType)
                    || ['.jpg', '.jpeg', '.png', '.webp', '.mp4', '.mov', '.webm', '.m4v', '.pdf'].some((extension) => name.endsWith(extension));
            },
            fileFromClipboardItem(item) {
                if (! item) {
                    return null;
                }

                if (item.kind === 'file') {
                    const file = item.getAsFile();

                    if (! file) {
                        return null;
                    }

                    if (file.name) {
                        return file;
                    }

                    return new File([file], this.clipboardFileName(file.type || 'image/png'), {
                        type: file.type || 'image/png',
                        lastModified: Date.now(),
                    });
                }

                return null;
            },
            clipboardFile(event) {
                const items = Array.from(event.clipboardData?.items || []);
                const itemFile = items
                    .map((item) => this.fileFromClipboardItem(item))
                    .find((file) => this.isSupportedFile(file));

                if (itemFile) {
                    return itemFile;
                }

                const files = Array.from(event.clipboardData?.files || []);

                return files.find((file) => this.isSupportedFile(file)) || null;
            },
            handlePaste(event) {
                const file = this.clipboardFile(event);

                if (! file) {
                    return;
                }

                event.preventDefault();

                if (this.attachFile(file)) {
                    this.menuOpen = false;
                    this.flashPasteStatus('Pasted evidence attached');
                }
            },
            preloadGooglePicker() {
                this.ensureGooglePickerLoaded().catch(() => {});
            },
            loadGoogleScript(src, id, ready) {
                if (ready()) {
                    return Promise.resolve();
                }

                const existingScript = document.getElementById(id);

                if (existingScript) {
                    return new Promise((resolve, reject) => {
                        if (ready()) {
                            resolve();
                            return;
                        }

                        existingScript.addEventListener('load', () => resolve(), { once: true });
                        existingScript.addEventListener('error', () => reject(new Error('Google Drive tools could not be loaded.')), { once: true });
                    });
                }

                return new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.id = id;
                    script.src = src;
                    script.async = true;
                    script.defer = true;
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Google Drive tools could not be loaded.'));
                    document.head.appendChild(script);
                });
            },
            ensureGooglePickerLoaded() {
                if (window.google?.picker && window.google?.accounts?.oauth2) {
                    return Promise.resolve();
                }

                if (! window.truthguardGooglePickerPromise) {
                    window.truthguardGooglePickerPromise = Promise
                        .all([
                            this.loadGoogleScript('https://apis.google.com/js/api.js', 'truthguard-google-api-js', () => !! window.gapi),
                            this.loadGoogleScript('https://accounts.google.com/gsi/client', 'truthguard-google-identity-js', () => !! window.google?.accounts?.oauth2),
                        ])
                        .then(() => new Promise((resolve, reject) => {
                            if (! window.gapi) {
                                reject(new Error('Google Drive tools could not be initialized.'));
                                return;
                            }

                            window.gapi.load('picker', {
                                callback: () => resolve(),
                                onerror: () => reject(new Error('Google Drive picker could not be opened.')),
                                timeout: 10000,
                                ontimeout: () => reject(new Error('Google Drive picker took too long to load.')),
                            });
                        }));
                }

                return window.truthguardGooglePickerPromise;
            },
            supportedDriveMimeTypes() {
                return [
                    'image/jpeg',
                    'image/png',
                    'image/webp',
                    'video/mp4',
                    'video/quicktime',
                    'video/webm',
                    'application/pdf',
                ];
            },
            drivePickerAppId() {
                if (this.drivePicker?.appId) {
                    return this.drivePicker.appId;
                }

                const match = (this.drivePicker?.clientId || '').match(/^(\d+)-/);

                return match?.[1] || '';
            },
            requestDriveAccessToken() {
                return new Promise((resolve, reject) => {
                    if (! window.google?.accounts?.oauth2) {
                        reject(new Error('Google Drive sign-in is not ready yet.'));
                        return;
                    }

                    this.driveTokenClient = window.google.accounts.oauth2.initTokenClient({
                        client_id: this.drivePicker.clientId,
                        scope: this.drivePicker.scope,
                        callback: (response) => {
                            if (response?.error) {
                                reject(new Error('Google Drive permission was not granted.'));
                                return;
                            }

                            this.driveAccessToken = response?.access_token || null;

                            if (! this.driveAccessToken) {
                                reject(new Error('Google Drive did not return an access token.'));
                                return;
                            }

                            resolve(this.driveAccessToken);
                        },
                    });

                    this.driveTokenClient.requestAccessToken({
                        prompt: this.driveAccessToken ? '' : 'consent',
                    });
                });
            },
            pickDriveFile(accessToken) {
                return new Promise((resolve, reject) => {
                    if (! window.google?.picker) {
                        reject(new Error('Google Drive picker is not available.'));
                        return;
                    }

                    const view = new window.google.picker.DocsView(window.google.picker.ViewId.DOCS)
                        .setMimeTypes(this.supportedDriveMimeTypes().join(','))
                        .setIncludeFolders(false)
                        .setSelectFolderEnabled(false);

                    const builder = new window.google.picker.PickerBuilder()
                        .setOAuthToken(accessToken)
                        .setDeveloperKey(this.drivePicker.apiKey)
                        .setTitle('Select evidence from Google Drive')
                        .setOrigin(window.location.protocol + '//' + window.location.host)
                        .addView(view)
                        .enableFeature(window.google.picker.Feature.NAV_HIDDEN)
                        .setCallback((data) => {
                            const action = data[window.google.picker.Response.ACTION];

                            if (action === window.google.picker.Action.PICKED) {
                                const documents = data[window.google.picker.Response.DOCUMENTS] || [];
                                resolve(documents[0] || null);
                                return;
                            }

                            if (action === window.google.picker.Action.CANCEL) {
                                resolve(null);
                            }
                        });

                    const appId = this.drivePickerAppId();

                    if (appId) {
                        builder.setAppId(appId);
                    }

                    builder.build().setVisible(true);
                });
            },
            driveFileName(name, mimeType) {
                const cleanName = (name || 'google-drive-file').replace(/[\\/:*?&quot;&lt;&gt;|]+/g, '-');

                if (cleanName.includes('.')) {
                    return cleanName;
                }

                const extensionMap = {
                    'image/jpeg': 'jpg',
                    'image/png': 'png',
                    'image/webp': 'webp',
                    'video/mp4': 'mp4',
                    'video/quicktime': 'mov',
                    'video/webm': 'webm',
                    'application/pdf': 'pdf',
                };

                return `${cleanName}.${extensionMap[mimeType] || 'bin'}`;
            },
            async downloadDriveFile(driveFile, accessToken) {
                const pickerDocument = window.google?.picker?.Document || {};
                const fileId = driveFile?.[pickerDocument.ID] || driveFile?.id;
                let fileName = driveFile?.[pickerDocument.NAME] || driveFile?.name || 'google-drive-file';
                let mimeType = driveFile?.[pickerDocument.MIME_TYPE] || driveFile?.mimeType || '';

                if (! fileId) {
                    throw new Error('Google Drive did not return a valid file.');
                }

                const maxBytes = this.uploadMaxBytes;
                const headers = {
                    Authorization: `Bearer ${accessToken}`,
                };
                const metadataResponse = await fetch(`https://www.googleapis.com/drive/v3/files/${encodeURIComponent(fileId)}?fields=name,mimeType,size&supportsAllDrives=true`, {
                    headers,
                });

                if (metadataResponse.ok) {
                    const metadata = await metadataResponse.json();
                    fileName = metadata?.name || fileName;
                    mimeType = metadata?.mimeType || mimeType;

                    const metadataSize = Number(metadata?.size || 0);

                    if (metadataSize > maxBytes) {
                        throw new RangeError(`Drive file must be ${this.uploadMaxMb}MB or less. Choose a smaller file.`);
                    }
                }

                if (mimeType.startsWith('application/vnd.google-apps.')) {
                    throw new Error('Choose an image, video, or PDF from Drive.');
                }

                if (! this.supportedDriveMimeTypes().includes(mimeType)) {
                    throw new Error('Drive file must be an image, video, or PDF.');
                }

                const response = await fetch(`https://www.googleapis.com/drive/v3/files/${encodeURIComponent(fileId)}?alt=media&supportsAllDrives=true`, {
                    headers,
                });

                if (! response.ok) {
                    throw new Error('Could not download the selected Drive file.');
                }

                const contentLength = Number(response.headers.get('content-length') || 0);

                if (contentLength > maxBytes) {
                    throw new RangeError(`Drive file must be ${this.uploadMaxMb}MB or less. Choose a smaller file.`);
                }

                const blob = await response.blob();

                if (blob.size > maxBytes) {
                    throw new RangeError(`Drive file must be ${this.uploadMaxMb}MB or less. Choose a smaller file.`);
                }

                return new File([blob], this.driveFileName(fileName, mimeType), {
                    type: blob.type || mimeType,
                    lastModified: Date.now(),
                });
            },
            async openDrivePicker() {
                if (this.drivePickerLoading) {
                    return;
                }

                this.drivePickerError = null;
                this.drivePickerMessage = null;

                if (! this.drivePicker?.enabled) {
                    this.drivePickerError = 'Google Drive picker is not configured yet.';
                    return;
                }

                this.drivePickerLoading = true;
                this.drivePickerMessage = 'Opening Google Drive...';

                try {
                    await this.ensureGooglePickerLoaded();
                    const accessToken = await this.requestDriveAccessToken();
                    const driveFile = await this.pickDriveFile(accessToken);

                    if (! driveFile) {
                        this.drivePickerMessage = null;
                        return;
                    }

                    this.drivePickerMessage = 'Importing Drive file...';
                    const file = await this.downloadDriveFile(driveFile, accessToken);

                    if (this.attachFile(file)) {
                        this.menuOpen = false;
                    }
                } catch (error) {
                    this.drivePickerError = error?.message || 'Could not import from Google Drive.';
                    if (error instanceof RangeError) {
                        window.alert(this.drivePickerError);
                    }
                } finally {
                    this.drivePickerLoading = false;

                    if (! this.drivePickerError) {
                        this.drivePickerMessage = null;
                    }
                }
            },
            focusComposer() {
                this.menuOpen = false;
                this.$nextTick(() => this.$refs.composerInput?.focus());
            },
            usePrompt(prompt) {
                if (this.$refs.composerInput) {
                    this.$refs.composerInput.value = prompt;
                    this.composerValue = prompt;
                }
                this.$nextTick(() => {
                    this.resizeComposer();
                    this.$refs.composerInput?.focus();
                });
            },
            formatFileSize(bytes) {
                if (! Number.isFinite(bytes) || bytes <= 0) {
                    return null;
                }

                const megabytes = bytes / 1024 / 1024;

                if (megabytes >= 1) {
                    return `${megabytes.toFixed(1)} MB`;
                }

                return `${Math.max(1, Math.round(bytes / 1024))} KB`;
            },
            setPreview(file) {
                this.clearPreview(false);

                if (! file) {
                    return;
                }

                const fileName = file.name || '';
                const mimeType = file.type || '';
                const normalizedName = fileName.toLowerCase();

                this.previewUrl = URL.createObjectURL(file);
                this.previewName = fileName || 'Uploaded file';
                this.previewSizeLabel = this.formatFileSize(file.size);
                this.previewKind = mimeType.startsWith('image/')
                    ? 'image'
                    : mimeType.startsWith('video/')
                        ? 'video'
                        : (mimeType === 'application/pdf' || normalizedName.endsWith('.pdf'))
                            ? 'pdf'
                            : 'file';
                this.selectedInputType = ['image', 'video'].includes(this.previewKind)
                    ? this.previewKind
                    : (this.composerUrl() ? 'link' : null);
            },
            syncPreviewFromInput(event) {
                const file = event.target?.files?.[0] ?? null;

                if (! file) {
                    this.clearPreview();
                    this.resetUploadError();
                    return;
                }

                if (this.rejectUnsupportedFile(file) || this.rejectOversizedFile(file)) {
                    return;
                }

                this.resetUploadError();
                this.setPreview(file);
                this.hasFile = true;
            },
            attachFile(file) {
                if (! file || ! this.$refs.fileInput) {
                    return false;
                }

                if (this.rejectUnsupportedFile(file) || this.rejectOversizedFile(file)) {
                    return false;
                }

                this.resetUploadError();
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                this.$refs.fileInput.files = dataTransfer.files;
                this.setPreview(file);
                this.hasFile = true;
                this.dragActive = false;

                return true;
            },
            openPreview() {
                if (! this.previewUrl) {
                    return;
                }

                this.previewZoom = 1;
                this.previewExpanded = true;
            },
            closePreview() {
                this.previewExpanded = false;
                this.previewZoom = 1;
            },
            zoomPreviewIn() {
                this.previewZoom = Math.min(2.5, Number((this.previewZoom + 0.15).toFixed(2)));
            },
            zoomPreviewOut() {
                this.previewZoom = Math.max(0.5, Number((this.previewZoom - 0.15).toFixed(2)));
            },
            resetPreviewZoom() {
                this.previewZoom = 1;
            },
            clearPreview(resetInput = true) {
                const clearedKind = this.previewKind;

                if (this.previewUrl) {
                    URL.revokeObjectURL(this.previewUrl);
                }

                this.previewUrl = null;
                this.previewName = null;
                this.previewKind = null;
                this.previewSizeLabel = null;
                this.previewExpanded = false;
                this.previewZoom = 1;
                this.uploadError = null;

                if (['image', 'video'].includes(clearedKind)) {
                    this.selectedInputType = this.composerUrl() ? 'link' : null;
                }

                if (resetInput && this.$refs.fileInput) {
                    this.hasFile = false;
                    this.$refs.fileInput.value = null;
                }
            },
            handleDrop(event) {
                this.dragActive = false;

                const file = event.dataTransfer?.files?.[0];

                if (! file) {
                    return;
                }

                this.attachFile(file);
            },
            resizeComposer() {
                const composer = this.$refs.composerInput;

                if (! composer) {
                    return;
                }

                composer.style.height = '0px';
                const maxHeight = 132;
                composer.style.height = `${Math.min(composer.scrollHeight, maxHeight)}px`;
                composer.style.overflowY = composer.scrollHeight > maxHeight ? 'auto' : 'hidden';
            }
        }"
        x-init="init(); $nextTick(() => resizeComposer())"
        @dragenter.prevent="dragActive = true"
        @dragover.prevent="dragActive = true"
        @dragleave.prevent="dragActive = false"
        @drop.prevent="handleDrop($event)"
        @paste="handlePaste($event)"
        @keydown.escape.window="menuOpen = false; closePreview()"
    >
        <div
            x-show="dragActive"
            x-cloak
            class="pointer-events-none absolute inset-6 z-20 flex items-center justify-center rounded-[28px] border-2 border-dashed border-blue-300 bg-blue-50/90"
        >
            <div class="text-center">
                <p class="text-sm font-semibold text-slate-900">Drop file to attach</p>
                <p class="mt-1 text-xs text-slate-500">Images, videos, and PDFs up to {{ $mediaUploadMaxMb }}MB.</p>
            </div>
        </div>

        <div @class([
            'relative',
            $activeDetection
                ? 'space-y-6'
                : 'truthguard-detection-stage flex flex-col gap-3 sm:gap-4',
        ])>
            <div @class([
                'truthguard-workspace-hero mx-auto w-full rounded-[28px] px-4 py-4 sm:px-5 sm:py-5 lg:px-6 lg:py-6',
                $activeDetection ? 'max-w-2xl' : 'max-w-[1120px]',
            ])>
                <span class="truthguard-workspace-hero-glint" aria-hidden="true"></span>
                <div class="grid items-center gap-5 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <div class="truthguard-workspace-intro min-w-0 max-w-3xl">
                        <div class="truthguard-agent-pill inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-sm font-semibold">
                            <span class="truthguard-agent-pill-icon" aria-hidden="true"></span>
                            <span>TruthGuard Agent AI</span>
                        </div>

                        <h2 class="truthguard-workspace-title mt-3 text-[2rem] font-bold leading-tight sm:text-4xl lg:text-[2.65rem]">
                            Fact Check workspace
                        </h2>

                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base sm:leading-7">
                            Upload evidence or paste a source link.
                        </p>

                        <div class="truthguard-capability-row truthguard-desktop-capabilities mt-4 flex flex-wrap">
                            <span class="truthguard-capability-pill inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold text-slate-700">
                                <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                Ready
                            </span>
                            <span class="truthguard-capability-pill inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-slate-600">
                                <svg class="h-3.5 w-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8 13 2.5-2.5L13 13l2.5-2.5L19 14"></path>
                                </svg>
                                Image
                            </span>
                            <span class="truthguard-capability-pill inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-slate-600">
                                <svg class="h-3.5 w-3.5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.5-2.25A1 1 0 0 1 21 8.65v6.7a1 1 0 0 1-1.5.87L15 14"></path>
                                    <rect x="3" y="6" width="12" height="12" rx="2"></rect>
                                </svg>
                                Video
                            </span>
                            <span class="truthguard-capability-pill inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold text-slate-600">
                                <svg class="h-3.5 w-3.5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 13.5 13.5 10.5"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.5 15.5 7.2 16.8a3.1 3.1 0 0 1-4.4-4.4l2.8-2.8a3.1 3.1 0 0 1 4.4 0"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m15.5 8.5 1.3-1.3a3.1 3.1 0 0 1 4.4 4.4l-2.8 2.8a3.1 3.1 0 0 1-4.4 0"></path>
                                </svg>
                                Link
                            </span>
                        </div>

                        <div class="truthguard-mobile-capabilities hidden" aria-label="Fact Check service capabilities">
                            <div class="truthguard-mobile-service-status">
                                <span class="truthguard-mobile-agent-mark" aria-hidden="true">
                                    <img src="{{ $truthguardLogoUrl }}" alt="">
                                </span>
                                <span class="min-w-0">
                                    <span class="truthguard-mobile-capability-label">Service status</span>
                                    <span class="truthguard-mobile-ready-status">
                                        <span aria-hidden="true"></span>
                                        Ready
                                    </span>
                                </span>
                            </div>

                            <div class="truthguard-mobile-evidence-summary">
                                <span class="truthguard-mobile-capability-label">Supported evidence</span>
                                <span class="truthguard-mobile-evidence-list">
                                    <span>Image</span>
                                    <span aria-hidden="true">&bull;</span>
                                    <span>Video</span>
                                    <span aria-hidden="true">&bull;</span>
                                    <span>Link</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="truthguard-workspace-orb hidden lg:block" aria-hidden="true">
                        <span class="truthguard-workspace-orb-core">
                            <img src="{{ $truthguardLogoUrl }}" alt="">
                        </span>
                    </div>
                </div>
            </div>

            @if (! $activeDetection)
                <section
                    class="truthguard-recent-section mx-auto w-full max-w-[1120px]"
                    aria-labelledby="truthguard-recent-title"
                >
                    <div class="truthguard-recent-heading mb-3 flex items-end justify-between gap-4 px-1">
                        <div class="min-w-0">
                            <h2 id="truthguard-recent-title" class="text-lg font-bold text-slate-950 sm:text-xl">Recent Fact Checks</h2>
                            <p class="truthguard-recent-subtitle mt-1 text-xs font-medium text-slate-500 sm:text-sm">Latest public claim reviews from trusted fact-check partners.</p>
                        </div>
                        <a href="{{ route('dashboard') }}" class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-xs font-bold text-blue-700 transition hover:bg-blue-50 hover:text-blue-800 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 sm:text-sm">
                            View all
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"></path>
                            </svg>
                        </a>
                    </div>

                    @if (! $recentFactChecksLoaded)
                        <div class="truthguard-recent-grid" aria-label="Loading recent fact checks" aria-busy="true">
                            @foreach (range(1, 3) as $skeleton)
                                <div @class(['truthguard-recent-skeleton p-4', 'hidden lg:block' => $skeleton === 3]) aria-hidden="true">
                                    <div class="flex gap-3">
                                        <span class="h-12 w-12 shrink-0 rounded-xl bg-slate-100"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block h-3 w-24 rounded-full bg-slate-100"></span>
                                            <span class="mt-3 block h-3.5 w-full rounded-full bg-slate-100"></span>
                                            <span class="mt-2 block h-3.5 w-3/4 rounded-full bg-slate-100"></span>
                                            <span class="mt-4 block h-2.5 w-28 rounded-full bg-slate-100"></span>
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($recentFactChecksError)
                        <div class="truthguard-recent-state flex min-h-24 flex-col items-start justify-center rounded-2xl border border-rose-100 bg-rose-50/60 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-rose-600 shadow-sm" aria-hidden="true">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" d="M12 8.5v4M12 16h.01"></path>
                                        <circle cx="12" cy="12" r="9"></circle>
                                    </svg>
                                </span>
                                <p class="text-sm font-semibold text-slate-700">{{ $recentFactChecksError }}</p>
                            </div>
                            <button type="button" wire:click="loadRecentFactChecks" wire:loading.attr="disabled" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-rose-200 bg-white px-4 text-sm font-bold text-rose-700 transition hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-100 disabled:opacity-60 sm:mt-0">
                                Try again
                            </button>
                        </div>
                    @elseif ($recentFactChecks === [])
                        <div class="truthguard-recent-state flex min-h-28 flex-col items-start justify-center rounded-2xl border border-blue-100 bg-white/80 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700" aria-hidden="true">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5 19 6v5.2c0 4.3-2.8 7.5-7 9.3-4.2-1.8-7-5-7-9.3V6l7-2.5Z"></path>
                                        <path stroke-linecap="round" d="M9.5 12h5M12 9.5v5"></path>
                                    </svg>
                                </span>
                                <span>
                                    <strong class="block text-sm font-bold text-slate-900">No public claim reviews yet</strong>
                                    <span class="mt-1 block text-xs font-medium text-slate-500 sm:text-sm">Latest partner fact checks will appear here.</span>
                                </span>
                            </div>
                            <a href="{{ route('dashboard') }}" class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg bg-blue-600 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-100 sm:mt-0">
                                Open latest feed
                            </a>
                        </div>
                    @else
                        <div class="truthguard-recent-grid">
                            @foreach ($recentFactChecks as $item)
                                <x-detections.recent-fact-check-card :item="$item" wire:key="recent-fact-check-{{ $item['id'] }}" />
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            <form
                id="truthguard-composer"
                method="POST"
                action="{{ route('detections.store') }}"
                enctype="multipart/form-data"
                @class([
                    'space-y-2',
                    ! $activeDetection ? 'truthguard-pre-result-form mx-auto w-full max-w-[1120px]' : null,
                ])
                @submit="handleSubmit($event)"
            >
                @csrf
                <input type="hidden" name="weather_consent" :value="locationStatus === 'granted' ? '1' : '0'">
                <input type="hidden" name="weather_lat" :value="locationLat !== null ? locationLat : ''">
                <input type="hidden" name="weather_lon" :value="locationLon !== null ? locationLon : ''">
                <input type="hidden" name="weather_label" :value="locationStatus === 'granted' ? locationLabel : ''">
                <input type="hidden" name="return_context" value="{{ $submitContext }}">
                <input
                    x-ref="fileInput"
                    type="file"
                    name="media_file"
                    class="hidden"
                    accept=".jpg,.jpeg,.png,.webp,.mp4,.mov,.webm,.m4v,.pdf"
                    @change="syncPreviewFromInput($event)"
                >

                <div
                    class="truthguard-detection-composer relative rounded-[30px] p-2.5 sm:p-3"
                        :class="[
                            previewUrl ? 'truthguard-preview-space' : '',
                            inputNudge ? 'truthguard-composer-hint' : ''
                        ]"
                    >
                        <div
                            x-show="previewUrl"
                            x-cloak
                            x-transition.opacity
                            class="truthguard-preview-anchor"
                        >
                            <div class="truthguard-preview-thumb">
                                <button
                                    type="button"
                                    class="truthguard-preview-trigger group relative transition hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2"
                                    @click="openPreview()"
                                    aria-label="Open larger preview"
                                >
                                    <template x-if="previewKind === 'image'">
                                        <img :src="previewUrl" alt="Preview of uploaded image" class="h-full w-full object-cover">
                                    </template>

                                    <template x-if="previewKind === 'video'">
                                        <div class="relative h-full w-full">
                                            <video :src="previewUrl" preload="metadata" muted playsinline class="h-full w-full object-cover"></video>
                                            <span class="absolute inset-x-0 bottom-0 bg-slate-900/55 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-white">Video</span>
                                        </div>
                                    </template>

                                    <template x-if="previewKind === 'pdf'">
                                        <div class="flex h-full w-full items-center justify-center rounded-[22px] border border-slate-200 bg-white text-xs font-semibold uppercase tracking-[0.18em] text-slate-700">
                                            PDF
                                        </div>
                                    </template>

                                    <template x-if="previewKind === 'file'">
                                        <div class="flex h-full w-full items-center justify-center rounded-[22px] border border-slate-200 bg-white text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-700">
                                            File
                                        </div>
                                    </template>
                                </button>
                            </div>

                            <div class="min-w-0 flex-1 self-center">
                                <p class="truncate text-xs font-bold text-slate-800" x-text="previewName || 'Attached evidence'"></p>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-slate-500">
                                    <span class="capitalize" x-text="previewKind === 'pdf' ? 'Document' : previewKind"></span>
                                    <span aria-hidden="true">&bull;</span>
                                    <span x-text="previewSizeLabel || 'Selected file'"></span>
                                </p>
                            </div>

                            <button
                                type="button"
                                class="truthguard-preview-remove"
                                @click.stop="clearPreview()"
                                aria-label="Remove attached file"
                                title="Remove attachment"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                        <div
                            x-show="selectedInputType === 'link' && composerUrl()"
                            x-cloak
                            x-transition.opacity
                            class="truthguard-link-preview"
                        >
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-700" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 14.5 5-5M8 16l-1.3 1.3a3.2 3.2 0 0 1-4.5-4.5L5 10a3.2 3.2 0 0 1 4.5 0M16 8l1.3-1.3a3.2 3.2 0 0 1 4.5 4.5L19 14a3.2 3.2 0 0 1-4.5 0"></path>
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <strong class="block truncate text-xs font-bold text-slate-800" x-text="composerDomain()"></strong>
                                <span class="mt-0.5 block truncate text-[11px] font-medium text-slate-500" x-text="composerUrlLabel()"></span>
                            </span>
                            <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition hover:bg-rose-50 hover:text-rose-600 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rose-100" @click="clearLinkPreview()" aria-label="Remove source link" title="Remove link">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>

                    <div
                        class="truthguard-composer-field flex items-start gap-3 p-2.5 sm:gap-3 sm:p-3"
                    >
                        <div class="relative shrink-0">
                            <button
                                type="button"
                                class="truthguard-attachment-trigger inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:text-blue-700 hover:shadow-md sm:h-12 sm:w-12"
                                :class="inputNudge ? 'truthguard-input-nudge truthguard-attachment-hint' : ''"
                                @click="menuOpen = !menuOpen"
                                aria-label="Attach evidence"
                                title="Attach evidence"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-6.315 6.316a4.5 4.5 0 1 1-6.364-6.364l7.02-7.02a3 3 0 1 1 4.243 4.243l-7.02 7.02a1.5 1.5 0 0 1-2.122-2.122l6.314-6.314" />
                                </svg>
                            </button>

                            <template x-teleport="body">
                                <div
                                    x-show="menuOpen"
                                    x-cloak
                                    x-transition.opacity
                                    class="truthguard-attachment-modal fixed inset-0 z-[2147483630] flex items-center justify-center p-4 sm:p-6"
                                    @wheel.prevent
                                    @touchmove.prevent
                                >
                                    <div class="truthguard-attachment-modal-backdrop absolute inset-0" aria-hidden="true" @click="menuOpen = false"></div>

                                    <div
                                        class="truthguard-attachment-popover relative z-10 border border-slate-200 bg-white shadow-[0_16px_34px_rgba(15,23,42,0.10)]"
                                        role="dialog"
                                        aria-modal="true"
                                        aria-labelledby="truthguard-uploader-title"
                                        x-transition.scale.origin.center
                                        @click.stop
                                    >
                                    <div class="truthguard-uploader-head">
                                        <span class="truthguard-uploader-mark" aria-hidden="true">
                                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.5V8.8"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.9 11.8 3.1-3.1 3.1 3.1"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 17.5h10.7a3.4 3.4 0 0 0 .6-6.75A5.35 5.35 0 0 0 7.2 9.7a3.9 3.9 0 0 0-.7 7.8Z"></path>
                                            </svg>
                                        </span>
                                        <div class="min-w-0">
                                            <p id="truthguard-uploader-title" class="text-base font-black text-slate-900">Upload evidence</p>
                                            <p class="mt-0.5 text-xs font-semibold text-slate-500">Media and documents</p>
                                        </div>
                                        <button
                                            type="button"
                                            class="truthguard-uploader-close ml-auto"
                                            @click="menuOpen = false"
                                            aria-label="Close upload modal"
                                        >
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                                            </svg>
                                        </button>
                                    </div>

                                    <button type="button" class="truthguard-uploader-dropzone" @click="triggerPicker('all')" @paste.prevent="handlePaste($event)">
                                    <span class="truthguard-uploader-cloud" aria-hidden="true">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.2V8.8"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m9.2 11.2 2.8-2.8 2.8 2.8"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 17.2h10.1a3.15 3.15 0 0 0 .48-6.25 5.1 5.1 0 0 0-10.08-.8A3.55 3.55 0 0 0 7 17.2Z"></path>
                                        </svg>
                                    </span>
                                    <span class="relative z-10 text-base font-black text-slate-800">Browse file</span>
                                    <span class="relative z-10 mt-1 text-xs font-semibold text-slate-500">or paste a copied screenshot</span>
                                    <span class="relative z-10 mt-2 text-[11px] font-black uppercase tracking-[0.12em] text-blue-600">Max {{ $mediaUploadMaxMb }}MB</span>
                                </button>

                                <div class="truthguard-upload-type-grid">
                                    <button type="button" class="truthguard-upload-type-button" @click="triggerPicker('image')">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m8 13 2.5-2.5L13 13l2.5-2.5L19 14"></path>
                                            <circle cx="8.5" cy="9" r="1"></circle>
                                        </svg>
                                        Image
                                    </button>
                                    <button type="button" class="truthguard-upload-type-button" @click="triggerPicker('video')">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.5-2.25A1 1 0 0 1 21 8.65v6.7a1 1 0 0 1-1.5.87L15 14"></path>
                                            <rect x="3" y="6" width="12" height="12" rx="2"></rect>
                                        </svg>
                                        Video
                                    </button>
                                    <button type="button" class="truthguard-upload-type-button" @click="triggerPicker('pdf')">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.8h6.8L18 8v12.2H7V3.8Z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4v4.4H18"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.4 13h5.2M9.4 16h4"></path>
                                        </svg>
                                        PDF
                                    </button>
                                    <button
                                        type="button"
                                        class="truthguard-upload-type-button truthguard-upload-type-button-google"
                                        :class="drivePickerLoading ? 'pointer-events-none opacity-70' : ''"
                                        @click="openDrivePicker()"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path fill="#0F9D58" d="M8.9 3.3 2.5 14.4l3.15 5.45 6.42-11.1L8.9 3.3Z"></path>
                                            <path fill="#4285F4" d="M15.1 3.3H8.9l6.42 11.12h6.18L15.1 3.3Z"></path>
                                            <path fill="#F4B400" d="M5.65 19.85h12.72l3.13-5.43H8.78l-3.13 5.43Z"></path>
                                        </svg>
                                        Drive
                                    </button>
                                </div>

                                <div
                                    x-show="drivePickerLoading || drivePickerMessage || drivePickerError"
                                    x-cloak
                                    class="truthguard-drive-status flex items-center gap-2 text-[11px] font-bold"
                                    :class="drivePickerError ? 'truthguard-drive-status-error' : ''"
                                >
                                    <span class="truthguard-drive-status-dot" aria-hidden="true"></span>
                                    <span class="min-w-0 truncate" x-text="drivePickerError || drivePickerMessage"></span>
                                </div>

                                <div x-show="previewName" x-cloak class="truthguard-upload-file-row">
                                    <span class="truthguard-upload-file-icon" aria-hidden="true">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 3.8h6.8L18 8v12.2H7V3.8Z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4v4.4H18"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.4 13h5.2M9.4 16h4"></path>
                                        </svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 items-center justify-between gap-2">
                                            <p class="truncate text-xs font-black text-slate-800" x-text="previewName"></p>
                                            <span class="shrink-0 text-[10px] font-black uppercase tracking-[0.12em] text-blue-600">Ready</span>
                                        </div>
                                        <p class="mt-0.5 truncate text-[10px] font-semibold text-slate-500" x-text="previewSizeLabel || 'Selected file'"></p>
                                        <div class="truthguard-upload-progress mt-2" aria-hidden="true"></div>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-rose-500 shadow-sm ring-1 ring-rose-100 transition hover:bg-rose-50 hover:text-rose-600"
                                        @click.stop="clearPreview()"
                                        aria-label="Remove attached file"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="min-w-0 flex-1">
                            <label for="truthguard-message" class="sr-only">TruthGuard detection prompt</label>
                            <textarea
                                id="truthguard-message"
                                x-ref="composerInput"
                                name="caption_text"
                                rows="2"
                                placeholder="Upload, paste a link, or describe a claim"
                                class="truthguard-detection-textarea w-full px-1 py-2 text-[12px] leading-5 text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-0 sm:text-[14px] sm:leading-5"
                                aria-describedby="truthguard-composer-feedback truthguard-ai-disclaimer truthguard-ai-disclaimer-mobile"
                                :aria-invalid="composerError ? 'true' : 'false'"
                                @input="handleComposerInput($event)"
                            >{{ $composerDraft }}</textarea>
                        </div>

                        <button
                            type="button"
                            class="truthguard-mobile-inline-submit truthguard-analyze-button relative inline-flex shrink-0 items-center justify-center overflow-hidden text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-60 sm:hidden"
                            :class="[
                                canAnalyze()
                                    ? 'truthguard-analyze-ready'
                                    : 'truthguard-analyze-empty',
                                submitting ? 'opacity-60' : ''
                            ]"
                            x-bind:disabled="submitting || !canAnalyze()"
                            :aria-disabled="(submitting || !canAnalyze()) ? 'true' : 'false'"
                            @click="handleAnalyzeClick($event)"
                            aria-label="Check claim"
                            title="Check claim"
                        >
                            <span class="truthguard-analyze-symbol" x-show="!submitting" aria-hidden="true">
                                <svg class="truthguard-ai-sparkle-mark h-full w-full" fill="none" viewBox="0 0 24 24">
                                    <path class="truthguard-ai-sparkle-main" d="M12 2.8c.76 5.06 4.14 8.44 9.2 9.2-5.06.76-8.44 4.14-9.2 9.2-.76-5.06-4.14-8.44-9.2-9.2 5.06-.76 8.44-4.14 9.2-9.2Z" fill="#2563eb"></path>
                                    <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-b" d="M18.4 3.9c.24 1.58 1.28 2.62 2.86 2.86-1.58.24-2.62 1.28-2.86 2.86-.24-1.58-1.28-2.62-2.86-2.86 1.58-.24 2.62-1.28 2.86-2.86Z" fill="#7dd3fc"></path>
                                    <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-c" d="M5.7 15.1c.2 1.28 1.04 2.12 2.32 2.32-1.28.2-2.12 1.04-2.32 2.32-.2-1.28-1.04-2.12-2.32-2.32 1.28-.2 2.12-1.04 2.32-2.32Z" fill="#60a5fa"></path>
                                </svg>
                            </span>
                            <span class="truthguard-analyze-spinner" x-show="submitting" x-cloak aria-hidden="true"></span>
                            <span class="sr-only" x-text="analyzeButtonLabel()"></span>
                        </button>
                    </div>

                    <div
                        x-show="pasteStatus"
                        x-cloak
                        x-transition.opacity
                        class="mt-2 inline-flex items-center gap-2 rounded-full border border-emerald-100 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-700 shadow-sm"
                        role="status"
                        aria-live="polite"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.8 12.4 2.2 2.2 4.7-5.2"></path>
                            <circle cx="12" cy="12" r="9"></circle>
                        </svg>
                        <span x-text="pasteStatus"></span>
                    </div>

                    <div
                        x-show="uploadError"
                        x-cloak
                        x-transition.opacity
                        class="mt-2 inline-flex items-center gap-2 rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-600 shadow-sm"
                        role="alert"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.5v4.2"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16h.01"></path>
                            <circle cx="12" cy="12" r="9"></circle>
                        </svg>
                        <span x-text="uploadError"></span>
                    </div>

                    <div
                        id="truthguard-composer-feedback"
                        x-show="composerError"
                        x-cloak
                        x-transition.opacity
                        class="mt-2 flex items-center gap-2 px-1 text-xs font-semibold text-rose-600"
                        role="alert"
                        aria-live="polite"
                    >
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" d="M12 8.5v4.2M12 16h.01"></path>
                            <circle cx="12" cy="12" r="9"></circle>
                        </svg>
                        <span x-text="composerError"></span>
                    </div>

                    <div class="truthguard-composer-footer mt-2 hidden items-center justify-between gap-3 sm:flex">
                        <div class="truthguard-input-types flex min-w-0 items-center gap-1.5 overflow-x-auto" role="group" aria-label="Evidence type">
                            <button type="button" class="truthguard-input-type" :class="selectedInputType === 'image' ? 'truthguard-input-type-active' : ''" :aria-pressed="selectedInputType === 'image'" @click="selectInputType('image')">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m8 14 2.5-2.5L13 14l2.5-2.5L19 14"></path>
                                </svg>
                                Image
                            </button>
                            <button type="button" class="truthguard-input-type" :class="selectedInputType === 'video' ? 'truthguard-input-type-active' : ''" :aria-pressed="selectedInputType === 'video'" @click="selectInputType('video')">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="5.5" width="13" height="13" rx="2"></rect>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16 10 4-2v8l-4-2"></path>
                                </svg>
                                Video
                            </button>
                            <button type="button" class="truthguard-input-type" :class="selectedInputType === 'link' ? 'truthguard-input-type-active' : ''" :aria-pressed="selectedInputType === 'link'" @click="selectInputType('link')">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.5 14.5 5-5M8 16l-1.3 1.3a3.2 3.2 0 0 1-4.5-4.5L5 10a3.2 3.2 0 0 1 4.5 0M16 8l1.3-1.3a3.2 3.2 0 0 1 4.5 4.5L19 14a3.2 3.2 0 0 1-4.5 0"></path>
                                </svg>
                                Link
                            </button>
                        </div>

                        <div class="truthguard-composer-actions flex shrink-0 items-center gap-2">
                            <span
                                x-show="showCharacterCount()"
                                x-cloak
                                class="text-[11px] font-bold tabular-nums"
                                :class="isComposerOverLimit() ? 'text-rose-600' : (composerCharacterCount() >= composerMaxCharacters * 0.95 ? 'text-amber-700' : 'text-slate-500')"
                                x-text="`${composerCharacterCount()}/${composerMaxCharacters}`"
                                aria-live="polite"
                            ></span>
                        <button
                            type="button"
                            class="truthguard-analyze-button relative inline-flex shrink-0 items-center justify-center overflow-hidden text-xs font-bold transition hover:shadow-xl disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm"
                            :class="[
                                canAnalyze()
                                    ? 'truthguard-analyze-ready'
                                    : 'truthguard-analyze-empty',
                                submitting ? 'opacity-60' : ''
                            ]"
                            x-bind:disabled="submitting || !canAnalyze()"
                            :aria-disabled="(submitting || !canAnalyze()) ? 'true' : 'false'"
                            @click="handleAnalyzeClick($event)"
                        >
                            <span class="truthguard-analyze-symbol" x-show="!submitting" aria-hidden="true">
                                <svg class="truthguard-ai-sparkle-mark h-full w-full" fill="none" viewBox="0 0 24 24">
                                    <defs>
                                        <linearGradient id="truthguard-ai-button-gradient" x1="3" x2="21" y1="21" y2="3" gradientUnits="userSpaceOnUse">
                                            <stop stop-color="#67E8F9"/>
                                            <stop offset="0.46" stop-color="#60A5FA"/>
                                            <stop offset="1" stop-color="#C084FC"/>
                                        </linearGradient>
                                    </defs>
                                    <path class="truthguard-ai-sparkle-main" d="M12 2.8c.76 5.06 4.14 8.44 9.2 9.2-5.06.76-8.44 4.14-9.2 9.2-.76-5.06-4.14-8.44-9.2-9.2 5.06-.76 8.44-4.14 9.2-9.2Z" fill="url(#truthguard-ai-button-gradient)"></path>
                                    <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-b" d="M18.4 3.9c.24 1.58 1.28 2.62 2.86 2.86-1.58.24-2.62 1.28-2.86 2.86-.24-1.58-1.28-2.62-2.86-2.86 1.58-.24 2.62-1.28 2.86-2.86Z" fill="#E0F2FE"></path>
                                    <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-c" d="M5.7 15.1c.2 1.28 1.04 2.12 2.32 2.32-1.28.2-2.12 1.04-2.32 2.32-.2-1.28-1.04-2.12-2.32-2.32 1.28-.2 2.12-1.04 2.32-2.32Z" fill="#BAE6FD"></path>
                                </svg>
                            </span>
                            <span class="truthguard-analyze-spinner" x-show="submitting" x-cloak aria-hidden="true"></span>
                            <span class="truthguard-analyze-text" x-text="analyzeButtonLabel()"></span>
                        </button>
                        </div>
                    </div>

                    @if (! $activeDetection)
                        <p id="truthguard-ai-disclaimer-mobile" class="truthguard-ai-disclaimer-copy truthguard-mobile-ai-disclaimer !mt-2 overflow-hidden px-1 text-center text-[10px] font-semibold leading-4 sm:hidden">
                            <span class="truthguard-ai-disclaimer">
                                <span class="truthguard-ai-disclaimer-icon" aria-hidden="true">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                        <circle cx="12" cy="12" r="8.4"></circle>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.8v4.7"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.1h.01"></path>
                                    </svg>
                                </span>
                                <span>TruthGuard can make mistakes. Verify details before sharing.</span>
                            </span>
                        </p>
                    @endif
                </div>

                @if (! $activeDetection)
                    <p id="truthguard-ai-disclaimer" class="truthguard-ai-disclaimer-copy !mt-2 hidden overflow-hidden px-2 text-center text-[10px] font-semibold leading-4 sm:block sm:text-[11px]">
                        <span class="truthguard-ai-disclaimer">
                            <span class="truthguard-ai-disclaimer-icon" aria-hidden="true">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4">
                                    <circle cx="12" cy="12" r="8.4"></circle>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 10.8v4.7"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8.1h.01"></path>
                                </svg>
                            </span>
                            <span>TruthGuard can make mistakes. Verify important details before sharing.</span>
                        </span>
                    </p>
                @endif

            </form>

            <div
                x-show="previewExpanded && previewUrl"
                x-cloak
                x-transition.opacity
                class="fixed inset-0 flex items-center justify-center overflow-hidden p-4 sm:p-6"
                style="z-index: 2147483645;"
                @click.self="closePreview()"
            >
                <div class="truthguard-preview-backdrop absolute inset-0"></div>

                <template x-if="previewKind === 'image'">
                    <img
                        :src="previewUrl"
                        alt=""
                        class="truthguard-preview-background-media pointer-events-none absolute inset-0 h-full w-full object-cover"
                        aria-hidden="true"
                    >
                </template>

                <template x-if="previewKind === 'video'">
                    <video
                        :src="previewUrl"
                        muted
                        loop
                        playsinline
                        preload="metadata"
                        class="truthguard-preview-background-media pointer-events-none absolute inset-0 h-full w-full object-cover"
                        aria-hidden="true"
                    ></video>
                </template>

                <div class="pointer-events-none absolute inset-0 opacity-80" aria-hidden="true">
                    <div class="absolute left-[8%] top-[12%] h-32 w-32 rounded-full bg-cyan-200/30 blur-3xl"></div>
                    <div class="absolute bottom-[14%] right-[9%] h-40 w-40 rounded-full bg-violet-200/30 blur-3xl"></div>
                </div>

                <div class="relative z-10 flex h-full w-full flex-col items-center justify-between gap-4" @click.self="closePreview()">
                    <div class="flex w-full max-w-[1060px] items-center justify-between gap-3">
                        <div class="truthguard-preview-toolbar min-w-0 rounded-full px-4 py-2">
                            <p class="truncate text-xs font-bold uppercase tracking-[0.16em] text-slate-500" x-text="previewKind === 'video' ? 'Video Preview' : (previewKind === 'image' ? 'Image Preview' : 'File Preview')"></p>
                            <p class="hidden truncate text-sm font-semibold text-slate-800 sm:block" x-text="previewName || 'Attached file'"></p>
                        </div>

                        <button
                            type="button"
                            class="truthguard-preview-toolbar inline-flex h-11 w-11 items-center justify-center rounded-full text-slate-700 transition hover:-translate-y-0.5 hover:text-rose-600"
                            @click="closePreview()"
                            aria-label="Close preview"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <div class="flex min-h-0 w-full flex-1 items-center justify-center overflow-hidden" @click.self="closePreview()">
                        <div class="truthguard-preview-media-shell flex max-h-[74svh] items-center justify-center overflow-visible rounded-[28px] p-2 sm:rounded-[32px] sm:p-3" style="max-width: min(92vw, 1040px);">
                            <template x-if="previewKind === 'image'">
                                <img
                                    :src="previewUrl"
                                    alt="Large preview of uploaded image"
                                    class="truthguard-preview-media max-h-[70svh] max-w-full rounded-[22px] object-contain shadow-[0_22px_58px_rgba(15,23,42,0.22)]"
                                    :style="`transform: scale(${previewZoom});`"
                                    @dblclick="resetPreviewZoom()"
                                >
                            </template>

                            <template x-if="previewKind === 'video'">
                                <video
                                    :src="previewUrl"
                                    controls
                                    preload="metadata"
                                    class="truthguard-preview-media max-h-[70svh] max-w-full rounded-[22px] object-contain shadow-[0_22px_58px_rgba(15,23,42,0.22)]"
                                    :style="`transform: scale(${previewZoom});`"
                                ></video>
                            </template>

                            <template x-if="previewKind === 'pdf'">
                                <a
                                    :href="previewUrl"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="inline-flex items-center justify-center rounded-full border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:border-blue-200 hover:text-blue-700"
                                >
                                    Open PDF in a new tab
                                </a>
                            </template>

                            <template x-if="previewKind === 'file'">
                                <p class="text-sm font-medium text-slate-900" x-text="previewName ?? 'Attached file'"></p>
                            </template>
                        </div>
                    </div>

                    <div
                        x-show="previewKind === 'image' || previewKind === 'video'"
                        x-cloak
                        class="truthguard-preview-toolbar flex items-center gap-2 rounded-full p-2"
                    >
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700 transition hover:bg-blue-50 hover:text-blue-700"
                            @click="zoomPreviewOut()"
                            aria-label="Zoom out"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"></path>
                            </svg>
                        </button>
                        <button
                            type="button"
                            class="min-w-[4.5rem] rounded-full px-3 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-100"
                            @click="resetPreviewZoom()"
                            x-text="`${Math.round(previewZoom * 100)}%`"
                        ></button>
                        <button
                            type="button"
                            class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-700 transition hover:bg-blue-50 hover:text-blue-700"
                            @click="zoomPreviewIn()"
                            aria-label="Zoom in"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <div
            x-show="submitting"
            x-cloak
            x-transition.opacity.duration.200ms
            class="truthguard-verify-overlay fixed inset-0 flex items-center justify-center overflow-hidden px-4 py-6"
            style="z-index: 2147483646;"
        >
            <div class="truthguard-verify-backdrop absolute inset-0"></div>
            <div class="truthguard-verify-social-field" aria-hidden="true">
                <span class="truthguard-verify-social-pop" style="--icon-color: #1877F2; --icon-size: 3rem; --icon-opacity: 0.2; --duration: 7.4s; --delay: -1.2s; --drift-x: 16px; --start-rotate: -6deg; --end-rotate: 5deg;">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M13.8 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5H17V4c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.3V10H8v3h2.4v8h3.4Z"/>
                    </svg>
                </span>
                <span class="truthguard-verify-social-pop" style="--icon-color: #111827; --icon-size: 3.15rem; --icon-opacity: 0.18; --duration: 8s; --delay: -3.8s; --drift-x: -18px; --start-rotate: 5deg; --end-rotate: -5deg;">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M18.9 3H22l-6.8 7.8L23.2 21h-6.3l-4.9-6.4L6.4 21H3.3l7.3-8.4L.8 3h6.4l4.4 5.8L18.9 3Zm-1.1 16h1.8L6.2 4.9H4.3L17.8 19Z"/>
                    </svg>
                </span>
                <span class="truthguard-verify-social-pop" style="--icon-size: 3.05rem; --icon-opacity: 0.18; --duration: 7.8s; --delay: -5.5s; --drift-x: 20px; --start-rotate: -4deg; --end-rotate: 4deg;">
                    <svg viewBox="0 0 24 24" fill="none">
                        <defs>
                            <linearGradient id="truthguard-instagram-gradient" x1="4" x2="20" y1="20" y2="4" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#FEDA75"/>
                                <stop offset="0.34" stop-color="#FA7E1E"/>
                                <stop offset="0.62" stop-color="#D62976"/>
                                <stop offset="1" stop-color="#4F5BD5"/>
                            </linearGradient>
                        </defs>
                        <rect x="4.25" y="4.25" width="15.5" height="15.5" rx="4.7" stroke="url(#truthguard-instagram-gradient)" stroke-width="1.9"></rect>
                        <circle cx="12" cy="12" r="3.25" stroke="url(#truthguard-instagram-gradient)" stroke-width="1.9"></circle>
                        <circle cx="17.15" cy="6.85" r="1.05" fill="#D62976"></circle>
                    </svg>
                </span>
                <span class="truthguard-verify-social-pop" style="--icon-size: 3.05rem; --icon-opacity: 0.18; --duration: 7.2s; --delay: -4.6s; --drift-x: 18px; --start-rotate: -7deg; --end-rotate: 5deg;">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M14.7 4.1c.5 2 1.8 3.4 3.9 3.7v2.25a6.8 6.8 0 0 1-3.9-1.15v7.16a4.93 4.93 0 1 1-4.06-4.84v2.43a2.51 2.51 0 1 0 1.61 2.34V4.1h2.45Z" fill="#111827"/>
                        <path d="M13.62 4.1c.5 2 1.8 3.4 3.9 3.7v1.08a6.76 6.76 0 0 1-2.82-1.08v7.16a4.93 4.93 0 0 1-7.7 4.08 4.94 4.94 0 0 0 8.76-3.08V8.8a6.8 6.8 0 0 0 3.9 1.15V7.8c-2.1-.3-3.4-1.7-3.9-3.7h-2.14Z" fill="#25F4EE" opacity="0.88"/>
                        <path d="M12.24 13.66a2.51 2.51 0 0 0-3.25 2.33 2.51 2.51 0 0 0 3.78 2.15 2.51 2.51 0 0 1-1.98.2 2.51 2.51 0 0 1 .37-4.78c.37 0 .73.08 1.08.24v-.14Zm3.52-9.56c.14.55.34 1.06.62 1.52a5.66 5.66 0 0 0 3.28 2.18v1.1a6.8 6.8 0 0 1-3.9-1.16V4.1Z" fill="#FE2C55" opacity="0.82"/>
                    </svg>
                </span>
                <span class="truthguard-verify-social-pop" style="--icon-size: 3.2rem; --icon-opacity: 0.16; --duration: 8.6s; --delay: -2.9s; --drift-x: -16px; --start-rotate: 4deg; --end-rotate: -4deg;">
                    <img src="{{ $truthguardLogoUrl }}" alt="">
                </span>
            </div>

            <div class="truthguard-verify-panel relative z-10 flex flex-col items-center justify-center" role="status" aria-live="polite" aria-busy="true">
                <div class="truthguard-verify-loader mx-auto" aria-hidden="true">
                    <div class="truthguard-scan-data">
                        <span style="--dot-top: 0.2rem; --dot-y: -0.42rem; --dot-delay: -0.15s;"></span>
                        <span style="--dot-top: 0.92rem; --dot-y: 0.1rem; --dot-delay: -0.72s; --dot-speed: 2.2s; --dot-size: 0.14rem;"></span>
                        <span style="--dot-top: 1.58rem; --dot-y: 0.38rem; --dot-delay: -1.18s;"></span>
                        <span style="--dot-top: 2.18rem; --dot-y: -0.18rem; --dot-delay: -1.62s; --dot-speed: 2s; --dot-size: 0.13rem;"></span>
                    </div>
                    <div class="truthguard-scan-document">
                        <div class="truthguard-scan-blur-haze"></div>
                        <svg class="truthguard-scan-neural-map" viewBox="0 0 220 150" aria-hidden="true">
                            <path class="truthguard-scan-neural-link" d="M34 106 C66 76 98 118 132 82 C154 58 174 65 198 36"></path>
                            <path class="truthguard-scan-neural-link" d="M28 42 C62 34 88 58 110 50 C142 38 164 94 202 82"></path>
                            <path class="truthguard-scan-neural-link" d="M54 126 C78 102 98 96 120 110 C150 130 174 108 198 116"></path>
                            <circle class="truthguard-scan-neural-node" cx="34" cy="106" r="3.6"></circle>
                            <circle class="truthguard-scan-neural-node" cx="110" cy="50" r="3.2"></circle>
                            <circle class="truthguard-scan-neural-node" cx="132" cy="82" r="3.8"></circle>
                            <circle class="truthguard-scan-neural-node" cx="198" cy="36" r="3.4"></circle>
                        </svg>
                        <div class="truthguard-scan-ai-logo">
                            <svg class="truthguard-ai-sparkle-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <defs>
                                    <linearGradient id="truthguard-ai-loader-gradient" x1="3" x2="21" y1="21" y2="3" gradientUnits="userSpaceOnUse">
                                        <stop stop-color="#67E8F9"/>
                                        <stop offset="0.44" stop-color="#60A5FA"/>
                                        <stop offset="1" stop-color="#C084FC"/>
                                    </linearGradient>
                                </defs>
                                <path class="truthguard-ai-sparkle-main" d="M12 2.6c.78 5.16 4.24 8.62 9.4 9.4-5.16.78-8.62 4.24-9.4 9.4-.78-5.16-4.24-8.62-9.4-9.4 5.16-.78 8.62-4.24 9.4-9.4Z" fill="url(#truthguard-ai-loader-gradient)"></path>
                                <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-b" d="M18.35 3.6c.26 1.7 1.39 2.83 3.09 3.09-1.7.26-2.83 1.39-3.09 3.09-.26-1.7-1.39-2.83-3.09-3.09 1.7-.26 2.83-1.39 3.09-3.09Z" fill="#E0F2FE"></path>
                                <path class="truthguard-ai-sparkle-small truthguard-ai-sparkle-small-c" d="M5.55 14.98c.22 1.42 1.16 2.36 2.58 2.58-1.42.22-2.36 1.16-2.58 2.58-.22-1.42-1.16-2.36-2.58-2.58 1.42-.22 2.36-1.16 2.58-2.58Z" fill="#BAE6FD"></path>
                            </svg>
                        </div>
                        <div class="truthguard-scan-doc-logo">
                            <span class="truthguard-verify-source-logo" style="--source-bg: #1877f2; --source-color: #fff; --source-delay: 0s;">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M13.8 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5H17V4c-.3 0-1.3-.1-2.5-.1-2.5 0-4.1 1.5-4.1 4.3V10H8v3h2.4v8h3.4Z"/>
                                </svg>
                            </span>
                            <span class="truthguard-verify-source-logo" style="--source-bg: linear-gradient(135deg, #f58529, #dd2a7b 48%, #515bd4); --source-color: #fff; --source-delay: 1.4s;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true">
                                    <rect x="4.8" y="4.8" width="14.4" height="14.4" rx="4.3"></rect>
                                    <circle cx="12" cy="12" r="3.15"></circle>
                                    <circle cx="16.85" cy="7.15" r="0.95" fill="currentColor" stroke="none"></circle>
                                </svg>
                            </span>
                            <span class="truthguard-verify-source-logo truthguard-verify-source-logo-wide" style="--source-bg: #0f6674; --source-color: #fff; --source-delay: 2.8s;">
                                VERA
                            </span>
                            <span class="truthguard-verify-source-logo" style="--source-bg: #ff5c1b; --source-color: #fff; --source-delay: 4.2s;">
                                R
                            </span>
                            <span class="truthguard-verify-source-logo truthguard-verify-source-logo-wide" style="--source-bg: #111827; --source-color: #fff; --source-delay: 5.6s;">
                                RTRS
                            </span>
                            <span class="truthguard-verify-source-logo truthguard-verify-source-logo-brand" style="--source-bg: #fff; --source-color: #0f172a; --source-delay: 7s;">
                                <img src="{{ $truthguardLogoUrl }}" alt="">
                            </span>
                        </div>
                        <div class="truthguard-scan-doc-lines">
                            <span></span>
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <div class="truthguard-scan-status">
                            <span></span>
                            <strong>Checking</strong>
                        </div>
                    </div>
                    <div class="truthguard-scan-magnifier" aria-hidden="true">
                        <svg viewBox="0 0 64 64" fill="none">
                            <circle cx="27" cy="27" r="15" fill="rgba(255,255,255,0.54)" stroke="currentColor" stroke-width="5"></circle>
                            <path d="M39 39 54 54" stroke="currentColor" stroke-width="6" stroke-linecap="round"></path>
                            <path d="M18 22c2.2-4 6.1-6.1 10.5-5.8" stroke="rgba(14,165,233,0.54)" stroke-width="3" stroke-linecap="round"></path>
                        </svg>
                    </div>
                    <div class="truthguard-scan-sparks" aria-hidden="true">
                        <span style="--spark-x: -4.4rem; --spark-y: -1.25rem; --spark-delay: -0.2s; --spark-color: #67e8f9;"></span>
                        <span style="--spark-x: -3.7rem; --spark-y: 0.35rem; --spark-delay: -1.05s; --spark-speed: 2.1s; --spark-size: 0.18rem; --spark-color: #38bdf8;"></span>
                        <span style="--spark-x: 3.7rem; --spark-y: -2.2rem; --spark-delay: -1.6s; --spark-size: 0.2rem; --spark-color: #a5b4fc;"></span>
                        <span style="--spark-x: 4.4rem; --spark-y: 1.75rem; --spark-delay: -2.3s; --spark-speed: 2.6s; --spark-size: 0.22rem; --spark-color: #bae6fd;"></span>
                    </div>

                </div>

                <div class="mt-5 flex flex-col items-center gap-3">
                    <p class="truthguard-verify-title" x-text="analyzeTitle()"></p>
                    <p class="truthguard-verify-copy" x-text="analyzeCopy()"></p>
                    <div class="truthguard-verify-progress" aria-hidden="true"></div>
                </div>
            </div>
        </div>
    </section>
</div>
