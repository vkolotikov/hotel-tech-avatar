<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PubMedCitationValidator implements CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult
    {
        $startTime = microtime(true);

        // Extract PMID (expected format: "PMID:12345" or "PMID 12345", case-insensitive)
        if (! preg_match('/PMID[:\s]+(\d+)/i', $citation_text, $matches)) {
            Log::debug('PubMed validator: Invalid PMID format', [
                'citation_text' => $citation_text,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Invalid PMID format',
                source_type: 'pubmed'
            );
        }

        $pmid = $matches[1];
        $cache_key = "citation:validation:pubmed:{$pmid}";
        $timeout = config('verification.citation_validators.pubmed.timeout_seconds', 3);
        $cacheTtlHours = config('verification.citation_validators.pubmed.cache_ttl_hours', 24);

        // Check cache first
        if (Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);

            Log::debug('PubMed validator: Cache hit', [
                'pmid' => $pmid,
                'is_valid' => $cached['is_valid'],
            ]);

            return new CitationValidationResult(
                is_valid: $cached['is_valid'],
                validation_detail: $cached['detail'],
                resolved_source_url: $cached['url'] ?? null,
                source_type: 'pubmed'
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->get('https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi', [
                    'db' => 'pubmed',
                    'id' => $pmid,
                    'rettype' => 'xml',
                ])
                ->throw();

            // Valid if 200 status and response body contains the PMID (article found)
            $valid = $response->status() === 200 && str_contains($response->body(), (string) $pmid);
            $url = "https://pubmed.ncbi.nlm.nih.gov/{$pmid}";
            $detail = $valid ? "PMID:{$pmid} resolved to PubMed article" : "PMID:{$pmid} not found";

            // Cache both success and NOT-FOUND (permanent validation failures)
            Cache::put(
                $cache_key,
                ['is_valid' => $valid, 'detail' => $detail, 'url' => $valid ? $url : null],
                now()->addHours($cacheTtlHours)
            );

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::debug('PubMed validator: Validation complete', [
                'pmid' => $pmid,
                'is_valid' => $valid,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: $valid,
                validation_detail: $detail,
                resolved_source_url: $valid ? $url : null,
                source_type: 'pubmed'
            );
        } catch (RequestException $e) {
            // Transient API error (5xx, 429, network timeout) — don't cache, let next request retry
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = $e->response?->status();

            Log::warning('PubMed validator: API error (transient)', [
                'pmid' => $pmid,
                'status' => $status,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'PubMed API temporarily unavailable',
                source_type: 'pubmed'
            );
        } catch (\Exception $e) {
            // Unexpected error — don't cache
            $duration = round((microtime(true) - $startTime) * 1000);
            Log::error('PubMed validator: Unexpected error', [
                'pmid' => $pmid,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Error validating PubMed citation',
                source_type: 'pubmed'
            );
        }
    }
}
