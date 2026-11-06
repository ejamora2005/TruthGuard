<?php

namespace Tests\Unit;

use App\Services\Detections\MediaPerceptualHash;
use PHPUnit\Framework\TestCase;

class MediaPerceptualHashTest extends TestCase
{
    public function test_it_returns_a_stable_64_bit_hash_when_gd_is_available(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not installed.');
        }

        $path = dirname(__DIR__).'/Fixtures/evidence/media/notice.png';
        $first = MediaPerceptualHash::fromPath($path);
        $second = MediaPerceptualHash::fromPath($path);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', (string) $first);
        $this->assertSame($first, $second);
    }
}
