<?php

namespace Tests\Unit\Services\Knowledge\Drivers;

use App\Services\Knowledge\Drivers\Usda\FoodDataDriver;
use App\Services\Knowledge\Drivers\RetrievedChunk;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UsedaFoodDataDriverTest extends TestCase
{
    public function test_fetch_returns_retrieved_chunks(): void
    {
        $mockResponse = [
            'foods' => [
                [
                    'fdcId' => '123456',
                    'description' => 'Chicken, raw',
                    'foodNutrients' => [
                        ['nutrient' => ['name' => 'Protein'], 'value' => 26.0],
                        ['nutrient' => ['name' => 'Fat'], 'value' => 1.4],
                    ],
                ],
            ],
        ];

        Http::fake([
            'fdc.nal.usda.gov/*' => Http::response($mockResponse, 200),
        ]);

        $driver = new FoodDataDriver();
        $chunks = $driver->fetch([
            'api_key' => 'test-key',
            'search_categories' => ['nutrition'],
            'max_results_per_sync' => 10,
        ]);

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(RetrievedChunk::class, $chunks[0]);
        $this->assertStringContainsString('Chicken', $chunks[0]->content);
        $this->assertStringContainsString('Protein', $chunks[0]->content);
        $this->assertStringContainsString('26', $chunks[0]->content);
        $this->assertEquals('database', $chunks[0]->evidence_grade);
        $this->assertStringContainsString('USDA', $chunks[0]->source_name);
    }

    public function test_driver_name_returns_label(): void
    {
        $driver = new FoodDataDriver();
        $this->assertEquals('USDA FoodData Central', $driver->name());
    }
}
