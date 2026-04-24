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
 *   premium  — €29 / month or €278.40 / year (20% off the monthly run
 *              rate), 5-day free trial configured in RevenueCat on the
 *              monthly product. 200 messages/day soft cap (≈ one every
 *              7 minutes around the clock — invisible to normal use,
 *              protects against abuse + runaway LLM cost). 90-day
 *              memory, all avatars, voice, attachments.
 *
 * Prices stored as integer cents in the existing `price_usd_cents_*`
 * columns. The column name is misleading — RevenueCat owns the
 * storefront-localised display price, so what we store here is just
 * for internal admin/reporting. Dropping a proper `currency` column
 * is a later schema migration.
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
                // €29.00 / mo. Field name says "usd" but the column is
                // generic integer cents — RevenueCat owns the localised
                // display price. See migration docblock.
                'price_usd_cents_monthly' => 2900,
                // €278.40 / yr = €29 × 12 × 0.80 (20% off).
                'price_usd_cents_annual'  => 27840,
                // Soft cap against runaway abuse — a normal user won't
                // hit 200 sends in a day.
                'daily_message_limit'     => 200,
                'memory_days'             => 90,
                'features'                => [
                    'all_avatars'      => true,
                    'voice_mode'       => true,
                    'attachments'      => true,
                    'priority_support' => true,
                    // 5-day free trial — configured on the RevenueCat
                    // monthly product (App Store "Introductory Offer" /
                    // Play Console "Free trial"). Flagged here for the
                    // mobile paywall UI to render "5-day free trial"
                    // language consistently.
                    'trial_days'       => 5,
                    'annual_discount_pct' => 20,
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
