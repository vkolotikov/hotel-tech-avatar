<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verification;

use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\ClaimExtractionService;
use App\Services\Verification\Drivers\Claim;
use Mockery;
use Tests\TestCase;

class ClaimExtractionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    public function test_extract_successfully_returns_claim_objects(): void
    {
        $jsonResponse = json_encode([
            [
                'text' => 'Magnesium improves sleep quality',
                'requires_citation' => true,
                'inferred_source_category' => 'research',
            ],
            [
                'text' => 'Sleep is important for health',
                'requires_citation' => false,
                'inferred_source_category' => 'general',
            ],
        ]);

        $llmResponse = new LlmResponse(
            content: $jsonResponse,
            role: 'assistant',
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            latencyMs: 500,
            traceId: 'trace-123',
        );

        $llmClientMock = Mockery::mock(LlmClient::class);
        $llmClientMock->shouldReceive('chat')->andReturn($llmResponse);

        $service = new ClaimExtractionService($llmClientMock);
        $claims = $service->extract('Test response text');

        $this->assertCount(2, $claims);
        $this->assertInstanceOf(Claim::class, $claims[0]);
        $this->assertSame('Magnesium improves sleep quality', $claims[0]->text);
        $this->assertTrue($claims[0]->requires_citation);
        $this->assertSame('research', $claims[0]->inferred_source_category);

        $this->assertInstanceOf(Claim::class, $claims[1]);
        $this->assertSame('Sleep is important for health', $claims[1]->text);
        $this->assertFalse($claims[1]->requires_citation);
        $this->assertSame('general', $claims[1]->inferred_source_category);
    }

    public function test_extract_returns_empty_array_on_llm_exception(): void
    {
        $llmClientMock = Mockery::mock(LlmClient::class);
        $llmClientMock->shouldReceive('chat')->andThrow(new \RuntimeException('LLM service error'));

        $service = new ClaimExtractionService($llmClientMock);
        $claims = $service->extract('Test response text');

        $this->assertIsArray($claims);
        $this->assertCount(0, $claims);
    }

    public function test_extract_returns_empty_array_on_invalid_json_response(): void
    {
        $llmResponse = new LlmResponse(
            content: 'This is not valid JSON {invalid}',
            role: 'assistant',
            provider: 'openai',
            model: 'gpt-4o',
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            latencyMs: 500,
            traceId: 'trace-123',
        );

        $llmClientMock = Mockery::mock(LlmClient::class);
        $llmClientMock->shouldReceive('chat')->andReturn($llmResponse);

        $service = new ClaimExtractionService($llmClientMock);
        $claims = $service->extract('Test response text');

        $this->assertIsArray($claims);
        $this->assertCount(0, $claims);
    }
}
