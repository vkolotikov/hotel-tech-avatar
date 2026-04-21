<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers\OpenFoodFacts;

use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;

final class FoodSearchDriver implements DriverInterface
{
    private const BASE_URL = 'https://world.openfoodfacts.org/api/v2/search';

    public function fetch(array $config): array
    {
        $maxResults = $config['max_results_per_sync'] ?? 300;

        try {
            $response = Http::get(self::BASE_URL, [
                'q' => 'nutrition food',
                'page_size' => min($maxResults, 50),
            ])->json();
        } catch (\Exception $e) {
            \Log::warning('Open Food Facts API call failed', ['error' => $e->getMessage()]);
            return [];
        }

        $chunks = [];
        $products = $response['products'] ?? [];

        foreach ($products as $product) {
            $barcode = $product['code'] ?? null;
            $name = $product['product_name'] ?? 'Unknown product';
            $ingredients = $product['ingredients_text'] ?? 'No ingredients listed';
            $allergens = $product['allergens'] ?? '';

            if (!$barcode) {
                continue;
            }

            $nutrients = [];
            $nutriments = $product['nutriments'] ?? [];
            foreach (['protein_100g', 'fat_100g', 'carbohydrates_100g', 'energy_100g'] as $key) {
                if (isset($nutriments[$key])) {
                    $label = ucfirst(str_replace('_100g', '', $key));
                    $nutrients[] = "{$label}: {$nutriments[$key]}";
                }
            }

            $nutritionStr = !empty($nutrients) ? implode(', ', $nutrients) : 'Nutrition unknown';
            $allergenStr = $allergens ? "Allergens: {$allergens}" : '';

            $content = "{$name}\nIngredients: {$ingredients}\n{$nutritionStr}\n{$allergenStr}";
            $sourceUrl = "https://world.openfoodfacts.org/product/{$barcode}";
            $citationKey = "Open Food Facts: {$barcode}";

            $chunks[] = new RetrievedChunk(
                content: $content,
                source_url: $sourceUrl,
                source_name: 'Open Food Facts',
                citation_key: $citationKey,
                evidence_grade: 'database',
                fetched_at: new \DateTimeImmutable(),
            );
        }

        return $chunks;
    }

    public function name(): string
    {
        return 'Open Food Facts';
    }
}
