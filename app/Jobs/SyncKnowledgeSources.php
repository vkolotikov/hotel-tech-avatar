<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Services\Knowledge\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncKnowledgeSources implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected ?int $avatarId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EmbeddingService $embeddingService): void
    {
        // Determine which agent(s) to sync
        $agents = $this->avatarId !== null
            ? Agent::where('id', $this->avatarId)->get()
            : Agent::all();

        foreach ($agents as $agent) {
            $this->syncAgent($agent, $embeddingService);
        }
    }

    /**
     * Sync a single agent's knowledge sources.
     */
    private function syncAgent(Agent $agent, EmbeddingService $embeddingService): void
    {
        try {
            $sources = $agent->knowledge_sources_json ?? [];

            if (empty($sources)) {
                $agent->update([
                    'knowledge_sync_status' => 'pending',
                    'knowledge_synced_at' => null,
                    'knowledge_last_error' => 'No knowledge sources configured',
                ]);
                return;
            }

            // Mark as in-progress
            $agent->update(['knowledge_sync_status' => 'syncing']);

            // Process each knowledge source
            foreach ($sources as $source) {
                $this->processSingle($agent, $source, $embeddingService);
            }

            // Mark as complete
            $agent->update([
                'knowledge_sync_status' => 'completed',
                'knowledge_synced_at' => now(),
                'knowledge_last_error' => null,
            ]);
        } catch (\Exception $e) {
            $agent->update([
                'knowledge_sync_status' => 'failed',
                'knowledge_last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Process a single knowledge source.
     */
    private function processSingle(Agent $agent, array $source, EmbeddingService $embeddingService): void
    {
        $sourceType = $source['type'] ?? null;
        $sourceKey = $source['key'] ?? null;
        $cachePolicy = $source['cache'] ?? 'cached';

        // Additional source-specific processing can be added here
        // For now, this is a placeholder for future drivers (PubMed, USDA, etc.)
    }
}
