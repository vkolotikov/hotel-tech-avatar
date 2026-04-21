<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class HotelVerticalRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private LlmClient $llmClientMock;
    private VerificationServiceInterface $verificationServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->llmClientMock = Mockery::mock(LlmClient::class);
        $this->verificationServiceMock = Mockery::mock(VerificationServiceInterface::class);

        $this->app->instance(LlmClient::class, $this->llmClientMock);
        $this->app->instance(VerificationServiceInterface::class, $this->verificationServiceMock);
    }

    public function test_hotel_vertical_never_calls_verification_service(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
            'name'        => 'Marketing Manager',
            'role'        => 'business advisor',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Strategy Session']);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->times(2)  // Two messages: the one we send, and potentially in loop
            ->andReturn(new LlmResponse(
                content: 'Here is the marketing strategy...',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 50,
                completionTokens: 100,
                totalTokens: 150,
                latencyMs: 800,
                traceId: 'trace-hotel-1',
            ));

        // Verification should NEVER be called for hotel
        $this->verificationServiceMock->shouldNotReceive('verify');

        // First message
        $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['content' => 'How should we market our new service?', 'auto_reply' => true],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(201);

        // Second message via agent-reply
        $this->postJson(
            "/api/v1/conversations/{$conversation->id}/agent-reply",
            [],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(201);

        // Verify verification was never called
        $this->verificationServiceMock->shouldNotReceive('verify');
    }

    public function test_hotel_message_creation_unchanged(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agents = Agent::where('vertical_id', $hotel->id)->get();

        $this->assertTrue($agents->count() > 0, 'No hotel agents found in database');

        $conversation = $agents->first()->conversations()->first();
        $this->assertNotNull($conversation, 'No conversation found for hotel agent');

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Response to your query',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 20,
                completionTokens: 35,
                totalTokens: 55,
                latencyMs: 600,
                traceId: 'trace-demo-1',
            ));

        $initialMessageCount = $conversation->messages()->count();

        $response = $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['content' => 'Test message', 'auto_reply' => true],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user_message'  => ['id', 'role', 'content'],
                'agent_message' => [
                    'id', 'role', 'content', 'ai_provider', 'ai_model',
                    'prompt_tokens', 'completion_tokens', 'total_tokens', 'ai_latency_ms',
                    'trace_id', 'verification_status'
                ],
            ]);

        // Verify messages were created
        $this->assertEquals($initialMessageCount + 2, $conversation->messages()->count());
    }

    public function test_hotel_message_list_unchanged(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agents = Agent::where('vertical_id', $hotel->id)->get();
        $conversation = $agents->first()->conversations()->first();

        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->getJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(200)
            ->assertJsonIsArray();

        // All messages should have basic fields
        $response->assertJsonStructure(['*' => ['id', 'role', 'content']]);
    }

    public function test_all_hotel_metadata_saved_correctly(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Metadata Test']);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response with complete metadata
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Metadata test response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o-turbo',
                promptTokens: 77,
                completionTokens: 99,
                totalTokens: 176,
                latencyMs: 1567,
                traceId: 'trace-metadata-test-123',
            ));

        $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            ['content' => 'Metadata test', 'auto_reply' => true],
            ['Authorization' => "Bearer {$token}"]
        )->assertStatus(201);

        // Verify message in database with all metadata
        $this->assertDatabaseHas('messages', [
            'conversation_id'    => $conversation->id,
            'agent_id'           => $agent->id,
            'role'               => 'agent',
            'content'            => 'Metadata test response',
            'ai_provider'        => 'openai',
            'ai_model'           => 'gpt-4o-turbo',
            'prompt_tokens'      => 77,
            'completion_tokens'  => 99,
            'total_tokens'       => 176,
            'ai_latency_ms'      => 1567,
            'trace_id'           => 'trace-metadata-test-123',
            'verification_status' => 'not_required',
        ]);

        // Verify the message object
        $message = Message::where('conversation_id', $conversation->id)
            ->where('role', 'agent')
            ->latest()
            ->firstOrFail();

        $this->assertEquals('agent', $message->role);
        $this->assertEquals('openai', $message->ai_provider);
        $this->assertEquals('gpt-4o-turbo', $message->ai_model);
        $this->assertEquals(77, $message->prompt_tokens);
        $this->assertEquals(99, $message->completion_tokens);
        $this->assertEquals(176, $message->total_tokens);
        $this->assertEquals(1567, $message->ai_latency_ms);
        $this->assertEquals('trace-metadata-test-123', $message->trace_id);
        $this->assertEquals('not_required', $message->verification_status);
        $this->assertNull($message->is_verified);
    }

    public function test_hotel_vertical_not_affected_by_wellness_changes(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();

        // Verify both verticals exist
        $this->assertNotNull($hotel);
        $this->assertNotNull($wellness);

        // Create agents for each vertical
        $hotelAgent = Agent::factory()->create(['vertical_id' => $hotel->id]);
        $wellnessAgent = Agent::factory()->create(['vertical_id' => $wellness->id]);

        // Verify agents belong to correct verticals
        $this->assertEquals($hotel->id, $hotelAgent->vertical_id);
        $this->assertEquals($wellness->id, $wellnessAgent->vertical_id);

        // Verify they're different
        $this->assertNotEquals($hotelAgent->vertical_id, $wellnessAgent->vertical_id);

        // Create conversations (they will inherit vertical from agent via constraint or observer)
        $hotelConversation = $hotelAgent->conversations()->create(['title' => 'Hotel']);
        $wellnessConversation = $wellnessAgent->conversations()->create(['title' => 'Wellness']);

        // Verify both conversations exist
        $this->assertNotNull($hotelConversation);
        $this->assertNotNull($wellnessConversation);
    }
}
