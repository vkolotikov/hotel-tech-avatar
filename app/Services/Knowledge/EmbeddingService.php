<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Services\Llm\LlmClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class EmbeddingService
{
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const EMBEDDING_DIMENSION = 3072;
    private const CACHE_TTL_SECONDS = 86400 * 30; // 30 days

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
