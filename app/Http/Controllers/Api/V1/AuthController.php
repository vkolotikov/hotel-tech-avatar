<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and return a Sanctum token so the mobile client
     * stays signed in without needing a separate login round-trip. Same
     * response shape as login().
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'email'       => ['required', 'email', 'max:190', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8', 'max:120'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => strtolower($data['email']),
            'password' => Hash::make($data['password']),
        ]);

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'       => ['required', 'email'],
            'password'    => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken($data['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $plan  = $user->activePlan();
        $limit = $plan?->daily_message_limit;
        $used  = $limit !== null ? $user->messagesUsedToday() : 0;

        // Token budget — main quota the mobile app surfaces.
        $tokenBudget    = $plan?->monthly_token_limit;
        $tokensUsed     = $tokenBudget !== null ? $user->tokensUsedThisPeriod() : 0;
        $tokensRemaining = $tokenBudget === null ? null : max(0, $tokenBudget - $tokensUsed);

        $profile = $user->profile;

        return response()->json([
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'subscription' => [
                'plan'                  => $plan?->slug,
                'plan_name'             => $plan?->name,
                'daily_limit'           => $limit,
                'used_today'            => $used,
                'remaining_today'       => $limit === null ? null : max(0, $limit - $used),
                // Token budget surface. NULL = unlimited, otherwise the
                // mobile renders a "x of y tokens used" bar in Settings.
                // period_resets_at is ISO-8601; client formats as
                // relative time ("resets in 5 days") in the user's locale.
                'monthly_token_limit'   => $tokenBudget,
                'tokens_used_period'    => $tokensUsed,
                'tokens_remaining'      => $tokensRemaining,
                'period_resets_at'      => now()->addDays(30)->toIso8601String(),
                'features'              => $plan?->features ?? [],
            ],
            'profile' => $profile ? [
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
                // The mobile uses this flag to decide whether to push
                // the user into the profile-setup flow on first login.
                'is_complete'        => $this->isProfileComplete($profile),
            ] : null,
        ]);
    }

    /**
     * Heuristic for "this user has filled in enough to skip onboarding".
     * Mobile triggers profile-setup when this is false. Tuned to be
     * reasonable but not annoying — name + age signal + sex + body
     * baseline is enough to stop nagging. Everything else (habits,
     * coaching style, female health, etc) is optional and degrades
     * gracefully in the system prompt.
     */
    private function isProfileComplete(?\App\Models\UserProfile $profile): bool
    {
        if (!$profile) return false;
        return !empty($profile->display_name)
            && !empty($profile->sex_at_birth)
            && !empty($profile->height_cm)
            && !empty($profile->weight_kg)
            && !empty($profile->age_band);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
