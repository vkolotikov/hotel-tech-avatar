<?php

namespace App\Services\Verification\Drivers;

final class CitationValidationResult
{
    public function __construct(
        public readonly bool $is_valid,
        public readonly string $validation_detail,
        public readonly ?string $resolved_source_url = null,
        public readonly ?string $source_type = null,
    ) {}
}
