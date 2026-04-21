<?php

namespace Tests\Unit\Services\Verification;

use App\Services\Verification\Drivers\{
    GroundingResult,
    CitationValidationResult,
    SafetyFlag,
    SafetyFlagSeverity,
    VerificationFailure,
    VerificationFailureType,
    VerificationResult
};
use PHPUnit\Framework\TestCase;

class DTOsTest extends TestCase
{
    public function test_grounding_result_can_be_instantiated()
    {
        $result = new GroundingResult(
            is_grounded: true,
            similarity_score: 0.78,
            supporting_evidence: 'Some evidence text'
        );

        $this->assertTrue($result->is_grounded);
        $this->assertEquals(0.78, $result->similarity_score);
    }

    public function test_citation_validation_result_can_be_instantiated()
    {
        $result = new CitationValidationResult(
            is_valid: true,
            validation_detail: 'PMID:12345 resolved',
            source_type: 'pubmed'
        );

        $this->assertTrue($result->is_valid);
        $this->assertEquals('pubmed', $result->source_type);
    }

    public function test_safety_flag_can_be_instantiated()
    {
        $flag = new SafetyFlag(
            severity: SafetyFlagSeverity::HARD,
            matched_pattern: 'prescribe',
            suggested_action: 'Use professional-referral response',
            matched_text: 'I prescribe magnesium'
        );

        $this->assertEquals(SafetyFlagSeverity::HARD, $flag->severity);
    }

    public function test_verification_failure_can_be_instantiated()
    {
        $failure = new VerificationFailure(
            type: VerificationFailureType::NOT_GROUNDED,
            claim_text: 'Magnesium improves sleep',
            reason: 'No matching chunk in retrieved context'
        );

        $this->assertEquals(VerificationFailureType::NOT_GROUNDED, $failure->type);
    }

    public function test_verification_result_can_be_instantiated()
    {
        $result = new VerificationResult(
            is_verified: true,
            failures: [],
            safety_flags: [],
            revision_count: 0,
            latency_ms: 1200
        );

        $this->assertTrue($result->is_verified);
        $this->assertEquals(1200, $result->latency_ms);
    }
}
