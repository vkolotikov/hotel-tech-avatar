<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds prompt_suggestions_json to agents so the super-admin can author
 * the 3-4 starter prompts each avatar surfaces in the mobile app on a
 * fresh conversation. Stored as a JSON array of strings for v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->jsonb('prompt_suggestions_json')->nullable()->after('knowledge_sources_json');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('prompt_suggestions_json');
        });
    }
};
