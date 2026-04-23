<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentPromptVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots every agent's current system_instructions + persona + scope +
 * red-flag + handoff config as a new row in agent_prompt_versions and
 * activates it. Useful as a "Phase-1 baseline" rollback point after you
 * tune prompts via the admin.
 *
 *   php artisan prompts:snapshot-all
 *   php artisan prompts:snapshot-all --note="Phase-1 baseline"
 *   php artisan prompts:snapshot-all --vertical=wellness --note="..."
 */
class PromptsSnapshotAllCommand extends Command
{
    protected $signature = 'prompts:snapshot-all
        {--vertical= : Only snapshot agents in this vertical slug (e.g. wellness)}
        {--note= : Note attached to every snapshot, for audit trail}';

    protected $description = 'Create an active prompt-version snapshot for every agent (or every agent in a vertical).';

    public function handle(): int
    {
        $vertical = $this->option('vertical');
        $note = $this->option('note');

        $query = Agent::query();
        if ($vertical) {
            $query->whereHas('vertical', fn ($q) => $q->where('slug', $vertical));
        }
        $agents = $query->orderBy('name')->get();

        if ($agents->isEmpty()) {
            $this->warn($vertical ? "no agents found in vertical '{$vertical}'" : 'no agents found');
            return self::SUCCESS;
        }

        $created = 0;
        foreach ($agents as $agent) {
            $nextNumber = ((int) $agent->promptVersions()->max('version_number')) + 1;

            DB::transaction(function () use ($agent, $nextNumber, $note) {
                $agent->promptVersions()
                    ->where('is_active', true)
                    ->update(['is_active' => false]);

                $version = AgentPromptVersion::create([
                    'agent_id'            => $agent->id,
                    'version_number'      => $nextNumber,
                    'system_instructions' => $agent->system_instructions,
                    'persona_json'        => $agent->persona_json,
                    'scope_json'          => $agent->scope_json,
                    'red_flag_rules_json' => $agent->red_flag_rules_json,
                    'handoff_rules_json'  => $agent->handoff_rules_json,
                    'is_active'           => true,
                    'note'                => $note,
                    'created_by_user_id'  => null,
                ]);

                $agent->update(['active_prompt_version_id' => $version->id]);
            });

            $created++;
            $this->line(sprintf('  snapshotted %s (%s) → v%d', $agent->name, $agent->slug, $nextNumber));
        }

        $this->info("snapshotted {$created} agent" . ($created === 1 ? '' : 's'));
        return self::SUCCESS;
    }
}
