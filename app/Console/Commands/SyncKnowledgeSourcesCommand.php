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
    protected $signature = 'knowledge:sync {--avatar=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync knowledge sources for avatar(s). Optionally specify a single avatar by slug or ID.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $avatarSpec = $this->option('avatar');

        if ($avatarSpec) {
            // Find the avatar by slug or ID
            $agent = Agent::where('slug', $avatarSpec)
                ->orWhere('id', (int) $avatarSpec)
                ->first();

            if (!$agent) {
                $this->error("Avatar not found: {$avatarSpec}");
                return 1;
            }

            $this->info("Syncing knowledge sources for {$agent->name} (ID: {$agent->id})...");
            SyncKnowledgeSources::dispatch($agent->id);
            $this->info('Job dispatched to queue.');
        } else {
            // Sync all avatars
            $agents = Agent::all();

            if ($agents->isEmpty()) {
                $this->warn('No avatars found.');
                return 0;
            }

            $this->info("Syncing knowledge sources for {$agents->count()} avatar(s)...");
            SyncKnowledgeSources::dispatch();
            $this->info('Job dispatched to queue.');
        }

        return 0;
    }
}
