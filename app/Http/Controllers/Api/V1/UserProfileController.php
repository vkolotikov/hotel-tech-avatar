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
 *   - Conditions, medications, allergies are sensitive. They flow to
 *     OpenAI only because the Phase-1 retrieval pipeline already does;
 *     ZDR confirmation governs the same surface (see
 *     docs/compliance/openai-zdr.md).
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
            'display_name'        => 'nullable|string|max:80',
            'pronouns'            => 'nullable|string|max:32',
            'sex_at_birth'        => 'nullable|in:F,M,I',
            'height_cm'           => 'nullable|integer|min:50|max:260',
            'weight_kg'           => 'nullable|integer|min:20|max:400',
            'activity_level'      => 'nullable|in:sedentary,light,moderate,active,athlete',
            'sleep_hours_target'  => 'nullable|integer|min:3|max:14',
            'goals'               => 'nullable|array',
            'goals.*'             => 'string|max:64',
            'conditions'          => 'nullable|array',
            'conditions.*'        => 'string|max:80',
            'medications'         => 'nullable|array',
            'medications.*'       => 'string|max:80',
            'dietary_flags'       => 'nullable|array',
            'dietary_flags.*'     => 'string|max:32',
            'allergies'           => 'nullable|array',
            'allergies.*'         => 'string|max:64',
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
            'display_name'       => $profile->display_name,
            'pronouns'           => $profile->pronouns,
            'sex_at_birth'       => $profile->sex_at_birth,
            'height_cm'          => $profile->height_cm,
            'weight_kg'          => $profile->weight_kg,
            'activity_level'     => $profile->activity_level,
            'sleep_hours_target' => $profile->sleep_hours_target,
            'goals'              => $profile->goals ?? [],
            'conditions'         => $profile->conditions ?? [],
            'medications'        => $profile->medications ?? [],
            'dietary_flags'      => $profile->dietary_flags ?? [],
            'allergies'          => $profile->allergies ?? [],
        ];
    }
}
