<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Services\Knowledge\Drivers\RetrievedChunk;

/**
 * DTO representing the context retrieved for a prompt.
 * Includes cached vector search results and metadata about the retrieval.
 */
final class RetrievedContext
{
    /**
     * @param RetrievedChunk[] $chunks
     * @param int $latency_ms Time taken to retrieve context in milliseconds
     * @param bool $is_high_risk Whether the query contains high-risk keywords
     * @param int $chunk_count Number of chunks retrieved
     */
    public function __construct(
        public readonly array $chunks,
        public readonly int $latency_ms,
        public readonly bool $is_high_risk,
        public readonly int $chunk_count,
    ) {}
}
