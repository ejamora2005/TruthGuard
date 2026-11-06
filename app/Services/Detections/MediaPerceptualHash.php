<?php

namespace App\Services\Detections;

/** Small dependency-free dHash implementation; null means safe fallback to SHA-256. */
final class MediaPerceptualHash
{
    public const VERSION = 'dhash64-v1';

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
