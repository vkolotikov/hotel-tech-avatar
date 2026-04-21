<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verification;

use App\Models\Agent;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\Contracts\CitationValidationServiceInterface;
use App\Services\Verification\Contracts\ClaimExtractionServiceInterface;
use App\Services\Verification\Contracts\GroundingServiceInterface;
use App\Services\Verification\Contracts\SafetyClassifierInterface;
use App\Services\Verification\Contracts\StructuredReviewServiceInterface;
use App\Services\Verification\Drivers\Claim;
use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;
use App\Services\Verification\Drivers\VerificationResult;
use App\Services\Verification\StructuredReviewResult;
use App\Services\Verification\VerificationService;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VerificationServiceTest extends TestCase
{
    private ClaimExtractionServiceInterface|MockInterface $claimExtractionService;
    private GroundingServiceInterface|MockInterface $groundingService;
    private CitationValidationServiceInterface|MockInterface $citationValidationService;
    private SafetyClassifierInterface|MockInterface $safetyClassifier;
    private StructuredReviewServiceInterface|MockInterface $structuredReviewService;
    private LlmClient|MockInterface $llmClient;
    private VerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimExtractionService = Mockery::mock(ClaimExtractionServiceInterface::class);
        $this->groundingService = Mockery::mock(GroundingServiceInterface::class);
        $this->citationValidationService = Mockery::mock(CitationValidationServiceInterface::class);
        $this->safetyClassifier = Mockery::mock(SafetyClassifierInterface::class);
        $this->structuredReviewService = Mockery::mock(StructuredReviewServiceInterface::class);
        $this->llmClient = Mockery::mock(LlmClient::class);

        $this->service = new VerificationService(
            claimExtractionService: $this->claimExtractionService,
            groundingService: $this->groundingService,
            citationValidationService: $this->citationValidationService,
            safetyClassifier: $this->safetyClassifier,
            structuredReviewService: $this->structuredReviewService,
            llmClient: $this->llmClient,
        );
    }

    /**
     * Test 1: Safe response returns verified result with no failures.
     */
    public function test_safe_response_returns_verified_result(): void
    {
        $responseText = 'Staying hydrated supports overall wellness and energy levels.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: false,
            chunk_count: 0,
        );
        $agent = Mockery::mock(Agent::class);

        $claim = new Claim(
            text: 'Staying hydrated supports overall wellness and energy levels.',
            requires_citation: false,
            inferred_source_category: 'general',
        );

        $this->claimExtractionService
            ->shouldReceive('extract')
            ->once()
            ->with($responseText)
            ->andReturn([$claim]);

        $this->groundingService
            ->shouldReceive('ground_all_claims')
            ->once()
            ->andReturn([$claim]);

        $this->citationValidationService
            ->shouldReceive('validate_all_citations')
            ->once()
            ->andReturn([$claim]);

        $this->safetyClassifier
            ->shouldReceive('classify')
            ->once()
            ->with($responseText)
            ->andReturn([]);

        $this->structuredReviewService
            ->shouldReceive('review')
            ->once()
            ->andReturn(new StructuredReviewResult(
                passed: true,
                issues: [],
                revision_suggestion: null,
            ));

        $result = $this->service->verify($responseText, $context, $agent);

        $this->assertInstanceOf(VerificationResult::class, $result);
        $this->assertTrue($result->is_verified);
        $this->assertEmpty($result->failures);
        $this->assertEmpty($result->safety_flags);
        $this->assertSame(0, $result->revision_count);
        $this->assertGreaterThanOrEqual(0, $result->latency_ms);
    }

    /**
     * Test 2: Unsafe response (hard safety flag) returns unverified result.
     */
    public function test_unsafe_response_returns_unverified_result(): void
    {
        $responseText = 'You should prescribe this medication for chest pain immediately.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: true,
            chunk_count: 0,
        );
        $agent = Mockery::mock(Agent::class);

        $claim = new Claim(
            text: 'You should prescribe this medication for chest pain immediately.',
            requires_citation: false,
            inferred_source_category: 'clinical',
        );

        $this->claimExtractionService
            ->shouldReceive('extract')
            ->once()
            ->with($responseText)
            ->andReturn([$claim]);

        $this->groundingService
            ->shouldReceive('ground_all_claims')
            ->once()
            ->andReturn([$claim]);

        $this->citationValidationService
            ->shouldReceive('validate_all_citations')
            ->once()
            ->andReturn([$claim]);

        $hardFlag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'prescribe',
            suggested_action: 'Requires immediate review and revision',
            matched_text: 'should prescribe this medication',
        );

        $this->safetyClassifier
            ->shouldReceive('classify')
            ->once()
            ->with($responseText)
            ->andReturn([$hardFlag]);

        $this->structuredReviewService
            ->shouldReceive('review')
            ->once()
            ->andReturn(new StructuredReviewResult(
                passed: false,
                issues: [['criterion' => 'safety', 'description' => 'Hard safety violation detected']],
                revision_suggestion: null,
            ));

        $result = $this->service->verify($responseText, $context, $agent);

        $this->assertInstanceOf(VerificationResult::class, $result);
        $this->assertFalse($result->is_verified);
        $this->assertNotEmpty($result->failures);
        $this->assertSame(0, $result->revision_count);
        $this->assertGreaterThanOrEqual(0, $result->latency_ms);
    }
}
