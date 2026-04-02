<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Detection extends Model
{
    protected $fillable = [
        'user_id',
        'source_kind',
        'source_url',
        'media_path',
        'media_type',
        'fake_score',
        'verdict',
        'notes',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analyzed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getMediaUrlAttribute(): ?string
    {
        if ($this->media_path) {
            return Storage::disk('public')->url($this->media_path);
        }

        return $this->source_url;
    }
}
