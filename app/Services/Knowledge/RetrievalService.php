<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Knowledge\RetrievedContext;

final class RetrievalService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Retrieve relevant knowledge chunks for a prompt.
     * Uses vector search on cached chunks, checks for high-risk keywords,
     * and falls back to live API calls if needed.
     *
     * @param string $prompt User query
     * @param Agent $agent The agent context
     * @return RetrievedContext Context with chunks and latency
     */
    public function retrieve(string $prompt, Agent $agent): RetrievedContext
    {
        $startTime = microtime(true);

        // Check for high-risk keywords
        $isHighRisk = $this->isHighRiskQuery($prompt);

        // Vector search on cached chunks
        $chunks = $this->vectorSearch($prompt, $agent);

        // If high-risk and available sources exist, check live APIs
        if ($isHighRisk && $this->hasLiveSource($agent)) {
            try {
                $liveChunks = $this->fetchLiveChunks($prompt);
                $chunks = array_merge($chunks, $liveChunks);
            } catch (\Throwable $e) {
                Log::warning('RetrievalService: Live API fetch failed for high-risk query', [
                    'agent_id' => $agent->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate by source_url
        $chunks = $this->deduplicateBySourceUrl($chunks);

        // Limit to max cached results
        $maxResults = (int) config('retrieval.max_cached_results', 5);
        $chunks = array_slice($chunks, 0, $maxResults);

        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        return new RetrievedContext(
            chunks: $chunks,
            latency_ms: $latencyMs,
            is_high_risk: $isHighRisk,
            chunk_count: count($chunks),
        );
    }

    /**
     * Check if prompt contains high-risk keywords that require live API verification.
     *
     * @param string $prompt
     * @return bool
     */
    public function isHighRiskQuery(string $prompt): bool
    {
        $keywords = (array) config('retrieval.high_risk_keywords', []);
        $lowerPrompt = strtolower($prompt);

        foreach ($keywords as $keyword) {
            // Support regex patterns
            if (preg_match("/{$keyword}/i", $lowerPrompt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform vector similarity search on cached knowledge chunks.
     *
     * @param string $prompt
     * @param Agent $agent
     * @return array Array of RetrievedChunk objects
     */
    private function vectorSearch(string $prompt, Agent $agent): array
    {
        $promptEmbedding = $this->embeddingService->embed($prompt);
        $embeddingString = $this->embeddingToString($promptEmbedding);

        // Query using raw SQL for pgvector distance operator
        // pgvector <-> returns distance (0 = identical, 1 = completely opposite)
        // We use < 1 to get reasonable similarity matches
        $chunks = DB::select(
            'SELECT kc.*, 1 - (kc.embedding <-> ?::vector) as similarity ' .
            'FROM knowledge_chunks kc ' .
            'WHERE kc.agent_id = ? AND kc.embedding IS NOT NULL ' .
            'ORDER BY kc.embedding <-> ?::vector ' .
            'LIMIT 10',
            [$embeddingString, $agent->id, $embeddingString]
        );

        // Convert raw results to models and filter by threshold
        $threshold = (float) config('retrieval.vector_similarity_threshold', 0.7);
        $result = [];

        foreach ($chunks as $row) {
            if ((float) $row->similarity >= $threshold) {
                // Convert to KnowledgeChunk model
                $chunk = KnowledgeChunk::find($row->id);
                if ($chunk) {
                    $result[] = $this->chunkToRetrievedChunk($chunk);
                }
            }
        }

        return $result;
    }

    /**
     * Check if agent has any live sources configured.
     *
     * @param Agent $agent
     * @return bool
     */
    private function hasLiveSource(Agent $agent): bool
    {
        // TODO: Implement per-agent live source configuration
        // For now, return false — live sources will be configured per agent
        // in knowledge_documents.metadata or agent.scope_json
        return false;
    }

    /**
     * Fetch chunks from live APIs (PubMed, USDA, etc).
     * This is a stub for Phase 1+ when live sources are configured.
     *
     * @param string $prompt
     * @return array
     */
    private function fetchLiveChunks(string $prompt): array
    {
        // TODO: Implement live API calls to PubMed, USDA FoodData Central, etc.
        // For now, return empty array
        return [];
    }

    /**
     * Deduplicate chunks by source_url, keeping the first occurrence.
     *
     * @param array $chunks
     * @return array
     */
    private function deduplicateBySourceUrl(array $chunks): array
    {
        $seen = [];
        $result = [];

        foreach ($chunks as $chunk) {
            $url = $chunk->source_url;
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $result[] = $chunk;
            }
        }

        return $result;
    }

    /**
     * Convert KnowledgeChunk model to RetrievedChunk DTO.
     *
     * Carries the chunk's DB id and embedding vector through to the DTO
     * so downstream services (GroundingService for cosine similarity,
     * citation persistence for chunk_id linkage) don't have to hit the
     * database or OpenAI again.
     *
     * @param KnowledgeChunk $chunk
     * @return \App\Services\Knowledge\Drivers\RetrievedChunk
     */
    private function chunkToRetrievedChunk(KnowledgeChunk $chunk): \App\Services\Knowledge\Drivers\RetrievedChunk
    {
        $document = $chunk->document;

        // pgvector values come back from Postgres as a string like
        // "[0.1,0.2,...]"; parse once here so consumers see a native
        // float array.
        $embedding = null;
        $raw = $chunk->getRawOriginal('embedding');
        if (is_string($raw) && $raw !== '' && $raw !== '[]') {
            $inner = trim($raw, '[]');
            if ($inner !== '') {
                $embedding = array_map(
                    static fn (string $v) => (float) trim($v),
                    explode(',', $inner),
                );
            }
        }

        $citationKeyFromMetadata = is_array($chunk->metadata)
            ? ($chunk->metadata['citation_key'] ?? null)
            : null;

        return new \App\Services\Knowledge\Drivers\RetrievedChunk(
            content: $chunk->content,
            source_url: $document->source_url,
            source_name: $document->title,
            citation_key: (string) ($citationKeyFromMetadata ?? $this->generateCitationKey($document)),
            evidence_grade: $document->evidence_grade,
            fetched_at: $document->ingested_at?->toDateTimeImmutable() ?? now()->toDateTimeImmutable(),
            chunk_id: $chunk->id,
            embedding: $embedding,
            metadata: is_array($chunk->metadata) ? $chunk->metadata : null,
        );
    }

    /**
     * Generate a citation key from document metadata.
     *
     * @param \App\Models\KnowledgeDocument $document
     * @return string
     */
    private function generateCitationKey(\App\Models\KnowledgeDocument $document): string
    {
        // Format: "source_name (evidence_grade, YYYY)"
        $year = $document->ingested_at?->year ?? date('Y');
        return "{$document->title} ({$document->evidence_grade}, {$year})";
    }

    /**
     * Convert embedding array to pgvector string format.
     * pgvector expects format: "[1.0, 2.0, 3.0, ...]"
     *
     * @param array $embedding
     * @return string
     */
    private function embeddingToString(array $embedding): string
    {
        return '[' . implode(',', $embedding) . ']';
    }
}
