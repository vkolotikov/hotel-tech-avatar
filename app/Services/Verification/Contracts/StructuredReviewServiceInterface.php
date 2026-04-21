<?php

declare(strict_types=1);

namespace App\Services\Verification\Contracts;

use App\Services\Knowledge\RetrievedContext;
use App\Services\Verification\StructuredReviewResult;

interface StructuredReviewServiceInterface
{
    public function review(
        string $response_text,
        RetrievedContext $context,
        array $failures_so_far = [],
    ): StructuredReviewResult;
}
