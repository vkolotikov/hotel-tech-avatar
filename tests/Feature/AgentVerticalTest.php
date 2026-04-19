<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Vertical;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentVerticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_seeded_agents_belong_to_hotel_vertical(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotel = Vertical::where('slug', 'hotel')->firstOrFail();

        Agent::all()->each(function (Agent $agent) use ($hotel) {
            $this->assertEquals($hotel->id, $agent->vertical_id, "Agent {$agent->slug} not on hotel vertical");
            $this->assertEquals($hotel->id, $agent->vertical->id);
        });
    }
}
