<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        // Identity
        'display_name', 'pronouns',
        // Demographics
        'birth_year', 'age_band', 'ethnicity',
        // Body
        'sex_at_birth', 'height_cm', 'weight_kg', 'waist_cm',
        // Day shape
        'activity_level', 'job_type', 'tracks_steps',
        'outdoor_minutes_band', 'wellness_time_band',
        // Sleep
        'sleep_hours_target', 'sleep_quality', 'chronotype',
        // Habits
        'smoking_status', 'alcohol_freq', 'caffeine_freq', 'stress_level',
        // Eating
        'dietary_flags', 'allergies', 'eating_pattern', 'eating_schedule',
        'cooking_skill', 'cooking_time_band', 'intolerances',
        // Health
        'goals', 'conditions', 'medications',
        'family_history', 'past_injuries', 'mental_health',
        // Life context
        'living_situation', 'travel_frequency', 'budget_conscious',
        // Female health
        'female_status', 'pregnancy_weeks', 'breastfeeding_months',
        'cycle_length_days', 'contraception',
        // Motivation
        'motivation_trigger', 'motivation_text', 'goal_timeline', 'goal_confidence',
        // Coaching style
        'coaching_tone', 'coaching_detail', 'coaching_pace',
        'coaching_style', 'accountability_style',
        // Avatar deep-dives
        'avatar_deepdive_pending', 'avatar_deepdive_data',
        // Wearables + freeform
        'wearables_connected', 'profile_metadata',
    ];

    protected function casts(): array
    {
        return [
            // Multi-select chips
            'goals'                      => 'array',
            'conditions'                 => 'array',
            'medications'                => 'array',
            'dietary_flags'              => 'array',
            'allergies'                  => 'array',
            'ethnicity'                  => 'array',
            'intolerances'               => 'array',
            'family_history'             => 'array',
            'past_injuries'              => 'array',
            'mental_health'              => 'array',
            'wearables_connected'        => 'array',
            'profile_metadata'           => 'array',
            'avatar_deepdive_pending'    => 'array',
            'avatar_deepdive_data'       => 'array',
            // Numerics
            'height_cm'                  => 'integer',
            'weight_kg'                  => 'integer',
            'waist_cm'                   => 'integer',
            'birth_year'                 => 'integer',
            'sleep_hours_target'         => 'integer',
            'pregnancy_weeks'            => 'integer',
            'breastfeeding_months'       => 'integer',
            'cycle_length_days'          => 'integer',
            'goal_confidence'            => 'integer',
            // Booleans
            'tracks_steps'               => 'boolean',
            'budget_conscious'           => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
