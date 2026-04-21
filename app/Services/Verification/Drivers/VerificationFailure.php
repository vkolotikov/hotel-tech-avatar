<?php

namespace App\Services\Verification\Drivers;

final class VerificationFailure
{
    public function __construct(
        public readonly VerificationFailureType $type,
        public readonly string $claim_text,
        public readonly string $reason,
    ) {}
}
