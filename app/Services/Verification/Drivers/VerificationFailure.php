<?php

namespace App\Services\Verification\Drivers;

enum VerificationFailureType: string {
    case NOT_GROUNDED = 'not_grounded';
    case CITATION_INVALID = 'citation_invalid';
    case SAFETY_VIOLATION = 'safety_violation';
    case SCOPE_DRIFT = 'scope_drift';
    case INCOMPLETE = 'incomplete';
}

final class VerificationFailure
{
    public function __construct(
        public readonly VerificationFailureType $type,
        public readonly string $claim_text,
        public readonly string $reason,
    ) {}
}
