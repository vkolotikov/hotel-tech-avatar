<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET / PATCH /api/v1/me/profile.
 *
 * Backs the multi-step "tell us about you" onboarding (mobile slice 2)
 * and the Settings → Edit Profile screen. Endpoints accept partial
 * payloads — every field is optional and validated independently so
 * the client can save in any order. Storing partial state is fine;
 * SystemPromptBuilder degrades gracefully when fields are null.
 *
 * Privacy posture:
 *   - Conditions, medications, allergies, mental_health, ethnicity,
 *     family_history are sensitive. They flow to OpenAI only because
 *     the Phase-1 retrieval pipeline already does; ZDR confirmation
 *     governs the same surface (see docs/compliance/openai-zdr.md).
 *   - We never log the body of these requests at INFO/DEBUG.
 */
final class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile ?? UserProfile::create(['user_id' => $user->id]);

        return response()->json([
            'profile' => $this->present($profile),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            // Identity
            'display_name'         => 'nullable|string|max:80',
            'pronouns'             => 'nullable|string|max:32',

            // Demographics
            'birth_year'           => 'nullable|integer|min:1900|max:' . (int) date('Y'),
            'age_band'             => 'nullable|in:under-18,18-24,25-34,35-44,45-54,55-64,65+',
            'ethnicity'            => 'nullable|array',
            'ethnicity.*'          => 'string|max:48',

            // Body
            'sex_at_birth'         => 'nullable|in:F,M,I',
            'height_cm'            => 'nullable|integer|min:50|max:260',
            'weight_kg'            => 'nullable|integer|min:20|max:400',
            'waist_cm'             => 'nullable|integer|min:30|max:200',

            // Day shape
            'activity_level'       => 'nullable|in:sedentary,light,moderate,active,athlete',
            'job_type'             => 'nullable|in:desk,mixed,feet,physical,shift,none',
            'tracks_steps'         => 'nullable|boolean',
            'outdoor_minutes_band' => 'nullable|in:under-15,15-60,60-120,120+',
            'wellness_time_band'   => 'nullable|in:under-15,15-30,30-60,60+',

            // Sleep
            'sleep_hours_target'   => 'nullable|integer|min:3|max:14',
            'sleep_quality'        => 'nullable|in:great,okay,poor',
            'chronotype'           => 'nullable|in:morning,night,shift',

            // Habits
            'smoking_status'       => 'nullable|in:never,quit,occasional,daily',
            'alcohol_freq'         => 'nullable|in:none,light,moderate,heavy',
            'caffeine_freq'        => 'nullable|in:none,1-2,3-4,5+',
            'stress_level'         => 'nullable|in:low,medium,high',

            // Eating
            'eating_pattern'       => 'nullable|in:omnivore,pescatarian,vegetarian,vegan,mediterranean,keto,paleo,no-specific',
            'eating_schedule'      => 'nullable|in:3-meals,2-meals,if,snacky,skip-breakfast',
            'cooking_skill'        => 'nullable|in:none,basic,intermediate,advanced',
            'cooking_time_band'    => 'nullable|in:under-15,15-30,30-60,60+',
            'goals'                => 'nullable|array',
            'goals.*'              => 'string|max:64',
            'conditions'           => 'nullable|array',
            'conditions.*'         => 'string|max:80',
            'medications'          => 'nullable|array',
            'medications.*'        => 'string|max:80',
            'dietary_flags'        => 'nullable|array',
            'dietary_flags.*'      => 'string|max:32',
            'allergies'            => 'nullable|array',
            'allergies.*'          => 'string|max:64',
            'intolerances'         => 'nullable|array',
            'intolerances.*'       => 'string|max:32',
            'family_history'       => 'nullable|array',
            'family_history.*'     => 'string|max:48',
            'past_injuries'        => 'nullable|array',
            'past_injuries.*'      => 'string|max:32',
            'mental_health'        => 'nullable|array',
            'mental_health.*'      => 'string|max:48',

            // Life context
            'living_situation'     => 'nullable|in:alone,partner,family-kids,parents,roommates',
            'travel_frequency'     => 'nullable|in:rarely,monthly,weekly',
            'budget_conscious'     => 'nullable|boolean',

            // Female health
            'female_status'        => 'nullable|in:regular,irregular,trying,pregnant,breastfeeding,perimenopause,menopause,post-menopause,prefer-not-to-say',
            'pregnancy_weeks'      => 'nullable|integer|min:1|max:45',
            'breastfeeding_months' => 'nullable|integer|min:0|max:60',
            'cycle_length_days'    => 'nullable|integer|min:14|max:60',
            'contraception'        => 'nullable|in:none,pill,iud-copper,iud-hormonal,implant,patch,ring,injection,natural,prefer-not-to-say',

            // Motivation
            'motivation_trigger'   => 'nullable|in:health-scare,event,birthday,doctor,energy,specific-goal,ready,other',
            'motivation_text'      => 'nullable|string|max:500',
            'goal_timeline'        => 'nullable|in:weeks,months,year,no-deadline',
            'goal_confidence'      => 'nullable|integer|min:1|max:10',

            // Coaching style
            'coaching_tone'        => 'nullable|in:friendly,expert,direct,gentle',
            'coaching_detail'      => 'nullable|in:brief,balanced,thorough',
            'coaching_pace'        => 'nullable|in:slow,fast',
            'coaching_style'       => 'nullable|in:routines,variety',
            'accountability_style' => 'nullable|in:solo,track,coach,compete',
        ]);

        $profile = $user->profile ?? new UserProfile(['user_id' => $user->id]);
        $profile->fill($validated);
        $profile->save();

        return response()->json([
            'profile' => $this->present($profile->fresh()),
        ]);
    }

    /**
     * Stable JSON shape for both endpoints. Keeps every field present
     * (null when unset) so the mobile form doesn't need defensive
     * undefined-checks per field.
     */
    private function present(UserProfile $profile): array
    {
        return [
            // Identity
            'display_name'         => $profile->display_name,
            'pronouns'             => $profile->pronouns,
            // Demographics
            'birth_year'           => $profile->birth_year,
            'age_band'             => $profile->age_band,
            'ethnicity'            => $profile->ethnicity ?? [],
            // Body
            'sex_at_birth'         => $profile->sex_at_birth,
            'height_cm'            => $profile->height_cm,
            'weight_kg'            => $profile->weight_kg,
            'waist_cm'             => $profile->waist_cm,
            // Day shape
            'activity_level'       => $profile->activity_level,
            'job_type'             => $profile->job_type,
            'tracks_steps'         => $profile->tracks_steps,
            'outdoor_minutes_band' => $profile->outdoor_minutes_band,
            'wellness_time_band'   => $profile->wellness_time_band,
            // Sleep
            'sleep_hours_target'   => $profile->sleep_hours_target,
            'sleep_quality'        => $profile->sleep_quality,
            'chronotype'           => $profile->chronotype,
            // Habits
            'smoking_status'       => $profile->smoking_status,
            'alcohol_freq'         => $profile->alcohol_freq,
            'caffeine_freq'        => $profile->caffeine_freq,
            'stress_level'         => $profile->stress_level,
            // Eating
            'eating_pattern'       => $profile->eating_pattern,
            'eating_schedule'      => $profile->eating_schedule,
            'cooking_skill'        => $profile->cooking_skill,
            'cooking_time_band'    => $profile->cooking_time_band,
            'dietary_flags'        => $profile->dietary_flags ?? [],
            'allergies'            => $profile->allergies ?? [],
            'intolerances'         => $profile->intolerances ?? [],
            // Health
            'goals'                => $profile->goals ?? [],
            'conditions'           => $profile->conditions ?? [],
            'medications'          => $profile->medications ?? [],
            'family_history'       => $profile->family_history ?? [],
            'past_injuries'        => $profile->past_injuries ?? [],
            'mental_health'        => $profile->mental_health ?? [],
            // Life
            'living_situation'     => $profile->living_situation,
            'travel_frequency'     => $profile->travel_frequency,
            'budget_conscious'     => $profile->budget_conscious,
            // Female health
            'female_status'        => $profile->female_status,
            'pregnancy_weeks'      => $profile->pregnancy_weeks,
            'breastfeeding_months' => $profile->breastfeeding_months,
            'cycle_length_days'    => $profile->cycle_length_days,
            'contraception'        => $profile->contraception,
            // Motivation
            'motivation_trigger'   => $profile->motivation_trigger,
            'motivation_text'      => $profile->motivation_text,
            'goal_timeline'        => $profile->goal_timeline,
            'goal_confidence'      => $profile->goal_confidence,
            // Coaching style
            'coaching_tone'        => $profile->coaching_tone,
            'coaching_detail'      => $profile->coaching_detail,
            'coaching_pace'        => $profile->coaching_pace,
            'coaching_style'       => $profile->coaching_style,
            'accountability_style' => $profile->accountability_style,
        ];
    }
}
