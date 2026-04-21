<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Generation;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Vertical;
use App\Services\Generation\GenerationService;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private LlmClient|MockInterface $llmClient;
    private VerificationServiceInterface|MockInterface $verificationService;
    private GenerationService $generationService;
    private Agent $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $vertical = Vertical::firstOrCreate(['slug' => 'hotel']);
        $this->agent = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'concierge']);

        $this->conversation = $this->agent->conversations()->create(['title' => 'Test']);

        $this->llmClient = $this->mock(LlmClient::class);
        $this->verificationService = $this->mock(VerificationServiceInterface::class);

        $this->generationService = new GenerationService(
            $this->llmClient,
            $this->verificationService,
        );
    }

    public function test_builds_system_prompt_with_agent_instructions(): void
    {
        $this->agent->update(['system_instructions' => 'You are a concierge.']);

        $this->llmClient->shouldReceive('chat')->once()->andReturnUsing(function ($request) {
            $messages = $request->messages;
            $this->assertStringContainsString('You are a concierge.', $messages[0]['content']);
            return new \App\Services\Llm\LlmResponse(
                content: 'Response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 100,
                traceId: 'trace-1',
            );
        });

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);
        $this->assertNotNull($message);
    }

    public function test_includes_knowledge_base_in_system_prompt(): void
    {
        $this->agent->update(['knowledge_text' => 'Knowledge: Be professional.']);

        $this->llmClient->shouldReceive('chat')->once()->andReturnUsing(function ($request) {
            $messages = $request->messages;
            $this->assertStringContainsString('Knowledge: Be professional.', $messages[0]['content']);
            return new \App\Services\Llm\LlmResponse(
                content: 'Response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 100,
                traceId: 'trace-1',
            );
        });

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);
        $this->assertNotNull($message);
    }

    public function test_includes_message_history_in_context(): void
    {
        $this->conversation->messages()->create(['role' => 'user', 'content' => 'Hello']);
        $this->conversation->messages()->create(['role' => 'agent', 'content' => 'Hi there']);

        $this->llmClient->shouldReceive('chat')->once()->andReturnUsing(function ($request) {
            $messages = $request->messages;
            // Check that history is included (system + user + agent + new user = 4 messages)
            $this->assertGreaterThanOrEqual(2, count($messages));
            return new \App\Services\Llm\LlmResponse(
                content: 'Response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 20,
                completionTokens: 5,
                totalTokens: 25,
                latencyMs: 100,
                traceId: 'trace-1',
            );
        });

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);
        $this->assertNotNull($message);
    }

    public function test_returns_offline_message_when_openai_key_missing(): void
    {
        config(['services.openai.api_key' => '']);

        $this->llmClient->shouldReceive('chat')->never();
        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);

        $this->assertNotNull($message);
        $this->assertStringContainsString('offline', strtolower($message->content));
        $this->assertEquals('agent', $message->role);
    }

    public function test_saves_message_with_token_counts(): void
    {
        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Test response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 50,
                completionTokens: 25,
                totalTokens: 75,
                latencyMs: 500,
                traceId: 'trace-abc',
            )
        );

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);

        $this->assertEquals(50, $message->prompt_tokens);
        $this->assertEquals(25, $message->completion_tokens);
        $this->assertEquals(75, $message->total_tokens);
        $this->assertEquals(500, $message->ai_latency_ms);
    }

    public function test_saves_message_with_ai_provider_and_model(): void
    {
        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Response',
                role: 'assistant',
                provider: 'anthropic',
                model: 'claude-opus-4.7',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 300,
                traceId: 'trace-xyz',
            )
        );

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);

        $this->assertEquals('anthropic', $message->ai_provider);
        $this->assertEquals('claude-opus-4.7', $message->ai_model);
    }

    public function test_skips_verification_for_hotel_vertical(): void
    {
        $this->conversation->agent->vertical()->update(['slug' => 'hotel']);

        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Response',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 100,
                traceId: 'trace-1',
            )
        );

        $this->verificationService->shouldReceive('verify')->never();

        $message = $this->generationService->generateResponse($this->conversation);

        $this->assertNull($message->is_verified);
        $this->assertNull($message->verification_failures_json);
        $this->assertNull($message->verification_latency_ms);
    }

    public function test_runs_verification_for_wellness_vertical(): void
    {
        // Create a wellness vertical and agent
        $wellnessVertical = Vertical::create(['slug' => 'wellness', 'name' => 'Wellness']);
        $wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);
        $wellnessConversation = $wellnessAgent->conversations()->create(['title' => 'Test Wellness']);

        // Verify the relationship is correct
        $this->assertEquals('wellness', $wellnessAgent->vertical->slug);

        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Protein is essential.',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 100,
                traceId: 'trace-1',
            )
        );

        $verificationResult = new \App\Services\Verification\Drivers\VerificationResult(
            passed: true,
            chunks: [],
            latency_ms: 200,
            is_high_risk: false,
            chunk_count: 0,
            failures: [],
            safety_flags: [],
            revision_count: 0,
        );

        $this->verificationService->shouldReceive('verify')
            ->once()
            ->andReturn($verificationResult);

        $message = $this->generationService->generateResponse($wellnessConversation);

        $this->assertTrue($message->is_verified);
    }

    public function test_revision_loop_retries_on_verification_failure(): void
    {
        $wellnessVertical = Vertical::create(['slug' => 'wellness', 'name' => 'Wellness']);
        $wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);
        $wellnessConversation = $wellnessAgent->conversations()->create(['title' => 'Test Wellness']);

        $this->llmClient->shouldReceive('chat')
            ->twice()
            ->andReturnUsing(function ($request) {
                static $count = 0;
                $count++;
                return new \App\Services\Llm\LlmResponse(
                    content: "Response {$count}",
                    role: 'assistant',
                    provider: 'openai',
                    model: 'gpt-4o',
                    promptTokens: 10,
                    completionTokens: 5,
                    totalTokens: 15,
                    latencyMs: 100,
                    traceId: "trace-{$count}",
                );
            });

        $failedResult = new \App\Services\Verification\Drivers\VerificationResult(
            passed: false,
            chunks: [],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 0,
            failures: [
                new \App\Services\Verification\Drivers\VerificationFailure(
                    type: \App\Services\Verification\Drivers\VerificationFailureType::NOT_GROUNDED,
                    claim_text: 'Test claim',
                    reason: 'No grounding found',
                ),
            ],
            safety_flags: [],
            revision_count: 0,
            revision_suggestion: 'Cite a source',
        );

        $passedResult = new \App\Services\Verification\Drivers\VerificationResult(
            passed: true,
            chunks: [],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 0,
            failures: [],
            safety_flags: [],
            revision_count: 1,
            revision_suggestion: null,
        );

        $this->verificationService->shouldReceive('verify')
            ->twice()
            ->andReturnValues([$failedResult, $passedResult]);

        $message = $this->generationService->generateResponse($wellnessConversation);

        $this->assertTrue($message->is_verified);
        $this->assertEquals('Response 2', $message->content);
    }

    public function test_respects_max_revisions_limit(): void
    {
        $wellnessVertical = Vertical::create(['slug' => 'wellness', 'name' => 'Wellness']);
        $wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);
        $wellnessConversation = $wellnessAgent->conversations()->create(['title' => 'Test Wellness']);

        $this->llmClient->shouldReceive('chat')
            ->times(3)  // Initial + 2 revisions
            ->andReturn(
                new \App\Services\Llm\LlmResponse(
                    content: 'Response',
                    role: 'assistant',
                    provider: 'openai',
                    model: 'gpt-4o',
                    promptTokens: 10,
                    completionTokens: 5,
                    totalTokens: 15,
                    latencyMs: 100,
                    traceId: 'trace-1',
                )
            );

        $failedResult = new \App\Services\Verification\Drivers\VerificationResult(
            passed: false,
            chunks: [],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 0,
            failures: [
                new \App\Services\Verification\Drivers\VerificationFailure(
                    type: \App\Services\Verification\Drivers\VerificationFailureType::NOT_GROUNDED,
                    claim_text: 'Test',
                    reason: 'No grounding',
                ),
            ],
            safety_flags: [],
            revision_count: 0,
            revision_suggestion: 'Revise',
        );

        $this->verificationService->shouldReceive('verify')
            ->times(3)
            ->andReturn($failedResult);

        $message = $this->generationService->generateResponse($wellnessConversation);

        $this->assertFalse($message->is_verified);
        $this->assertNotNull($message->verification_failures_json);
    }

    public function test_uses_fallback_response_when_verification_exhausted(): void
    {
        $wellnessVertical = Vertical::create(['slug' => 'wellness', 'name' => 'Wellness']);
        $wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);
        $wellnessConversation = $wellnessAgent->conversations()->create(['title' => 'Test Wellness']);

        $this->llmClient->shouldReceive('chat')->times(3)->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Original response that will fail',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                latencyMs: 100,
                traceId: 'trace-1',
            )
        );

        $failedResult = new \App\Services\Verification\Drivers\VerificationResult(
            passed: false,
            chunks: [],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 0,
            failures: [
                new \App\Services\Verification\Drivers\VerificationFailure(
                    type: \App\Services\Verification\Drivers\VerificationFailureType::SAFETY_VIOLATION,
                    claim_text: 'Dangerous claim',
                    reason: 'Safety violation detected',
                ),
            ],
            safety_flags: [],
            revision_count: 0,
            revision_suggestion: 'Cannot be fixed',
        );

        $this->verificationService->shouldReceive('verify')
            ->times(3)
            ->andReturn($failedResult);

        $message = $this->generationService->generateResponse($wellnessConversation);

        $this->assertFalse($message->is_verified);
        $this->assertStringContainsString('recommend consulting a healthcare professional', $message->content);
    }
}
