<?php

namespace App\Eval\Assertion;

final class CitationCountAtLeast implements Assertion
{
    public function __construct(private readonly int $min) {}

    public static function type(): string { return 'citation_count_at_least'; }

    public function evaluate(string $response, array $context): AssertionResult
    {
        preg_match_all('/\[\d+\]|\(PMID:\d+\)|\(DOI:[^\)]+\)/i', $response, $m);
        $count = count($m[0]);
        return $count >= $this->min
            ? AssertionResult::pass()
            : AssertionResult::fail("expected ≥{$this->min} citations, found {$count}");
    }
}
