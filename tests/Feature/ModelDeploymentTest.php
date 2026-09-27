<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModelDeploymentTest extends TestCase
{
    public function test_trained_model_artifacts_are_available_for_deployment(): void
    {
        $this->assertFileExists(base_path('automation/models/truthguard_image_model.keras'));
        $this->assertFileExists(base_path('automation/models/truthguard_video_face_model.keras'));
        $this->assertFileExists(base_path('automation/models/truthguard_image_model_config.json'));
        $this->assertFileExists(base_path('automation/models/truthguard_video_face_model_config.json'));
        $this->assertFileExists(base_path('automation/models/filipino-transformer/best_model/config.json'));
        $this->assertFileExists(base_path('automation/models/filipino-transformer/best_model/model.safetensors'));
        $this->assertGreaterThan(1024 * 1024, filesize(base_path('automation/models/filipino-transformer/best_model/model.safetensors')));
    }

    public function test_model_health_command_checks_configured_deployment_files(): void
    {
        $this->artisan('truthguard:models:health', ['--json' => true])
            ->assertExitCode(0);
    }

    public function test_compose_routes_laravel_to_internal_nlp_service(): void
    {
        $compose = file_get_contents(base_path('compose.yaml'));

        $this->assertStringContainsString('nlp:', $compose);
        $this->assertStringContainsString('TRUTHGUARD_NLP_URL: ${TRUTHGUARD_NLP_URL:-http://nlp:8765}', $compose);
        $this->assertStringNotContainsString('TRUTHGUARD_NLP_URL: ${TRUTHGUARD_NLP_URL:-http://127.0.0.1', $compose);
    }

    public function test_large_model_artifacts_are_declared_for_git_lfs(): void
    {
        $attributes = file_get_contents(base_path('.gitattributes'));

        $this->assertStringContainsString('/automation/models/*.keras filter=lfs', $attributes);
        $this->assertStringContainsString('/automation/models/filipino-transformer/best_model/*.safetensors filter=lfs', $attributes);
    }
}
