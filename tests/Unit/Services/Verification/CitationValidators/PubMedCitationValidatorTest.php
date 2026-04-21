<?php

namespace Tests\Unit\Services\Verification\CitationValidators;

use App\Services\Verification\CitationValidators\PubMedCitationValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PubMedCitationValidatorTest extends TestCase
{
    public function test_validate_returns_valid_result_for_existing_pmid()
    {
        Http::fake([
            'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
                '<?xml version="1.0"?><PubmedArticle><PMID Version="1">12345</PMID></PubmedArticle>',
                200
            ),
        ]);

        $validator = new PubMedCitationValidator();
        $result = $validator->validate('PMID:12345');

        // Assert ALL properties
        $this->assertTrue($result->is_valid);
        $this->assertStringContainsString('resolved', $result->validation_detail);
        $this->assertEquals('https://pubmed.ncbi.nlm.nih.gov/12345', $result->resolved_source_url);
        $this->assertEquals('pubmed', $result->source_type);
    }

    public function test_validate_returns_invalid_result_for_nonexistent_pmid()
    {
        Http::fake([
            'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response('', 200),
        ]);

        $validator = new PubMedCitationValidator();
        $result = $validator->validate('PMID:999999');

        // Assert ALL properties
        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('not found', $result->validation_detail);
        $this->assertNull($result->resolved_source_url);
        $this->assertEquals('pubmed', $result->source_type);
    }

    public function test_validate_returns_invalid_result_for_malformed_citation()
    {
        $validator = new PubMedCitationValidator();
        $result = $validator->validate('invalid citation');

        // Assert ALL properties
        $this->assertFalse($result->is_valid);
        $this->assertStringContainsString('Invalid', $result->validation_detail);
        $this->assertNull($result->resolved_source_url);
        $this->assertEquals('pubmed', $result->source_type);
    }
}
