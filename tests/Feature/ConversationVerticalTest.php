<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Vertical;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationVerticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversations_inherit_vertical_from_agent(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $hotelId = Vertical::where('slug', 'hotel')->value('id');

        Conversation::all()->each(fn (Conversation $c) =>
            $this->assertEquals($hotelId, $c->vertical_id)
        );
    }

    public function test_conversation_session_cost_defaults_to_zero(): void
    {
        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        Conversation::all()->each(fn (Conversation $c) =>
            $this->assertSame(0, $c->session_cost_usd_cents)
        );
    }
}
