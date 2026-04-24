<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Services\Llm\LlmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const EMBEDDING_DIMENSION = 3072;
    private const CACHE_TTL_SECONDS = 86400 * 30; // 30 days
    // OpenAI accepts up to 2048 inputs per call but individual requests
    // get slow past ~100 — chunking here keeps wall-clock latency bounded
    // during large syncs without bloating individual requests.
    private const BATCH_CHUNK_SIZE = 100;

    public function __construct(
        private readonly LlmClient $llmClient,
    ) {}

    /**
     * Generate embedding for text via OpenAI API.
     * Caches embeddings by text hash to avoid redundant API calls.
     * Returns zero vector on failure (graceful degradation).
     *
     * @param string $text Text to embed
     * @return array Float array of 3072 dimensions
     */
    public function embed(string $text): array
    {
        if (empty(trim($text))) {
            return $this->zeroVector();
        }

        $cacheKey = $this->getCacheKey($text);

        // Try cache first
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $embedding = $this->callOpenAiEmbedding($text);

            // Cache the embedding
            Cache::put($cacheKey, $embedding, self::CACHE_TTL_SECONDS);

            return $embedding;
        } catch (\Throwable $e) {
            Log::warning('EmbeddingService: Failed to generate embedding', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);

            // Return zero vector on failure (graceful degradation)
            return $this->zeroVector();
        }
    }

    /**
     * Embed an array of texts in one or more batched API calls. Preserves
     * the input order; empty strings map to zero vectors without hitting
     * the API. Cache is consulted per-text so re-syncs that haven't
     * changed content reuse embeddings for free.
     *
     * On a batch-level API failure we fall back to a parallel zero-vector
     * array for that batch — the caller can still persist chunks, and a
     * future re-sync will backfill embeddings once the upstream recovers.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>> Parallel array of embeddings.
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $results = array_fill(0, count($texts), null);
        $toFetch = []; // index => text

        foreach ($texts as $i => $text) {
            $trimmed = trim((string) $text);
            if ($trimmed === '') {
                $results[$i] = $this->zeroVector();
                continue;
            }
            $cached = Cache::get($this->getCacheKey($trimmed));
            if (is_array($cached)) {
                $results[$i] = $cached;
                continue;
            }
            $toFetch[$i] = $trimmed;
        }

        foreach (array_chunk($toFetch, self::BATCH_CHUNK_SIZE, preserve_keys: true) as $chunk) {
            $indices = array_keys($chunk);
            $inputs  = array_values($chunk);
            try {
                $embeddings = $this->callOpenAiEmbeddingBatch($inputs);
            } catch (\Throwable $e) {
                Log::warning('EmbeddingService: Batch embedding failed — using zero vectors', [
                    'error'       => $e->getMessage(),
                    'chunk_size'  => count($inputs),
                ]);
                foreach ($indices as $idx) {
                    $results[$idx] = $this->zeroVector();
                }
                continue;
            }
            foreach ($indices as $offset => $idx) {
                $embedding      = $embeddings[$offset] ?? $this->zeroVector();
                $results[$idx]  = $embedding;
                Cache::put(
                    $this->getCacheKey($inputs[$offset]),
                    $embedding,
                    self::CACHE_TTL_SECONDS,
                );
            }
        }

        // Anything still null means empty or un-fetchable — coerce to zero.
        return array_map(fn ($v) => is_array($v) ? $v : $this->zeroVector(), $results);
    }

    /**
     * Call OpenAI embeddings API directly via HTTP.
     *
     * @param string $text
     * @return array Float array of embedding
     * @throws \RuntimeException
     */
    private function callOpenAiEmbedding(string $text): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.openai.timeout', 45);

        if (empty($apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/embeddings", [
                'model' => self::EMBEDDING_MODEL,
                'input' => $text,
                'encoding_format' => 'float',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "OpenAI embedding failed (HTTP {$response->status()}): " .
                ($response->body() ?? 'No response body')
            );
        }

        $json = $response->json() ?? [];
        $data = $json['data'][0] ?? [];
        $embedding = $data['embedding'] ?? [];

        if (!is_array($embedding) || count($embedding) !== self::EMBEDDING_DIMENSION) {
            throw new \RuntimeException(
                "Invalid embedding response: expected {$this->getDimension()} dimensions, got " .
                count($embedding)
            );
        }

        return $embedding;
    }

    /**
     * Batch variant of callOpenAiEmbedding — the API accepts an array of
     * inputs in a single request and returns a matching array, ordered.
     *
     * @param array<int, string> $texts
     * @return array<int, array<int, float>>
     */
    private function callOpenAiEmbeddingBatch(array $texts): array
    {
        $apiKey  = (string) config('services.openai.api_key', '');
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
        $timeout = (int) config('services.openai.timeout', 45);

        if (empty($apiKey)) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
            ->timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$baseUrl}/embeddings", [
                'model' => self::EMBEDDING_MODEL,
                'input' => array_values($texts),
                'encoding_format' => 'float',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "OpenAI batch embedding failed (HTTP {$response->status()}): " .
                ($response->body() ?? 'No response body')
            );
        }

        $json = $response->json() ?? [];
        $data = $json['data'] ?? [];

        // OpenAI guarantees data is index-aligned with input, but defend
        // against order drift by sorting by the `index` field.
        usort($data, fn ($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        $out = [];
        foreach ($data as $row) {
            $emb = $row['embedding'] ?? [];
            if (!is_array($emb) || count($emb) !== self::EMBEDDING_DIMENSION) {
                throw new \RuntimeException(
                    'Invalid batch embedding response: unexpected dimensionality'
                );
            }
            $out[] = $emb;
        }

        if (count($out) !== count($texts)) {
            throw new \RuntimeException(
                'Batch embedding returned ' . count($out) . ' rows for ' . count($texts) . ' inputs'
            );
        }

        return $out;
    }

    /**
     * Generate cache key from text hash.
     */
    private function getCacheKey(string $text): string
    {
        $hash = hash('sha256', $text);
        return "embedding:openai:{$hash}";
    }

    /**
     * Return a zero vector of the expected dimension.
     *
     * @return array Float array of zeros
     */
    private function zeroVector(): array
    {
        return array_fill(0, self::EMBEDDING_DIMENSION, 0.0);
    }

    /**
     * Get the embedding dimension.
     */
    public function getDimension(): int
    {
        return self::EMBEDDING_DIMENSION;
    }
}
