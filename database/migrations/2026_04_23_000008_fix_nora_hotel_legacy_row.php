<?php

use App\Models\Agent;
use App\Support\Avatars\WellnessAvatarConfigs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalises the Nora row that inherited a hotel-legacy shape — slug
 * "Nutrition & Gut Health" (spaces + ampersand, not URL-safe), a spa
 * description, and all the wellness JSON config fields empty.
 *
 * The original enrichment migration (2026_04_23_000006) keyed off
 * slug='nora', so this row was silently skipped. Match by name
 * (case-insensitive) within the wellness vertical instead.
 *
 * Behaviour:
 *   - Rewrites slug to 'nora' if the current slug isn't already 'nora'
 *     AND no other agent is using that slug (uniqueness guard).
 *   - Overwrites description if it still references "spa" / "wellness
 *     treatments" / "relaxation therapies" (hotel-legacy strings), or
 *     is empty.
 *   - Overwrites system_instructions if it's null OR under 400 chars
 *     (same rule as the original enrichment migration — treats any
 *     longer prompt as manually authored and leaves it alone).
 *   - Fills every JSON config field (persona / scope / red-flag /
 *     handoff / prompt_suggestions / knowledge_sources) only when the
 *     current value is null or an empty array — super-admin edits are
 *     preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $wellnessId = DB::table('verticals')->where('slug', 'wellness')->value('id');
        if (!$wellnessId) {
            return;
        }

        $nora = Agent::whereRaw('LOWER(name) = ?', ['nora'])
            ->where('vertical_id', $wellnessId)
            ->first();

        if (!$nora) {
            return;
        }

        $config = WellnessAvatarConfigs::all()['nora'] ?? [];
        if (empty($config)) {
            return;
        }

        $updates = [];

        // Slug normalisation — avoid collision with any existing 'nora'.
        if ($nora->slug !== 'nora') {
            $collision = Agent::where('slug', 'nora')->where('id', '!=', $nora->id)->exists();
            if (!$collision) {
                $updates['slug'] = 'nora';
            }
        }

        // Description — overwrite legacy hotel strings or empty values.
        $currentDesc = (string) ($nora->description ?? '');
        $hotelLegacy = stripos($currentDesc, 'spa') !== false
            || stripos($currentDesc, 'wellness treatments') !== false
            || stripos($currentDesc, 'relaxation therapies') !== false;
        if (trim($currentDesc) === '' || $hotelLegacy) {
            $updates['description'] = 'Plain-English nutrition and gut-health education — food labels, meal composition, ingredient awareness. Not a dietitian.';
        }

        // Role sanity — if role is still "Nutrition & Gut Health" that's
        // actually fine, matches what we write in seeder. Only fix if empty.
        if (empty(trim((string) ($nora->role ?? '')))) {
            $updates['role'] = 'Nutrition & Gut Health';
        }

        // Domain — fill if empty.
        if (empty(trim((string) ($nora->domain ?? '')))) {
            $updates['domain'] = 'nutrition';
        }

        // system_instructions — overwrite if null/short (treats > 400 chars
        // as manually authored).
        $currentPrompt = (string) ($nora->system_instructions ?? '');
        if (trim($currentPrompt) === '' || mb_strlen($currentPrompt) < 400) {
            $updates['system_instructions'] = $config['system_instructions'];
        }

        // JSON config fields — only overwrite if null/empty array.
        foreach (
            [
                'persona_json',
                'scope_json',
                'red_flag_rules_json',
                'handoff_rules_json',
                'prompt_suggestions_json',
                'knowledge_sources_json',
            ] as $key
        ) {
            $current = $nora->{$key};
            $isEmpty = $current === null
                || (is_array($current) && count($current) === 0);
            if ($isEmpty && isset($config[$key])) {
                $updates[$key] = $config[$key];
            }
        }

        if (!empty($updates)) {
            $nora->fill($updates);
            $nora->save();
        }
    }

    public function down(): void
    {
        // No-op: this migration enriches empty / legacy state. We don't
        // track the previous values, and rolling back would reintroduce
        // the broken slug and spa description.
    }
};
