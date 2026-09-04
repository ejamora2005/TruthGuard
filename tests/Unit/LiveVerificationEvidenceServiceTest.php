<?php

namespace Tests\Unit;

use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\TrustedFactCheckVerdictResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class LiveVerificationEvidenceServiceTest extends TestCase
{
    public function test_sona_class_suspension_claim_uses_government_verification_routes(): void
    {
        $service = new LiveVerificationEvidenceService(
            new TrustedFactCheckVerdictResolver(),
            new GoogleFactCheckFeedService(),
        );

        $reflection = new ReflectionClass($service);
        $detectTopic = $reflection->getMethod('detectTopic');
        $detectTopic->setAccessible(true);
        $buildOfficialSources = $reflection->getMethod('buildOfficialSources');
        $buildOfficialSources->setAccessible(true);

        $topic = $detectTopic->invoke(
            $service,
            'Walang Pasok sa July 27 dahil sa State of the National Address ng Pangulo BBM'
        );

        $sources = $buildOfficialSources->invoke($service, [], $topic, null, null);

        $this->assertSame('class_suspension', $topic);
        $this->assertContains('Official Gazette proclamations', array_column($sources, 'name'));
        $this->assertContains('Presidential Communications Office', array_column($sources, 'name'));
        $this->assertContains('Department of Education', array_column($sources, 'name'));
    }
}
