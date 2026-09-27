<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    public const DEFAULTS = [
        'push_enabled' => false,
        'analysis_results' => true,
        'fact_check_updates' => true,
        'new_fact_checks' => true,
        'system_notifications' => true,
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_fill_keys(array_keys(self::DEFAULTS), 'boolean');
    }

    public static function forUser(User $user): array
    {
        $preference = static::where('user_id', $user->id)->first();

        return array_replace(self::DEFAULTS, $preference?->only(array_keys(self::DEFAULTS)) ?? []);
    }
}
