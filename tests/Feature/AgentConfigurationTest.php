<?php

namespace Tests\Feature;

use App\Models\Agent;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_has_configuration_columns(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();
        $agent->update([
            'domain' => 'hospitality',
            'persona_json' => ['voice' => 'nova', 'accent' => '#8B5CF6'],
            'scope_json' => ['allowed' => ['hotel-services']],
            'red_flag_rules_json' => [],
            'handoff_rules_json' => ['fallback' => null],
        ]);

        $agent->refresh();

        $this->assertSame('hospitality', $agent->domain);
        $this->assertSame('nova', $agent->persona_json['voice']);
        $this->assertIsArray($agent->scope_json);
    }
}
