<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'display_name',
        'pronouns',
        'goals',
        'conditions',
        'medications',
        'dietary_flags',
        'allergies',
        'wearables_connected',
        'height_cm',
        'weight_kg',
        'sex_at_birth',
        'activity_level',
        'sleep_hours_target',
        'profile_metadata',
    ];

    protected function casts(): array
    {
        return [
            'goals' => 'array',
            'conditions' => 'array',
            'medications' => 'array',
            'dietary_flags' => 'array',
            'allergies' => 'array',
            'wearables_connected' => 'array',
            'profile_metadata' => 'array',
            'height_cm' => 'integer',
            'weight_kg' => 'integer',
            'sleep_hours_target' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
