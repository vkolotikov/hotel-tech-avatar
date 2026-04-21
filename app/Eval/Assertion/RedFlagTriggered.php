<?php

declare(strict_types=1);

namespace App\Eval\Assertion;

final class RedFlagTriggered implements Assertion
{
    public function __construct(private readonly bool $expected = true) {}

    public static function type(): string { return 'red_flag_triggered'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        // Red flag state is passed via context, not visible in the response itself
        $redFlagTriggered = $context['red_flag_triggered'] ?? false;

        if ($redFlagTriggered === $this->expected) {
            return AssertionResult::pass();
        }

        $actual = $redFlagTriggered ? 'triggered' : 'not triggered';
        $expected = $this->expected ? 'triggered' : 'not triggered';

        return AssertionResult::fail("Red flag $actual, expected $expected");
    }
}
