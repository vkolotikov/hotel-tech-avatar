<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'free', 'name' => 'Free', 'monthly' => 0, 'annual' => 0, 'daily' => 5, 'memory' => 7, 'features' => ['avatars' => 1, 'voice' => false, 'uploads' => false, 'wearables' => false]],
            ['slug' => 'basic', 'name' => 'Basic', 'monthly' => 999, 'annual' => 8388, 'daily' => 30, 'memory' => 30, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'basic', 'wearables' => false]],
            ['slug' => 'pro', 'name' => 'Pro', 'monthly' => 1999, 'annual' => 16788, 'daily' => 100, 'memory' => null, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'full', 'wearables' => true, 'lab_ocr' => true, 'skin_analysis' => true]],
            ['slug' => 'ultimate', 'name' => 'Ultimate', 'monthly' => 3999, 'annual' => 33588, 'daily' => 500, 'memory' => null, 'features' => ['avatars' => 6, 'voice' => true, 'uploads' => 'full', 'wearables' => true, 'video_form_check' => true, 'priority' => true]],
        ];

        foreach ($plans as $p) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $p['slug']],
                [
                    'name' => $p['name'],
                    'price_usd_cents_monthly' => $p['monthly'],
                    'price_usd_cents_annual' => $p['annual'],
                    'daily_message_limit' => $p['daily'],
                    'memory_days' => $p['memory'],
                    'features' => $p['features'],
                    'is_active' => true,
                ]
            );
        }
    }
}
