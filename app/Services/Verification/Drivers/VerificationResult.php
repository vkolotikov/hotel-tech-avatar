<?php

namespace App\Services\Verification\Drivers;

final class VerificationResult
{
    public function __construct(
        public readonly bool $is_verified,
        public readonly array $failures,
        public readonly array $safety_flags,
        public readonly int $revision_count,
        public readonly int $latency_ms,
    ) {}
}
