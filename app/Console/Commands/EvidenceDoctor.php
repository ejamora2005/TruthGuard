<?php

namespace App\Console\Commands;

use App\Services\Detections\AssessmentVerdict;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\EvidenceVerdictCalibration;
use App\Services\Detections\FilipinoNewsClassifier;
use Illuminate\Console\Command;

class EvidenceDoctor extends Command
{
    protected $signature = 'truthguard:evidence-doctor {--live : Make bounded provider/model smoke requests (may consume API quota)}';

    protected $description = 'Report configured evidence integrations without exposing keys or response bodies';

    public function handle(ArticleSearch $search, StructuredAnalysis $model, FilipinoNewsClassifier $classifier): int
    {
        $enhanced = (bool) config('fact_check.enhanced_enabled', true);
        $result = ['checked_at' => now()->utc()->toIso8601String(),
            'verdict_groups' => ['factual' => AssessmentVerdict::FACTUAL, 'context' => AssessmentVerdict::CONTEXT, 'media' => AssessmentVerdict::MEDIA],
            'evidence_calibration' => ['enabled' => (bool) config('fact_check.verdict_calibration.enabled', false),
                'pipeline_identity' => EvidenceVerdictCalibration::identity(),
                'meaning' => 'Agreement with independent evidence review within a tested task/verdict population; not claim-truth probability.',
                'readiness' => config('fact_check.verdict_calibration.enabled', false) ? 'artifact_validation_required_per_result' : 'disabled_no_confidence_claim'],
            'mode' => $this->option('live') ? 'live_smoke' : 'configuration_only',
            'search_adapter' => 'enhanced_article_search',
            'production_search_adapter' => $enhanced ? 'enhanced_article_search' : 'legacy_live_verification',
            'tests_active_production_adapter' => $enhanced,
            'providers' => []];
        foreach (['google_fact_check', 'gnews', 'newsapi', 'openai', 'openai_web_search'] as $provider) {
            $configProvider = $provider === 'openai_web_search' ? 'openai' : $provider;
            $result['providers'][$provider] = ['key_configured' => filled(config('services.'.$configProvider.'.key')), 'live_status' => 'not_tested'];
        }
        $result['providers']['openai_web_search']['model'] = config('fact_check.web_search.model');
        $result['providers']['openai_web_search']['enabled'] = (bool) config('fact_check.web_search.enabled');
        foreach (['automation/models/filipino-transformer/best_model/model.safetensors', 'automation/models/truthguard_image_model.keras', 'automation/models/truthguard_video_face_model.keras'] as $path) {
            $size = is_file(base_path($path)) ? filesize(base_path($path)) : 0;
            $result['artifacts'][basename($path)] = ['present' => $size > 0, 'bytes' => $size, 'possible_lfs_pointer' => $size > 0 && $size < 1024];
        }
        if ($this->option('live')) {
            config()->set('fact_check.retrieval.max_pages', 0);
            config()->set('fact_check.retrieval.max_search_requests', 4);
            config()->set('fact_check.web_search.max_calls', 1);
            config()->set('fact_check.retrieval.google_pages', 1);
            config()->set('fact_check.retrieval.max_candidates', 40);
            $budget = new RetrievalBudget;
            $search->retrieve(['Philippines public information'], $budget, now()->utc()->toIso8601String());
            foreach ($budget->providers as $provider => $status) {
                $result['providers'][$provider]['live_status'] = $status;
            }
            foreach ($budget->attempts as $attempt) {
                $result['providers'][$attempt['provider']]['last_search'] = [
                    'http_status' => $attempt['http_status'],
                    'content_type' => $attempt['content_type'],
                    'cache_hit' => $attempt['cache_hit'],
                    'response_fields' => $attempt['response_fields'],
                    'provider_total' => $attempt['provider_total'],
                    'returned_articles' => $attempt['raw_results'],
                    'availability' => $attempt['availability'],
                    'sanitized_error' => $attempt['sanitized_error'],
                ];
                if ($attempt['provider'] === 'openai_web_search') {
                    $meta = $attempt['web_search'];
                    $result['providers']['openai_web_search']['last_search']['response_status'] = $meta['response_status'];
                    $result['providers']['openai_web_search']['last_search']['search_calls'] = $meta['search_calls'];
                    $result['providers']['openai_web_search']['last_search']['consulted_source_count'] = count($meta['consulted_sources']);
                    $result['providers']['openai_web_search']['last_search']['citation_count'] = count($meta['citation_annotations']);
                    $result['providers']['openai_web_search']['last_search']['usage'] = $meta['usage'];
                }
            }
            $model->reset();
            $reply = $model->ask('integration_probe', 'Return ok=true. This is a schema/connectivity smoke test, not a fact-check.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']]));
            $result['providers']['openai']['live_status'] = $reply === ['ok' => true] ? 'structured_response_completed' : ($model->stages['integration_probe'] ?? 'unavailable');
            $sample = 'Ang ulat na ito ay isang halimbawa para sa pagsusuri ng koneksyon sa modelo. Tinatalakay nito ang pangangalap ng impormasyon, pagbabasa ng dokumento, at pag-unawa sa nilalaman ng isang artikulo. Walang konklusyon tungkol sa katotohanan ng anumang pangyayari ang dapat ibatay sa simpleng pagsubok na ito.';
            $prediction = $classifier->inspect($sample, 'synthetic connectivity probe');
            $result['trained_model'] = ['status' => $prediction['status'], 'version' => $prediction['prediction']['model_version'] ?? null, 'task' => $prediction['task'], 'accuracy' => 'not_measured'];
            $result['api_usage'] = $model->usage;
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
