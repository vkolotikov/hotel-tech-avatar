<?php

namespace App\Eval\Assertion;

final class MatchesRegex implements Assertion
{
    public function __construct(private readonly string $pattern) {}

    public static function type(): string { return 'matches_regex'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        set_error_handler(static fn () => true);
        try {
            $result = preg_match($this->pattern, $response);
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw new \InvalidArgumentException("invalid regex: {$this->pattern}");
        }
        return $result === 1
            ? AssertionResult::pass()
            : AssertionResult::fail("response did not match {$this->pattern}");
    }
}
