<?php

namespace App\Services\Verification\CitationValidators;

use App\Services\Verification\Drivers\CitationValidationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class UsdaCitationValidator implements CitationValidatorInterface
{
    public function validate(string $citation_text): CitationValidationResult
    {
        // Extract FDC ID from citation (expected format: "USDA FDC ID: 12345")
        if (! preg_match('/\d+/', $citation_text, $matches)) {
            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'Invalid USDA FDC ID format'
            );
        }

        $fdc_id = $matches[0];
        $cache_key = "citation:validation:usda:{$fdc_id}";

        // Check cache first
        if (Cache::has($cache_key)) {
            $cached = Cache::get($cache_key);
            return new CitationValidationResult(
                is_valid: $cached['is_valid'],
                validation_detail: $cached['detail'],
                source_type: 'usda'
            );
        }

        try {
            $response = Http::timeout(3)
                ->get('https://fdc.nal.usda.gov/api/food/' . $fdc_id, [
                    'pageSize' => 1,
                ])
                ->throw();

            $valid = $response->status() === 200;
            $detail = $valid ? "USDA FDC ID {$fdc_id} found" : "USDA FDC ID {$fdc_id} not found";

            Cache::put($cache_key, ['is_valid' => $valid, 'detail' => $detail], now()->addDay());

            return new CitationValidationResult(
                is_valid: $valid,
                validation_detail: $detail,
                source_type: 'usda'
            );
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // Handle HTTP errors (4xx/5xx) with user-friendly message for 404
            if ($e->response->status() === 404) {
                $detail = "USDA FDC ID {$fdc_id} not found";
            } else {
                $detail = 'USDA API error: ' . $e->getMessage();
            }

            Cache::put($cache_key, ['is_valid' => false, 'detail' => $detail], now()->addDay());

            return new CitationValidationResult(
                is_valid: false,
                validation_detail: $detail,
                source_type: 'usda'
            );
        } catch (\Exception $e) {
            return new CitationValidationResult(
                is_valid: false,
                validation_detail: 'USDA API error: ' . $e->getMessage(),
                source_type: 'usda'
            );
        }
    }
}
