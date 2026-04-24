<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers\OpenFoodFacts;

use App\Services\Knowledge\Drivers\DriverInterface;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;

final class FoodSearchDriver implements DriverInterface
{
    private const BASE_URL = 'https://world.openfoodfacts.org/api/v2/search';
    private const HTTP_TIMEOUT_SECONDS = 20;
    // OFF is lenient but asks callers to stay under 100 per page.
    private const MAX_PAGE_SIZE = 100;

    public function fetch(array $config): array
    {
        // OFF requires a descriptive User-Agent; anonymous callers get
        // aggressive rate limits. Contact email is informational only.
        $contactEmail = (string) config(
            'services.openfoodfacts.contact_email',
            'ops@wellnessai.app',
        );
        $searchQuery = trim((string) ($config['search_query'] ?? 'nutrition food'));
        $maxResults  = (int) ($config['max_results_per_sync'] ?? 100);

        if ($maxResults < 1) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                    'User-Agent' => 'wellnessai/1.0 (' . $contactEmail . ')',
                ])
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(self::BASE_URL, [
                    'search_terms' => $searchQuery,
                    'page_size'    => min($maxResults, self::MAX_PAGE_SIZE),
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Open Food Facts API call failed', [
                'error'        => $e->getMessage(),
                'search_query' => $searchQuery,
            ]);
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
