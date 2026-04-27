<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The user picks their language as the very first onboarding step.
 * That choice (a) drives the app UI via i18n, (b) instructs every
 * avatar to reply in that language, (c) is passed to Whisper STT so
 * voice mode transcribes correctly.
 *
 * 5-char column ('en', 'pt-br', etc) keeps room for region-specific
 * codes if we ever want them, though v1 stores only base codes:
 * en, es, fr, de, pl, it, ru, uk, lv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('preferred_language', 5)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('preferred_language');
        });
    }
};
