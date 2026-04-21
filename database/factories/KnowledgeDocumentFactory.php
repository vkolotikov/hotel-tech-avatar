<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'title' => $this->faker->sentence(),
            'source_url' => $this->faker->url(),
            'evidence_grade' => $this->faker->randomElement(['A', 'B', 'C']),
            'licence' => 'CC-BY',
            'locale' => 'en-US',
            'checksum' => $this->faker->sha256(),
            'ingested_at' => now(),
        ];
    }
}
