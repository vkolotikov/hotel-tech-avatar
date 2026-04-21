<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeChunkFactory extends Factory
{
    protected $model = KnowledgeChunk::class;

    public function definition(): array
    {
        return [
            'document_id' => KnowledgeDocument::factory(),
            'agent_id' => Agent::factory(),
            'chunk_index' => $this->faker->numberBetween(0, 100),
            'content' => $this->faker->paragraph(),
            'embedding' => null, // Will be set by test or other means
        ];
    }
}
