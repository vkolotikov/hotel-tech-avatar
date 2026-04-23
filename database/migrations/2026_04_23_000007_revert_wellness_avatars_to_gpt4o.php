<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Integra / Luna / Zen / Axel / Aura were seeded with openai_model =
 * 'gpt-5.4' as their default, but that model isn't necessarily available
 * on every OpenAI account tier. Nora was seeded earlier when the default
 * was 'gpt-4o' and scores 88.24% in live evals — so revert the other
 * five to the same known-good model.
 *
 * Only touches rows that still have the default 'gpt-5.4' we set in the
 * seeder; any super-admin who deliberately picked a different model via
 * the admin UI is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('agents')
            ->whereIn('slug', ['integra', 'luna', 'zen', 'axel', 'aura'])
            ->where('openai_model', 'gpt-5.4')
            ->update([
                'openai_model' => 'gpt-4o',
                'updated_at'   => now(),
            ]);
    }

    public function down(): void
    {
        // No-op: we don't re-set anything to 'gpt-5.4' since we don't
        // know which rows were originally that vs. set manually.
    }
};
