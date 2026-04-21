<?php

namespace App\Services\Verification\Drivers;

use App\Models\KnowledgeChunk;

final class GroundingResult
{
    public function __construct(
        public readonly bool $is_grounded,
        public readonly ?KnowledgeChunk $matched_chunk = null,
        public readonly float $similarity_score = 0.0,
        public readonly string $supporting_evidence = '',
    ) {}
}
