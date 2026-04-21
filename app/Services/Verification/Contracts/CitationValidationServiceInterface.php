<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

interface CitationValidationServiceInterface
{
    /**
     * @param  array<\App\Services\Verification\Drivers\Claim>  $claims
     * @return array<\App\Services\Verification\Drivers\Claim>
     */
    public function validate_all_citations(array $claims): array;
}
