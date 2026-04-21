<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GenericCitationValidator implements CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult
    {
        $startTime = microtime(true);

        // Extract HTTPS URL (expected format: "https://..." or "http://...")
        if (! preg_match('|https?://[^\s]+|i', $citation_text, $matches)) {
            Log::debug('Generic URL validator: Invalid URL format', [
                'citation_text' => $citation_text,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Invalid URL format',
                source_type: 'generic_url'
            );
        }

        $url = $matches[0];
        $cache_key = 'citation:validation:generic:' . hash('sha256', $url);
        $timeout = config('verification.citation_validators.generic.timeout_seconds', 2);
        $cacheTtlHoursSuccess = config('verification.citation_validators.generic.cache_ttl_hours', 24);
        $cacheTtlHoursError = config('verification.citation_validators.generic.cache_ttl_hours_error', 1);

        // Check cache first
        if (Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);

            Log::debug('Generic URL validator: Cache hit', [
                'url' => $url,
                'is_valid' => $cached['is_valid'],
            ]);

            return new CitationValidationResult(
                is_valid: $cached['is_valid'],
                validation_detail: $cached['detail'],
                resolved_source_url: $cached['url'] ?? null,
                source_type: 'generic_url'
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->head($url);

            // Valid if status is 200
            $valid = $response->status() === 200;
            $detail = $valid ? "URL {$url} is accessible" : "URL {$url} returned status {$response->status()}";

            // Cache both success and non-200 responses (permanent validation failures) with 24h TTL
            Cache::put(
                $cache_key,
                ['is_valid' => $valid, 'detail' => $detail, 'url' => $valid ? $url : null],
                now()->addHours($cacheTtlHoursSuccess)
            );

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::debug('Generic URL validator: Validation complete', [
                'url' => $url,
                'is_valid' => $valid,
                'status' => $response->status(),
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: $valid,
                validation_detail: $detail,
                resolved_source_url: $valid ? $url : null,
                source_type: 'generic_url'
            );
        } catch (RequestException $e) {
            // Transient API error (5xx, 429, network timeout) — don't cache, let next request retry
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = $e->response?->status();

            Log::warning('Generic URL validator: Request error (transient)', [
                'url' => $url,
                'status' => $status,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'URL validation temporarily unavailable',
                source_type: 'generic_url'
            );
        } catch (\Exception $e) {
            // Unexpected error — cache failure with 1h TTL
            $duration = round((microtime(true) - $startTime) * 1000);
            Log::error('Generic URL validator: Unexpected error', [
                'url' => $url,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            // Cache the failure with shorter TTL to retry sooner
            Cache::put(
                $cache_key,
                ['is_valid' => false, 'detail' => 'Error validating URL'],
                now()->addHours($cacheTtlHoursError)
            );

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Error validating URL',
                source_type: 'generic_url'
            );
        }
    }
}
