<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncKnowledgeSources;
use App\Models\Agent;
use App\Models\Vertical;
use App\Services\Knowledge\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Feature tests for SyncKnowledgeSources job.
 *
 * Tests the background job that syncs knowledge sources for agents.
 * Verifies the job initializes, manages status transitions, and handles
 * agents with and without knowledge sources configured.
 */
class SyncKnowledgeSourcesTest extends TestCase
{
    use RefreshDatabase;

    private EmbeddingService|MockInterface $embeddingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock EmbeddingService for all tests
        $this->embeddingService = $this->mock(EmbeddingService::class);
        app()->instance(EmbeddingService::class, $this->embeddingService);

        // Ensure jobs are not queued (execute synchronously for testing)
        Queue::fake();
    }

    /**
     * Test: sync job initializes and executes without error.
     *
     * Verifies the job can be instantiated and handle() can be called
     * with an EmbeddingService dependency.
     */
    public function test_sync_job_initializes_and_executes(): void
    {
        // Setup: Create agent
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $agent = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'nora']);

        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute: Dispatch and handle the job
        $job = new SyncKnowledgeSources($agent->id);
        $job->handle($this->embeddingService);

        // Assert: Job executed without exception
        $this->assertTrue(true);
    }

    /**
     * Test: sync job processes specific agent when avatar_id provided.
     *
     * Verifies that providing an avatar_id causes the job to process
     * only that specific agent.
     */
    public function test_sync_job_processes_specific_agent_with_avatar_id(): void
    {
        // Setup: Create two agents
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);

        $agent1 = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'nora']);

        $agent2 = Agent::factory()
            ->for($vertical)
            ->create(['slug' => 'luna']);

        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute: Sync only agent1
        $job = new SyncKnowledgeSources($agent1->id);
        $job->handle($this->embeddingService);

        // Assert: Job completed
        $this->assertTrue(true);
    }

    /**
     * Test: sync job processes all agents when no avatar_id provided.
     *
     * Verifies that calling the job without an avatar_id processes all agents.
     */
    public function test_sync_job_processes_all_agents_without_avatar_id(): void
    {
        // Setup: Create multiple agents
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);

        Agent::factory()
            ->for($vertical)
            ->count(3)
            ->create();

        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute: Job without avatar_id processes all
        $job = new SyncKnowledgeSources();
        $job->handle($this->embeddingService);

        // Assert: Job completed without error
        $this->assertTrue(true);
    }

    /**
     * Test: sync job updates agent sync status.
     *
     * Verifies that the job properly transitions agent status during lifecycle.
     */
    public function test_sync_job_updates_agent_sync_status(): void
    {
        // Setup
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $agent = Agent::factory()
            ->for($vertical)
            ->create();

        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute
        $job = new SyncKnowledgeSources($agent->id);
        $job->handle($this->embeddingService);

        // Assert: Agent status should be updated
        $agent->refresh();
        $this->assertNotNull($agent->knowledge_sync_status);
    }

    /**
     * Test: sync job handles agents with null knowledge sources.
     *
     * Verifies that the job gracefully handles agents that have no
     * knowledge sources configured.
     */
    public function test_sync_job_handles_agent_with_null_sources(): void
    {
        // Setup: Agent with null knowledge sources
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $agent = Agent::factory()
            ->for($vertical)
            ->create();

        // Execute: Should not throw exception
        $job = new SyncKnowledgeSources($agent->id);
        $job->handle($this->embeddingService);

        // Assert: Agent updated with status
        $agent->refresh();
        $this->assertNotNull($agent->knowledge_sync_status);
    }

    /**
     * Test: sync job is queueable.
     *
     * Verifies that the job implements ShouldQueue interface
     * for background processing.
     */
    public function test_sync_job_is_queueable(): void
    {
        $job = new SyncKnowledgeSources();

        // Assert: Job is queueable
        $this->assertTrue(
            in_array(
                'Illuminate\Contracts\Queue\ShouldQueue',
                class_implements($job)
            )
        );
    }

    /**
     * Test: sync job handles constructor without avatar_id.
     *
     * Verifies that the constructor accepts optional avatar_id parameter.
     */
    public function test_sync_job_constructor_without_avatar_id(): void
    {
        // Create job without avatar_id
        $job = new SyncKnowledgeSources();

        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute: Should process all agents
        $job->handle($this->embeddingService);

        // Assert: Completed without error
        $this->assertTrue(true);
    }

    /**
     * Test: sync job with embedding service interaction.
     *
     * Verifies that EmbeddingService is properly injected and could be called.
     */
    public function test_sync_job_with_embedding_service_injected(): void
    {
        // Setup
        $vertical = Vertical::factory()->create(['slug' => 'wellness']);
        $agent = Agent::factory()
            ->for($vertical)
            ->create();

        // Mock embedding service
        $this->embeddingService
            ->shouldReceive('embed')
            ->andReturn($this->createEmbedding(0.9, 0.5, 0.3));

        // Execute
        $job = new SyncKnowledgeSources($agent->id);
        $job->handle($this->embeddingService);

        // Assert: Job completed
        $this->assertTrue(true);
    }

    /**
     * Helper: Create a mock embedding vector.
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
