<?php

namespace Tests\Unit\Services\Verification;

use App\Services\Knowledge\Drivers\RetrievedChunk;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\StructuredReviewResult;
use App\Services\Verification\StructuredReviewService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class StructuredReviewServiceTest extends TestCase
{
    private LlmClient|MockInterface $llmClient;
    private StructuredReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmClient = Mockery::mock(LlmClient::class);
        $this->service = new StructuredReviewService($this->llmClient);
    }

    /**
     * Test 1: Successful review returns passed result
     */
    public function test_successful_review_returns_passed_result()
    {
        $responseText = 'Vitamin C helps support immune function.';
        $chunk = new RetrievedChunk(
            content: 'Vitamin C is a water-soluble vitamin that plays a role in immune support.',
            source_url: 'https://example.com/vitamin-c',
            source_name: 'Example Medical Source',
            citation_key: 'PMID:12345',
            evidence_grade: 'high',
            fetched_at: new \DateTimeImmutable(),
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 1,
        );

        $successResponse = json_encode([
            'passed' => true,
            'issues' => [],
            'revision_suggestion' => null,
        ]);

        $this->llmClient
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: $successResponse,
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4',
                promptTokens: 100,
                completionTokens: 50,
                totalTokens: 150,
                latencyMs: 500,
                traceId: 'trace-1',
                raw: [],
            ));

        $result = $this->service->review($responseText, $context);

        $this->assertInstanceOf(StructuredReviewResult::class, $result);
        $this->assertTrue($result->passed);
        $this->assertEmpty($result->issues);
        $this->assertNull($result->revision_suggestion);
    }

    /**
     * Test 2: Review with issues returns failed result with revision suggestion
     */
    public function test_review_with_issues_returns_failed_result_with_revision_suggestion()
    {
        $responseText = 'This vitamin cures all diseases.';
        $chunk = new RetrievedChunk(
            content: 'Vitamin C is a water-soluble vitamin.',
            source_url: 'https://example.com/vitamin-c',
            source_name: 'Medical Reference',
            citation_key: 'PMID:54321',
            evidence_grade: 'medium',
            fetched_at: new \DateTimeImmutable(),
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 100,
            is_high_risk: true,
            chunk_count: 1,
        );

        $failureResponse = json_encode([
            'passed' => false,
            'issues' => [
                [
                    'criterion' => 'safety',
                    'description' => 'Claims cure without evidence',
                ],
                [
                    'criterion' => 'scope',
                    'description' => 'Medical claim outside wellness scope',
                ],
            ],
            'revision_suggestion' => 'Replace "cures all diseases" with "may support immune function" and cite evidence.',
        ]);

        $this->llmClient
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: $failureResponse,
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4',
                promptTokens: 150,
                completionTokens: 100,
                totalTokens: 250,
                latencyMs: 600,
                traceId: 'trace-2',
                raw: [],
            ));

        $result = $this->service->review($responseText, $context);

        $this->assertInstanceOf(StructuredReviewResult::class, $result);
        $this->assertFalse($result->passed);
        $this->assertCount(2, $result->issues);
        $this->assertEquals('safety', $result->issues[0]['criterion']);
        $this->assertEquals('Claims cure without evidence', $result->issues[0]['description']);
        $this->assertNotNull($result->revision_suggestion);
        $this->assertStringContainsString('Replace', $result->revision_suggestion);
    }

    /**
     * Test 3: LLM error returns failed result
     */
    public function test_llm_error_returns_failed_result()
    {
        $responseText = 'Some response.';
        $chunk = new RetrievedChunk(
            content: 'Some medical text.',
            source_url: 'https://example.com/medical',
            source_name: 'Medical Source',
            citation_key: 'PMID:99999',
            evidence_grade: 'low',
            fetched_at: new \DateTimeImmutable(),
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 100,
            is_high_risk: false,
            chunk_count: 1,
        );

        $this->llmClient
            ->shouldReceive('chat')
            ->once()
            ->andThrow(new \Exception('LLM service unavailable'));

        $result = $this->service->review($responseText, $context);

        $this->assertInstanceOf(StructuredReviewResult::class, $result);
        $this->assertFalse($result->passed);
        $this->assertNotEmpty($result->issues);
        $this->assertEquals('unknown', $result->issues[0]['criterion']);
        $this->assertNull($result->revision_suggestion);
    }
}
