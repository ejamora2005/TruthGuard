<?php

namespace App\Services\Detections;

/** Small dependency-free dHash implementation; null means safe fallback to SHA-256. */
final class MediaPerceptualHash
{
    public const VERSION = 'dhash64-verified-v2';

    /** A dHash is a candidate index, not proof of visual identity (flat colors collide). */
    public static function matchesPaths(?string $first, ?string $second): bool
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($first ?? '') || ! is_file($second ?? '')) {
            return false;
        }
        $images = [];
        try {
            foreach ([$first, $second] as $path) {
                $decoded = @imagecreatefromstring((string) file_get_contents($path));
                if ($decoded === false) {
                    return false;
                }
                $images[] = $decoded;
            }
            if (imagesx($images[0]) !== imagesx($images[1]) || imagesy($images[0]) !== imagesy($images[1])) {
                return false;
            }
            $samples = [];
            foreach ($images as $decoded) {
                $sample = imagecreatetruecolor(128, 128);
                $samples[] = $sample;
                $images[] = $sample;
                imagecopyresampled($sample, $decoded, 0, 0, 0, 0, 128, 128, imagesx($decoded), imagesy($decoded));
            }
            $difference = 0;
            for ($y = 0; $y < 128; $y++) {
                for ($x = 0; $x < 128; $x++) {
                    $left = imagecolorat($samples[0], $x, $y);
                    $right = imagecolorat($samples[1], $x, $y);
                    foreach ([0, 8, 16] as $shift) {
                        $delta = abs((($left >> $shift) & 255) - (($right >> $shift) & 255));
                        if ($delta > 24) {
                            return false;
                        }
                        $difference += $delta;
                    }
                }
            }

            return $difference / (128 * 128 * 3) <= 2;
        } finally {
            foreach ($images as $decoded) {
                imagedestroy($decoded);
            }
        }
    }

    public static function fromPath(?string $path): ?string
    {
        if (! is_string($path) || ! is_file($path) || ! function_exists('imagecreatefromstring')) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        $sample = imagecreatetruecolor(9, 8);
        if ($sample === false) {
            imagedestroy($source);

            return null;
        }
        imagecopyresampled($sample, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= self::luminance(imagecolorat($sample, $x, $y))
                    > self::luminance(imagecolorat($sample, $x + 1, $y)) ? '1' : '0';
            }
        }
        imagedestroy($sample);
        imagedestroy($source);

        return implode('', array_map(fn (string $chunk): string => dechex(bindec($chunk)), str_split($bits, 4)));
    }

    private static function luminance(int $color): float
    {
        $red = ($color >> 16) & 0xFF;
        $green = ($color >> 8) & 0xFF;
        $blue = $color & 0xFF;

        return .299 * $red + .587 * $green + .114 * $blue;
    }
}
