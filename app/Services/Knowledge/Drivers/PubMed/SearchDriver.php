<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers\PubMed;

use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;

final class SearchDriver implements DriverInterface
{
    private const SEARCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi';
    private const FETCH_URL = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';

    public function fetch(array $config): array
    {
        $apiKey = $config['api_key'];
        $searchQuery = $config['search_query'] ?? 'nutrition';
        $maxResults = $config['max_results_per_sync'] ?? 200;

        try {
            // Step 1: Search for PMIDs
            $searchResponse = Http::get(self::SEARCH_URL, [
                'db' => 'pubmed',
                'term' => $searchQuery,
                'retmax' => min($maxResults, 100),
                'rettype' => 'json',
                'api_key' => $apiKey,
            ])->json();

            $pmids = $searchResponse['esearchresult']['idlist'] ?? [];

            if (empty($pmids)) {
                return [];
            }

            // Step 2: Fetch full articles
            $pmidString = implode(',', array_slice($pmids, 0, 10));
            $fetchResponse = Http::get(self::FETCH_URL, [
                'db' => 'pubmed',
                'id' => $pmidString,
                'rettype' => 'xml',
                'api_key' => $apiKey,
            ])->body();

            return $this->parseXmlResponse($fetchResponse);
        } catch (\Exception $e) {
            \Log::warning('PubMed API call failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function parseXmlResponse(string $xml): array
    {
        $chunks = [];

        try {
            $dom = new SimpleXMLElement($xml);
        } catch (\Exception $e) {
            \Log::warning('PubMed XML parse failed', ['error' => $e->getMessage()]);
            return [];
        }

        foreach ($dom->PubmedArticle as $article) {
            $medlineCitation = $article->MedlineCitation;
            $articleElem = $medlineCitation->Article;
            $pubmedData = $article->PubmedData;

            $title = (string) $articleElem->ArticleTitle;
            $abstract = (string) ($articleElem->Abstract->AbstractText ?? '');
            $pmid = (string) ($pubmedData->ArticleIdList->ArticleId[0] ?? 'unknown');

            if (!$title) {
                continue;
            }

            $content = "{$title}\n\n{$abstract}";
            $sourceUrl = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/";
            $citationKey = "PMID:{$pmid}";

            $chunks[] = new RetrievedChunk(
                content: $content,
                source_url: $sourceUrl,
                source_name: 'PubMed',
                citation_key: $citationKey,
                evidence_grade: 'research',
                fetched_at: new \DateTimeImmutable(),
            );
        }

        return $chunks;
    }

    public function name(): string
    {
        return 'PubMed';
    }
}
