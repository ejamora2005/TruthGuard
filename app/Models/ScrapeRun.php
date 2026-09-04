<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeRun extends Model
{
    protected $fillable = [
        'triggered_by_user_id',
        'trigger_source',
        'source_key',
        'target_url',
        'status',
        'browser_name',
        'headless',
        'screenshot_path',
        'options',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'headless' => 'boolean',
            'options' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ScrapedPost::class);
    }
}
