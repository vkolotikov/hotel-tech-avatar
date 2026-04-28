<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promote the wellness avatars' default model from gpt-4o to gpt-5.5
 * once the Responses API path lands. The 04_23_000007 migration had
 * reverted them to gpt-4o because gpt-5.4 wasn't accessible on every
 * org tier; gpt-5.5 is now the recommended chat-quality default and
 * the LlmServiceProvider routes it through the Responses provider
 * (default `LLM_API_BACKEND=responses`).
 *
 * Why an admin-overridden model is left alone: anyone who deliberately
 * picked a non-default in the admin UI did so for a reason (smoke
 * testing, eval comparison, cost). Only rows that still hold the
 * previous default get bumped.
 *
 * Defaults `reasoning_effort` and `verbosity` to NULL on these rows so
 * env-level defaults (OPENAI_REASONING_EFFORT, OPENAI_VERBOSITY) apply
 * unless an admin opts in to a per-agent override later.
 *
 * Rollback: flip `LLM_API_BACKEND=chat` AND set `OPENAI_MODEL_DEFAULT=
 * gpt-4o` to revert behaviour without restoring the old DB rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('agents')
            ->whereIn('slug', ['integra', 'nora', 'luna', 'zen', 'axel', 'aura'])
            ->where('openai_model', 'gpt-4o')
            ->update([
                'openai_model' => 'gpt-5.5',
                'updated_at'   => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('agents')
            ->whereIn('slug', ['integra', 'nora', 'luna', 'zen', 'axel', 'aura'])
            ->where('openai_model', 'gpt-5.5')
            ->update([
                'openai_model' => 'gpt-4o',
                'updated_at'   => now(),
            ]);
    }
};
