<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;

/**
 * Ops-facing snapshot of the knowledge pipeline's current state.
 *
 *   php artisan knowledge:status
 *
 * Prints one row per agent with sync status, document count, chunk
 * count, and last-synced timestamp. Exit code 1 if any agent has zero
 * chunks but is expected to have some (sources configured but nothing
 * ingested), so CI/ops can catch silent driver failures.
 */
class KnowledgeStatusCommand extends Command
{
    protected $signature = 'knowledge:status';

    protected $description = 'Report chunk/document counts + sync status per avatar.';

    public function handle(): int
    {
        $agents = Agent::query()->orderBy('id')->get();
        if ($agents->isEmpty()) {
            $this->warn('No avatars found.');
            return 0;
        }

        $rows = [];
        $hasSilentFailure = false;

        foreach ($agents as $agent) {
            $sources   = is_array($agent->knowledge_sources_json) ? count($agent->knowledge_sources_json) : 0;
            $docs      = KnowledgeDocument::where('agent_id', $agent->id)->count();
            $chunks    = KnowledgeChunk::where('agent_id', $agent->id)->count();
            $status    = (string) ($agent->knowledge_sync_status ?? '—');
            $syncedAt  = $agent->knowledge_synced_at?->diffForHumans() ?? '—';

            if ($sources > 0 && $chunks === 0 && $status !== 'syncing' && $status !== 'pending') {
                $hasSilentFailure = true;
            }

            $rows[] = [
                $agent->slug,
                $sources,
                $docs,
                $chunks,
                $status,
                $syncedAt,
            ];
        }

        $this->table(
            ['Slug', 'Sources', 'Docs', 'Chunks', 'Status', 'Synced'],
            $rows,
        );

        if ($hasSilentFailure) {
            $this->warn('At least one agent has sources configured but zero chunks — driver probably failed silently. Check storage/logs/laravel.log.');
            return 1;
        }

        return 0;
    }
}
