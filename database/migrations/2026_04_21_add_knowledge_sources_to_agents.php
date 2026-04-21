<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // Add knowledge_sources_json column for per-avatar knowledge source configuration
            if (!Schema::hasColumn('agents', 'knowledge_sources_json')) {
                $table->json('knowledge_sources_json')
                    ->nullable()
                    ->after('knowledge_files_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('knowledge_sources_json');
        });
    }
};
