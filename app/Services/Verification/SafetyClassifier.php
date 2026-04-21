<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;

final class SafetyClassifier
{
    /**
     * Hard patterns - immediate safety concern, severe symptoms, crisis indicators.
     *
     * @var array<string>
     */
    private array $hard_patterns = [
        'diagnosed with',
        'prescribe',
        'dosage',
        'chest pain',
        'shortness of breath',
        'suicidal',
        'self-harm',
        'severe allergic',
        'anaphylaxis',
    ];

    /**
     * Soft patterns - clinical language, treatment advice, referral.
     *
     * @var array<string>
     */
    private array $soft_patterns = [
        'medical advice',
        'clinical',
        'treatment',
        'consult your doctor',
    ];

    /**
     * Classify response text for safety rule violations.
     *
     * @return array<SafetyFlag>
     */
    public function classify(string $response_text): array
    {
        $flags = [];

        // Check hard patterns
        foreach ($this->hard_patterns as $pattern) {
            if ($this->patternMatches($response_text, $pattern)) {
                $matched_text = $this->extract_match($response_text, $pattern);
                $flags[] = new SafetyFlag(
                    severity: SafetyFlagSeverity::HARD,
                    matched_pattern: $pattern,
                    suggested_action: 'Requires immediate review and revision',
                    matched_text: $matched_text,
                );
            }
        }

        // Check soft patterns
        foreach ($this->soft_patterns as $pattern) {
            if ($this->patternMatches($response_text, $pattern)) {
                $matched_text = $this->extract_match($response_text, $pattern);
                $flags[] = new SafetyFlag(
                    severity: SafetyFlagSeverity::SOFT,
                    matched_pattern: $pattern,
                    suggested_action: 'Consider softening language or adding disclaimer',
                    matched_text: $matched_text,
                );
            }
        }

        return $flags;
    }

    /**
     * Check if a pattern matches in the text (case-insensitive).
     */
    private function patternMatches(string $text, string $pattern): bool
    {
        return stripos($text, $pattern) !== false;
    }

    /**
     * Extract matched text with context (30 chars before and after).
     */
    private function extract_match(string $text, string $pattern): string
    {
        $position = stripos($text, $pattern);

        if ($position === false) {
            return $pattern;
        }

        $start = max(0, $position - 30);
        $end = min(strlen($text), $position + strlen($pattern) + 30);

        return substr($text, $start, $end - $start);
    }
}
