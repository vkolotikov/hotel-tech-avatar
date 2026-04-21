<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Models\Agent;
use App\Services\Knowledge\RetrievedContext;
use App\Services\Verification\Drivers\VerificationResult;

interface VerificationServiceInterface
{
    public function verify(
        string $response_text,
        RetrievedContext $context,
        Agent $agent,
    ): VerificationResult;
}
