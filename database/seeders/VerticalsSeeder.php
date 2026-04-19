<?php

namespace Database\Seeders;

use App\Models\Vertical;
use Illuminate\Database\Seeder;

class VerticalsSeeder extends Seeder
{
    public function run(): void
    {
        Vertical::updateOrCreate(
            ['slug' => 'hotel'],
            [
                'name' => 'Hotel Concierge Suite',
                'description' => 'Hospitality avatars — concierge, spa, events, culinary.',
                'is_active' => true,
                'launched_at' => now(),
                'metadata' => ['accent' => '#8B5CF6', 'surface' => 'spa'],
            ]
        );

        Vertical::updateOrCreate(
            ['slug' => 'wellness'],
            [
                'name' => 'WellnessAI',
                'description' => 'Six specialist avatars for consumer wellness.',
                'is_active' => false,
                'launched_at' => null,
                'metadata' => ['accent' => '#10B981', 'app_store_id' => null, 'play_store_id' => null],
            ]
        );
    }
}
