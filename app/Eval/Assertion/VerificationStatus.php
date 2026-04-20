<?php

namespace App\Eval\Assertion;

final class VerificationStatus implements Assertion
{
    public function __construct(private readonly string $expected) {}

    public static function type(): string { return 'verification_status'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        $actual = $context['verification_status'] ?? null;
        return $actual === $this->expected
            ? AssertionResult::pass()
            : AssertionResult::fail("verification_status: expected {$this->expected}, got " . ($actual ?? 'null'));
    }
}
