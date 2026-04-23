<?php

declare(strict_types=1);

namespace App\Services\Verification;

/**
 * Pulls citation identifiers out of raw LLM output so we can validate them
 * against the source databases before the message reaches the user.
 *
 * Operates purely on text — independent of the retrieval/grounding pipeline.
 * Covers the Phase-1 "no invented PMIDs" rule: even if the model fabricates
 * a citation that was never retrieved, we still catch it here.
 */
final class ResponseCitationExtractor
{
    /**
     * Extract all citation keys from a response text. Returns a list of
     * { key, type } tuples where type is one of: pubmed, usda, doi, url.
     *
     * @return array<int, array{key:string, type:string}>
     */
    public function extract(string $text): array
    {
        $citations = [];
        $seen = [];

        $pushUnique = function (string $key, string $type) use (&$citations, &$seen): void {
            $normalized = strtolower($type . '|' . $key);
            if (isset($seen[$normalized])) {
                return;
            }
            $seen[$normalized] = true;
            $citations[] = ['key' => $key, 'type' => $type];
        };

        // PMID:12345 or PMID 12345 (case-insensitive, digits only)
        if (preg_match_all('/PMID[:\s]+(\d{3,10})/i', $text, $matches)) {
            foreach ($matches[1] as $pmid) {
                $pushUnique("PMID:{$pmid}", 'pubmed');
            }
        }

        // FDC ID:12345 (USDA FoodData Central)
        if (preg_match_all('/FDC\s+ID[:\s]+(\d{3,10})/i', $text, $matches)) {
            foreach ($matches[1] as $fdcId) {
                $pushUnique("FDC ID:{$fdcId}", 'usda');
            }
        }

        // DOI pattern — 10.xxxx/something. Conservative: stop at whitespace
        // or a closing paren/bracket so surrounding prose doesn't leak in.
        if (preg_match_all('/\b(10\.\d{4,9}\/[^\s\)\]]+)/i', $text, $matches)) {
            foreach ($matches[1] as $doi) {
                $pushUnique(rtrim($doi, '.,;'), 'doi');
            }
        }

        return $citations;
    }
}
