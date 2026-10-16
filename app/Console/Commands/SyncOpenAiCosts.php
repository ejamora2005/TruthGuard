<?php

namespace App\Console\Commands;

use App\Services\Usage\OpenAiCostMonitor;
use Illuminate\Console\Command;

class SyncOpenAiCosts extends Command
{
    protected $signature = 'truthguard:sync-openai-costs';

    protected $description = 'Read OpenAI monthly costs and notify admins near or over the configured budget (no model calls)';

    public function handle(OpenAiCostMonitor $monitor): int
    {
        if (! filled(config('services.openai.admin_key'))) {
            $this->warn('Set OPENAI_ADMIN_API_KEY on the server to enable billing sync. No request was sent.');

            return self::FAILURE;
        }
        if (! $monitor->sync()) {
            $this->error('Cost sync unavailable or already running. The last successful amount was preserved.');

            return self::FAILURE;
        }
        $summary = $monitor->summary();
        $this->info('OpenAI reported cost: '.$summary['usedLabel'].' / '.$summary['budgetLabel'].' ('.$summary['scopeLabel'].'). Admin alert thresholds checked.');

        return self::SUCCESS;
    }
}
