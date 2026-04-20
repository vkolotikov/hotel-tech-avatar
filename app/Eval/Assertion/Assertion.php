<?php

namespace App\Eval\Assertion;

interface Assertion
{
    public static function type(): string;

    public function evaluate(string $response, array $context): AssertionResult;
}
