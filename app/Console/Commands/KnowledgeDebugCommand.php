<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot retrieval inspector. Given an avatar and a query, prints
 * the chunks that would be pulled into the system prompt along with
 * their cosine similarity scores — so we can see whether "Fallback
 * response" is caused by empty retrieval, below-threshold retrieval,
 * or the model ignoring chunks we did supply.
 *
 *   php artisan knowledge:debug nora "Does fermented food help gut health?"
 */
class KnowledgeDebugCommand extends Command
{
    protected $signature = 'knowledge:debug {avatar : slug or id} {query : user message to retrieve against}';

    protected $description = 'Show retrieval results for a prompt against one avatar\'s knowledge base.';

    public function handle(RetrievalService $retrieval, EmbeddingService $embeddings): int
    {
        $avatarSpec = (string) $this->argument('avatar');
        $query      = (string) $this->argument('query');

        $agent = Agent::query()
            ->where('slug', $avatarSpec)
            ->orWhere('id', (int) $avatarSpec)
            ->first();
        if (!$agent) {
            $this->error("Avatar not found: {$avatarSpec}");
            return 1;
        }

        $this->info("Retrieving against {$agent->name} (id {$agent->id}) for query:");
        $this->line("  {$query}");
        $this->line('');

        // --- Sanity check #1: do the stored chunk embeddings look real?
        $totalChunks = KnowledgeChunk::where('agent_id', $agent->id)->count();
        $this->line("Stored chunks for this agent: {$totalChunks}");
        if ($totalChunks === 0) {
            $this->warn('No chunks — run `php artisan knowledge:sync --avatar=' . $agent->slug . '` first.');
            return 1;
        }

        $sampleChunk = KnowledgeChunk::where('agent_id', $agent->id)
            ->whereNotNull('embedding')
            ->first();
        if (!$sampleChunk) {
            $this->error('No chunks have an embedding — sync must have failed to persist vectors.');
            return 1;
        }

        $sampleNorm = $this->vectorNorm(
            $this->parsePgvector((string) $sampleChunk->getRawOriginal('embedding'))
        );
        $this->line("Sample chunk (id {$sampleChunk->id}) embedding norm: " . number_format($sampleNorm, 4));
        if ($sampleNorm < 1e-6) {
            $this->error('Sample embedding is a zero vector — OpenAI embedding calls failed during sync.');
            $this->line('Fix: confirm OPENAI_API_KEY is set, then re-run `php artisan knowledge:sync`.');
            return 1;
        }

        // --- Sanity check #2: embed the query and show TOP-10 similarities
        // --- bypassing the threshold so we can see where real scores land.
        $this->line('');
        $this->info('Top 10 chunks by cosine similarity (unfiltered):');

        $queryVector = $embeddings->embed($query);
        $queryNorm = $this->vectorNorm($queryVector);
        if ($queryNorm < 1e-6) {
            $this->error('Query embedded to a zero vector — OpenAI call failed for the query itself.');
            return 1;
        }
        $this->line("Query embedding norm: " . number_format($queryNorm, 4));

        $embeddingString = '[' . implode(',', $queryVector) . ']';
        $rows = DB::select(
            'SELECT kc.id, 1 - (kc.embedding <=> ?::vector) as similarity, ' .
            'LEFT(kc.content, 90) as preview, ' .
            'COALESCE(kc.metadata->>\'citation_key\', \'\') as citation_key ' .
            'FROM knowledge_chunks kc ' .
            'WHERE kc.agent_id = ? AND kc.embedding IS NOT NULL ' .
            'ORDER BY kc.embedding <=> ?::vector ' .
            'LIMIT 10',
            [$embeddingString, $agent->id, $embeddingString]
        );

        $activeThreshold = (float) config('retrieval.vector_similarity_threshold', 0.5);
        $this->line("Active threshold: {$activeThreshold}");
        $this->line('');
        foreach ($rows as $i => $row) {
            $sim = number_format((float) $row->similarity, 4);
            $passes = (float) $row->similarity >= $activeThreshold ? '✓' : '·';
            $preview = preg_replace('/\s+/', ' ', (string) $row->preview);
            $this->line("  {$passes} sim={$sim}  [{$row->citation_key}]  {$preview}...");
        }

        // --- Now the actual retrieval pipeline (with threshold applied).
        $this->line('');
        $this->info('Actual retrieval result (post-threshold):');
        $context = $retrieval->retrieve($query, $agent);

        $this->line("Chunks returned: {$context->chunk_count}");
        $this->line("Latency:         {$context->latency_ms}ms");
        $this->line("High risk:       " . ($context->is_high_risk ? 'yes' : 'no'));
        $this->line('');

        if ($context->chunk_count === 0) {
            $topSim = isset($rows[0]) ? (float) $rows[0]->similarity : 0.0;
            $this->warn("Zero chunks passed threshold. Top raw similarity was " . number_format($topSim, 4) . ".");
            if ($topSim > 0 && $topSim < $activeThreshold) {
                $this->line("Threshold ({$activeThreshold}) is above the best match. Try lowering retrieval.vector_similarity_threshold.");
            }
            return 0;
        }

        foreach ($context->chunks as $i => $chunk) {
            $n = $i + 1;
            $preview = mb_substr(preg_replace('/\s+/', ' ', $chunk->content) ?? '', 0, 140);
            $this->line("[{$n}] {$chunk->citation_key}");
            $this->line("    source: {$chunk->source_name}");
            $this->line("    url:    {$chunk->source_url}");
            $this->line("    text:   {$preview}...");
            $this->line('');
        }

        return 0;
    }

    /** Parse pgvector "[a,b,c]" string into a float array. */
    private function parsePgvector(string $raw): array
    {
        $trimmed = trim($raw, '[] ');
        if ($trimmed === '') return [];
        return array_map(static fn ($v) => (float) trim($v), explode(',', $trimmed));
    }

    /** Euclidean norm of a vector. Zero for a zero/empty vector. */
    private function vectorNorm(array $v): float
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }
        return sqrt($sum);
    }
}
