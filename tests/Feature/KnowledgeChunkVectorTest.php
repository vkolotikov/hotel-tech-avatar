<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\KnowledgeDocument;
use Database\Seeders\DemoSeeder;
use Database\Seeders\VerticalsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KnowledgeChunkVectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_store_and_nearest_neighbor_query_a_chunk(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector requires PostgreSQL');
        }

        $this->seed(VerticalsSeeder::class);
        $this->seed(DemoSeeder::class);

        $agent = Agent::first();

        $doc = KnowledgeDocument::create([
            'agent_id' => $agent->id,
            'title' => 'Test source',
            'source_url' => null,
            'evidence_grade' => 'expert-opinion',
            'licence' => 'internal',
            'locale' => 'en',
            'metadata' => [],
        ]);

        $vec = '[' . implode(',', array_fill(0, 3072, 0.0)) . ']';

        DB::insert("INSERT INTO knowledge_chunks (document_id, agent_id, chunk_index, content, embedding, created_at, updated_at)
                    VALUES (?, ?, 0, 'Hello world', ?::vector, NOW(), NOW())", [$doc->id, $agent->id, $vec]);

        $count = DB::scalar('SELECT COUNT(*) FROM knowledge_chunks');
        $this->assertSame(1, (int) $count);

        $nearest = DB::selectOne("SELECT content FROM knowledge_chunks ORDER BY embedding <-> ?::vector LIMIT 1", [$vec]);
        $this->assertSame('Hello world', $nearest->content);
    }
}
