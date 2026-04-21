<?php

namespace Tests\Unit\Services\Knowledge\Drivers;

use App\Services\Knowledge\Drivers\OpenFoodFacts\FoodSearchDriver;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenFoodFactsFoodSearchDriverTest extends TestCase
{
    public function test_fetch_returns_retrieved_chunks(): void
    {
        $mockResponse = [
            'products' => [
                [
                    'code' => '5901234123457',
                    'product_name' => 'Whole Wheat Bread',
                    'ingredients_text' => 'Whole wheat flour, water, salt, yeast',
                    'nutriments' => [
                        'protein_100g' => 8.0,
                        'fat_100g' => 2.5,
                        'carbohydrates_100g' => 45.0,
                    ],
                ],
            ],
        ];

        Http::fake([
            'world.openfoodfacts.org/*' => Http::response($mockResponse, 200),
        ]);

        $driver = new FoodSearchDriver();
        $chunks = $driver->fetch([
            'search_categories' => ['food'],
            'max_results_per_sync' => 100,
        ]);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(RetrievedChunk::class, $chunks[0]);
        $this->assertStringContainsString('Whole Wheat Bread', $chunks[0]->content);
        $this->assertStringContainsString('Whole wheat flour', $chunks[0]->content);
        $this->assertEquals('database', $chunks[0]->evidence_grade);
        $this->assertStringContainsString('Open Food Facts', $chunks[0]->source_name);
    }

    public function test_driver_name_returns_label(): void
    {
        $driver = new FoodSearchDriver();
        $this->assertEquals('Open Food Facts', $driver->name());
    }
}
