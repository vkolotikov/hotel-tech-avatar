<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data migration to normalise the "Nutrition & Gut Health" / Nora
 * record created by the earlier NoraAvatarSeeder. If there is exactly one
 * wellness agent named "Nora" and its slug is anything other than "nora"
 * (e.g. the display string "Nutrition & Gut Health"), rename the slug.
 *
 * Safe: does nothing if a clean "nora"-slug record already exists (in which
 * case there would be a unique-constraint collision), does nothing if no
 * such record exists, and only touches the slug column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $wellness = DB::table('verticals')->where('slug', 'wellness')->value('id');
        if (!$wellness) {
            return;
        }

        // If a proper "nora"-slug record already exists, leave everything alone.
        $hasCanonical = DB::table('agents')
            ->where('vertical_id', $wellness)
            ->where('slug', 'nora')
            ->exists();
        if ($hasCanonical) {
            return;
        }

        // Find any wellness agent named "Nora" that doesn't have the canonical slug.
        $rows = DB::table('agents')
            ->where('vertical_id', $wellness)
            ->whereRaw('LOWER(name) = ?', ['nora'])
            ->get();

        if ($rows->count() === 1) {
            DB::table('agents')->where('id', $rows->first()->id)->update([
                'slug'       => 'nora',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: renaming the slug back to "Nutrition & Gut Health"
        // would undo a correctness fix, and we can't know which record to target.
    }
};
