<?php

namespace App\Services\Verification\Drivers;

final class VerificationResult
{
    /**
     * @param array<int, VerificationFailure> $failures
     * @param array<int, SafetyFlag> $safety_flags
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $chunks,
        public readonly int $latency_ms,
        public readonly bool $is_high_risk,
        public readonly int $chunk_count,
        public readonly array $failures,
        public readonly array $safety_flags,
        public readonly int $revision_count,
        public readonly ?string $revision_suggestion = null,
        // Backwards-compatibility alias
        public readonly ?bool $is_verified = null,
    ) {}
}
