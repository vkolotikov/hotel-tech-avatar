<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('parent_llm_call_id')->nullable()->constrained('llm_calls')->nullOnDelete();
            $table->string('purpose', 32)->default('generation');
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cost_usd_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('trace_id', 128)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('message_id');
            $table->index(['provider', 'model']);
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
    }
};
