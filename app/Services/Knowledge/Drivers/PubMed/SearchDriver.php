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
    private const FETCH_URL  = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi';
    // NCBI asks API consumers to identify themselves with a tool= + email=
    // query-string, plus a descriptive User-Agent. Prevents us from being
    // rate-limited as anonymous traffic.
    private const TOOL_NAME   = 'wellnessai';
    private const HTTP_TIMEOUT_SECONDS = 20;
    // NCBI caps single efetch calls at 200 PMIDs; we stay well below.
    private const MAX_FETCH_PER_CALL   = 100;

    public function fetch(array $config): array
    {
        $apiKey      = (string) ($config['api_key'] ?? '');
        $searchQuery = trim((string) ($config['search_query'] ?? 'nutrition'));
        $maxResults  = (int) ($config['max_results_per_sync'] ?? 200);

        if ($maxResults < 1) {
            return [];
        }

        $contactEmail = (string) config('services.pubmed.contact_email', 'ops@wellnessai.app');

        try {
            // Step 1: search for PMIDs matching the configured query.
            $searchResponse = Http::withHeaders([
                    'User-Agent' => self::TOOL_NAME . '/1.0 (' . $contactEmail . ')',
                ])
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::SEARCH_URL, array_filter([
                    'db'      => 'pubmed',
                    'term'    => $searchQuery,
                    'retmax'  => min($maxResults, self::MAX_FETCH_PER_CALL),
                    'retmode' => 'json',
                    'tool'    => self::TOOL_NAME,
                    'email'   => $contactEmail,
                    'api_key' => $apiKey !== '' ? $apiKey : null,
                ], fn ($v) => $v !== null))
                ->throw()
                ->json();

            $pmids = $searchResponse['esearchresult']['idlist'] ?? [];
            if (empty($pmids)) {
                return [];
            }

            // Step 2: fetch up to max_results_per_sync full records. Don't
            // silently drop IDs — that was the old bug that capped every
            // sync at 10 articles regardless of configuration.
            $pmidsToFetch = array_slice(
                $pmids,
                0,
                min($maxResults, self::MAX_FETCH_PER_CALL),
            );
            $pmidString = implode(',', $pmidsToFetch);

            $fetchResponse = Http::withHeaders([
                    'User-Agent' => self::TOOL_NAME . '/1.0 (' . $contactEmail . ')',
                ])
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->get(self::FETCH_URL, array_filter([
                    'db'      => 'pubmed',
                    'id'      => $pmidString,
                    'rettype' => 'xml',
                    'tool'    => self::TOOL_NAME,
                    'email'   => $contactEmail,
                    'api_key' => $apiKey !== '' ? $apiKey : null,
                ], fn ($v) => $v !== null))
                ->throw()
                ->body();

            return $this->parseXmlResponse($fetchResponse);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PubMed API call failed', [
                'error'         => $e->getMessage(),
                'search_query'  => $searchQuery,
            ]);
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
