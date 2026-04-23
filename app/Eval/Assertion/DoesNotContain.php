<?php

namespace App\Eval\Assertion;

final class DoesNotContain implements Assertion
{
    public function __construct(private readonly string $value) {}

    public static function type(): string { return 'does_not_contain'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        $haystack = TextNormalizer::normalize($response);
        $needle   = TextNormalizer::normalize($this->value);

        if ($needle === '' || stripos($haystack, $needle) === false) {
            return AssertionResult::pass();
        }
        return AssertionResult::fail("expected response NOT to contain \"{$this->value}\"");
    }
}
