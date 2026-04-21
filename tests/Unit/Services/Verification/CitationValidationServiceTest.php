<?php

namespace Tests\Unit\Services\Verification;

use App\Models\KnowledgeChunk;
use App\Services\Verification\CitationValidationService;
use App\Services\Verification\Drivers\Claim;
use App\Services\Verification\Drivers\CitationValidationResult;
use App\Services\Verification\Drivers\GroundingResult;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class CitationValidationServiceTest extends TestCase
{
    private CitationValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CitationValidationService();
    }

    /**
     * Test 1: Route to PubMed validator and validate successfully.
     */
    public function test_route_to_pubmed_validator_and_validate_successfully()
    {
        Http::fake([
            'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi*' => Http::response(
                '<?xml version="1.0"?><PubmedArticle><PMID Version="1">12345</PMID></PubmedArticle>',
                200
            ),
        ]);

        // Mock KnowledgeChunk with citation_key
        $chunk = \Mockery::mock(KnowledgeChunk::class . '[offsetExists]');
        $chunk->metadata = ['citation_key' => 'PMID:12345'];
        $chunk->shouldReceive('offsetExists')
            ->andReturn(true);

        $grounding = new GroundingResult(
            is_grounded: true,
            matched_chunk: $chunk,
            similarity_score: 0.95,
            supporting_evidence: 'Some evidence'
        );

        $claim = new Claim(
            text: 'Vitamin C improves immune function.',
            requires_citation: true,
            inferred_source_category: 'pubmed',
            grounding: $grounding
        );

        $result = $this->service->validate_all_citations([$claim]);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Claim::class, $result[0]);
        $this->assertNotNull($result[0]->citation);
        $this->assertTrue($result[0]->citation->is_valid);
        $this->assertEquals('pubmed', $result[0]->citation->source_type);
        $this->assertEquals('https://pubmed.ncbi.nlm.nih.gov/12345', $result[0]->citation->resolved_source_url);
    }

    /**
     * Test 2: Skip validation for ungrounded claims.
     */
    public function test_skip_validation_for_ungrounded_claims()
    {
        $claim = new Claim(
            text: 'Some ungrounded claim.',
            requires_citation: false,
            inferred_source_category: 'generic',
            grounding: new GroundingResult(is_grounded: false)
        );

        $result = $this->service->validate_all_citations([$claim]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]->citation);
    }
}
