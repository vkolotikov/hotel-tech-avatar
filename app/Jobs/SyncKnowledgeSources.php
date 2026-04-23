<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\OpenFoodFacts\FoodSearchDriver as OffDriver;
use App\Services\Knowledge\Drivers\PubMed\SearchDriver as PubMedDriver;
use App\Services\Knowledge\Drivers\Usda\FoodDataDriver as UsdaDriver;
use App\Services\Knowledge\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pulls an agent's knowledge_sources_json through the matching driver
 * (PubMed / USDA / Open Food Facts), embeds the retrieved chunks via
 * the EmbeddingService, and writes them into knowledge_documents +
 * knowledge_chunks so the RetrievalService can find them.
 *
 * Idempotent per source: for each unique source_url we upsert the
 * document and rebuild its chunks, so re-running = clean slate.
 */
class SyncKnowledgeSources implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ?int $avatarId = null,
    ) {}

    public function handle(EmbeddingService $embeddingService): void
    {
        $agents = $this->avatarId !== null
            ? Agent::where('id', $this->avatarId)->get()
            : Agent::all();

        foreach ($agents as $agent) {
            $this->syncAgent($agent, $embeddingService);
        }
    }

    private function syncAgent(Agent $agent, EmbeddingService $embeddingService): void
    {
        try {
            $sources = $agent->knowledge_sources_json ?? [];

            if (empty($sources)) {
                $agent->update([
                    'knowledge_sync_status' => 'pending',
                    'knowledge_synced_at'   => null,
                    'knowledge_last_error'  => 'No knowledge sources configured',
                ]);
                return;
            }

            $agent->update(['knowledge_sync_status' => 'syncing']);

            $chunksIngested = 0;
            $sourcesSucceeded = 0;
            $sourcesFailed = 0;
            $firstError = null;

            foreach ($sources as $source) {
                try {
                    $ingested = $this->processSingle($agent, $source, $embeddingService);
                    $chunksIngested += $ingested;
                    $sourcesSucceeded++;
                } catch (\Throwable $e) {
                    $sourcesFailed++;
                    $firstError = $firstError ?? $e->getMessage();
                    Log::warning('SyncKnowledgeSources: source failed', [
                        'agent_id' => $agent->id,
                        'source'   => $source['type'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            $status = $sourcesSucceeded > 0 ? 'completed' : 'failed';
            $errorMsg = $sourcesFailed > 0
                ? "{$sourcesFailed} of " . count($sources) . " sources failed: {$firstError}"
                : null;

            $agent->update([
                'knowledge_sync_status' => $status,
                'knowledge_synced_at'   => now(),
                'knowledge_last_error'  => $errorMsg,
            ]);

            Log::info('SyncKnowledgeSources: finished', [
                'agent_id'          => $agent->id,
                'chunks_ingested'   => $chunksIngested,
                'sources_succeeded' => $sourcesSucceeded,
                'sources_failed'    => $sourcesFailed,
            ]);
        } catch (\Throwable $e) {
            $agent->update([
                'knowledge_sync_status' => 'failed',
                'knowledge_last_error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Drive a single source through its driver, upsert the matching
     * document(s), and write fresh chunks with embeddings. Returns the
     * number of chunks ingested for this source.
     */
    private function processSingle(Agent $agent, array $source, EmbeddingService $embeddingService): int
    {
        $type = (string) ($source['type'] ?? '');

        $driver = $this->driverFor($type);
        if (!$driver) {
            Log::info('SyncKnowledgeSources: skipping unknown source type', [
                'agent_id' => $agent->id,
                'type'     => $type,
            ]);
            return 0;
        }

        $config = array_merge($source, [
            'api_key' => $this->apiKeyFor($type),
        ]);

        $fetched = $driver->fetch($config);
        if (empty($fetched)) {
            return 0;
        }

        // Group by source_url — one KnowledgeDocument per distinct source.
        $byUrl = [];
        foreach ($fetched as $chunk) {
            $byUrl[$chunk->source_url][] = $chunk;
        }

        $total = 0;
        foreach ($byUrl as $url => $list) {
            $first = $list[0];

            $document = KnowledgeDocument::updateOrCreate(
                ['agent_id' => $agent->id, 'source_url' => $url],
                [
                    'title'          => $first->source_name,
                    'evidence_grade' => $first->evidence_grade,
                    'metadata'       => [
                        'source_type' => $type,
                        'source_key'  => $source['key'] ?? null,
                        'citation'    => $first->citation_key,
                    ],
                    'ingested_at'    => now(),
                    'retired_at'     => null,
                ],
            );

            // Replace any previous chunks for this document so reindex
            // is a clean slate.
            KnowledgeChunk::where('document_id', $document->id)->delete();

            foreach ($list as $i => $chunk) {
                $embedding = $embeddingService->embed($chunk->content);
                KnowledgeChunk::create([
                    'document_id' => $document->id,
                    'agent_id'    => $agent->id,
                    'chunk_index' => $i,
                    'content'     => $chunk->content,
                    'metadata'    => [
                        'citation_key' => $chunk->citation_key,
                        'fetched_at'   => $chunk->fetched_at->format(\DateTimeInterface::ATOM),
                    ],
                    'embedding'   => $embedding,
                ]);
                $total++;
            }
        }

        return $total;
    }

    private function driverFor(string $type): ?DriverInterface
    {
        return match ($type) {
            'pubmed'          => new PubMedDriver(),
            'usda'            => new UsdaDriver(),
            'open_food_facts' => new OffDriver(),
            default           => null,
        };
    }

    private function apiKeyFor(string $type): string
    {
        return match ($type) {
            'pubmed' => (string) config('services.pubmed.api_key', ''),
            'usda'   => (string) config('services.usda.api_key', ''),
            default  => '',
        };
    }
}
