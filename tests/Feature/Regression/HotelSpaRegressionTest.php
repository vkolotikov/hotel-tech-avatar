<?php

namespace Tests\Feature\Regression;

use App\Models\Agent;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelSpaRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_agents_index_returns_four_published_hotel_agents(): void
    {
        $response = $this->getJson('/api/v1/agents');

        $response->assertOk();
        $response->assertJsonCount(4);
        $response->assertJsonFragment(['slug' => 'hotel-concierge']);
        $response->assertJsonFragment(['slug' => 'spa-therapist']);
        $response->assertJsonFragment(['slug' => 'events-coordinator']);
        $response->assertJsonFragment(['slug' => 'culinary-guide']);
    }

    public function test_agent_show_returns_existing_fields(): void
    {
        $agent = Agent::where('slug', 'spa-therapist')->firstOrFail();

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'id', 'slug', 'name', 'role', 'description',
            'avatar_image_url', 'chat_background_url',
            'openai_voice', 'is_published',
        ]);
        $response->assertJsonFragment(['slug' => 'spa-therapist']);
    }

    public function test_conversation_create_and_message_roundtrip(): void
    {
        $agent = Agent::where('slug', 'hotel-concierge')->firstOrFail();

        $conv = $this->postJson("/api/v1/agents/{$agent->id}/conversations")
            ->assertCreated()
            ->json();

        $this->assertEquals($agent->id, $conv['agent_id']);

        $this->postJson("/api/v1/conversations/{$conv['id']}/messages", [
            'content' => 'Hello',
            'auto_reply' => false,
        ])->assertCreated();

        $this->getJson("/api/v1/conversations/{$conv['id']}/messages")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.content', 'Hello')
            ->assertJsonPath('0.role', 'user');
    }
}
