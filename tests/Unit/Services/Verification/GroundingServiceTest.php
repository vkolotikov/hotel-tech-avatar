<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verification;

use App\Models\KnowledgeChunk;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Verification\Drivers\Claim;
use App\Services\Verification\Drivers\GroundingResult;
use App\Services\Verification\GroundingService;
use Mockery;
use Tests\TestCase;

class GroundingServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Test 1: Claims matched to chunks with similarity >= 0.65 are marked as grounded.
     */
    public function test_claims_above_threshold_are_marked_as_grounded(): void
    {
        // Vectors that will produce high cosine similarity (nearly identical)
        $claimVector = [1.0, 0.0, 0.0];
        $chunkVector = [1.0, 0.0, 0.0]; // perfect match, similarity = 1.0

        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $embeddingServiceMock
            ->shouldReceive('embed')
            ->once()
            ->andReturn($claimVector);

        $chunk = $this->makeKnowledgeChunk(
            content: 'Magnesium supplementation has been shown to improve sleep quality in clinical trials.',
            embeddingString: '[1.0,0.0,0.0]',
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 10,
            is_high_risk: false,
            chunk_count: 1,
        );

        $claim = new Claim(
            text: 'Magnesium improves sleep quality',
            requires_citation: true,
            inferred_source_category: 'research',
        );

        $service = new GroundingService($embeddingServiceMock);
        $results = $service->ground_all_claims([$claim], $context);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(Claim::class, $results[0]);
        $this->assertNotNull($results[0]->grounding);
        $this->assertInstanceOf(GroundingResult::class, $results[0]->grounding);
        $this->assertTrue($results[0]->grounding->is_grounded);
        $this->assertSame($chunk, $results[0]->grounding->matched_chunk);
        $this->assertGreaterThanOrEqual(0.65, $results[0]->grounding->similarity_score);
        $this->assertSame(
            'Magnesium supplementation has been shown to improve sleep quality in clinical trials.',
            $results[0]->grounding->supporting_evidence
        );
    }

    /**
     * Test 2: No matching chunks (low similarity) returns ungrounded results.
     */
    public function test_no_matching_chunks_returns_ungrounded_results(): void
    {
        // Orthogonal vectors → cosine similarity = 0
        $claimVector = [1.0, 0.0, 0.0];
        $chunkVector = [0.0, 1.0, 0.0]; // orthogonal, similarity = 0.0

        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $embeddingServiceMock
            ->shouldReceive('embed')
            ->once()
            ->andReturn($claimVector);

        $chunk = $this->makeKnowledgeChunk(
            content: 'Some unrelated content about fitness.',
            embeddingString: '[0.0,1.0,0.0]',
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 10,
            is_high_risk: false,
            chunk_count: 1,
        );

        $claim = new Claim(
            text: 'Magnesium improves sleep quality',
            requires_citation: true,
            inferred_source_category: 'research',
        );

        $service = new GroundingService($embeddingServiceMock);
        $results = $service->ground_all_claims([$claim], $context);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->grounding);
        $this->assertFalse($results[0]->grounding->is_grounded);
        $this->assertNull($results[0]->grounding->matched_chunk);
    }

    /**
     * Test 3: Empty context returns ungrounded claims.
     */
    public function test_empty_context_returns_ungrounded_claims(): void
    {
        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        // embed() should NOT be called for empty context
        $embeddingServiceMock->shouldNotReceive('embed');

        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 0,
            is_high_risk: false,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'Magnesium improves sleep quality',
            requires_citation: true,
            inferred_source_category: 'research',
        );

        $service = new GroundingService($embeddingServiceMock);
        $results = $service->ground_all_claims([$claim], $context);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->grounding);
        $this->assertFalse($results[0]->grounding->is_grounded);
        $this->assertNull($results[0]->grounding->matched_chunk);
    }

    /**
     * Test 4: Embedding failure returns ungrounded with log warning.
     */
    public function test_embedding_failure_returns_ungrounded_with_log_warning(): void
    {
        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $embeddingServiceMock
            ->shouldReceive('embed')
            ->once()
            ->andThrow(new \RuntimeException('OpenAI API unavailable'));

        $chunk = $this->makeKnowledgeChunk(
            content: 'Some content.',
            embeddingString: '[0.5,0.5,0.5]',
        );

        $context = new RetrievedContext(
            chunks: [$chunk],
            latency_ms: 10,
            is_high_risk: false,
            chunk_count: 1,
        );

        $claim = new Claim(
            text: 'Magnesium improves sleep quality',
            requires_citation: true,
            inferred_source_category: 'research',
        );

        $service = new GroundingService($embeddingServiceMock);
        $results = $service->ground_all_claims([$claim], $context);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]->grounding);
        $this->assertFalse($results[0]->grounding->is_grounded);
        $this->assertNull($results[0]->grounding->matched_chunk);
    }

    /**
     * Test 5: Claims not requiring citation are returned unchanged.
     */
    public function test_claims_without_citation_requirement_returned_unchanged(): void
    {
        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $embeddingServiceMock->shouldNotReceive('embed');

        $context = new RetrievedContext(
            chunks: [],
            latency_ms: 0,
            is_high_risk: false,
            chunk_count: 0,
        );

        $claim = new Claim(
            text: 'Sleep is important for overall wellbeing',
            requires_citation: false,
            inferred_source_category: 'general',
        );

        $service = new GroundingService($embeddingServiceMock);
        $results = $service->ground_all_claims([$claim], $context);

        $this->assertCount(1, $results);
        $this->assertSame($claim, $results[0]);
        $this->assertNull($results[0]->grounding);
    }

    /**
     * Test 6: parse_pgvector correctly parses pgvector string format.
     */
    public function test_parse_pgvector_parses_string_to_float_array(): void
    {
        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $service = new GroundingService($embeddingServiceMock);

        $result = $service->parse_pgvector('[0.1, 0.2, 0.3]');

        $this->assertCount(3, $result);
        $this->assertEqualsWithDelta(0.1, $result[0], 1e-6);
        $this->assertEqualsWithDelta(0.2, $result[1], 1e-6);
        $this->assertEqualsWithDelta(0.3, $result[2], 1e-6);
    }

    /**
     * Test 7: calculate_similarity returns correct cosine similarity.
     */
    public function test_calculate_similarity_returns_correct_cosine_similarity(): void
    {
        $embeddingServiceMock = Mockery::mock(EmbeddingService::class);
        $service = new GroundingService($embeddingServiceMock);

        // Identical vectors → similarity = 1.0
        $this->assertEqualsWithDelta(1.0, $service->calculate_similarity([1.0, 0.0], [1.0, 0.0]), 1e-6);

        // Orthogonal vectors → similarity = 0.0
        $this->assertEqualsWithDelta(0.0, $service->calculate_similarity([1.0, 0.0], [0.0, 1.0]), 1e-6);

        // Zero vector → similarity = 0.0
        $this->assertEqualsWithDelta(0.0, $service->calculate_similarity([0.0, 0.0], [1.0, 0.0]), 1e-6);
    }

    /**
     * Create a fake KnowledgeChunk model instance with a given embedding string.
     * Uses newInstanceWithoutConstructor to avoid DB interaction.
     */
    private function makeKnowledgeChunk(string $content, string $embeddingString): KnowledgeChunk
    {
        $chunk = new KnowledgeChunk();
        $chunk->content = $content;
        // Set raw attribute so getRawOriginal('embedding') returns the pgvector string
        $chunk->setRawAttributes([
            'id' => 1,
            'content' => $content,
            'embedding' => $embeddingString,
        ]);

        return $chunk;
    }
}
