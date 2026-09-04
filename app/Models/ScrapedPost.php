<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapedPost extends Model
{
    protected $fillable = [
        'scrape_run_id',
        'detection_id',
        'source_key',
        'external_id',
        'post_fingerprint',
        'post_url',
        'display_name',
        'username',
        'caption_text',
        'posted_at',
        'source_links',
        'image_urls',
        'video_urls',
        'media_urls',
        'raw_payload',
        'scraped_at',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'scraped_at' => 'datetime',
            'source_links' => 'array',
            'image_urls' => 'array',
            'video_urls' => 'array',
            'media_urls' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function scrapeRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class);
    }

    public function detection(): BelongsTo
    {
        return $this->belongsTo(Detection::class);
    }
}
