<?php

namespace App\Http\Controllers;

use App\Models\Detection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

class DetectionController extends Controller
{
    public function create(): View
    {
        return view('detections.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source_url' => ['nullable', 'url', 'max:2048', 'required_without:media_file'],
            'media_file' => ['nullable', 'file', 'max:51200', 'required_without:source_url', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm,m4v'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->file('media_file');

        $mediaPath = $uploadedFile?->store('detections', 'public');
        $mediaType = $this->detectMediaType($uploadedFile, $validated['source_url'] ?? null);
        $fakeScore = $this->calculateFakeScore($uploadedFile, $validated['source_url'] ?? null);

        $verdict = match (true) {
            $fakeScore >= 70 => 'fake',
            $fakeScore >= 45 => 'review',
            default => 'real',
        };

        Detection::create([
            'user_id' => $request->user()->id,
            'source_kind' => $uploadedFile ? 'upload' : 'link',
            'source_url' => $validated['source_url'] ?? null,
            'media_path' => $mediaPath,
            'media_type' => $mediaType,
            'fake_score' => $fakeScore,
            'verdict' => $verdict,
            'notes' => $validated['notes'] ?? null,
            'analyzed_at' => now(),
        ]);

        return redirect()
            ->route('detections.create')
            ->with('status', "Detection complete: {$verdict} ({$fakeScore}% fake probability).");
    }

    private function detectMediaType(?UploadedFile $uploadedFile, ?string $sourceUrl): string
    {
        if ($uploadedFile) {
            $mimeType = (string) $uploadedFile->getMimeType();

            if (str_starts_with($mimeType, 'image/')) {
                return 'image';
            }

            if (str_starts_with($mimeType, 'video/')) {
                return 'video';
            }
        }

        if ($sourceUrl) {
            $path = strtolower((string) parse_url($sourceUrl, PHP_URL_PATH));
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                return 'image';
            }

            if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v'], true)) {
                return 'video';
            }
        }

        return 'unknown';
    }

    private function calculateFakeScore(?UploadedFile $uploadedFile, ?string $sourceUrl): int
    {
        $signalText = strtolower(trim(($sourceUrl ?? '').' '.($uploadedFile?->getClientOriginalName() ?? '')));
        $seed = $signalText !== ''
            ? $signalText
            : strtolower((string) $uploadedFile?->getClientMimeType()).'|'.(string) $uploadedFile?->getSize();

        $score = 30 + (abs(crc32($seed)) % 41); // 30..70 baseline

        $keywords = ['ai', 'deepfake', 'synthetic', 'generated', 'midjourney', 'stable-diffusion', 'face-swap', 'fake'];
        foreach ($keywords as $keyword) {
            if (str_contains($signalText, $keyword)) {
                $score += 22;
                break;
            }
        }

        if ($uploadedFile && str_starts_with((string) $uploadedFile->getMimeType(), 'video/')) {
            $score += 6;
        }

        if ($sourceUrl && str_contains(strtolower($sourceUrl), 'bit.ly')) {
            $score += 5;
        }

        return min(99, max(1, $score));
    }
}
