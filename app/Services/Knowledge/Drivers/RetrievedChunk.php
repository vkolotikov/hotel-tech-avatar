<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers;

final class RetrievedChunk
{
    public function __construct(
        public readonly string $content,
        public readonly string $source_url,
        public readonly string $source_name,
        public readonly string $citation_key,
        public readonly string $evidence_grade,
        public readonly \DateTimeImmutable $fetched_at,
    ) {}
}
