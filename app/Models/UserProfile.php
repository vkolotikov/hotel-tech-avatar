<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = ['user_id', 'goals', 'conditions', 'medications', 'dietary_flags', 'wearables_connected', 'height_cm', 'weight_kg', 'sex_at_birth', 'activity_level', 'profile_metadata'];

    protected function casts(): array
    {
        return [
            'goals' => 'array',
            'conditions' => 'array',
            'medications' => 'array',
            'dietary_flags' => 'array',
            'wearables_connected' => 'array',
            'profile_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
