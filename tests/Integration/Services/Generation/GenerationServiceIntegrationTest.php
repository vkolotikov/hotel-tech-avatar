<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Generation;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Vertical;
use App\Services\Generation\GenerationService;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use App\Services\Verification\Drivers\VerificationResult;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GenerationServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private GenerationService $generationService;
    private LlmClient $llmClientMock;
    private VerificationServiceInterface $verificationServiceMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->llmClientMock = Mockery::mock(LlmClient::class);
        $this->verificationServiceMock = Mockery::mock(VerificationServiceInterface::class);

        $this->app->instance(LlmClient::class, $this->llmClientMock);
        $this->app->instance(VerificationServiceInterface::class, $this->verificationServiceMock);

        $this->generationService = app(GenerationService::class);
    }

    public function test_hotel_agent_message_passes_through_without_verification(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
            'name'        => 'Concierge',
            'role'        => 'hotel',
            'description' => 'Front desk staff',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        // Create a user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => 'What time is breakfast?',
        ]);

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Breakfast is served from 7 AM to 10 AM.',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 15,
                totalTokens: 25,
                latencyMs: 500,
                traceId: 'trace-123',
            ));

        // Verification should NOT be called for hotel
        $this->verificationServiceMock->shouldNotReceive('verify');

        $message = $this->generationService->generateResponse($conversation);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('agent', $message->role);
        $this->assertEquals('Breakfast is served from 7 AM to 10 AM.', $message->content);
        $this->assertEquals('not_required', $message->verification_status);
        $this->assertNull($message->is_verified);
    }

    public function test_wellness_agent_message_is_verified_when_passing(): void
    {
        $this->seed(VerticalsSeeder::class);

        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $wellness->id,
            'name'        => 'Dr. Integra',
            'role'        => 'doctor',
            'description' => 'Functional medicine expert',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        // Create a user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => 'What are some foods high in omega-3?',
        ]);

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Omega-3 rich foods include salmon, flaxseeds, and walnuts.',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 15,
                totalTokens: 25,
                latencyMs: 500,
                traceId: 'trace-123',
            ));

        // Mock verification to pass
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

        $message = $this->generationService->generateResponse($conversation);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('agent', $message->role);
        $this->assertEquals('Omega-3 rich foods include salmon, flaxseeds, and walnuts.', $message->content);
        $this->assertEquals('passed', $message->verification_status);
        $this->assertTrue($message->is_verified);
    }

    public function test_wellness_agent_uses_fallback_when_verification_fails(): void
    {
        $this->seed(VerticalsSeeder::class);

        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $wellness->id,
            'name'        => 'Nora',
            'role'        => 'nutrition',
            'description' => 'Nutrition expert',
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        // Create a user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => 'Should I take vitamin supplements?',
        ]);

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'You should take vitamin D 2000 IU daily.',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 15,
                totalTokens: 25,
                latencyMs: 500,
                traceId: 'trace-123',
            ));

        // Mock verification to fail
        $this->verificationServiceMock
            ->shouldReceive('verify')
            ->once()
            ->andReturn(new VerificationResult(
                passed: false,
                chunks: [],
                latency_ms: 300,
                is_high_risk: false,
                chunk_count: 0,
                failures: [
                    (object) ['type' => (object) ['name' => 'DOSAGE'], 'reason' => 'Dosage claim not grounded'],
                ],
                safety_flags: [],
                revision_count: 0,
            ));

        $message = $this->generationService->generateResponse($conversation);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('agent', $message->role);
        $this->assertEquals('I recommend consulting a healthcare professional for this question.', $message->content);
        $this->assertEquals('failed', $message->verification_status);
        $this->assertFalse($message->is_verified);
    }

    public function test_message_relationships_work_correctly(): void
    {
        $this->seed(VerticalsSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $agent = Agent::factory()->create([
            'vertical_id' => $hotel->id,
        ]);
        $conversation = $agent->conversations()->create(['title' => 'Test']);

        // Create a user message
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => 'Hello',
        ]);

        // Mock LLM response
        $this->llmClientMock
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Hi there!',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 5,
                completionTokens: 10,
                totalTokens: 15,
                latencyMs: 300,
                traceId: 'trace-456',
            ));

        $message = $this->generationService->generateResponse($conversation);

        // Verify relationships
        $this->assertEquals($conversation->id, $message->conversation_id);
        $this->assertEquals($agent->id, $message->agent_id);
        $this->assertSame($conversation->id, $message->conversation()->first()->id);
        $this->assertSame($agent->id, $message->agent()->first()->id);
    }
}
