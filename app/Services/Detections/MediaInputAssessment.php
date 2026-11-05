<?php

namespace App\Services\Detections;

use Illuminate\Http\UploadedFile;

/** Read-only input and provenance signals. Missing metadata is never evidence of AI generation. */
class MediaInputAssessment
{
    public const VERSION = 'media-input-assessment-v1';

    public static function inspect(?UploadedFile $file): array
    {
        $path = $file?->getRealPath();
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            return ['version' => self::VERSION, 'status' => 'unavailable', 'limitations' => ['The original image could not be inspected.']];
        }

        $size = @getimagesize($path);
        if (! is_array($size) || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            return ['version' => self::VERSION, 'status' => 'unavailable', 'limitations' => ['The upload is not a readable raster image.']];
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];
        $bytes = max(0, (int) filesize($path));
        $mime = strtolower((string) ($size['mime'] ?? $file?->getMimeType() ?? ''));
        $pixels = max(1, $width * $height);
        $bytesPerPixel = $bytes / $pixels;
        $exif = in_array($mime, ['image/jpeg', 'image/tiff'], true) && function_exists('exif_read_data')
            ? (@exif_read_data($path, null, true, false) ?: []) : [];
        $cameraMake = trim((string) data_get($exif, 'IFD0.Make', ''));
        $cameraModel = trim((string) data_get($exif, 'IFD0.Model', ''));
        $software = trim((string) data_get($exif, 'IFD0.Software', ''));
        $hasCameraRecord = $cameraMake !== '' || $cameraModel !== '';
        $sample = (string) @file_get_contents($path, false, null, 0, min($bytes, 4 * 1024 * 1024));
        $hasContentCredentials = stripos($sample, 'c2pa') !== false
            || stripos($sample, 'contentauth') !== false || stripos($sample, 'content credentials') !== false;

        $flags = [];
        if (min($width, $height) < 384) $flags[] = 'low_resolution';
        if (in_array($mime, ['image/jpeg', 'image/webp'], true) && $bytesPerPixel < .08) {
            $flags[] = 'heavy_compression_or_resizing';
        }
        $ratio = max($width, $height) / max(1, min($width, $height));
        $screenshotLikely = ! $hasCameraRecord
            && in_array($mime, ['image/png', 'image/webp'], true)
            && ($ratio >= 1.55 || ($height > $width && $height >= 900));
        if ($screenshotLikely) $flags[] = 'screenshot_or_composite_likely';

        $limitations = [];
        if (in_array('low_resolution', $flags, true)) $limitations[] = 'The image is small, which can hide or distort detector signals.';
        if (in_array('heavy_compression_or_resizing', $flags, true)) $limitations[] = 'The file appears heavily compressed or resized, which can reduce detector reliability.';
        if ($screenshotLikely) $limitations[] = 'The upload appears to be a screenshot or composite; the surrounding interface, text, and embedded photograph cannot be treated as one original camera image.';

        return [
            'version' => self::VERSION,
            'status' => 'completed',
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'mime_type' => $mime,
            'bytes_per_pixel' => round($bytesPerPixel, 4),
            'quality_flags' => $flags,
            'scope' => $screenshotLikely ? 'screenshot_or_composite' : 'whole_image',
            'region_analysis' => $screenshotLikely ? 'embedded_media_not_isolated' : 'not_required',
            'reliability_limited' => $flags !== [],
            'decision_limited' => in_array('low_resolution', $flags, true)
                || in_array('heavy_compression_or_resizing', $flags, true),
            'provenance' => [
                'content_credentials_present' => $hasContentCredentials,
                'camera_record_present' => $hasCameraRecord,
                'camera_make' => $cameraMake !== '' ? $cameraMake : null,
                'camera_model' => $cameraModel !== '' ? $cameraModel : null,
                'software' => $software !== '' ? $software : null,
                'absence_is_not_evidence' => true,
            ],
            'limitations' => $limitations,
        ];
    }
}
