<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'agent_id' => Agent::factory(),
            'role' => $this->faker->randomElement(['user', 'assistant']),
            'content' => $this->faker->paragraph(),
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-2025-03-15',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'ai_latency_ms' => 500,
            'retrieval_used' => false,
            'retrieval_source_count' => 0,
            'verification_status' => 'pending',
            'claim_count' => 0,
            'grounded_claim_count' => 0,
            'red_flag_triggered' => false,
        ];
    }
}
