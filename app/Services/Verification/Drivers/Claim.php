<?php

namespace App\Services\Verification\Drivers;

final class Claim
{
    public function __construct(
        public readonly string $text,
        public readonly bool $requires_citation,
        public readonly string $inferred_source_category,
        public readonly ?GroundingResult $grounding = null,
        public readonly ?CitationValidationResult $citation = null,
    ) {}
}
