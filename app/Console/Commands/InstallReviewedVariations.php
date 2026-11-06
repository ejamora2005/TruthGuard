<?php

namespace App\Console\Commands;

use App\Models\ClaimVariation;
use App\Models\ReviewedClaim;
use App\Services\Detections\ReviewedClaimLookup;
use Database\Seeders\ReviewedClaimSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InstallReviewedVariations extends Command
{
    protected $signature = 'truthguard:install-reviewed-variations
        {slug : Slug of one source-controlled reviewed claim}
        {--apply : Add the listed missing approved variations to this review}';

    protected $description = 'Preview or install bundled claim variations without replacing reviewer-owned verdicts, sources or review settings';

    public function handle(ReviewedClaimSeeder $seeder): int
    {
        $slug = (string) $this->argument('slug');
        $definition = $seeder->definitions()[$slug] ?? null;
        if (! $definition || ! ReviewedClaimLookup::available()) {
            $this->error('The bundled review or reviewed-claim tables are unavailable. No changes made.');

            return self::FAILURE;
        }
        try {
            $missing = DB::transaction(function () use ($slug, $definition): array {
                $review = ReviewedClaim::where('slug', $slug)->lockForUpdate()->first();
                if (! $review || ! $review->active || ! $review->reviewed_at || $review->reviewed_at->gt(now())
                    || ($review->expires_at && $review->expires_at->lte(now()))
                    || ReviewedClaimLookup::normalize($review->claim) !== ReviewedClaimLookup::normalize($definition['claim'])
                    || ($review->result['verdict'] ?? null) !== $definition['result']['verdict']
                    || empty($review->result['sources'])) {
                    throw new RuntimeException('An active, current review with the same canonical claim, verdict and saved sources is required. No changes made.');
                }
                $missing = [];
                foreach (array_unique([$definition['claim'], $definition['result']['claim'], ...$definition['variations']]) as $text) {
                    $hash = hash('sha256', ReviewedClaimLookup::normalize($text));
                    $existing = ClaimVariation::where('text_hash', $hash)->lockForUpdate()->first();
                    if ($existing && $existing->reviewed_claim_id !== $review->id) {
                        throw new RuntimeException('An approved variation belongs to another review. No changes made.');
                    }
                    if (! $existing) {
                        $missing[$hash] = $text;
                    }
                }
                if ($this->option('apply')) {
                    foreach ($missing as $text) {
                        $review->variations()->create(['text' => $text]);
                    }
                }

                return array_values($missing);
            });
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        foreach ($missing as $text) {
            $this->line($text);
        }
        $this->info($this->option('apply') ? count($missing).' approved variations installed.' : count($missing).' missing approved variations. Preview only; use --apply to install.');
        $this->line('Existing review data and saved reports were preserved. No model calls were made.');

        return self::SUCCESS;
    }
}
