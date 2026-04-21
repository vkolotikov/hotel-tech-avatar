<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'vertical_id' => Vertical::factory(),
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(),
            'summary_json' => null,
            'last_activity_at' => now(),
            'session_cost_usd_cents' => 0,
        ];
    }
}
