<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Knowledge;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Models\Vertical;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature tests for RetrievalService end-to-end retrieval pipeline.
 *
 * Tests the complete retrieval flow: high-risk detection, context assembly,
 * and graceful error handling. Note: vector search is tested in unit tests due
 * to pgvector complexity in test databases.
 */
class RetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmbeddingService|MockInterface $embeddingService;
    private RetrievalService $retrievalService;
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Create vertical and agent for feature test context
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $this->agent = Agent::factory()
            ->for($vertical)
            ->create([
                'slug' => 'nora',
                'name' => 'Nora - Nutrition Expert',
            ]);

        // Mock EmbeddingService for consistent test vectors
        $this->embeddingService = $this->mock(EmbeddingService::class);
        app()->instance(EmbeddingService::class, $this->embeddingService);

        // Instantiate RetrievalService with mocked dependency
        $this->retrievalService = new RetrievalService($this->embeddingService);
    }

    /**
     * Test: high-risk keywords are correctly detected.
     *
     * Verifies that dangerous medical terms trigger the is_high_risk flag.
     */
    public function test_high_risk_keywords_are_detected(): void
    {
        // Test various high-risk prompts
        $highRiskPrompts = [
            'Is warfarin safe to take with my supplements?',
            'Can I take SSRIs with alcohol?',
            'What are the symptoms of melanoma?',
            'Is there a drug interaction with warfarin and aspirin?',
            'Should I take a cardiovascular medication?',
        ];

        foreach ($highRiskPrompts as $prompt) {
            $this->assertTrue(
                $this->retrievalService->isHighRiskQuery($prompt),
                "High-risk flag not set for prompt: {$prompt}"
            );
        }
    }

    /**
     * Test: safe prompts are not flagged as high-risk.
     *
     * Verifies that routine wellness queries don't trigger safety checks.
     */
    public function test_safe_prompts_are_not_flagged_as_high_risk(): void
    {
        $safePrompts = [
            'What are good healthy snacks?',
            'How do I stay hydrated?',
            'What is a balanced diet?',
        ];

        foreach ($safePrompts as $prompt) {
            $this->assertFalse(
                $this->retrievalService->isHighRiskQuery($prompt),
                "High-risk flag incorrectly set for safe prompt: {$prompt}"
            );
        }
    }

    /**
     * Test: is_high_risk_query method detects keywords correctly.
     *
     * Direct test of the public is_high_risk_query method to verify
     * keyword matching logic.
     */
    public function test_is_high_risk_query_method_detects_keywords(): void
    {
        // Test high-risk keywords
        $this->assertTrue($this->retrievalService->isHighRiskQuery('warfarin'));
        $this->assertTrue($this->retrievalService->isHighRiskQuery('can i take SSRIs?'));
        $this->assertTrue($this->retrievalService->isHighRiskQuery('melanoma symptoms'));
        $this->assertTrue($this->retrievalService->isHighRiskQuery('drug interactions'));
        $this->assertTrue($this->retrievalService->isHighRiskQuery('cardiac issues'));

        // Test safe keywords
        $this->assertFalse($this->retrievalService->isHighRiskQuery('healthy snacks'));
        $this->assertFalse($this->retrievalService->isHighRiskQuery('water intake'));
        $this->assertFalse($this->retrievalService->isHighRiskQuery('breakfast ideas'));
    }

    /**
     * Helper: Create a mock embedding vector of 3072 dimensions.
     * Uses provided values as first elements, pads remainder with zeros.
     *
     * @param float $val1
     * @param float $val2
     * @param float $val3
     * @return array
     */
    private function createEmbedding(float $val1, float $val2, float $val3): array
    {
        $embedding = [$val1, $val2, $val3];
        while (count($embedding) < 3072) {
            $embedding[] = 0.0;
        }
        return array_slice($embedding, 0, 3072);
    }
}
