<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personalisation fields the wellness avatars use when answering.
 *
 * Existing columns (height_cm, weight_kg, sex_at_birth, activity_level,
 * goals, conditions, medications, dietary_flags) cover the body /
 * lifestyle / safety surface. This migration adds a thin layer on top
 * that the *conversational* parts of the prompt need:
 *
 *   display_name        — the avatar greets / addresses the user by this.
 *                         Falls back to users.name when null.
 *   pronouns            — short string (e.g. "she/her", "they/them",
 *                         "él/he"). Optional. Improves how Zen / Aura /
 *                         Nora phrase encouragement.
 *   allergies           — jsonb list of allergens. Distinct from
 *                         conditions because the avatar logic treats
 *                         them differently — Nora must NEVER suggest a
 *                         food the user is allergic to, regardless of
 *                         nutritional fit.
 *   sleep_hours_target  — for Luna's nudges; lets Axel calibrate
 *                         recovery suggestions.
 *
 * All nullable so existing users keep working without a backfill —
 * UserProfileController handles partial updates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('display_name', 80)->nullable()->after('user_id');
            $table->string('pronouns', 32)->nullable()->after('display_name');
            $table->jsonb('allergies')->nullable()->after('dietary_flags');
            $table->unsignedTinyInteger('sleep_hours_target')->nullable()->after('activity_level');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'pronouns', 'allergies', 'sleep_hours_target']);
        });
    }
};
