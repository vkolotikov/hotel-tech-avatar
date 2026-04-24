<?php

namespace App\Console\Commands;

use App\Jobs\SyncKnowledgeSources;
use App\Models\Agent;
use Illuminate\Console\Command;

class SyncKnowledgeSourcesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'knowledge:sync {--avatar=} {--queue : Dispatch to the queue instead of running inline}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync knowledge sources for avatar(s). Runs inline by default so you see results; pass --queue to dispatch asynchronously.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $avatarSpec = $this->option('avatar');
        $useQueue   = (bool) $this->option('queue');

        $agents = $avatarSpec
            ? Agent::query()
                ->where('slug', $avatarSpec)
                ->orWhere('id', (int) $avatarSpec)
                ->get()
            : Agent::query()->orderBy('id')->get();

        if ($agents->isEmpty()) {
            if ($avatarSpec) {
                $this->error("Avatar not found: {$avatarSpec}");
                return 1;
            }
            $this->warn('No avatars found.');
            return 0;
        }

        $this->info(sprintf(
            'Syncing knowledge sources for %d avatar%s (%s mode)...',
            $agents->count(),
            $agents->count() === 1 ? '' : 's',
            $useQueue ? 'queued' : 'inline',
        ));

        if ($useQueue) {
            foreach ($agents as $agent) {
                SyncKnowledgeSources::dispatch($agent->id);
                $this->line("  · {$agent->slug}: dispatched");
            }
            $this->info('Jobs dispatched — watch the queue worker for completion.');
            return 0;
        }

        // Inline mode: run each avatar sequentially and print status so the
        // operator can see what succeeded, what failed, and where they are
        // in the batch. Using dispatchSync() keeps the job's
        // status-tracking + error-handling behaviour.
        $hadFailure = false;
        foreach ($agents as $agent) {
            $this->line("  · {$agent->slug}: syncing...");
            try {
                SyncKnowledgeSources::dispatchSync($agent->id);
            } catch (\Throwable $e) {
                $hadFailure = true;
                $this->error("    failed: {$e->getMessage()}");
                continue;
            }
            $fresh = $agent->fresh();
            $status = $fresh?->knowledge_sync_status ?? 'unknown';
            $error  = $fresh?->knowledge_last_error;
            $this->line("    · status={$status}" . ($error ? " · {$error}" : ''));
            if ($status === 'failed') {
                $hadFailure = true;
            }
        }

        return $hadFailure ? 1 : 0;
    }
}
