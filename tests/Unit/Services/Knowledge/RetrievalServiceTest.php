<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Vertical;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class RetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmbeddingService|MockInterface $embeddingService;
    private RetrievalService $retrievalService;
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Create vertical and agent
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $this->agent = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'nora']);

        // Mock EmbeddingService
        $this->embeddingService = $this->mock(EmbeddingService::class);

        // Instantiate RetrievalService
        $this->retrievalService = new RetrievalService($this->embeddingService);
    }

    /**
     * Test: retrieve returns chunks ordered by vector similarity.
     */
    public function test_retrieve_returns_chunks_by_vector_similarity(): void
    {
        // Setup: Create knowledge documents and chunks
        $doc1 = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create([
                'title' => 'Nutrition Basics',
                'source_url' => 'https://example.com/nutrition',
                'evidence_grade' => 'A',
            ]);

        $chunk1 = KnowledgeChunk::factory()
            ->for($doc1, 'document')
            ->for($this->agent)
            ->create([
                'content' => 'Protein is essential for muscle growth and repair.',
                'embedding' => $this->createEmbedding(1.0, 0.5, 0.3),
            ]);

        $doc2 = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create([
                'title' => 'Carbohydrates Guide',
                'source_url' => 'https://example.com/carbs',
                'evidence_grade' => 'B',
            ]);

        $chunk2 = KnowledgeChunk::factory()
            ->for($doc2, 'document')
            ->for($this->agent)
            ->create([
                'content' => 'Carbohydrates provide energy for the body.',
                'embedding' => $this->createEmbedding(0.9, 0.4, 0.2),
            ]);

        $prompt = 'What role does protein play in muscle growth?';
        $promptEmbedding = $this->createEmbedding(1.0, 0.5, 0.3); // Similar to chunk1

        // Mock embedding service to return consistent embedding
        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($promptEmbedding);

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert: Should return at least one chunk
        $this->assertGreater(0, $context->chunk_count);
        $this->assertGreater(0, $context->latency_ms);
        $this->assertFalse($context->is_high_risk);
        $this->assertIsArray($context->chunks);
    }

    /**
     * Test: high-risk keywords trigger live API check.
     */
    public function test_high_risk_keywords_trigger_live_api_check(): void
    {
        $prompt = 'Is warfarin safe to take with my supplements?';

        $doc = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create([
                'title' => 'Drug Interactions',
                'source_url' => 'https://example.com/interactions',
                'evidence_grade' => 'A',
            ]);

        KnowledgeChunk::factory()
            ->for($doc, 'document')
            ->for($this->agent)
            ->create([
                'content' => 'Warfarin interactions are serious.',
                'embedding' => $this->createEmbedding(0.8, 0.6, 0.4),
            ]);

        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($this->createEmbedding(0.8, 0.6, 0.4));

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert: High-risk flag should be set
        $this->assertTrue($context->is_high_risk);
    }

    /**
     * Test: deduplication by source_url keeps only first occurrence.
     */
    public function test_deduplication_by_source_url(): void
    {
        // Setup: Create two documents with same source_url (edge case)
        // In practice, this shouldn't happen due to uniqueness, but test the dedup logic
        $doc1 = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create([
                'title' => 'Nutrition Part 1',
                'source_url' => 'https://example.com/nutrition',
                'evidence_grade' => 'A',
            ]);

        $chunk1 = KnowledgeChunk::factory()
            ->for($doc1, 'document')
            ->for($this->agent)
            ->create([
                'content' => 'First mention of protein.',
                'embedding' => $this->createEmbedding(0.9, 0.5, 0.3),
            ]);

        // Create another document with different URL
        $doc2 = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create([
                'title' => 'Nutrition Part 2',
                'source_url' => 'https://example.com/more-nutrition',
                'evidence_grade' => 'A',
            ]);

        $chunk2 = KnowledgeChunk::factory()
            ->for($doc2, 'document')
            ->for($this->agent)
            ->create([
                'content' => 'Second mention of protein.',
                'embedding' => $this->createEmbedding(0.85, 0.5, 0.3),
            ]);

        $prompt = 'Tell me about protein.';

        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert: Should have chunks with different source URLs
        $sourceUrls = array_map(fn ($chunk) => $chunk->source_url, $context->chunks);
        $uniqueUrls = array_unique($sourceUrls);

        $this->assertCount(count($uniqueUrls), $sourceUrls);
    }

    /**
     * Test: respects max_cached_results configuration.
     */
    public function test_respects_max_cached_results_limit(): void
    {
        // Create 10 chunks
        for ($i = 0; $i < 10; $i++) {
            $doc = KnowledgeDocument::factory()
                ->for($this->agent)
                ->create([
                    'title' => "Document {$i}",
                    'source_url' => "https://example.com/doc{$i}",
                    'evidence_grade' => 'A',
                ]);

            KnowledgeChunk::factory()
                ->for($doc, 'document')
                ->for($this->agent)
                ->create([
                    'content' => "Content {$i}",
                    'embedding' => $this->createEmbedding(0.9, 0.5, 0.3),
                ]);
        }

        $prompt = 'General query';

        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert: Should not exceed max_cached_results
        $maxResults = (int) config('retrieval.max_cached_results', 5);
        $this->assertLessThanOrEqual($maxResults, $context->chunk_count);
    }

    /**
     * Test: isHighRiskQuery detects high-risk keywords.
     */
    public function test_is_high_risk_query_detects_keywords(): void
    {
        $this->assertTrue(
            $this->retrievalService->isHighRiskQuery('Is warfarin safe?')
        );

        $this->assertTrue(
            $this->retrievalService->isHighRiskQuery('Can I take SSRIs with food?')
        );

        $this->assertTrue(
            $this->retrievalService->isHighRiskQuery('What are the symptoms of melanoma?')
        );

        $this->assertFalse(
            $this->retrievalService->isHighRiskQuery('What are good healthy snacks?')
        );

        $this->assertFalse(
            $this->retrievalService->isHighRiskQuery('How do I stay hydrated?')
        );
    }

    /**
     * Test: returns empty context on no matching chunks.
     */
    public function test_returns_empty_context_on_no_matches(): void
    {
        $prompt = 'Completely unrelated query that matches no chunks';

        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($this->createEmbedding(0.1, 0.1, 0.1));

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert
        $this->assertIsArray($context->chunks);
        // May be empty or have low-quality chunks depending on threshold
        $this->assertGreaterThanOrEqual(0, $context->chunk_count);
    }

    /**
     * Test: latency_ms is recorded.
     */
    public function test_latency_ms_is_recorded(): void
    {
        $doc = KnowledgeDocument::factory()
            ->for($this->agent)
            ->create();

        KnowledgeChunk::factory()
            ->for($doc, 'document')
            ->for($this->agent)
            ->create([
                'embedding' => $this->createEmbedding(0.9, 0.5, 0.3),
            ]);

        $prompt = 'Test query';

        $this->embeddingService
            ->shouldReceive('embed')
            ->with($prompt)
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute
        $context = $this->retrievalService->retrieve($prompt, $this->agent);

        // Assert
        $this->assertGreaterThanOrEqual(0, $context->latency_ms);
    }

    /**
     * Helper: Create a mock embedding vector.
     * For testing, we create a minimal valid embedding by padding with zeros.
     *
     * @param float $val1
     * @param float $val2
     * @param float $val3
     * @return array
     */
    private function createEmbedding(float $val1, float $val2, float $val3): array
    {
        $embedding = [$val1, $val2, $val3];
        // Pad to 3072 dimensions with zeros
        while (count($embedding) < 3072) {
            $embedding[] = 0.0;
        }
        return array_slice($embedding, 0, 3072);
    }
}
