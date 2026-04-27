<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches subscription quotas from message-count to token-budget.
 *
 * `monthly_token_limit` is the user-facing budget — counts only chat
 * generation tokens (purpose='generation' on llm_calls). Internal calls
 * (verification, claim extraction, structured review, TTS, STT) stay
 * our overhead so the burn rate the user sees matches their actual
 * conversation activity.
 *
 * NULL = unlimited (e.g. internal admin overrides, future "ultra"
 * tier without caps). The existing `daily_message_limit` stays as a
 * cheap anti-abuse cap so a single runaway script can't drain a
 * month's tokens in one sitting.
 *
 * Backfills sensible defaults onto the existing seeded plans so users
 * on those plans don't suddenly get cut off after deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->unsignedInteger('monthly_token_limit')
                ->nullable()
                ->after('daily_message_limit');
        });

        // Backfill existing seeded plans with token budgets that match
        // their daily-message tiers. Numbers tuned so:
        //   - Free: ~$0.50 OpenAI cost / month / user
        //   - Basic: comfortable headroom, profit margin >70%
        //   - Pro: power-user friendly, margin still >60%
        //   - Ultimate: very generous; absorbs heavy users
        // Operators can override these via the SubscriptionPlansSeeder
        // or a one-off DB update if pricing assumptions change.
        $defaults = [
            'free'     => 50_000,
            'basic'    => 250_000,
            'pro'      => 1_000_000,
            'ultimate' => 5_000_000,
        ];
        foreach ($defaults as $slug => $tokens) {
            DB::table('subscription_plans')
                ->where('slug', $slug)
                ->update(['monthly_token_limit' => $tokens]);
        }
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('monthly_token_limit');
        });
    }
};
