<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppearancePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public static function layouts(): array
    {
        return ['user' => [false], 'admin' => [true]];
    }

    #[DataProvider('layouts')]
    public function test_reading_preferences_render_in_each_settings_layout_without_external_calls(bool $admin): void
    {
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $user = User::factory()->create(['is_admin' => $admin]);
        $response = $this->actingAs($user)->get('/profile?section=appearance')->assertOk()
            ->assertSee('Text size')->assertSee('Extra large')->assertSee('Live preview')
            ->assertSee('TruthGuardAppearance', false);

        if (getenv('TRUTHGUARD_APPEARANCE_BROWSER_FIXTURE')) {
            file_put_contents(storage_path('tmp-appearance-'.($admin ? 'admin' : 'user').'.html'), $response->getContent());
        }

        Http::assertNothingSent();
    }

    public function test_user_workspace_pages_render_with_shared_display_controls_without_external_requests(): void
    {
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $item = ['id' => 'sample-review', 'headline' => 'Sample fact-check headline for the display fixture',
            'claim' => 'A sample claim and its recorded review, used only to check the interface.',
            'publisher' => 'Example newsroom', 'rating' => 'False', 'claimant' => 'Sample post',
            'host' => 'example.org', 'source_domain' => 'example.org', 'url' => 'https://example.org/review',
            'image_url' => '/images/fact-check-preview.svg', 'logo_url' => '/images/truthguard-logo.png',
            'date_label' => 'Oct 16, 2026', 'query' => 'Sample review'];
        $feed = $this->mock(GoogleFactCheckFeedService::class);
        $feed->shouldReceive('latest')->andReturn(['items' => [$item], 'configured' => true,
            'updated_at' => now(), 'max_age_days' => 7]);
        $feed->shouldReceive('find')->with('sample-review')->andReturn($item);
        $feed->shouldReceive('relatedTo')->andReturn([]);
        $user = User::factory()->create();
        $result = ReportAnswer::complete(MediaAnalysisReport::result(['status' => 'completed', 'media_type' => 'image',
            'label' => 'fake', 'fake_probability' => .99, 'threshold' => .5, 'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]], ['status' => 'not_requested']));
        $report = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'caption_text' => 'Is this sample AI-generated?', 'verdict' => 'fake', 'fake_score' => 99,
            'source_url' => '/images/fact-check-preview.svg', 'processing_status' => 'completed',
            'analyzed_at' => now(), 'fact_check_result' => $result]);
        $notice = app(NotificationEventService::class)->system($user, 'Display fixture', 'A sample account update.');
        $this->actingAs($user);
        $pages = ['dashboard' => route('dashboard'), 'create' => route('detections.create'),
            'result' => route('detections.result', $report), 'history' => route('history'),
            'inbox' => route('notifications.index'), 'notice' => route('notifications.show', $notice->id),
            'article' => route('dashboard.fact-check', 'sample-review')];
        foreach (['personal', 'appearance', 'photo', 'security', 'notifications', 'account'] as $section) {
            $pages['settings-'.$section] = route('profile', ['section' => $section]);
        }
        foreach ($pages as $name => $url) {
            $response = $this->get($url)->assertOk()->assertSee('role="switch"', false);
            if (getenv('TRUTHGUARD_DARK_MODE_BROWSER_FIXTURE')) {
                file_put_contents(storage_path('tmp-dark-mode-'.$name.'.html'), $response->getContent());
            }
        }
        Http::assertNothingSent();
    }

    public function test_react_admin_shell_loads_the_same_saved_appearance_preference(): void
    {
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $response = $this->get('/admin/dashboard')->assertOk()
            ->assertSee('TruthGuardAppearance', false)->assertSee('truthguard-display-mode', false);
        if (getenv('TRUTHGUARD_DARK_MODE_BROWSER_FIXTURE')) {
            file_put_contents(storage_path('tmp-dark-mode-admin.html'), $response->getContent());
        }
        Http::assertNothingSent();
    }
}
