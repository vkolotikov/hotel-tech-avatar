<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verification;

use App\Services\Verification\SafetyClassifier;
use App\Services\Verification\Drivers\SafetyFlag;
use App\Services\Verification\Drivers\SafetyFlagSeverity;
use Tests\TestCase;

class SafetyClassifierTest extends TestCase
{
    private SafetyClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new SafetyClassifier();
    }

    public function test_hard_pattern_prescribe_detected(): void
    {
        $response = 'We recommend you prescribe this medication to treat your condition.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $this->assertInstanceOf(SafetyFlag::class, $flags[0]);
        $this->assertSame(SafetyFlagSeverity::HARD, $flags[0]->severity);
        $this->assertSame('prescribe', $flags[0]->matched_pattern);
        $this->assertStringContainsString('prescribe', $flags[0]->matched_text);
    }

    public function test_soft_pattern_clinical_detected(): void
    {
        $response = 'This clinical information is provided for educational purposes.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $this->assertInstanceOf(SafetyFlag::class, $flags[0]);
        $this->assertSame(SafetyFlagSeverity::SOFT, $flags[0]->severity);
        $this->assertSame('clinical', $flags[0]->matched_pattern);
        $this->assertStringContainsString('clinical', $flags[0]->matched_text);
    }

    public function test_no_patterns_returns_empty_array(): void
    {
        $response = 'This is a safe response about wellness and healthy habits.';

        $flags = $this->classifier->classify($response);

        $this->assertIsArray($flags);
        $this->assertCount(0, $flags);
    }

    public function test_multiple_patterns_detected(): void
    {
        $response = 'We prescribe this clinical treatment for your diagnosed with condition.';

        $flags = $this->classifier->classify($response);

        $this->assertGreaterThanOrEqual(3, count($flags));

        $patterns = array_map(fn (SafetyFlag $flag) => $flag->matched_pattern, $flags);
        $this->assertContains('prescribe', $patterns);
        $this->assertContains('clinical', $patterns);
        $this->assertContains('diagnosed with', $patterns);
    }

    public function test_case_insensitive_matching(): void
    {
        $response = 'PRESCRIBE this medication immediately.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $this->assertSame('prescribe', $flags[0]->matched_pattern);
    }

    public function test_hard_pattern_chest_pain_detected(): void
    {
        $response = 'If you experience chest pain, seek emergency care immediately.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $this->assertSame(SafetyFlagSeverity::HARD, $flags[0]->severity);
        $this->assertSame('chest pain', $flags[0]->matched_pattern);
    }

    public function test_soft_pattern_consult_doctor_detected(): void
    {
        $response = 'Please consult your doctor before starting any supplement.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $this->assertSame(SafetyFlagSeverity::SOFT, $flags[0]->severity);
        $this->assertSame('consult your doctor', $flags[0]->matched_pattern);
    }

    public function test_extract_match_includes_context(): void
    {
        $response = 'The quick brown fox jumps over the lazy prescribe dog in the field.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $matched = $flags[0]->matched_text;

        // Should include context around "prescribe"
        $this->assertStringContainsString('prescribe', $matched);
        $this->assertTrue(strlen($matched) > strlen('prescribe'));
    }

    public function test_safety_flag_severity_enum_values(): void
    {
        $response = 'You are experiencing suicidal thoughts.';

        $flags = $this->classifier->classify($response);

        $this->assertCount(1, $flags);
        $flag = $flags[0];

        // Verify enum value is correct
        $this->assertSame('hard', $flag->severity->value);
        $this->assertSame(SafetyFlagSeverity::HARD, $flag->severity);
    }

    public function test_multiple_hard_patterns_in_response(): void
    {
        $response = 'You were diagnosed with a condition. We prescribe treatment for shortness of breath.';

        $flags = $this->classifier->classify($response);

        // Should detect 'diagnosed with', 'prescribe', and 'shortness of breath'
        $this->assertGreaterThanOrEqual(3, count($flags));

        $hard_flags = array_filter(
            $flags,
            fn (SafetyFlag $flag) => $flag->severity === SafetyFlagSeverity::HARD
        );
        $this->assertGreaterThanOrEqual(3, count($hard_flags));
    }
}
