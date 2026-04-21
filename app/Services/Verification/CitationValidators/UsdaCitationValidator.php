<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class UsdaCitationValidator implements CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult
    {
        $startTime = microtime(true);

        // Extract FDC ID from citation (expected format: "USDA FDC ID: 12345")
        if (! preg_match('/(?:USDA\s+)?FDC\s+ID[:\s]+(\d+)/i', $citation_text, $matches)) {
            Log::debug('USDA validator: Invalid FDC ID format', [
                'citation_text' => $citation_text,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Invalid USDA FDC ID format',
                source_type: 'usda'
            );
        }

        $fdc_id = $matches[1];
        $cache_key = "citation:validation:usda:{$fdc_id}";
        $timeout = config('verification.citation_validators.usda.timeout_seconds', 3);
        $cacheTtlHours = config('verification.citation_validators.usda.cache_ttl_hours', 24);

        // Check cache first
        if (Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);

            Log::debug('USDA validator: Cache hit', [
                'fdc_id' => $fdc_id,
                'is_valid' => $cached['is_valid'],
            ]);

            return new CitationValidationResult(
                is_valid: $cached['is_valid'],
                validation_detail: $cached['detail'],
                source_type: 'usda'
            );
        }

        try {
            $response = Http::timeout($timeout)
                ->get('https://fdc.nal.usda.gov/api/food/' . $fdc_id, [
                    'pageSize' => 1,
                ])
                ->throw();

            $valid = $response->status() === 200;
            $detail = $valid ? "USDA FDC ID {$fdc_id} found" : "USDA FDC ID {$fdc_id} not found";

            // Cache both success and NOT-FOUND (permanent validation failures)
            Cache::put($cache_key, ['is_valid' => $valid, 'detail' => $detail], now()->addHours($cacheTtlHours));

            $duration = round((microtime(true) - $startTime) * 1000);
            Log::debug('USDA validator: Validation complete', [
                'fdc_id' => $fdc_id,
                'is_valid' => $valid,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: $valid,
                validation_detail: $detail,
                source_type: 'usda'
            );
        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000);
            $status = $e->response?->status();

            // 404 = permanent "not found" — cache so we don't hammer the API
            if ($status === 404) {
                $detail = "USDA FDC ID {$fdc_id} not found";
                Cache::put($cache_key, ['is_valid' => false, 'detail' => $detail], now()->addHours($cacheTtlHours));

                Log::debug('USDA validator: FDC ID not found', [
                    'fdc_id' => $fdc_id,
                    'duration_ms' => $duration,
                ]);

                return new CitationValidationResult(
                    is_valid: false,
                    validation_detail: $detail,
                    source_type: 'usda'
                );
            }

            // Transient API error (5xx, 429, network timeout) — don't cache, let next request retry
            Log::warning('USDA validator: API error (transient)', [
                'fdc_id' => $fdc_id,
                'status' => $status,
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'USDA API temporarily unavailable',
                source_type: 'usda'
            );
        } catch (\Exception $e) {
            // Unexpected error — don't cache
            $duration = round((microtime(true) - $startTime) * 1000);
            Log::error('USDA validator: Unexpected error', [
                'fdc_id' => $fdc_id,
                'message' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Error validating USDA citation',
                source_type: 'usda'
            );
        }
    }
}
