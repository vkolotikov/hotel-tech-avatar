<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentPromptVersion;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentPromptVersionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_agents_have_active_prompt_version_snapshot(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Agent::all()->each(function (Agent $agent) {
            $this->assertNotNull($agent->active_prompt_version_id, "Agent {$agent->slug} missing active prompt version");
            $version = $agent->activePromptVersion;
            $this->assertSame($agent->system_instructions, $version->system_instructions);
            $this->assertSame(1, $version->version_number);
            $this->assertTrue($version->is_active);
        });
    }

    public function test_can_publish_a_new_version(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();
        AgentPromptVersion::create([
            'agent_id' => $agent->id,
            'version_number' => 2,
            'system_instructions' => 'Updated instructions',
            'persona_json' => null,
            'scope_json' => null,
            'red_flag_rules_json' => null,
            'handoff_rules_json' => null,
            'is_active' => false,
            'created_by_user_id' => null,
            'note' => 'manual test',
        ]);

        $this->assertEquals(2, $agent->promptVersions()->count());
    }
}
