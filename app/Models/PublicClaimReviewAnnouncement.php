<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class PublicClaimReviewAnnouncement extends Model
{
    protected $fillable = [
        'feed_item_id',
        'publisher',
        'headline',
        'claim',
        'rating',
        'source_domain',
        'url',
        'image_url',
        'published_at',
        'announced_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'announced_at' => 'datetime',
        ];
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(PublicClaimReviewEmailDelivery::class);
    }
}
