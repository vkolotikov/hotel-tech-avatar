<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent LLM tuning for the OpenAI Responses API: reasoning effort
 * and output verbosity. Both are NULL by default — when NULL the
 * GenerationService falls back to env-driven defaults
 * (OPENAI_REASONING_EFFORT, OPENAI_VERBOSITY), so existing rows keep
 * working unchanged.
 *
 * - reasoning_effort: 'low' | 'medium' | 'high' | 'xhigh' — only sent
 *   to gpt-5/o-series models (the provider gates on model id).
 *   Recommended baseline for chat-style turns is 'low'; 'medium'/'high'
 *   for tasks where evals show a measurable lift.
 * - verbosity: 'low' | 'medium' | 'high' — controls output length /
 *   density. Conversational chat wants 'low'; the user can ask for
 *   detail and the model will expand on a per-turn basis. API default
 *   is 'medium'.
 *
 * Stored as varchar(8) rather than enum so we can add new tiers (e.g.
 * 'minimal', 'xhigh') without a schema migration when OpenAI ships them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('reasoning_effort', 8)->nullable()->after('openai_model');
            $table->string('verbosity', 8)->nullable()->after('reasoning_effort');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['reasoning_effort', 'verbosity']);
        });
    }
};
