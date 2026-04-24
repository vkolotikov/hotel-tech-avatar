<?php

use App\Models\Agent;
use App\Support\Avatars\WellnessAvatarConfigs;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Broadens each wellness avatar's retrieval coverage by appending the
 * new knowledge_sources entries defined in WellnessAvatarConfigs.
 *
 * Original Phase-1 coverage was lean (2 PubMed queries per avatar,
 * except Nora which also has USDA + OFF). Debug runs against real data
 * confirmed retrieval is healthy but the query breadth was limiting
 * how many on-topic chunks any given user question can resolve to.
 * This migration widens each avatar to 4–6 distinct query angles —
 * e.g. Luna picks up sleep-apnea and melatonin in addition to her
 * existing CBT-I and circadian-rhythm queries.
 *
 * Merge rules:
 *   - Additive only. Never removes or modifies an existing entry.
 *   - Idempotent by `key` — if a source with the same key already
 *     exists (seeder ran, prior migration ran, super-admin added it
 *     manually), it's left alone.
 *   - Super-admin edits to existing entries survive untouched. New
 *     entries inherit the defaults from WellnessAvatarConfigs.
 *
 * Running this migration does NOT trigger a knowledge sync — it only
 * updates the config. Operator must run:
 *     php artisan knowledge:sync
 * afterwards to ingest chunks for the newly-added sources.
 */
return new class extends Migration
{
    public function up(): void
    {
        $verticalId = DB::table('verticals')->where('slug', 'wellness')->value('id');
        if (!$verticalId) {
            return;
        }

        $configs = WellnessAvatarConfigs::all();
        $addedTotal = 0;

        foreach ($configs as $slug => $config) {
            $targetSources = $config['knowledge_sources_json'] ?? [];
            if (empty($targetSources)) {
                continue;
            }

            $agent = Agent::whereRaw('LOWER(slug) = ?', [strtolower($slug)])
                ->where('vertical_id', $verticalId)
                ->first();
            if (!$agent) {
                continue;
            }

            $current = is_array($agent->knowledge_sources_json)
                ? $agent->knowledge_sources_json
                : [];

            $existingKeys = [];
            foreach ($current as $entry) {
                if (is_array($entry) && !empty($entry['key'])) {
                    $existingKeys[(string) $entry['key']] = true;
                }
            }

            $appended = 0;
            foreach ($targetSources as $target) {
                if (!is_array($target) || empty($target['key'])) {
                    continue;
                }
                if (isset($existingKeys[(string) $target['key']])) {
                    continue;
                }
                $current[] = $target;
                $existingKeys[(string) $target['key']] = true;
                $appended++;
            }

            if ($appended > 0) {
                $agent->update([
                    'knowledge_sources_json' => $current,
                    // Flag the agent for resync so `knowledge:status`
                    // surfaces the stale state until the operator
                    // runs `knowledge:sync`. Doesn't block the agent
                    // from serving chats in the meantime — existing
                    // chunks remain valid.
                    'knowledge_sync_status'  => 'pending',
                ]);
                $addedTotal += $appended;
                Log::info('ExpandWellnessKnowledgeSources: appended sources', [
                    'agent_id' => $agent->id,
                    'slug'     => $slug,
                    'appended' => $appended,
                ]);
            }
        }

        Log::info('ExpandWellnessKnowledgeSources: finished', [
            'total_appended' => $addedTotal,
        ]);
    }

    public function down(): void
    {
        // No-op. Reversing this migration would require remembering which
        // specific keys THIS migration added vs. prior state, which we
        // don't track. Rolling back is handled by editing
        // knowledge_sources_json directly via the admin UI if needed.
    }
};
