<?php

namespace Tests\Unit\Services\Knowledge\Drivers;

use App\Services\Knowledge\Drivers\PubMed\SearchDriver;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PubMedSearchDriverTest extends TestCase
{
    public function test_fetch_returns_retrieved_chunks(): void
    {
        $mockSearchResponse = [
            'esearchresult' => [
                'idlist' => ['12345'],
            ],
        ];

        $mockFetchXml = <<<'XML'
<?xml version="1.0" ?>
<PubmedArticleSet>
  <PubmedArticle>
    <MedlineCitation Status="Publisher">
      <Article>
        <ArticleTitle>The effects of nutrition on health and wellness</ArticleTitle>
        <Abstract>
          <AbstractText>This study examines the relationship between nutrition and long-term health outcomes. Results show significant benefits from balanced diets.</AbstractText>
        </Abstract>
        <AuthorList>
          <Author>
            <LastName>Smith</LastName>
          </Author>
        </AuthorList>
        <PublicationTypeList>
          <PublicationType>Journal Article</PublicationType>
        </PublicationTypeList>
      </Article>
      <MedlinePgn>12345</MedlinePgn>
    </MedlineCitation>
    <PubmedData>
      <ArticleIdList>
        <ArticleId IdType="pubmed">12345</ArticleId>
      </ArticleIdList>
    </PubmedData>
  </PubmedArticle>
</PubmedArticleSet>
XML;

        Http::fake([
            '*esearch*' => Http::response($mockSearchResponse, 200),
            '*efetch*' => Http::response($mockFetchXml, 200),
        ]);

        $driver = new SearchDriver();
        $chunks = $driver->fetch([
            'api_key' => 'test-key',
            'search_query' => 'nutrition AND health',
            'max_results_per_sync' => 10,
        ]);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(RetrievedChunk::class, $chunks[0]);
        $this->assertStringContainsString('nutrition', $chunks[0]->content);
        $this->assertEquals('research', $chunks[0]->evidence_grade);
        $this->assertStringContainsString('PMID', $chunks[0]->citation_key);
        $this->assertEquals('PubMed', $chunks[0]->source_name);
    }

    public function test_driver_name_returns_label(): void
    {
        $driver = new SearchDriver();
        $this->assertEquals('PubMed', $driver->name());
    }
}
