<?php

namespace App\Eval\Assertion;

final class AssertionResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly ?string $reason = null,
    ) {}

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
