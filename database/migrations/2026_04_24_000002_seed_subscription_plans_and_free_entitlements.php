<?php

use App\Models\SubscriptionEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the free + premium subscription plans and backfills every
 * existing user to the free entitlement so the gating logic has
 * something to compare against from the first request onward.
 *
 * Plans are defined here (not in a seeder) because they're foundational
 * state — the gating service assumes both exist, so running the
 * subscription code without them would crash. The seeder path is
 * reserved for sample data that's optional.
 *
 * Tier shape (v1 — can be adjusted via admin UI later):
 *
 *   free     — 10 user messages per day, 7-day conversation memory,
 *              all avatars accessible for evaluation purposes. No
 *              attachments (photo/file).
 *   premium  — unlimited messages, 90-day memory, all avatars, all
 *              features. Monthly / annual pricing copy stored in cents.
 *
 * Existing users are backfilled to 'free' with status='active' and
 * billing_provider=null (no RevenueCat linkage until they upgrade).
 * Doesn't touch users who already have an entitlement — super-admin
 * or QA accounts may have been granted premium manually.
 */
return new class extends Migration
{
    public function up(): void
    {
        $free = SubscriptionPlan::updateOrCreate(
            ['slug' => 'free'],
            [
                'name' => 'Free',
                'price_usd_cents_monthly' => 0,
                'price_usd_cents_annual'  => 0,
                'daily_message_limit'     => 10,
                'memory_days'             => 7,
                'features'                => [
                    'all_avatars'      => true,
                    'voice_mode'       => false,
                    'attachments'      => false,
                    'priority_support' => false,
                ],
                'is_active' => true,
            ],
        );

        SubscriptionPlan::updateOrCreate(
            ['slug' => 'premium'],
            [
                'name' => 'Premium',
                'price_usd_cents_monthly' => 999,   // $9.99 / mo
                'price_usd_cents_annual'  => 7999,  // $79.99 / yr
                'daily_message_limit'     => null,  // unlimited
                'memory_days'             => 90,
                'features'                => [
                    'all_avatars'      => true,
                    'voice_mode'       => true,
                    'attachments'      => true,
                    'priority_support' => true,
                ],
                'is_active' => true,
            ],
        );

        // Backfill: any user without an entitlement row gets 'free'.
        User::query()
            ->whereDoesntHave('entitlement')
            ->cursor()
            ->each(function (User $user) use ($free) {
                SubscriptionEntitlement::create([
                    'user_id'    => $user->id,
                    'plan_id'    => $free->id,
                    'status'     => 'active',
                    'renews_at'  => null,
                    'billing_provider'    => null,
                    'billing_customer_id' => null,
                    'billing_metadata'    => null,
                ]);
            });
    }

    public function down(): void
    {
        // Destroying subscription entitlements and plans would orphan live
        // RevenueCat customer_ids and leave the gating service unable to
        // resolve any user's tier. Reverse by hand if you really need to —
        // a safer path is to disable enforcement via feature flag.
    }
};
