<?php

use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

// Render the real create view with local, synthetic feed cards. No database rows
// or provider requests are made, and no application credentials enter the fixture.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config()->set([
    'session.driver' => 'array',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'services.google_picker' => [],
    'services.google.client_id' => '',
    'services.google_fact_check.key' => '',
]);

$request = Request::create('http://127.0.0.1:8000/detections/create');
$request->setLaravelSession(app('session')->driver());
$request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
$app->instance('request', $request);
app('url')->setRequest($request);
auth()->setUser(User::factory()->make([
    'id' => 0,
    'name' => 'UI fixture',
    'email' => 'fixture@example.test',
    'onboarding_completed_at' => now(),
]));
app('view')->share('errors', new ViewErrorBag);

$app->instance(GoogleFactCheckFeedService::class, new class extends GoogleFactCheckFeedService
{
    public function latest(int $limit = 10, ?int $maxAgeDays = null): array
    {
        return ['items' => array_map(fn (int $index): array => [
            'id' => 'fixture-'.$index,
            'headline' => 'Synthetic public review '.$index.' for create page layout verification',
            'claim' => 'A demonstration review used only for browser layout checks.',
            'rating' => 'False',
            'tone' => 'false',
            'publisher' => 'Fixture publisher',
            'image_url' => asset('images/truthguard-logo.png'),
            'url' => 'https://example.invalid/fixture-'.$index,
            'date_label' => '2026-10-14',
        ], range(1, 3))];
    }
});

$directory = storage_path('tmp-create-browser');
if (! is_dir($directory)) {
    mkdir($directory, 0755, true);
}
file_put_contents($directory.'/create.html', view('detections.create', ['selectedDetection' => null])->render());
echo "Rendered synthetic create-page fixture without external requests or database writes.\n";
