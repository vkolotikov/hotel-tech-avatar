<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the deep-personalisation columns introduced 2026-04-27. Every
 * column is nullable + has a sensible default so existing profiles keep
 * their state — onboarding completion is heuristic (display_name + sex
 * + height + weight set), not gated on these new fields.
 *
 * Grouped here for readability; the SystemPromptBuilder renders any
 * subset that's filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // ─── Demographics ────────────────────────────────────────────
            $table->smallInteger('birth_year')->nullable()->after('pronouns');
            $table->string('age_band', 8)->nullable()->after('birth_year');
            $table->jsonb('ethnicity')->nullable()->after('age_band');

            // ─── Body ────────────────────────────────────────────────────
            $table->unsignedSmallInteger('waist_cm')->nullable()->after('weight_kg');

            // ─── Day shape ───────────────────────────────────────────────
            $table->string('job_type', 24)->nullable()->after('activity_level');
            $table->boolean('tracks_steps')->default(false)->after('job_type');
            $table->string('outdoor_minutes_band', 16)->nullable()->after('tracks_steps');
            $table->string('wellness_time_band', 16)->nullable()->after('outdoor_minutes_band');

            // ─── Sleep depth ─────────────────────────────────────────────
            $table->string('sleep_quality', 8)->nullable()->after('sleep_hours_target');
            $table->string('chronotype', 16)->nullable()->after('sleep_quality');

            // ─── Habits ──────────────────────────────────────────────────
            $table->string('smoking_status', 16)->nullable()->after('chronotype');
            $table->string('alcohol_freq', 16)->nullable()->after('smoking_status');
            $table->string('caffeine_freq', 8)->nullable()->after('alcohol_freq');
            $table->string('stress_level', 8)->nullable()->after('caffeine_freq');

            // ─── Eating context ──────────────────────────────────────────
            $table->string('eating_pattern', 32)->nullable()->after('dietary_flags');
            $table->string('eating_schedule', 16)->nullable()->after('eating_pattern');
            $table->string('cooking_skill', 16)->nullable()->after('eating_schedule');
            $table->string('cooking_time_band', 16)->nullable()->after('cooking_skill');
            $table->jsonb('intolerances')->nullable()->after('cooking_time_band');

            // ─── Health context ──────────────────────────────────────────
            $table->jsonb('family_history')->nullable()->after('allergies');
            $table->jsonb('past_injuries')->nullable()->after('family_history');
            $table->jsonb('mental_health')->nullable()->after('past_injuries');

            // ─── Life context ────────────────────────────────────────────
            $table->string('living_situation', 24)->nullable()->after('mental_health');
            $table->string('travel_frequency', 16)->nullable()->after('living_situation');
            $table->boolean('budget_conscious')->nullable()->after('travel_frequency');

            // ─── Female health (only relevant when sex_at_birth=F) ───────
            $table->string('female_status', 24)->nullable()->after('budget_conscious');
            $table->unsignedTinyInteger('pregnancy_weeks')->nullable()->after('female_status');
            $table->unsignedTinyInteger('breastfeeding_months')->nullable()->after('pregnancy_weeks');
            $table->unsignedTinyInteger('cycle_length_days')->nullable()->after('breastfeeding_months');
            $table->string('contraception', 32)->nullable()->after('cycle_length_days');

            // ─── Motivation / story ──────────────────────────────────────
            $table->string('motivation_trigger', 32)->nullable()->after('contraception');
            $table->text('motivation_text')->nullable()->after('motivation_trigger');
            $table->string('goal_timeline', 16)->nullable()->after('motivation_text');
            $table->unsignedTinyInteger('goal_confidence')->nullable()->after('goal_timeline');

            // ─── Coaching style (the slide that shapes every reply) ──────
            $table->string('coaching_tone', 16)->nullable()->after('goal_confidence');
            $table->string('coaching_detail', 16)->nullable()->after('coaching_tone');
            $table->string('coaching_pace', 16)->nullable()->after('coaching_detail');
            $table->string('coaching_style', 16)->nullable()->after('coaching_pace');
            $table->string('accountability_style', 16)->nullable()->after('coaching_style');

            // ─── Avatar deep-dive plumbing (Tier-3 first-chat asks) ──────
            $table->jsonb('avatar_deepdive_pending')->nullable()->after('accountability_style');
            $table->jsonb('avatar_deepdive_data')->nullable()->after('avatar_deepdive_pending');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'birth_year', 'age_band', 'ethnicity',
                'waist_cm',
                'job_type', 'tracks_steps', 'outdoor_minutes_band', 'wellness_time_band',
                'sleep_quality', 'chronotype',
                'smoking_status', 'alcohol_freq', 'caffeine_freq', 'stress_level',
                'eating_pattern', 'eating_schedule', 'cooking_skill', 'cooking_time_band', 'intolerances',
                'family_history', 'past_injuries', 'mental_health',
                'living_situation', 'travel_frequency', 'budget_conscious',
                'female_status', 'pregnancy_weeks', 'breastfeeding_months', 'cycle_length_days', 'contraception',
                'motivation_trigger', 'motivation_text', 'goal_timeline', 'goal_confidence',
                'coaching_tone', 'coaching_detail', 'coaching_pace', 'coaching_style', 'accountability_style',
                'avatar_deepdive_pending', 'avatar_deepdive_data',
            ]);
        });
    }
};
