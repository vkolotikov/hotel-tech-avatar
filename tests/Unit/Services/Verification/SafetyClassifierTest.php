<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verification;

use App\Services\Verification\SafetyClassifier;
use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;
use Tests\TestCase;

/**
 * The classifier semantics are deliberately narrow: it should only
 * HARD-flag the avatar making a *first-person claim about the user*
 * (diagnosing them, prescribing for them, telling them to take a
 * specific dose, declaring them in crisis). Educational mentions and
 * referral language must pass cleanly — they are the desired safe
 * behavior, and false-positive flagging there triggers the localized
 * fallback message which is worse for both safety and UX.
 */
class SafetyClassifierTest extends TestCase
{
    private SafetyClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new SafetyClassifier();
    }

    private function hardFlags(string $text): array
    {
        return array_values(array_filter(
            $this->classifier->classify($text),
            fn (SafetyFlag $f) => $f->severity === SafetyFlagSeverity::HARD,
        ));
    }

    // ─── HARD: legitimate claims about the user ───────────────────────

    public function test_avatar_diagnosing_user_is_hard_flag(): void
    {
        $flags = $this->hardFlags('Based on your symptoms, you have been diagnosed with prediabetes.');
        $this->assertCount(1, $flags);
        $this->assertSame('diagnosed-with-user', $flags[0]->matched_pattern);
    }

    public function test_avatar_prescribing_to_user_is_hard_flag(): void
    {
        $flags = $this->hardFlags('I will prescribe metformin starting tomorrow.');
        $this->assertCount(1, $flags);
        $this->assertSame('prescription-by-avatar', $flags[0]->matched_pattern);
    }

    public function test_explicit_dose_instruction_is_hard_flag(): void
    {
        $flags = $this->hardFlags('Take 500mg of metformin twice daily for 8 weeks.');
        $this->assertCount(1, $flags);
        $this->assertSame('dosing-instruction', $flags[0]->matched_pattern);
    }

    // ─── NOT HARD: educational and referral mentions ──────────────────
    // These are the false positives the previous classifier produced
    // and that triggered the localized fallback in production.

    public function test_educational_mention_of_diagnosis_passes(): void
    {
        $flags = $this->hardFlags('People diagnosed with prediabetes often experience afternoon energy dips.');
        $this->assertCount(0, $flags, 'educational "people diagnosed with X" must not hard-fail');
    }

    public function test_referral_to_prescriber_passes(): void
    {
        $flags = $this->hardFlags('Talk to your prescriber about dosage — they have the full picture.');
        $this->assertCount(0, $flags, 'referring to the prescriber must not hard-fail');
    }

    public function test_referral_for_chest_pain_passes(): void
    {
        // Avatars are SUPPOSED to refer out for chest pain. Previously
        // the classifier flagged any mention of "chest pain" — so
        // doing the right thing got the reply replaced with the
        // generic fallback. The new classifier lets this through.
        $flags = $this->hardFlags('If you experience chest pain or shortness of breath, please call 911 or go to the nearest ED.');
        $this->assertCount(0, $flags, 'red-flag referral language must not hard-fail');
    }

    public function test_safe_crisis_referral_passes(): void
    {
        $flags = $this->hardFlags('If you are feeling suicidal, please call 988 — they are available 24/7.');
        $this->assertCount(0, $flags, 'crisis referral language must not hard-fail');
    }

    public function test_general_dose_discussion_passes(): void
    {
        $flags = $this->hardFlags('Dosage is the prescriber\'s call — I can talk about what supplements generally do.');
        $this->assertCount(0, $flags, 'discussing dosage abstractly must not hard-fail');
    }

    public function test_clean_response_returns_empty_array(): void
    {
        $flags = $this->classifier->classify('A great 15-minute high-protein lunch could be Greek yogurt with berries and walnuts.');
        $this->assertCount(0, $flags);
    }

    // ─── SOFT patterns still flag (review, not replace) ───────────────

    public function test_soft_pattern_clinical_detected(): void
    {
        $response = 'This clinical information is provided for educational purposes.';
        $flags = $this->classifier->classify($response);

        $softFlags = array_values(array_filter($flags, fn ($f) => $f->severity === SafetyFlagSeverity::SOFT));
        $this->assertCount(1, $softFlags);
        $this->assertSame('clinical', $softFlags[0]->matched_pattern);
    }

    public function test_soft_pattern_consult_doctor_detected(): void
    {
        $response = 'Please consult your doctor before starting any supplement.';
        $flags = $this->classifier->classify($response);
        $softFlags = array_values(array_filter($flags, fn ($f) => $f->severity === SafetyFlagSeverity::SOFT));
        $this->assertCount(1, $softFlags);
        $this->assertSame('consult your doctor', $softFlags[0]->matched_pattern);
    }

    // ─── Plumbing: severity enum, context extraction ──────────────────

    public function test_safety_flag_severity_enum_values(): void
    {
        $flags = $this->hardFlags('I will prescribe sertraline and you should follow up next week.');
        $this->assertCount(1, $flags);
        $this->assertSame('hard', $flags[0]->severity->value);
        $this->assertSame(SafetyFlagSeverity::HARD, $flags[0]->severity);
    }

    public function test_multiple_hard_patterns_can_fire_together(): void
    {
        $flags = $this->hardFlags(
            'You have been diagnosed with anxiety, and I will prescribe sertraline. Take 50mg daily.'
        );
        $patterns = array_map(fn ($f) => $f->matched_pattern, $flags);
        $this->assertContains('diagnosed-with-user', $patterns);
        $this->assertContains('prescription-by-avatar', $patterns);
        $this->assertContains('dosing-instruction', $patterns);
    }

    public function test_full_integra_reply_with_red_flag_referral_passes(): void
    {
        // Realistic Integra response to "I have low energy" — mentions
        // labs, conditions, and red-flag symptoms in referral context.
        // Under the previous classifier this whole thing got replaced
        // with the localized fallback. Under the new one it passes.
        $reply = "I'd start by mapping the pattern: timing, sleep, caffeine, "
               . "and basic labs. Worth discussing with your clinician: CBC, "
               . "ferritin, TSH, B12, and fasting glucose — anemia, thyroid "
               . "dysfunction, and prediabetes are common drivers. If you "
               . "experience chest pain, shortness of breath, or unexplained "
               . "weight loss alongside the fatigue, please see your doctor "
               . "promptly rather than waiting.";
        $flags = $this->hardFlags($reply);
        $this->assertCount(0, $flags, 'realistic Integra referral text must not hard-fail');
    }

    public function test_extract_match_includes_context(): void
    {
        $flags = $this->hardFlags('Based on your symptoms you have been diagnosed with iron deficiency anemia.');
        $this->assertCount(1, $flags);
        $matched = $flags[0]->matched_text;
        // Should include text around the matched assertion.
        $this->assertStringContainsString('diagnosed', $matched);
        $this->assertTrue(strlen($matched) > strlen('diagnosed with'));
    }

    public function test_case_insensitive_matching(): void
    {
        $flags = $this->hardFlags('I PRESCRIBE this medication.');
        $this->assertCount(1, $flags);
        $this->assertSame('prescription-by-avatar', $flags[0]->matched_pattern);
    }
}
