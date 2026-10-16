<?php

namespace Tests\Unit;

use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\ModelHealthService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOriginHealthTest extends TestCase
{
    public function test_missing_origin_files_fail_before_starting_the_python_runtime(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('original.png', 'offline fixture');
        config()->set(['services.ai_origin.model_path' => Storage::disk('local')->path('missing-model'),
            'services.ai_origin.python_binary' => 'this-runtime-must-not-be-started']);
        $result = app(AiOriginClassifier::class)->classify(new UploadedFile(
            Storage::disk('local')->path('original.png'), 'original.png', 'image/png', null, true,
        ));
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('model_missing', $result['failure_code']);
        $this->assertNull($result['fake_probability']);
    }

    public function test_image_health_probe_loads_the_current_origin_model_and_never_mobilenet(): void
    {
        Storage::fake('local');
        foreach (['pytorch_model.pt', 'config.json', 'preprocessor_config.json', 'siglip_config.json', 'source.json'] as $file) {
            Storage::disk('local')->put('fixture-origin/'.$file, 'offline fixture');
        }
        config()->set(['services.ai_origin.enabled' => true,
            'services.ai_origin.model_path' => Storage::disk('local')->path('fixture-origin')]);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()
            ->withArgs(fn ($file) => $file instanceof UploadedFile && $file->isValid())
            ->andReturn(['status' => 'completed']);
        $method = new \ReflectionMethod(ModelHealthService::class, 'checkImageOrigin');
        $result = $method->invoke(app(ModelHealthService::class), true);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['loaded']);
    }

    public function test_disabled_origin_health_probe_never_starts_either_image_model(): void
    {
        config()->set('services.ai_origin.enabled', false);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $method = new \ReflectionMethod(ModelHealthService::class, 'checkImageOrigin');
        $result = $method->invoke(app(ModelHealthService::class), true);
        $this->assertFalse($result['enabled']);
        $this->assertArrayNotHasKey('loaded', $result);
    }
}
