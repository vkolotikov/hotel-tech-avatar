<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('agents')->orderBy('id')->get()->each(function ($agent) use ($now) {
            $versionId = DB::table('agent_prompt_versions')->insertGetId([
                'agent_id' => $agent->id,
                'version_number' => 1,
                'system_instructions' => $agent->system_instructions ?? '',
                'persona_json' => $agent->persona_json,
                'scope_json' => $agent->scope_json,
                'red_flag_rules_json' => $agent->red_flag_rules_json,
                'handoff_rules_json' => $agent->handoff_rules_json,
                'is_active' => true,
                'created_by_user_id' => null,
                'note' => 'Initial snapshot of pre-versioning prompt',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('agents')->where('id', $agent->id)->update(['active_prompt_version_id' => $versionId]);
        });
    }

    public function down(): void
    {
        DB::table('agents')->update(['active_prompt_version_id' => null]);
        DB::table('agent_prompt_versions')->where('version_number', 1)->delete();
    }
};
