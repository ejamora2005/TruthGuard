<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CanonicalFactCheckResult extends Model
{
    protected $fillable = ['canonical_key', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
