<?php

namespace Tests\Feature;

use App\Models\TokenUsageDaily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenUsageDailyTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_upsert_daily_rollup(): void
    {
        $user = User::factory()->create();

        TokenUsageDaily::create([
            'user_id' => $user->id,
            'usage_date' => '2026-04-19',
            'messages_count' => 3,
            'tokens_in' => 450,
            'tokens_out' => 800,
            'cost_usd_cents' => 5,
        ]);

        $this->assertSame(1, TokenUsageDaily::where('user_id', $user->id)->count());
    }

    public function test_unique_user_plus_date(): void
    {
        $user = User::factory()->create();

        TokenUsageDaily::create([
            'user_id' => $user->id, 'usage_date' => '2026-04-19',
            'messages_count' => 1, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd_cents' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TokenUsageDaily::create([
            'user_id' => $user->id, 'usage_date' => '2026-04-19',
            'messages_count' => 2, 'tokens_in' => 0, 'tokens_out' => 0, 'cost_usd_cents' => 0,
        ]);
    }
}
