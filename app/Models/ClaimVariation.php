<?php

namespace App\Models;

use App\Services\Detections\ReviewedClaimLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClaimVariation extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $variation): void {
            $variation->normalized_text = ReviewedClaimLookup::normalize($variation->text);
            $variation->text_hash = hash('sha256', $variation->normalized_text);
        });
    }

    public function reviewedClaim(): BelongsTo
    {
        return $this->belongsTo(ReviewedClaim::class);
    }
}
