<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use App\Services\Verification\Drivers\VerificationResult;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class VerificationIntegrationTest extends TestCase
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

    public function test_creating_message_via_http_post_hotel(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
            'slug' => 'hotel-agent',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Hotel response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 15,
                totalTokens: 25,
                latencyMs: 500,
                traceId: 'trace-123',
            ));

        // Hotel should NOT verify
        $this->verificationServiceMock->shouldNotReceive('verify');

        $response = $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            [
                'content'    => 'What is your policy?',
                'auto_reply' => true,
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user_message'  => ['id', 'role', 'content'],
                'agent_message' => [
                    'id', 'role', 'content', 'ai_provider', 'ai_model',
                    'prompt_tokens', 'completion_tokens', 'total_tokens',
                    'verification_status'
                ],
            ])
            ->assertJsonPath('agent_message.verification_status', 'not_required')
            ->assertJsonPath('agent_message.content', 'Hotel response');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_creating_message_via_http_post_wellness(): void
    {
        $this->seed(VerticalsSeeder::class);

        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $wellness->id,
            'slug' => 'wellness-agent',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Wellness response with evidence',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 15,
                totalTokens: 25,
                latencyMs: 500,
                traceId: 'trace-123',
            ));

        // Wellness should verify
        $this->verificationServiceMock
            ->shouldReceive('verify')
            ->once()
            ->andReturn(new VerificationResult(
                passed: true,
                chunks: [],
                latency_ms: 300,
                is_high_risk: false,
                chunk_count: 0,
                failures: [],
                safety_flags: [],
                revision_count: 0,
            ));

        $response = $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            [
                'content'    => 'What should I eat for energy?',
                'auto_reply' => true,
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'user_message'  => ['id', 'role', 'content'],
                'agent_message' => [
                    'id', 'role', 'content', 'ai_provider', 'ai_model',
                    'prompt_tokens', 'completion_tokens', 'total_tokens',
                    'verification_status', 'is_verified'
                ],
            ])
            ->assertJsonPath('agent_message.verification_status', 'passed')
            ->assertJsonPath('agent_message.is_verified', true)
            ->assertJsonPath('agent_message.content', 'Wellness response with evidence');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_agent_reply_endpoint(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
            'slug' => 'reply-agent',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        // Create initial user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => 'Hello',
        ]);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Manual agent reply',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 5,
                completionTokens: 10,
                totalTokens: 15,
                latencyMs: 300,
                traceId: 'trace-789',
            ));

        $response = $this->postJson(
            "/api/v1/conversations/{$conversation->id}/agent-reply",
            [],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id', 'role', 'content', 'ai_provider', 'ai_model',
                'prompt_tokens', 'completion_tokens', 'total_tokens',
                'verification_status'
            ])
            ->assertJsonPath('role', 'agent')
            ->assertJsonPath('content', 'Manual agent reply');

        $this->assertDatabaseCount('messages', 2);
    }

    public function test_response_includes_token_counts_and_metadata(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
            'slug' => 'test-agent',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        $token = $this->user->createToken('test')->plainTextToken;

        // Mock LLM response with specific token counts
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Token test response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o-mini',
                promptTokens: 42,
                completionTokens: 88,
                totalTokens: 130,
                latencyMs: 1234,
                traceId: 'trace-metadata',
            ));

        $response = $this->postJson(
            "/api/v1/conversations/{$conversation->id}/messages",
            [
                'content'    => 'Test token counts',
                'auto_reply' => true,
            ],
            ['Authorization' => "Bearer {$token}"]
        );

        $response->assertStatus(201)
            ->assertJsonPath('agent_message.prompt_tokens', 42)
            ->assertJsonPath('agent_message.completion_tokens', 88)
            ->assertJsonPath('agent_message.total_tokens', 130)
            ->assertJsonPath('agent_message.ai_latency_ms', 1234)
            ->assertJsonPath('agent_message.ai_provider', 'openai')
            ->assertJsonPath('agent_message.ai_model', 'gpt-4o-mini')
            ->assertJsonPath('agent_message.trace_id', 'trace-metadata');
    }
}
