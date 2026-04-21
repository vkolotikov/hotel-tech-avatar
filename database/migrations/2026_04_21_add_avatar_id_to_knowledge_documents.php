<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            // Add avatar_id as a nullable foreign key initially
            // This allows gradual migration from agent_id-only references
            $table->foreignId('avatar_id')
                ->nullable()
                ->after('agent_id')
                ->constrained('agents', 'id')
                ->cascadeOnDelete();

            // Index for efficient queries on avatar_id
            $table->index('avatar_id');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['avatar_id']);
            $table->dropIndex(['avatar_id']);
            $table->dropColumn('avatar_id');
        });
    }
};
