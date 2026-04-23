<?php

use App\Models\Agent;
use App\Support\Avatars\WellnessAvatarConfigs;
use Illuminate\Database\Migrations\Migration;

/**
 * Applies the full persona / scope / red-flag / handoff / starter-prompt
 * / knowledge-source config from WellnessAvatarConfigs to the six
 * wellness avatars already present in production.
 *
 * SAFE: for each field, only overwrites when the current value is null
 * or (for JSON fields) empty. If a super-admin has already edited any
 * field, that edit is preserved.
 *
 * system_instructions is treated slightly differently: it's overwritten
 * only if it's null OR starts with the short seeded one-liner. Manual
 * rewrites are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $configs = WellnessAvatarConfigs::all();

        foreach ($configs as $slug => $config) {
            $agent = Agent::where('slug', $slug)->first();
            if (!$agent) {
                continue;
            }

            $updates = [];

            // JSON fields — overwrite only if null or empty array.
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
                $current = $agent->{$key};
                $isEmpty = $current === null
                    || (is_array($current) && count($current) === 0);
                if ($isEmpty && isset($config[$key])) {
                    $updates[$key] = $config[$key];
                }
            }

            // system_instructions: overwrite if null OR looks like the short
            // one-liner seed. Treat any prompt over 400 chars as "manually
            // authored, leave it alone".
            $currentPrompt = (string) ($agent->system_instructions ?? '');
            if (isset($config['system_instructions'])
                && (trim($currentPrompt) === '' || mb_strlen($currentPrompt) < 400)
            ) {
                $updates['system_instructions'] = $config['system_instructions'];
            }

            if (!empty($updates)) {
                $agent->fill($updates);
                $agent->save();
            }
        }
    }

    public function down(): void
    {
        // Intentional no-op: this migration enriches empty config, it
        // doesn't destroy anything. Rolling it back would require remembering
        // per-row what was empty at up() time, which we don't track.
    }
};
