# Verification Pipeline Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the verification pipeline into the generation flow so every wellness avatar response is verified before reaching the user, while maintaining zero regression in the hotel vertical.

**Architecture:** Extract generation logic into a dedicated `GenerationService` that orchestrates context building, LLM calls, and conditional verification. Verification runs synchronously in the request cycle and completes before message save. Vertical-specific behavior (hotel vs wellness) is data-driven on agent.vertical_slug. Failed verifications trigger a revision loop (max 2 attempts) with fallback to professional referral response.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, existing VerificationService, existing LlmClient abstraction, Mockery for testing.

---

### Task 1: Create GenerationService Skeleton with Constructor

**Files:**
- Create: `app/Services/Generation/GenerationService.php`

- [ ] **Step 1: Write the failing test file**

Create `tests/Unit/Services/Generation/GenerationServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Generation;

use App\Http\Controllers\Api\V1\ConversationController;
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

        $vertical = Vertical::factory()->create(['slug' => 'hotel']);
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

    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(GenerationService::class, $this->generationService);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_service_can_be_instantiated -v`

Expected: FAIL with "Class "App\Services\Generation\GenerationService" not found"

- [ ] **Step 3: Create GenerationService skeleton**

Create `app/Services/Generation/GenerationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Generation;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;

final class GenerationService
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly VerificationServiceInterface $verificationService,
    ) {}

    /**
     * Generate a response for a conversation and optionally verify it.
     *
     * @param Conversation $conversation
     * @return Message|null
     */
    public function generateResponse(Conversation $conversation): ?Message
    {
        // Placeholder
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_service_can_be_instantiated -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Generation/GenerationService.php tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "feat: scaffold GenerationService with constructor and dependencies"
```

---

### Task 2: Implement Context Building

**Files:**
- Modify: `app/Services/Generation/GenerationService.php`
- Modify: `tests/Unit/Services/Generation/GenerationServiceTest.php`

- [ ] **Step 1: Add failing tests for context building**

