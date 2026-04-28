<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Services\Verification\Contracts\SafetyClassifierInterface;
use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;

final class SafetyClassifier implements SafetyClassifierInterface
{
    /**
     * Hard patterns — the AVATAR is making a forbidden claim about the
     * user (diagnosis, prescription, dose). These are regex patterns
     * because plain substring matches were over-firing on educational
     * and referral usage:
     *
     *   - "people diagnosed with prediabetes often..." (educational)
     *   - "talk to your prescriber about dosage"        (referral)
     *   - "if you experience chest pain, call 911"      (correct red-flag handling)
     *
     * Each previously fired a hard fail and triggered the localized
     * fallback, which is worse for both safety and UX. The patterns
     * below now require the avatar to be making a *first-person claim
     * about the user* — preserving the original "no diagnosis / no
     * prescription / no dosing" intent without shooting down legitimate
     * referrals.
     *
     * @var array<string,string> Map of human-readable rule name → PCRE pattern.
     */
    private array $hard_patterns = [
        // "you have been diagnosed with X" / "you're diagnosed with X" /
        // "I diagnose you with X" — assertions that target the user.
        'diagnosed-with-user' =>
            '/(?:\byou(?:\b|\'re|\s+(?:are|have|\'ve|been))[^.!?]{0,60}\bdiagnosed\s+with\b'
            . '|\bI(?:\s+(?:can|now|hereby))?\s+diagnose\s+you\b)/i',

        // First-person prescription verbs targeting the user. Allows
        // "talk to your prescriber" and "the prescribing clinician" to
        // pass cleanly.
        'prescription-by-avatar' =>
            '/(?:\bI(?:\s+(?:can|will|would|hereby|now|am|\'ll))?\s+prescribe\b'
            . '|\bI(?:\'m| am)\s+prescribing\b'
            . '|\byou\s+should\s+(?:take|start)\s+\d+\s*(?:mg|mcg|g|iu|µg)\b)/i',

        // Direct dosing instructions — "take 500mg twice daily",
        // "your dosage should be 1000mg". The bare word "dosage" used
        // contextually ("dosage is the prescriber's call") no longer
        // fires.
        'dosing-instruction' =>
            '/\b(?:take|start(?:ing)?(?:\s+at)?|titrate(?:\s+up)?(?:\s+to)?|increase\s+to|your\s+(?:dose|dosage)\s+(?:should|will|is)\b[^.!?]{0,30}(?:be\s+)?)\s*\d+\s*(?:mg|mcg|g|iu|µg)\b/i',
    ];

    // Why no "user-is-suicidal" hard pattern: the agents.red_flag_rules_json
    // mechanism fires deterministic canned responses when the USER
    // mentions crisis terms, so the input side is already covered.
    // Output-side detection of "you are suicidal" claims is fraught
    // (lookbehinds for "if/when/people who" prefixes get messy and
    // false-positive on every safe referral), and an avatar
    // authoritatively declaring crisis without conditionals is a
    // pattern we have not seen in eval. If it does emerge, the
    // structured-review stage of VerificationService catches free-form
    // tone problems through an LLM critic, and we add a targeted rule
    // here.

    /**
     * Soft patterns — clinical language, treatment claims, referral
     * phrasing. These trigger a SOFT flag for review/revision but never
     * the hard fallback path. Substring match is still appropriate here
     * because the goal is "this language could be softened", not "this
     * is a hard violation".
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

        foreach ($this->hard_patterns as $name => $regex) {
            if (preg_match($regex, $response_text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $flags[] = new SafetyFlag(
                    severity: SafetyFlagSeverity::HARD,
                    matched_pattern: $name,
                    suggested_action: 'Requires immediate review and revision',
                    matched_text: $this->extract_match_offset($response_text, (int) $m[0][1], strlen((string) $m[0][0])),
                );
            }
        }

        foreach ($this->soft_patterns as $pattern) {
            if (stripos($response_text, $pattern) !== false) {
                $flags[] = new SafetyFlag(
                    severity: SafetyFlagSeverity::SOFT,
                    matched_pattern: $pattern,
                    suggested_action: 'Consider softening language or adding disclaimer',
                    matched_text: $this->extract_match_substring($response_text, $pattern),
                );
            }
        }

        return $flags;
    }

    /**
     * Extract context around a regex match (30 chars before and after).
     */
    private function extract_match_offset(string $text, int $position, int $matchLen): string
    {
        $start = max(0, $position - 30);
        $end = min(strlen($text), $position + $matchLen + 30);
        return substr($text, $start, $end - $start);
    }

    /**
     * Extract context around a substring match (used by soft patterns).
     */
    private function extract_match_substring(string $text, string $pattern): string
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
