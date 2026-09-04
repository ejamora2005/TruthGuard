<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use App\Models\ScrapedPost;

class Detection extends Model
{
    protected $fillable = [
        'user_id',
        'source_kind',
        'platform',
        'source_url',
        'media_path',
        'media_checksum',
        'media_type',
        'request_fingerprint',
        'reused_from_detection_id',
        'caption_text',
        'fake_score',
        'processing_status',
        'preprocessing_summary',
        'analysis_summary',
        'verification_summary',
        'explanation_summary',
        'recommendation',
        'signals',
        'verification_sources',
        'verdict',
        'notes',
        'analyzed_at',
        'archived_at',
        'archive_reason',
    ];

    protected function casts(): array
    {
        return [
            'analyzed_at' => 'datetime',
            'archived_at' => 'datetime',
            'signals' => 'array',
            'verification_sources' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reusedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reused_from_detection_id');
    }

    public function reusedDetections(): HasMany
    {
        return $this->hasMany(self::class, 'reused_from_detection_id');
    }

    public function scrapedPosts(): HasMany
    {
        return $this->hasMany(ScrapedPost::class);
    }

    public function getMediaUrlAttribute(): ?string
    {
        if ($this->media_path) {
            $normalizedPath = ltrim(str_replace('\\', '/', (string) $this->media_path), '/');

            return '/storage/'.$normalizedPath;
        }

        return $this->source_url;
    }
}
