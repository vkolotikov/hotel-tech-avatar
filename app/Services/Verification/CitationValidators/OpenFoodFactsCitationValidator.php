<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OpenFoodFactsCitationValidator implements CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult
    {
        $startTime = microtime(true);

        // Extract barcode from citation (expected format: 12-14 digits)
        if (! preg_match('/(\d{12,14})/', $citation_text, $matches)) {
            Log::debug('OpenFoodFacts validator: Invalid barcode format', [
                'citation_text' => $citation_text,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Invalid barcode format',
                source_type: 'openfood'
            );
        }

        $barcode = $matches[1];
        $cache_key = "citation:validation:openfood:{$barcode}";
        $timeout = config('verification.citation_validators.openfood.timeout_seconds', 3);
        $cacheTtlHours = config('verification.citation_validators.openfood.cache_ttl_hours', 24);

        // Check cache first
        if (Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);

            Log::debug('OpenFoodFacts validator: Cache hit', [
                'barcode' => $barcode,
                'is_valid' => $cached['is_valid'],
            ]);

            return new CitationValidationResult(
                is_valid: $cached['is_valid'],
                validation_detail: $cached['detail'],
                resolved_source_url: $cached['url'] ?? null,
                source_type: 'openfood'
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->get('https://world.openfoodfacts.org/api/v2/product/' . $barcode)
                ->throw();

            $valid = $response->status() === 200 && isset($response['code']);
            $url = $valid ? "https://world.openfoodfacts.org/product/{$barcode}" : null;
            $detail = $valid ? "Product barcode {$barcode} found" : "Product barcode {$barcode} not found";

            // Cache both success and NOT-FOUND (permanent validation failures)
            Cache::put($cache_key, ['is_valid' => $valid, 'detail' => $detail, 'url' => $url], now()->addHours($cacheTtlHours));

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::debug('OpenFoodFacts validator: Validation complete', [
                'barcode' => $barcode,
                'is_valid' => $valid,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: $valid,
                validation_detail: $detail,
                resolved_source_url: $url,
                source_type: 'openfood'
            );
        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = $e->response?->status();

            // 404 = permanent "not found" — cache so we don't hammer the API
            if ($status === 404) {
                $detail = "Product barcode {$barcode} not found";
                Cache::put($cache_key, ['is_valid' => false, 'detail' => $detail, 'url' => null], now()->addHours($cacheTtlHours));

                Log::debug('OpenFoodFacts validator: Barcode not found', [
                    'barcode' => $barcode,
                    'duration_ms' => $duration,
                ]);

                return new CitationValidationResult(
                    is_valid: false,
                    validation_detail: $detail,
                    source_type: 'openfood'
                );
            }

            // Transient API error (5xx, 429, network timeout) — don't cache, let next request retry
            Log::warning('OpenFoodFacts validator: API error (transient)', [
                'barcode' => $barcode,
                'status' => $status,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'OpenFoodFacts API temporarily unavailable',
                source_type: 'openfood'
            );
        } catch (\Exception $e) {
            // Unexpected error — don't cache
            $duration = round((microtime(true) - $startTime) * 1000);
            Log::error('OpenFoodFacts validator: Unexpected error', [
                'barcode' => $barcode,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Error validating OpenFoodFacts citation',
                source_type: 'openfood'
            );
        }
    }
}
