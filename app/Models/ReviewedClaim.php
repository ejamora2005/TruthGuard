<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewedClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['result' => 'array', 'active' => 'boolean', 'reviewed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ClaimVariation::class);
    }
}
