<?php

namespace App\Console\Commands;

use App\Models\Agent;
use Illuminate\Console\Command;

/**
 * Set / swap the LiveAvatar avatar id for one of our wellness agents.
 * Clears the cached context_id at the same time so the controller
 * lazy-creates a fresh context against the new avatar on the next
 * session.
 *
 * Usage:
 *   php artisan liveavatar:set-avatar --slug=nora --id=fc9c1f9f-...
 *
 * Pass --voice=<uuid> to also set liveavatar_voice_id, or --clear-voice
 * to wipe it back to the avatar's default.
 *
 * Saves the operator from wrestling tinker through bash escaping.
 */
class LiveAvatarSetAvatarCommand extends Command
{
    protected $signature = 'liveavatar:set-avatar
        {--slug= : agent slug (e.g. nora)}
        {--id= : LiveAvatar avatar UUID}
        {--voice= : optional LiveAvatar voice UUID}
        {--clear-voice : null out the existing voice override}';

    protected $description = 'Map an agent to a LiveAvatar avatar id (and optionally a voice).';

    public function handle(): int
    {
        $slug = (string) $this->option('slug');
        $id   = (string) $this->option('id');

        if ($slug === '' || $id === '') {
            $this->error('Both --slug and --id are required.');
            return 1;
        }

        $agent = Agent::where('slug', $slug)->first();
        if (!$agent) {
            $this->error("Agent '{$slug}' not found.");
            return 1;
        }

        $updates = [
            'liveavatar_avatar_id'  => $id,
            // Force re-create against the new avatar — the cached context
            // was bound to the previous one.
            'liveavatar_context_id' => null,
        ];

        $voice = $this->option('voice');
        if ($this->option('clear-voice')) {
            $updates['liveavatar_voice_id'] = null;
        } elseif ($voice !== null && $voice !== '') {
            $updates['liveavatar_voice_id'] = (string) $voice;
        }

        $agent->update($updates);

        $this->info("Updated {$agent->slug}:");
        foreach ($updates as $k => $v) {
            $this->line("  {$k} = " . ($v === null ? 'NULL' : (string) $v));
        }
        $this->line('');
        $this->line("Run `php artisan liveavatar:test --avatar={$slug}` to mint a fresh session.");

        return 0;
    }
}
