<?php

namespace Tests\Feature;

use App\Models\Vertical;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerticalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_hotel_and_wellness_verticals(): void
    {
        $this->seed(VerticalsSeeder::class);

        $this->assertEquals(2, Vertical::count());

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();
        $this->assertTrue($hotel->is_active);
        $this->assertNotNull($hotel->launched_at);

        $wellness = Vertical::where('slug', 'wellness')->firstOrFail();
        $this->assertFalse($wellness->is_active);
        $this->assertNull($wellness->launched_at);
    }
}
