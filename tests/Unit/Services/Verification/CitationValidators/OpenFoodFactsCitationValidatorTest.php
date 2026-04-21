<?php

namespace Tests\Unit\Services\Verification\CitationValidators;

use App\Services\Verification\CitationValidators\OpenFoodFactsCitationValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenFoodFactsCitationValidatorTest extends TestCase
{
    public function test_validate_returns_valid_result_with_url_for_existing_barcode()
    {
        Http::fake([
            'https://world.openfoodfacts.org/api/v2/product/*' => Http::response(['code' => '123456789012'], 200),
        ]);

        $validator = new OpenFoodFactsCitationValidator();
        $result = $validator->validate('Product barcode 123456789012');

        $this->assertTrue($result->is_valid);
        $this->assertStringContainsString('found', $result->validation_detail);
        $this->assertEquals('openfood', $result->source_type);
        $this->assertEquals('https://world.openfoodfacts.org/product/123456789012', $result->resolved_source_url);
    }

    public function test_validate_returns_invalid_result_for_nonexistent_barcode()
    {
        Http::fake([
            'https://world.openfoodfacts.org/api/v2/product/*' => Http::response(null, 404),
        ]);

        $validator = new OpenFoodFactsCitationValidator();
        $result = $validator->validate('Product barcode 999999999999');

        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('not found', $result->validation_detail);
        $this->assertEquals('openfood', $result->source_type);
        $this->assertNull($result->resolved_source_url);
    }

    public function test_validate_returns_invalid_result_for_malformed_barcode()
    {
        $validator = new OpenFoodFactsCitationValidator();
        $result = $validator->validate('invalid citation text');

        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('Invalid', $result->validation_detail);
        $this->assertEquals('openfood', $result->source_type);
        $this->assertNull($result->resolved_source_url);
    }
}
