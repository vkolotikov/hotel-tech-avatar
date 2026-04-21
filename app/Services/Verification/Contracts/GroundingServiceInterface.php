<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Services\Knowledge\RetrievedContext;

interface GroundingServiceInterface
{
    /**
     * @param  array<\App\Services\Verification\Drivers\Claim>  $claims
     * @return array<\App\Services\Verification\Drivers\Claim>
     */
    public function ground_all_claims(array $claims, RetrievedContext $context): array;
}
