<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        return [
            'vertical_id' => Vertical::factory(),
            'slug' => $this->faker->unique()->slug(),
            'name' => $this->faker->name(),
            'role' => $this->faker->word(),
            'domain' => $this->faker->word(),
            'description' => $this->faker->sentence(),
            'system_instructions' => $this->faker->paragraph(),
            'knowledge_text' => $this->faker->paragraph(),
            'is_published' => true,
        ];
    }
}
