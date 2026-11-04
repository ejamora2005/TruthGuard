<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReviewedMediaSeeder extends Seeder
{
    public function run(): void
    {
        $records = json_decode(ltrim(file_get_contents(database_path('data/reviewed-media.json')), "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($records): void {
            foreach ($records as $record) {
                if (! preg_match('/^[a-f0-9]{64}$/D', $record['sha256'])
                    || ! in_array($record['origin'], ['photograph', 'ai_generated'], true)) {
                    throw new \RuntimeException('Invalid reviewed media record.');
                }
                $existing = DB::table('reviewed_media')->where('slug', $record['slug'])->first();
                DB::table('reviewed_media')->updateOrInsert(['slug' => $record['slug']], [
                    'filename' => $record['filename'], 'sha256' => $record['sha256'],
                    'origin' => $record['origin'], 'summary' => $record['summary'],
                    'provenance' => json_encode($record['provenance'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    // Preserve an administrator's decision to disable an entry.
                    'active' => $existing?->active ?? true,
                    'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
                ]);
            }
        });
    }
};
