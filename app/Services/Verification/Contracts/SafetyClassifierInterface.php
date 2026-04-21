<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

interface SafetyClassifierInterface
{
    /**
     * @return array<\App\Services\Verification\Drivers\SafetyFlag>
     */
    public function classify(string $response_text): array;
}
