<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers\Usda;

use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;

final class FoodDataDriver implements DriverInterface
{
    private const BASE_URL = 'https://fdc.nal.usda.gov/api/v1/foods/search';
    private const HTTP_TIMEOUT_SECONDS = 20;
    // USDA caps pageSize at 200 for this endpoint.
    private const MAX_PAGE_SIZE = 200;

    public function fetch(array $config): array
    {
        $apiKey      = (string) ($config['api_key'] ?? '');
        // Default to a broad nutrition query so mis-configured sources
        // still return something useful, but honour the per-source query
        // when provided (the common case).
        $searchQuery = trim((string) ($config['search_query'] ?? 'nutrition'));
        $maxResults  = (int) ($config['max_results_per_sync'] ?? 200);

        if ($maxResults < 1 || $apiKey === '') {
            return [];
        }

        try {
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::BASE_URL, [
                    'api_key'  => $apiKey,
                    'query'    => $searchQuery,
                    'pageSize' => min($maxResults, self::MAX_PAGE_SIZE),
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('USDA FoodData API call failed', [
                'error'        => $e->getMessage(),
                'search_query' => $searchQuery,
            ]);
            return [];
        }

        $chunks = [];
        $foods = $response['foods'] ?? [];

        foreach ($foods as $food) {
            $fdcId = $food['fdcId'] ?? null;
            $description = $food['description'] ?? 'Unknown';

            if (!$fdcId) {
                continue;
            }

            $nutrients = [];
            foreach ($food['foodNutrients'] ?? [] as $nutrient) {
                $name = $nutrient['nutrient']['name'] ?? 'Unknown';
                $value = $nutrient['value'] ?? 0;
                $unit = $nutrient['nutrient']['unitName'] ?? '';
                $nutrients[] = "{$name}: {$value} {$unit}";
            }

            $content = "{$description}\n" . implode("\n", array_slice($nutrients, 0, 5));
            $sourceUrl = "https://fdc.nal.usda.gov/fdc-app.html#/?query={$fdcId}";
            $citationKey = "USDA FDC ID: {$fdcId}";

            $chunks[] = new RetrievedChunk(
                content: $content,
                source_url: $sourceUrl,
                source_name: 'USDA FoodData Central',
                citation_key: $citationKey,
                evidence_grade: 'database',
                fetched_at: new \DateTimeImmutable(),
            );
        }

        return $chunks;
    }

    public function name(): string
    {
        return 'USDA FoodData Central';
    }
}
