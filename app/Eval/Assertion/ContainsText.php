<?php

namespace App\Eval\Assertion;

final class ContainsText implements Assertion
{
    public function __construct(private readonly string $value) {}

    public static function type(): string { return 'contains_text'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        $haystack = TextNormalizer::normalize($response);
        $needle   = TextNormalizer::normalize($this->value);

        if ($needle !== '' && stripos($haystack, $needle) !== false) {
            return AssertionResult::pass();
        }
        return AssertionResult::fail("expected response to contain \"{$this->value}\"");
    }
}
