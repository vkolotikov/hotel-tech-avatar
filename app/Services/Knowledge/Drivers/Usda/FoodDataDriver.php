<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers\Usda;

use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;

final class FoodDataDriver implements DriverInterface
{
    private const BASE_URL = 'https://fdc.nal.usda.gov/api/v1/foods/search';

    public function fetch(array $config): array
    {
        $apiKey = $config['api_key'];
        $maxResults = $config['max_results_per_sync'] ?? 500;

        try {
            $response = Http::get(self::BASE_URL, [
                'api_key' => $apiKey,
                'query' => 'nutrition',
                'pageSize' => $maxResults,
            ])->json();
        } catch (\Exception $e) {
            \Log::warning('USDA FoodData API call failed', ['error' => $e->getMessage()]);
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
