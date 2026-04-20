<?php

namespace Tests\Feature;

use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_four_plans(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $this->assertSame(4, SubscriptionPlan::count());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'free')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'basic')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'pro')->first());
        $this->assertNotNull(SubscriptionPlan::where('slug', 'ultimate')->first());
    }

    public function test_user_can_have_entitlement(): void
    {
        $this->seed(SubscriptionPlansSeeder::class);

        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', 'pro')->first();

        SubscriptionEntitlement::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(5),
            'renews_at' => now()->addDays(35),
            'billing_provider' => 'revenuecat',
            'billing_customer_id' => 'rc_abc123',
            'billing_metadata' => ['product_id' => 'pro_monthly', 'latest_receipt' => 'opaque-token'],
        ]);

        $this->assertSame('trialing', $user->entitlement->status);
        $this->assertSame('pro', $user->entitlement->plan->slug);
        $this->assertSame('revenuecat', $user->entitlement->billing_provider);
        $this->assertSame('pro_monthly', $user->entitlement->billing_metadata['product_id']);
    }
}
