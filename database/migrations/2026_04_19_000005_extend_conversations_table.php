<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('vertical_id')->nullable()->after('agent_id')->constrained('verticals')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->after('vertical_id')->constrained('users')->nullOnDelete();
            $table->jsonb('summary_json')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('session_cost_usd_cents')->default(0);

            $table->index('vertical_id');
            $table->index('user_id');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['vertical_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['vertical_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn(['vertical_id', 'user_id', 'summary_json', 'last_activity_at', 'session_cost_usd_cents']);
        });
    }
};
