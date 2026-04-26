<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Verification;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmResponse;
use App\Services\Verification\Contracts\CitationValidationServiceInterface;
use App\Services\Verification\Contracts\ClaimExtractionServiceInterface;
use App\Services\Verification\Contracts\GroundingServiceInterface;
use App\Services\Verification\Contracts\SafetyClassifierInterface;
use App\Services\Verification\Contracts\StructuredReviewServiceInterface;
use App\Services\Verification\Drivers\Claim;
use App\Services\Verification\Drivers\CitationValidationResult;
use App\Services\Verification\Drivers\GroundingResult;
use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;
use App\Services\Verification\StructuredReviewResult;
use App\Services\Verification\VerificationService;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class VerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClaimExtractionServiceInterface|MockInterface $claimExtractionService;
    private GroundingServiceInterface|MockInterface $groundingService;
    private CitationValidationServiceInterface|MockInterface $citationValidationService;
    private SafetyClassifierInterface|MockInterface $safetyClassifier;
    private StructuredReviewServiceInterface|MockInterface $structuredReviewService;
    private LlmClient|MockInterface $llmClient;
    private VerificationService $service;
    private Agent $agent;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the database with verticals and demo data
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        // Create test data
        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        $vertical = Vertical::firstOrCreate(
            ['slug' => 'wellness'],
            ['name' => 'Wellness']
        );

        $this->agent = Agent::where('slug', 'nora')->first() ?? Agent::create([
            'vertical_id' => $vertical->id,
            'slug' => 'nora',
            'name' => 'Nora',
            'system_instructions' => 'You are a nutrition expert.',
            'openai_model' => 'gpt-4o',
        ]);

        $this->conversation = Conversation::create([
            'user_id' => $user->id,
            'agent_id' => $this->agent->id,
            'vertical_id' => $vertical->id,
            'title' => 'Test Conversation',
        ]);

        // Set up mocks
        $this->claimExtractionService = Mockery::mock(ClaimExtractionServiceInterface::class);
        $this->groundingService = Mockery::mock(GroundingServiceInterface::class);
        $this->citationValidationService = Mockery::mock(CitationValidationServiceInterface::class);
        $this->safetyClassifier = Mockery::mock(SafetyClassifierInterface::class);
        $this->structuredReviewService = Mockery::mock(StructuredReviewServiceInterface::class);
        $this->llmClient = Mockery::mock(LlmClient::class);

        // Bind mocks to container before creating service
        $this->app->bind(ClaimExtractionServiceInterface::class, fn () => $this->claimExtractionService);
        $this->app->bind(GroundingServiceInterface::class, fn () => $this->groundingService);
        $this->app->bind(CitationValidationServiceInterface::class, fn () => $this->citationValidationService);
        $this->app->bind(SafetyClassifierInterface::class, fn () => $this->safetyClassifier);
        $this->app->bind(StructuredReviewServiceInterface::class, fn () => $this->structuredReviewService);
        $this->app->bind(LlmClient::class, fn () => $this->llmClient);

        // Create service with mocked dependencies
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
     * Test 1: Safe grounded response passes verification with 0-2 revisions
     */
    public function test_verify_passes_safe_grounded_response(): void
    {
        // Create a knowledge document and chunk
        $document = KnowledgeDocument::create([
            'agent_id' => $this->agent->id,
            'title' => 'Nutrition 101',
            'source_url' => 'https://example.com/nutrition',
            'evidence_grade' => 'expert-opinion',
            'licence' => 'internal',
            'locale' => 'en',
            'metadata' => [],
        ]);

        // Create a chunk with embedding
        $embedding = array_fill(0, 3072, 0.1); // 3072-dimensional vector
        $chunk = KnowledgeChunk::create([
            'document_id' => $document->id,
            'agent_id' => $this->agent->id,
            'chunk_index' => 0,
            'content' => 'Magnesium supports muscle function and relaxation.',
            'embedding' => $embedding,
            'metadata' => [],
        ]);

        $responseText = 'Magnesium supports muscle function and relaxation.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: false,
            chunk_count: 1,
        );

        $claim = new Claim(
            text: 'Magnesium supports muscle function and relaxation.',
            requires_citation: true,
            inferred_source_category: 'nutrition',
            grounding: new GroundingResult(
                is_grounded: true,
                matched_chunk: $chunk,
                similarity_score: 0.95,
                supporting_evidence: 'Exact match in knowledge base.',
            ),
            citation: new CitationValidationResult(
                is_valid: true,
                validation_detail: 'Citation validated against source.',
                resolved_source_url: 'https://example.com/nutrition',
                source_type: 'expert-opinion',
            ),
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

        // Clean first pass — Stage 5 (structured review) is intentionally
        // skipped to save the ~1–3 s critic LLM round-trip.
        $this->structuredReviewService
            ->shouldNotReceive('review');

        $result = $this->service->verify($responseText, $context, $this->agent);

        $this->assertTrue($result->is_verified);
        $this->assertEmpty($result->failures);
        $this->assertEmpty($result->safety_flags);
        $this->assertGreaterThanOrEqual(0, $result->revision_count);
        $this->assertLessThanOrEqual(2, $result->revision_count);
        $this->assertGreaterThanOrEqual(0, $result->latency_ms);
    }

    /**
     * Test 2: Response with diagnosis language is flagged as unsafe
     */
    public function test_verify_flags_diagnosis_language(): void
    {
        $responseText = 'You have a magnesium deficiency that needs treatment.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: true,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'You have a magnesium deficiency that needs treatment.',
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

        $diagnosisFlag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'has.*deficiency',
            suggested_action: 'Rephrase as educational content, not diagnosis.',
            matched_text: 'You have a magnesium deficiency',
        );

        $this->safetyClassifier
            ->shouldReceive('classify')
            ->once()
            ->with($responseText)
            ->andReturn([$diagnosisFlag]);

        $this->structuredReviewService
            ->shouldReceive('review')
            ->once()
            ->andReturn(new StructuredReviewResult(
                passed: false,
                issues: [['criterion' => 'safety', 'description' => 'Diagnosis language detected']],
                revision_suggestion: null,
            ));

        $result = $this->service->verify($responseText, $context, $this->agent);

        $this->assertFalse($result->is_verified);
        $this->assertNotEmpty($result->safety_flags);
        $this->assertCount(1, $result->safety_flags);
        $this->assertSame(SafetyFlagSeverity::HARD, $result->safety_flags[0]->severity);
    }

    /**
     * Test 3: Response with prescription language is flagged as unsafe
     */
    public function test_verify_flags_prescription_language(): void
    {
        $responseText = 'I prescribe 300mg of magnesium daily.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: true,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'I prescribe 300mg of magnesium daily.',
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

        $prescriptionFlag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'prescribe',
            suggested_action: 'Remove prescription language; use educational phrasing instead.',
            matched_text: 'I prescribe 300mg',
        );

        $this->safetyClassifier
            ->shouldReceive('classify')
            ->once()
            ->with($responseText)
            ->andReturn([$prescriptionFlag]);

        $this->structuredReviewService
            ->shouldReceive('review')
            ->once()
            ->andReturn(new StructuredReviewResult(
                passed: false,
                issues: [['criterion' => 'safety', 'description' => 'Prescription language detected']],
                revision_suggestion: null,
            ));

        $result = $this->service->verify($responseText, $context, $this->agent);

        $this->assertFalse($result->is_verified);
        $this->assertNotEmpty($result->safety_flags);
        $this->assertCount(1, $result->safety_flags);
        $this->assertSame(SafetyFlagSeverity::HARD, $result->safety_flags[0]->severity);
    }

    /**
     * Test 4: Verification result includes latency metric
     */
    public function test_verify_adds_latency_metric(): void
    {
        $responseText = 'Staying hydrated is important for wellness.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: false,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'Staying hydrated is important for wellness.',
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

        // Clean first pass — Stage 5 skipped (see Stage 5 comment in
        // VerificationService::verify()).
        $this->structuredReviewService
            ->shouldNotReceive('review');

        $result = $this->service->verify($responseText, $context, $this->agent);

        $this->assertGreaterThanOrEqual(0, $result->latency_ms);
        $this->assertIsInt($result->latency_ms);
    }

    /**
     * Test 5: Verification respects max revisions limit
     */
    public function test_verify_respects_max_revisions(): void
    {
        $responseText = 'You have a vitamin D deficiency and I prescribe 1000 IU daily.';
        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 50,
            is_high_risk: true,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'You have a vitamin D deficiency and I prescribe 1000 IU daily.',
            requires_citation: false,
            inferred_source_category: 'clinical',
        );

        // Multiple issues require revision
        $diagnosisFlag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'has.*deficiency',
            suggested_action: 'Rephrase as educational content.',
            matched_text: 'You have a vitamin D deficiency',
        );

        $prescriptionFlag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'prescribe',
            suggested_action: 'Remove prescription language.',
            matched_text: 'I prescribe 1000 IU',
        );

        $this->claimExtractionService
            ->shouldReceive('extract')
            ->twice()
            ->andReturn([$claim]);

        $this->groundingService
            ->shouldReceive('ground_all_claims')
            ->twice()
            ->andReturn([$claim]);

        $this->citationValidationService
            ->shouldReceive('validate_all_citations')
            ->twice()
            ->andReturn([$claim]);

        $this->safetyClassifier
            ->shouldReceive('classify')
            ->twice()
            ->andReturn([$diagnosisFlag, $prescriptionFlag]);

        // First review: fails, suggests revision
        // Second review: fails again (simulating persistent issues beyond revision)
        $this->structuredReviewService
            ->shouldReceive('review')
            ->twice()
            ->andReturn(new StructuredReviewResult(
                passed: false,
                issues: [
                    ['criterion' => 'safety', 'description' => 'Multiple violations'],
                ],
                revision_suggestion: 'Please rephrase without diagnosis or prescription language.',
            ));

        // Mock LLM revision response - should be called once (first revision attempt)
        $this->llmClient
            ->shouldReceive('chat')
            ->once()
            ->andReturn(new LlmResponse(
                content: 'Consider that vitamin D supports bone health.',
                role: 'assistant',
                provider: 'openai',
                model: 'gpt-4o',
                promptTokens: 10,
                completionTokens: 10,
                totalTokens: 20,
                latencyMs: 100,
                traceId: null,
            ));

        $result = $this->service->verify($responseText, $context, $this->agent);

        // Should not exceed max revisions
        $this->assertLessThanOrEqual(VerificationService::MAX_REVISIONS, $result->revision_count);
        $this->assertFalse($result->is_verified);
    }
}