Add these test methods to `GenerationServiceTest`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_builds_system_prompt_with_agent_instructions tests/Unit/Services/Generation/GenerationServiceTest.php::test_includes_knowledge_base_in_system_prompt tests/Unit/Services/Generation/GenerationServiceTest.php::test_includes_message_history_in_context -v`

Expected: FAIL (LlmResponse not being called, or chat not being called)

- [ ] **Step 3: Implement context building**

Replace the `generateResponse` method in `GenerationService`:

```php
public function generateResponse(Conversation $conversation): ?Message
{
    $agent = $conversation->agent;

    if (empty(config('services.openai.api_key'))) {
        return $conversation->messages()->create([
            'role'    => 'agent',
            'content' => "I'm currently offline — the AI service is not configured.",
        ]);
    }

    $maxContext = (int) config('services.openai.max_context_messages', 20);
    $maxKnowledge = (int) config('services.openai.max_knowledge_chars', 12000);

    // Build system prompt
    $systemPrompt = $agent->system_instructions ?? "You are {$agent->name}, {$agent->role}. {$agent->description}";

    if ($agent->knowledge_text) {
        $knowledge = mb_substr($agent->knowledge_text, 0, $maxKnowledge);
        $systemPrompt .= "\n\n--- Knowledge Base ---\n{$knowledge}";
    }

    // Build message history
    $history = $conversation->messages()
        ->orderByDesc('created_at')
        ->limit($maxContext)
        ->get()
        ->reverse()
        ->map(fn ($m) => [
            'role'    => $m->role === 'agent' ? 'assistant' : 'user',
            'content' => $m->content,
        ])
        ->values()
        ->toArray();

    $messages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $history
    );

    // Call LLM
    $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
        messages: $messages,
        model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
        temperature: (float) config('services.openai.temperature', 0.3),
        maxTokens: (int) config('services.openai.max_output_tokens', 220),
        tools: [],
        purpose: 'generation',
        messageId: null,
    ));

    // Save message
    return $conversation->messages()->create([
        'role'                   => 'agent',
        'content'                => $response->content,
        'ai_provider'            => $response->provider,
        'ai_model'               => $response->model,
        'prompt_tokens'          => $response->promptTokens,
        'completion_tokens'      => $response->completionTokens,
        'total_tokens'           => $response->totalTokens,
        'ai_latency_ms'          => $response->latencyMs,
        'is_verified'            => null,
        'verification_failures_json' => null,
        'verification_latency_ms' => null,
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_builds_system_prompt_with_agent_instructions tests/Unit/Services/Generation/GenerationServiceTest.php::test_includes_knowledge_base_in_system_prompt tests/Unit/Services/Generation/GenerationServiceTest.php::test_includes_message_history_in_context -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Generation/GenerationService.php tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "feat: implement context building in GenerationService"
```

---

### Task 3: Implement LLM Call with Response Metadata

**Files:**
- Modify: `app/Services/Generation/GenerationService.php`
- Modify: `tests/Unit/Services/Generation/GenerationServiceTest.php`

- [ ] **Step 1: Add failing tests for response metadata**

Add to `GenerationServiceTest`:

```php
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
    $this->assertEquals('trace-abc', $message->trace_id);
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

public function test_returns_offline_message_if_no_api_key(): void
{
    config(['services.openai.api_key' => null]);

    $message = $this->generationService->generateResponse($this->conversation);

    $this->assertNotNull($message);
    $this->assertStringContainsString('offline', strtolower($message->content));
    $this->assertEquals('agent', $message->role);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_saves_message_with_token_counts tests/Unit/Services/Generation/GenerationServiceTest.php::test_saves_message_with_ai_provider_and_model tests/Unit/Services/Generation/GenerationServiceTest.php::test_returns_offline_message_if_no_api_key -v`

Expected: FAIL (attributes missing from message)

- [ ] **Step 3: Verify implementation already includes these fields**

The Message model should already have these columns. Check by looking at existing migrations. The code in Task 2 already sets these fields, so tests should now pass. If they don't, verify Message model has all required fields.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_saves_message_with_token_counts tests/Unit/Services/Generation/GenerationServiceTest.php::test_saves_message_with_ai_provider_and_model tests/Unit/Services/Generation/GenerationServiceTest.php::test_returns_offline_message_if_no_api_key -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "test: verify token counts and metadata are saved to message"
```

---

### Task 4: Add Vertical-Conditional Verification Logic

**Files:**
- Modify: `app/Services/Generation/GenerationService.php`
- Modify: `tests/Unit/Services/Generation/GenerationServiceTest.php`

- [ ] **Step 1: Add failing tests for vertical detection**

Add to `GenerationServiceTest`:

```php
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
    $this->conversation->agent->vertical()->update(['slug' => 'wellness']);

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
        chunks: [],
        latency_ms: 200,
        is_high_risk: false,
        chunk_count: 0,
        passed: true,
        failures: [],
        safety_flags: [],
        revision_count: 0,
        revision_suggestion: null,
    );

    $this->verificationService->shouldReceive('verify')
        ->once()
        ->with('Protein is essential.', \Mockery\Matchers\Any::class, $this->conversation->agent)
        ->andReturn($verificationResult);

    $message = $this->generationService->generateResponse($this->conversation);

    $this->assertTrue($message->is_verified);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_skips_verification_for_hotel_vertical tests/Unit/Services/Generation/GenerationServiceTest.php::test_runs_verification_for_wellness_vertical -v`

Expected: FAIL (verification logic not implemented)

- [ ] **Step 3: Implement vertical-conditional verification**

Update `generateResponse` method in `GenerationService` to check vertical before saving:

Replace the final save section:

```php
    // Conditional verification
    $responseText = $response->content;
    $verificationLatencyMs = 0;
    $isVerified = null;
    $verificationFailures = null;

    if ($agent->vertical->slug === 'wellness') {
        $verificationStartTime = microtime(true);
        
        $verificationResult = $this->verificationService->verify($responseText, $agent);

        $verificationLatencyMs = (int) round((microtime(true) - $verificationStartTime) * 1000);
        $isVerified = $verificationResult->passed;
        $verificationFailures = $verificationResult->passed ? null : $verificationResult->failures;
    }

    // Save message
    return $conversation->messages()->create([
        'role'                   => 'agent',
        'content'                => $responseText,
        'ai_provider'            => $response->provider,
        'ai_model'               => $response->model,
        'prompt_tokens'          => $response->promptTokens,
        'completion_tokens'      => $response->completionTokens,
        'total_tokens'           => $response->totalTokens,
        'ai_latency_ms'          => $response->latencyMs,
        'is_verified'            => $isVerified,
        'verification_failures_json' => $verificationFailures,
        'verification_latency_ms' => $verificationLatencyMs > 0 ? $verificationLatencyMs : null,
    ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_skips_verification_for_hotel_vertical tests/Unit/Services/Generation/GenerationServiceTest.php::test_runs_verification_for_wellness_vertical -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Generation/GenerationService.php tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "feat: add vertical-conditional verification logic"
```

---

### Task 5: Implement Revision Loop

**Files:**
- Modify: `app/Services/Generation/GenerationService.php`
- Modify: `tests/Unit/Services/Generation/GenerationServiceTest.php`

- [ ] **Step 1: Add failing tests for revision loop**

Add to `GenerationServiceTest`:

```php
public function test_revision_loop_retries_on_verification_failure(): void
{
    $this->conversation->agent->vertical()->update(['slug' => 'wellness']);

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
        chunks: [],
        latency_ms: 100,
        is_high_risk: false,
        chunk_count: 0,
        passed: false,
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
        chunks: [],
        latency_ms: 100,
        is_high_risk: false,
        chunk_count: 0,
        passed: true,
        failures: [],
        safety_flags: [],
        revision_count: 1,
        revision_suggestion: null,
    );

    $this->verificationService->shouldReceive('verify')
        ->twice()
        ->andReturnValues([$failedResult, $passedResult]);

    $message = $this->generationService->generateResponse($this->conversation);

    $this->assertTrue($message->is_verified);
    $this->assertEquals('Response 2', $message->content);
}

public function test_respects_max_revisions_limit(): void
{
    $this->conversation->agent->vertical()->update(['slug' => 'wellness']);

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
        chunks: [],
        latency_ms: 100,
        is_high_risk: false,
        chunk_count: 0,
        passed: false,
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

    $message = $this->generationService->generateResponse($this->conversation);

    $this->assertFalse($message->is_verified);
    $this->assertNotNull($message->verification_failures_json);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_revision_loop_retries_on_verification_failure tests/Unit/Services/Generation/GenerationServiceTest.php::test_respects_max_revisions_limit -v`

Expected: FAIL (revision loop not implemented)

- [ ] **Step 3: Implement revision loop**

Add a private method to `GenerationService`:

```php
private function buildRevisionPrompt(\App\Services\Verification\Drivers\VerificationResult $verificationResult): array
{
    $failuresSummary = collect($verificationResult->failures)
        ->map(fn ($f) => "{$f->type->name}: {$f->reason}")
        ->join("\n");

    return [
        'role'    => 'user',
        'content' => "The previous response had these issues:\n\n{$failuresSummary}\n\nPlease revise the response to address these concerns. {$verificationResult->revision_suggestion}",
    ];
}
```

Update `generateResponse` to implement revision loop:

```php
    // Conditional verification with revision loop
    $responseText = $response->content;
    $verificationLatencyMs = 0;
    $isVerified = null;
    $verificationFailures = null;

    if ($agent->vertical->slug === 'wellness') {
        $verificationStartTime = microtime(true);
        
        $verificationResult = $this->verificationService->verify($responseText, $agent);
        $revisionCount = 0;

        while (!$verificationResult->passed && $revisionCount < 2 && $verificationResult->revision_suggestion) {
            $revisionPrompt = $this->buildRevisionPrompt($verificationResult);
            $messages[] = $revisionPrompt;

            $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
                messages: $messages,
                model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
                temperature: (float) config('services.openai.temperature', 0.3),
                maxTokens: (int) config('services.openai.max_output_tokens', 220),
                tools: [],
                purpose: 'generation',
                messageId: null,
            ));

            $responseText = $response->content;
            $messages[] = ['role' => 'assistant', 'content' => $responseText];

            $verificationResult = $this->verificationService->verify($responseText, $agent);
            $revisionCount++;
        }

        $verificationLatencyMs = (int) round((microtime(true) - $verificationStartTime) * 1000);
        $isVerified = $verificationResult->passed;
        $verificationFailures = $verificationResult->passed ? null : $verificationResult->failures;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_revision_loop_retries_on_verification_failure tests/Unit/Services/Generation/GenerationServiceTest.php::test_respects_max_revisions_limit -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Generation/GenerationService.php tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "feat: implement revision loop with max 2 attempts"
```

---

### Task 6: Implement Fallback Response

**Files:**
- Modify: `app/Services/Generation/GenerationService.php`
- Modify: `tests/Unit/Services/Generation/GenerationServiceTest.php`

- [ ] **Step 1: Add failing test for fallback**

Add to `GenerationServiceTest`:

```php
public function test_uses_fallback_response_when_verification_exhausted(): void
{
    $this->conversation->agent->vertical()->update(['slug' => 'wellness']);

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
        chunks: [],
        latency_ms: 100,
        is_high_risk: false,
        chunk_count: 0,
        passed: false,
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

    $message = $this->generationService->generateResponse($this->conversation);

    $this->assertFalse($message->is_verified);
    $this->assertStringContainsString('recommend consulting a healthcare professional', $message->content);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_uses_fallback_response_when_verification_exhausted -v`

Expected: FAIL (fallback not implemented)

- [ ] **Step 3: Implement fallback response**

Add constant to `GenerationService`:

```php
private const FALLBACK_RESPONSE = 'I recommend consulting a healthcare professional for this question';
```

Update revision loop in `generateResponse`:

```php
    // Conditional verification with revision loop
    $responseText = $response->content;
    $verificationLatencyMs = 0;
    $isVerified = null;
    $verificationFailures = null;

    if ($agent->vertical->slug === 'wellness') {
        $verificationStartTime = microtime(true);
        
        $verificationResult = $this->verificationService->verify($responseText, $agent);
        $revisionCount = 0;

        while (!$verificationResult->passed && $revisionCount < 2 && $verificationResult->revision_suggestion) {
            $revisionPrompt = $this->buildRevisionPrompt($verificationResult);
            $messages[] = $revisionPrompt;

            $response = $this->llmClient->chat(new \App\Services\Llm\LlmRequest(
                messages: $messages,
                model: $agent->openai_model ?? (string) config('services.openai.model', 'gpt-4o'),
                temperature: (float) config('services.openai.temperature', 0.3),
                maxTokens: (int) config('services.openai.max_output_tokens', 220),
                tools: [],
                purpose: 'generation',
                messageId: null,
            ));

            $responseText = $response->content;
            $messages[] = ['role' => 'assistant', 'content' => $responseText];

            $verificationResult = $this->verificationService->verify($responseText, $agent);
            $revisionCount++;
        }

        // Use fallback if verification still failed
        if (!$verificationResult->passed) {
            $responseText = self::FALLBACK_RESPONSE;
        }

        $verificationLatencyMs = (int) round((microtime(true) - $verificationStartTime) * 1000);
        $isVerified = $verificationResult->passed;
        $verificationFailures = $verificationResult->passed ? null : $verificationResult->failures;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php::test_uses_fallback_response_when_verification_exhausted -v`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Generation/GenerationService.php tests/Unit/Services/Generation/GenerationServiceTest.php
git commit -m "feat: implement fallback response when verification exhausted"
```

---

### Task 7: Create Database Migration

**Files:**
- Create: `database/migrations/2026_04_21_add_verification_to_messages_table.php`

- [ ] **Step 1: Write the migration file**

Create `database/migrations/2026_04_21_add_verification_to_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'is_verified')) {
                $table->boolean('is_verified')->nullable()->after('content');
            }
            if (!Schema::hasColumn('messages', 'verification_failures_json')) {
                $table->json('verification_failures_json')->nullable()->after('is_verified');
            }
            if (!Schema::hasColumn('messages', 'verification_latency_ms')) {
                $table->integer('verification_latency_ms')->nullable()->after('verification_failures_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'verification_failures_json', 'verification_latency_ms']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`

Expected: Successfully migrated (or columns already exist)

- [ ] **Step 3: Verify columns exist**

Run: `php artisan tinker` then:
```php
$columns = \Illuminate\Support\Facades\Schema::getColumns('messages');
collect($columns)->filter(fn($c) => in_array($c['name'], ['is_verified', 'verification_failures_json', 'verification_latency_ms']))->each(fn($c) => dump($c['name']));
```

Expected: Three columns listed (is_verified, verification_failures_json, verification_latency_ms)

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_04_21_add_verification_to_messages_table.php
git commit -m "database: add verification columns to messages table"
```

---

### Task 8: Update Message Model

**Files:**
- Modify: `app/Models/Message.php`

- [ ] **Step 1: Add JSON casts to Message model**

Add to the `$casts` property in `app/Models/Message.php`:

```php
protected $casts = [
    // ... existing casts
    'verification_failures_json' => 'json',
];
```

If the `$casts` property doesn't exist, add it:

```php
protected $casts = [
    'created_at' => 'datetime',
    'updated_at' => 'datetime',
    'verification_failures_json' => 'json',
];
```

- [ ] **Step 2: Verify casts work**

Run: `php artisan tinker` then:
```php
$msg = \App\Models\Message::first();
$msg->verification_failures_json = ['test' => 'data'];
$msg->save();
$msg->refresh();
dump($msg->verification_failures_json); // Should be array, not string
```

Expected: Array is returned (casting works)

- [ ] **Step 3: Commit**

```bash
git add app/Models/Message.php
git commit -m "refactor: add JSON casts for verification fields in Message model"
```

---

### Task 9: Modify ConversationController

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ConversationController.php`

- [ ] **Step 1: Inject GenerationService into controller**

Update the constructor:

```php
public function __construct(
    private readonly GenerationService $generationService,
) {}
```

Add the import at the top:

```php
use App\Services\Generation\GenerationService;
```

- [ ] **Step 2: Update createMessage method**

Replace the `createMessage` method:

```php
public function createMessage(Request $request, Conversation $conversation): JsonResponse
{
    $validated = $request->validate([
        'content'    => 'required|string',
        'auto_reply' => 'boolean',
    ]);

    // Save user message
    $userMsg = $conversation->messages()->create([
        'role'    => 'user',
        'content' => $validated['content'],
    ]);
    $conversation->touch();

    $result = ['user_message' => $userMsg, 'agent_message' => null];

    // Auto-reply if requested
    if ($request->boolean('auto_reply', true)) {
        $result['agent_message'] = $this->generationService->generateResponse($conversation);
    }

    return response()->json($result, 201);
}
```

- [ ] **Step 3: Update agentReply method**

Replace the `agentReply` method:

```php
public function agentReply(Conversation $conversation): JsonResponse
{
    $agentMsg = $this->generationService->generateResponse($conversation);
    return response()->json($agentMsg, 201);
}
```

- [ ] **Step 4: Delete generateReply method**

Remove the entire `generateReply()` method (lines 183-267 in the original file).

- [ ] **Step 5: Verify tests still pass**

Run: `php artisan test tests/Unit/Services/Generation/GenerationServiceTest.php -v`

Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/ConversationController.php
git commit -m "refactor: delegate generation to GenerationService in ConversationController"
```

---

### Task 10: Write Integration Tests

**Files:**
- Create: `tests/Integration/Services/Generation/GenerationServiceIntegrationTest.php`

- [ ] **Step 1: Create integration test file**

Create `tests/Integration/Services/Generation/GenerationServiceIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Generation;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Vertical;
use App\Services\Generation\GenerationService;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class GenerationServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private LlmClient|MockInterface $llmClient;
    private VerificationServiceInterface|MockInterface $verificationService;
    private GenerationService $generationService;
    private Agent $hotelAgent;
    private Agent $wellnessAgent;
    private Conversation $hotelConversation;
    private Conversation $wellnessConversation;

    protected function setUp(): void
    {
        parent::setUp();

        $hotelVertical = Vertical::factory()->create(['slug' => 'hotel']);
        $wellnessVertical = Vertical::factory()->create(['slug' => 'wellness']);

        $this->hotelAgent = Agent::factory()
            ->for($hotelVertical)
            ->create(['slug' => 'concierge']);

        $this->wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);

        $this->hotelConversation = $this->hotelAgent->conversations()->create(['title' => 'Hotel Chat']);
        $this->wellnessConversation = $this->wellnessAgent->conversations()->create(['title' => 'Wellness Chat']);

        $this->llmClient = $this->mock(LlmClient::class);
        $this->verificationService = $this->mock(VerificationServiceInterface::class);

        $this->generationService = new GenerationService(
            $this->llmClient,
            $this->verificationService,
        );
    }

    public function test_hotel_agent_message_has_null_verification(): void
    {
        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Welcome to our hotel.',
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

        $message = $this->generationService->generateResponse($this->hotelConversation);

        $this->assertNull($message->is_verified);
        $this->assertNull($message->verification_failures_json);
        $this->assertNull($message->verification_latency_ms);
        $this->assertEquals('Welcome to our hotel.', $message->content);
    }

    public function test_wellness_agent_message_is_verified_when_passing(): void
    {
        $this->llmClient->shouldReceive('chat')->once()->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'Protein supports muscle recovery.',
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
            chunks: [],
            latency_ms: 150,
            is_high_risk: false,
            chunk_count: 0,
            passed: true,
            failures: [],
            safety_flags: [],
            revision_count: 0,
            revision_suggestion: null,
        );

        $this->verificationService->shouldReceive('verify')->once()->andReturn($verificationResult);

        $message = $this->generationService->generateResponse($this->wellnessConversation);

        $this->assertTrue($message->is_verified);
        $this->assertNull($message->verification_failures_json);
        $this->assertGreaterThan(0, $message->verification_latency_ms);
        $this->assertEquals('Protein supports muscle recovery.', $message->content);
    }

    public function test_wellness_agent_message_fails_verification_with_fallback(): void
    {
        $this->llmClient->shouldReceive('chat')->times(3)->andReturn(
            new \App\Services\Llm\LlmResponse(
                content: 'This will fail verification.',
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
            chunks: [],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 0,
            passed: false,
            failures: [
                new \App\Services\Verification\Drivers\VerificationFailure(
                    type: \App\Services\Verification\Drivers\VerificationFailureType::NOT_GROUNDED,
                    claim_text: 'Invalid claim',
                    reason: 'Not grounded in knowledge',
                ),
            ],
            safety_flags: [],
            revision_count: 0,
            revision_suggestion: 'Add sources',
        );

        $this->verificationService->shouldReceive('verify')->times(3)->andReturn($failedResult);

        $message = $this->generationService->generateResponse($this->wellnessConversation);

        $this->assertFalse($message->is_verified);
        $this->assertNotNull($message->verification_failures_json);
        $this->assertStringContainsString('recommend consulting a healthcare professional', $message->content);
    }

    public function test_message_relationships_are_correct(): void
    {
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

        $message = $this->generationService->generateResponse($this->hotelConversation);

        $this->assertEquals($this->hotelConversation->id, $message->conversation_id);
        $this->assertEquals('agent', $message->role);
        $this->assertNotNull($message->created_at);
        $this->assertNotNull($message->updated_at);
    }
}
```

- [ ] **Step 2: Run integration tests**

Run: `php artisan test tests/Integration/Services/Generation/GenerationServiceIntegrationTest.php -v`

Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Services/Generation/GenerationServiceIntegrationTest.php
git commit -m "test: add integration tests for GenerationService"
```

---

### Task 11: Write Feature Tests

**Files:**
- Create: `tests/Feature/Conversations/VerificationIntegrationTest.php`

- [ ] **Step 1: Create feature test file**

Create `tests/Feature/Conversations/VerificationIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Vertical;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class VerificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Agent $hotelAgent;
    private Agent $wellnessAgent;
    private Conversation $hotelConversation;
    private Conversation $wellnessConversation;

    protected function setUp(): void
    {
        parent::setUp();

        $hotelVertical = Vertical::factory()->create(['slug' => 'hotel']);
        $wellnessVertical = Vertical::factory()->create(['slug' => 'wellness']);

        $this->hotelAgent = Agent::factory()
            ->for($hotelVertical)
            ->create(['slug' => 'concierge']);

        $this->wellnessAgent = Agent::factory()
            ->for($wellnessVertical)
            ->create(['slug' => 'nora']);

        $this->hotelConversation = $this->hotelAgent->conversations()->create(['title' => 'Hotel']);
        $this->wellnessConversation = $this->wellnessAgent->conversations()->create(['title' => 'Wellness']);

        // Mock LlmClient and VerificationService
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturnUsing(function ($request) {
                return new \App\Services\Llm\LlmResponse(
                    content: 'Generated response',
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
        });

        $this->mock(VerificationServiceInterface::class, function ($mock) {
            $mock->shouldReceive('verify')->andReturnUsing(function () {
                return new \App\Services\Verification\Drivers\VerificationResult(
                    chunks: [],
                    latency_ms: 100,
                    is_high_risk: false,
                    chunk_count: 0,
                    passed: true,
                    failures: [],
                    safety_flags: [],
                    revision_count: 0,
                    revision_suggestion: null,
                );
            });
        });
    }

    public function test_hotel_agent_message_via_http(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->hotelConversation->id}/messages",
            [
                'content' => 'What services do you offer?',
                'auto_reply' => true,
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user_message' => ['id', 'role', 'content'],
            'agent_message' => ['id', 'role', 'content', 'is_verified', 'ai_provider', 'ai_model'],
        ]);

        $agentMessage = $response->json('agent_message');
        $this->assertNull($agentMessage['is_verified']);
    }

    public function test_wellness_agent_message_via_http(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->wellnessConversation->id}/messages",
            [
                'content' => 'Tell me about nutrition',
                'auto_reply' => true,
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user_message' => ['id', 'role', 'content'],
            'agent_message' => ['id', 'role', 'content', 'is_verified', 'ai_provider', 'ai_model'],
        ]);

        $agentMessage = $response->json('agent_message');
        $this->assertTrue($agentMessage['is_verified']);
    }

    public function test_agent_reply_endpoint_triggers_generation(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->hotelConversation->id}/agent-reply"
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'role', 'content', 'is_verified']);
    }

    public function test_response_includes_token_counts(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->hotelConversation->id}/messages",
            [
                'content' => 'Hello',
                'auto_reply' => true,
            ]
        );

        $agentMessage = $response->json('agent_message');
        $this->assertIsInt($agentMessage['prompt_tokens']);
        $this->assertIsInt($agentMessage['completion_tokens']);
        $this->assertIsInt($agentMessage['total_tokens']);
    }
}
```

- [ ] **Step 2: Run feature tests**

Run: `php artisan test tests/Feature/Conversations/VerificationIntegrationTest.php -v`

Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Conversations/VerificationIntegrationTest.php
git commit -m "test: add feature tests for verification integration via HTTP"
```

---

### Task 12: Hotel Vertical Regression Testing

**Files:**
- Create: `tests/Feature/Conversations/HotelVerticalRegressionTest.php`

- [ ] **Step 1: Create regression test file**

Create `tests/Feature/Conversations/HotelVerticalRegressionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Conversations;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Vertical;
use App\Services\Llm\LlmClient;
use App\Services\Verification\Contracts\VerificationServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HotelVerticalRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $vertical = Vertical::factory()->create(['slug' => 'hotel']);
        $this->agent = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'concierge']);

        $this->conversation = $this->agent->conversations()->create(['title' => 'Test']);

        // Mock LlmClient for all tests
        $this->mock(LlmClient::class, function ($mock) {
            $mock->shouldReceive('chat')->andReturnUsing(function ($request) {
                return new \App\Services\Llm\LlmResponse(
                    content: 'Hotel response',
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
        });

        // Verify verification service is NEVER called for hotel vertical
        $this->mock(VerificationServiceInterface::class, function ($mock) {
            $mock->shouldReceive('verify')->never();
        });
    }

    public function test_hotel_conversation_creation_unchanged(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->agent->id}/latest"
        );

        $response->assertStatus(200);
        $this->assertIsInt($response->json('id'));
    }

    public function test_hotel_message_creation_unchanged(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->conversation->id}/messages",
            [
                'content' => 'Test message',
                'auto_reply' => true,
            ]
        );

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'user_message' => ['id', 'role', 'content'],
            'agent_message' => ['id', 'role', 'content', 'ai_provider', 'ai_model'],
        ]);

        $userMessage = Message::find($response->json('user_message.id'));
        $agentMessage = Message::find($response->json('agent_message.id'));

        $this->assertEquals('user', $userMessage->role);
        $this->assertEquals('agent', $agentMessage->role);
        $this->assertNull($agentMessage->is_verified);
    }

    public function test_hotel_message_list_unchanged(): void
    {
        $this->conversation->messages()->create(['role' => 'user', 'content' => 'Hello']);
        $this->conversation->messages()->create(['role' => 'agent', 'content' => 'Hi']);

        $response = $this->getJson("/api/v1/conversations/{$this->conversation->id}/messages");

        $response->assertStatus(200);
        $messages = $response->json();
        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('agent', $messages[1]['role']);
    }

    public function test_hotel_agent_reply_unchanged(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->conversation->id}/agent-reply"
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'role', 'content']);
        $this->assertEquals('agent', $response->json('role'));
    }

    public function test_verification_never_called_for_hotel(): void
    {
        // This test ensures mocked verification is never called
        // If it is, the test will fail with "Expected calls did not match"
        $this->postJson(
            "/api/v1/conversations/{$this->conversation->id}/messages",
            [
                'content' => 'Test',
                'auto_reply' => true,
            ]
        );

        // Assertion happens implicitly via mock->shouldReceive()->never()
        $this->assertTrue(true);
    }

    public function test_hotel_message_includes_all_metadata(): void
    {
        $response = $this->postJson(
            "/api/v1/conversations/{$this->conversation->id}/messages",
            [
                'content' => 'Tell me about your services',
                'auto_reply' => true,
            ]
        );

        $agentMessage = $response->json('agent_message');
        $this->assertNotNull($agentMessage['ai_provider']);
        $this->assertNotNull($agentMessage['ai_model']);
        $this->assertIsInt($agentMessage['prompt_tokens']);
        $this->assertIsInt($agentMessage['completion_tokens']);
        $this->assertIsInt($agentMessage['total_tokens']);
        $this->assertIsInt($agentMessage['ai_latency_ms']);
    }
}
```

- [ ] **Step 2: Run regression tests**

Run: `php artisan test tests/Feature/Conversations/HotelVerticalRegressionTest.php -v`

Expected: All tests pass (verification service mock should never be called)

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Conversations/HotelVerticalRegressionTest.php
git commit -m "test: add hotel vertical regression tests to ensure no verification overhead"
```

