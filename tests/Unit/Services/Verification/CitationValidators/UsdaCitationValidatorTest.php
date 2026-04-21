<?php

namespace Tests\Unit\Services\Verification\CitationValidators;

use App\Services\Verification\CitationValidators\UsdaCitationValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsdaCitationValidatorTest extends TestCase
{
    public function test_validate_returns_valid_result_for_existing_fdc_id()
    {
        Http::fake([
            'https://fdc.nal.usda.gov/api/food/123456*' => Http::response(['id' => 123456], 200),
        ]);

        $validator = new UsdaCitationValidator();
        $result = $validator->validate('USDA FDC ID: 123456');

        $this->assertTrue($result->is_valid);
        $this->assertStringContainsString('found', $result->validation_detail);
        $this->assertEquals('usda', $result->source_type);
        $this->assertNull($result->resolved_source_url);
    }

    public function test_validate_returns_invalid_result_for_nonexistent_fdc_id()
    {
        Http::fake([
            'https://fdc.nal.usda.gov/api/food/999999*' => Http::response(null, 404),
        ]);

        $validator = new UsdaCitationValidator();
        $result = $validator->validate('USDA FDC ID: 999999');

        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('not found', $result->validation_detail);
        $this->assertEquals('usda', $result->source_type);
        $this->assertNull($result->resolved_source_url);
    }

    public function test_validate_returns_invalid_result_for_malformed_citation()
    {
        $validator = new UsdaCitationValidator();
        $result = $validator->validate('invalid citation text');

        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('Invalid', $result->validation_detail);
        $this->assertEquals('usda', $result->source_type);
        $this->assertNull($result->resolved_source_url);
    }
}
