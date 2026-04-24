<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers;

final class RetrievedChunk
{
    /**
     * @param string                     $content
     * @param string                     $source_url
     * @param string                     $source_name
     * @param string                     $citation_key
     * @param string                     $evidence_grade
     * @param \DateTimeImmutable         $fetched_at
     * @param int|null                   $chunk_id  DB id of the source KnowledgeChunk when the chunk came from retrieval cache. Null for fresh driver fetches that haven't been persisted yet.
     * @param array<int, float>|null     $embedding Unparsed float vector. Populated for cached chunks so grounding can compute cosine similarity without re-embedding the content.
     * @param array<string, mixed>|null  $metadata  Passthrough of KnowledgeChunk.metadata (citation_key, fetched_at, etc) when available.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $source_url,
        public readonly string $source_name,
        public readonly string $citation_key,
        public readonly string $evidence_grade,
        public readonly \DateTimeImmutable $fetched_at,
        public readonly ?int $chunk_id = null,
        public readonly ?array $embedding = null,
        public readonly ?array $metadata = null,
    ) {}
}