---

### Task 13: Run All Tests and Verify Integration

**Files:**
- (No new files)

- [ ] **Step 1: Run all unit tests**

Run: `php artisan test tests/Unit/Services/Generation/ -v`

Expected: All unit tests pass (12+ tests)

- [ ] **Step 2: Run all integration tests**

Run: `php artisan test tests/Integration/Services/Generation/ -v`

Expected: All integration tests pass (4+ tests)

- [ ] **Step 3: Run all feature tests**

Run: `php artisan test tests/Feature/Conversations/VerificationIntegrationTest.php tests/Feature/Conversations/HotelVerticalRegressionTest.php -v`

Expected: All feature tests pass (8+ tests)

- [ ] **Step 4: Run full test suite**

Run: `php artisan test`

Expected: All tests pass, no regressions in hotel vertical

- [ ] **Step 5: Verify database schema**

Run: `php artisan tinker` then:
```php
Schema::getColumns('messages')->filter(fn($c) => in_array($c['name'], ['is_verified', 'verification_failures_json', 'verification_latency_ms']))->each(fn($c) => dump($c));
```

Expected: Three columns listed with correct types (boolean, json, integer)

- [ ] **Step 6: Verify migrations are clean**

Run: `php artisan migrate:status`

Expected: All migrations show as "Ran"

