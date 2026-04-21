<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

interface ClaimExtractionServiceInterface
{
    /**
     * @return array<\App\Services\Verification\Drivers\Claim>
     */
    public function extract(string $responseText): array;
}
