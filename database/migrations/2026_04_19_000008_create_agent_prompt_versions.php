<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->text('system_instructions');
            $table->jsonb('persona_json')->nullable();
            $table->jsonb('scope_json')->nullable();
            $table->jsonb('red_flag_rules_json')->nullable();
            $table->jsonb('handoff_rules_json')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'version_number']);
            $table->index(['agent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_prompt_versions');
    }
};
