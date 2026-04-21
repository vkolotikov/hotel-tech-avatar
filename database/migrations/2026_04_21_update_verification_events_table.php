<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('verification_events', function (Blueprint $table) {
            // Make message_id nullable if it exists
            if (Schema::hasColumn('verification_events', 'message_id')) {
                $table->foreignId('message_id')->nullable()->change();
            }

            // Drop old columns if they exist
            if (Schema::hasColumn('verification_events', 'stage')) {
                $table->dropColumn('stage');
            }
            if (Schema::hasColumn('verification_events', 'passed')) {
                $table->dropColumn('passed');
            }

            // Add new columns
            if (!Schema::hasColumn('verification_events', 'conversation_id')) {
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            }
            if (!Schema::hasColumn('verification_events', 'avatar_id')) {
                $table->foreignId('avatar_id')->nullable()->constrained('agents')->nullOnDelete();
            }
            if (!Schema::hasColumn('verification_events', 'vertical_slug')) {
                $table->string('vertical_slug')->default('wellness');
            }
            if (!Schema::hasColumn('verification_events', 'response_text')) {
                $table->longText('response_text');
            }
            if (!Schema::hasColumn('verification_events', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }
            if (!Schema::hasColumn('verification_events', 'revision_count')) {
                $table->integer('revision_count')->default(0);
            }
            if (!Schema::hasColumn('verification_events', 'failures_json')) {
                $table->jsonb('failures_json')->nullable();
            }
            if (!Schema::hasColumn('verification_events', 'safety_flags_json')) {
                $table->jsonb('safety_flags_json')->nullable();
            }
            if (!Schema::hasColumn('verification_events', 'latency_ms')) {
                $table->integer('latency_ms')->nullable();
            }
            if (!Schema::hasColumn('verification_events', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        // Drop old index safely if it exists
        DB::statement('DROP INDEX IF EXISTS verification_events_message_id_stage_index');

        // Add new indexes
        Schema::table('verification_events', function (Blueprint $table) {
            $table->index(['conversation_id', 'is_verified']);
            $table->index(['avatar_id', 'is_verified']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('verification_events', function (Blueprint $table) {
            // Drop new columns
            if (Schema::hasColumn('verification_events', 'conversation_id')) {
                $table->dropForeign(['conversation_id']);
                $table->dropColumn('conversation_id');
            }
            if (Schema::hasColumn('verification_events', 'avatar_id')) {
                $table->dropForeign(['avatar_id']);
                $table->dropColumn('avatar_id');
            }
            if (Schema::hasColumn('verification_events', 'vertical_slug')) {
                $table->dropColumn('vertical_slug');
            }
            if (Schema::hasColumn('verification_events', 'response_text')) {
                $table->dropColumn('response_text');
            }
            if (Schema::hasColumn('verification_events', 'is_verified')) {
                $table->dropColumn('is_verified');
            }
            if (Schema::hasColumn('verification_events', 'revision_count')) {
                $table->dropColumn('revision_count');
            }
            if (Schema::hasColumn('verification_events', 'failures_json')) {
                $table->dropColumn('failures_json');
            }
            if (Schema::hasColumn('verification_events', 'safety_flags_json')) {
                $table->dropColumn('safety_flags_json');
            }
            if (Schema::hasColumn('verification_events', 'latency_ms')) {
                $table->dropColumn('latency_ms');
            }
            if (Schema::hasColumn('verification_events', 'updated_at')) {
                $table->dropColumn('updated_at');
            }

            // Re-add old columns
            $table->string('stage', 32);
            $table->boolean('passed');

            // Restore old index
            $table->index(['message_id', 'stage']);
        });

        // Drop new indexes safely
        DB::statement('DROP INDEX IF EXISTS verification_events_conversation_id_is_verified_index');
        DB::statement('DROP INDEX IF EXISTS verification_events_avatar_id_is_verified_index');
        DB::statement('DROP INDEX IF EXISTS verification_events_created_at_index');
    }
};