- [ ] **Step 7: Final commit**

```bash
git add -A
git commit -m "feat: complete verification pipeline integration with all tests passing"
```

---

## Summary of Changes

**New Files:**
- `app/Services/Generation/GenerationService.php` — orchestrates generation + verification
- `database/migrations/2026_04_21_add_verification_to_messages_table.php` — schema migration
- `tests/Unit/Services/Generation/GenerationServiceTest.php` — unit tests
- `tests/Integration/Services/Generation/GenerationServiceIntegrationTest.php` — integration tests
- `tests/Feature/Conversations/VerificationIntegrationTest.php` — feature tests
- `tests/Feature/Conversations/HotelVerticalRegressionTest.php` — regression tests

**Modified Files:**
- `app/Http/Controllers/Api/V1/ConversationController.php` — inject GenerationService, remove generateReply
- `app/Models/Message.php` — add JSON casts for verification fields

**Test Coverage:**
- 12+ unit tests (context building, LLM calls, verification conditional, revision loop, fallback, metadata)
- 4+ integration tests (hotel/wellness flows, relationships, fallback behavior)
- 5+ feature tests (HTTP endpoints, token counts, response structure)
- 6+ regression tests (hotel vertical unchanged, no verification overhead)

**Total:** 27+ tests, all passing, zero regression in hotel vertical

---

## Verification Checklist

- [ ] All 27+ tests passing
- [ ] Hotel vertical messages have is_verified=null
- [ ] Wellness vertical messages have is_verified=boolean
- [ ] Revision loop respects max 2 attempts
- [ ] Fallback response used when exhausted
- [ ] No verification overhead for hotel agents
- [ ] Database migration clean
- [ ] Message metadata saved correctly
