<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Console\Command;

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

    public function handle(RetrievalService $retrieval): int
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

        $context = $retrieval->retrieve($query, $agent);

        $this->line("Chunks returned: {$context->chunk_count}");
        $this->line("Latency:         {$context->latency_ms}ms");
        $this->line("High risk:       " . ($context->is_high_risk ? 'yes' : 'no'));
        $this->line('');

        if ($context->chunk_count === 0) {
            $this->warn('Retrieval returned zero chunks. Two likely causes:');
            $this->line('  1. Similarity threshold (retrieval.vector_similarity_threshold) still too strict');
            $this->line('  2. Agent has no knowledge chunks (check `knowledge:status`)');
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
}
