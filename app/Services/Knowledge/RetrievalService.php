<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $threshold = (float) config('retrieval.vector_similarity_threshold', 0.7);

        // Vector search using pgvector cosine similarity
        // SELECT ... ORDER BY embedding <=> $1 LIMIT n
        $chunks = KnowledgeChunk::query()
            ->where('agent_id', $agent->id)
            ->whereNotNull('embedding')
            ->select('knowledge_chunks.*')
            ->selectRaw(
                '(embedding <=> ?)::float as similarity',
                [$this->embeddingToString($promptEmbedding)]
            )
            ->having('similarity', '>=', -1) // All similarities are valid, but we'll filter below
            ->orderByRaw('embedding <=> ?', [$this->embeddingToString($promptEmbedding)])
            ->limit(10)
            ->get();

        // Filter by threshold
        $result = [];
        foreach ($chunks as $chunk) {
            // similarity from <=> is in range [-1, 1], where 1 = most similar
            // We need cosine_similarity = 1 - distance, so filter by threshold
            if ($chunk->similarity >= $threshold) {
                $result[] = $this->chunkToRetrievedChunk($chunk);
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
     * @param KnowledgeChunk $chunk
     * @return \App\Services\Knowledge\Drivers\RetrievedChunk
     */
    private function chunkToRetrievedChunk(KnowledgeChunk $chunk): \App\Services\Knowledge\Drivers\RetrievedChunk
    {
        $document = $chunk->document;

        return new \App\Services\Knowledge\Drivers\RetrievedChunk(
            content: $chunk->content,
            source_url: $document->source_url,
            source_name: $document->title,
            citation_key: $this->generateCitationKey($document),
            evidence_grade: $document->evidence_grade,
            fetched_at: $document->ingested_at ?? now(),
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
