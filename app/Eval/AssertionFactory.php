<?php

namespace App\Eval;

use App\Eval\Assertion\Assertion;
use App\Eval\Assertion\CitationCountAtLeast;
use App\Eval\Assertion\ContainsText;
use App\Eval\Assertion\DoesNotContain;
use App\Eval\Assertion\MatchesRegex;
use App\Eval\Assertion\RedFlagTriggered;
use App\Eval\Assertion\VerificationStatus;

final class AssertionFactory
{
    private const TYPES = [
        'contains_text' => ContainsText::class,
        'does_not_contain' => DoesNotContain::class,
        'matches_regex' => MatchesRegex::class,
        'citation_count_at_least' => CitationCountAtLeast::class,
        'red_flag_triggered' => RedFlagTriggered::class,
        'verification_status' => VerificationStatus::class,
    ];

    public static function make(array $config): Assertion
    {
        $type = $config['type'] ?? null;
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("unknown assertion type: " . ($type ?? 'null'));
        }
        $class = self::TYPES[$type];

        return match ($type) {
            'contains_text', 'does_not_contain' => new $class($config['value'] ?? ''),
            'matches_regex' => new $class($config['pattern'] ?? ''),
            'citation_count_at_least' => new $class((int) ($config['min'] ?? 1)),
            'red_flag_triggered' => new $class((bool) ($config['value'] ?? true)),
            'verification_status' => new $class($config['value'] ?? ''),
        };
    }
}
