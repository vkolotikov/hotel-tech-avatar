<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('conversation_id')->constrained('agents')->nullOnDelete();
            $table->string('verification_status', 16)->default('not_required')->after('retrieval_source_count');
            $table->foreignId('handoff_from_agent_id')->nullable()->after('verification_status')->constrained('agents')->nullOnDelete();
            $table->unsignedInteger('claim_count')->nullable();
            $table->unsignedInteger('grounded_claim_count')->nullable();
            $table->boolean('red_flag_triggered')->default(false);

            $table->index('agent_id');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['handoff_from_agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['agent_id', 'verification_status', 'handoff_from_agent_id', 'claim_count', 'grounded_claim_count', 'red_flag_triggered']);
        });
    }
};
