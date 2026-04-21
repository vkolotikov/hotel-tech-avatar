<?php

namespace Tests\Unit\Services\Verification\CitationValidators;

use App\Services\Verification\CitationValidators\GenericCitationValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenericCitationValidatorTest extends TestCase
{
    public function test_validate_returns_valid_result_for_accessible_url()
    {
        Http::fake([
            'https://example.com/article' => Http::response('', 200),
        ]);

        $validator = new GenericCitationValidator();
        $result = $validator->validate('https://example.com/article');

        // Assert ALL properties
        $this->assertTrue($result->is_valid);
        $this->assertStringContainsString('accessible', $result->validation_detail);
        $this->assertEquals('https://example.com/article', $result->resolved_source_url);
        $this->assertEquals('generic_url', $result->source_type);
    }

    public function test_validate_returns_invalid_result_for_404_url()
    {
        Http::fake([
            'https://example.com/notfound' => Http::response('', 404),
        ]);

        $validator = new GenericCitationValidator();
        $result = $validator->validate('https://example.com/notfound');

        // Assert ALL properties
        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('404', $result->validation_detail);
        $this->assertNull($result->resolved_source_url);
        $this->assertEquals('generic_url', $result->source_type);
    }

    public function test_validate_returns_invalid_result_when_no_url_found()
    {
        $validator = new GenericCitationValidator();
        $result = $validator->validate('just some text without a url');

        // Assert ALL properties
        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('Invalid', $result->validation_detail);
        $this->assertNull($result->resolved_source_url);
        $this->assertEquals('generic_url', $result->source_type);
    }
}
