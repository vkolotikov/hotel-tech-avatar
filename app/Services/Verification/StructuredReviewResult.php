<?php

declare(strict_types=1);

namespace App\Services\Verification;

/**
 * DTO representing the result of a structured review.
 */
final class StructuredReviewResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly array $issues,
        public readonly ?string $revision_suggestion,
    ) {}
}
