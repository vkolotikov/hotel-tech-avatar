<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;

interface CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult;
}
