<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\LiveAvatar\LiveAvatarClient;
use Illuminate\Console\Command;

/**
 * One-shot end-to-end smoke test for the LiveAvatar integration.
 * Proves that the API key + avatar_id + context lifecycle work against
 * the real upstream before we wire mobile:
 *
 *   php artisan liveavatar:test --avatar=nora
 *
 * Prints the embed URL on success. Open that URL in any browser —
 * you should see the avatar's face in a live WebRTC stream. If it
 * loads, the full backend path is good.
 */
class LiveAvatarTestCommand extends Command
{
    protected $signature = 'liveavatar:test {--avatar=nora : agent slug}';

    protected $description = 'Create a LiveAvatar session for one agent and print the embed URL (sandbox mode).';

    public function handle(LiveAvatarClient $client): int
    {
        if (!$client->isConfigured()) {
            $this->error('LIVEAVATAR_API_KEY is empty — set it in Laravel Cloud env first.');
            return 1;
        }

        $slug  = (string) $this->option('avatar');
        $agent = Agent::where('slug', $slug)->first();
        if (!$agent) {
            $this->error("Avatar '{$slug}' not found.");
            return 1;
        }

        if (empty($agent->liveavatar_avatar_id)) {
            $this->error(
                "Agent '{$slug}' has no liveavatar_avatar_id mapped. " .
                "Set it via tinker or an admin UI."
            );
            return 1;
        }

        $this->line("Agent:      {$agent->slug} (id {$agent->id})");
        $this->line("Avatar ID:  {$agent->liveavatar_avatar_id}");

        if (empty($agent->liveavatar_context_id)) {
            $this->info('Context not cached — creating one upstream...');
            try {
                $contextId = $client->createContext($agent);
            } catch (\Throwable $e) {
                $this->error('Context creation failed: ' . $e->getMessage());
                return 1;
            }
            $agent->update(['liveavatar_context_id' => $contextId]);
            $this->line("Context ID: {$contextId} (new, cached on agent)");
        } else {
            $this->line("Context ID: {$agent->liveavatar_context_id} (cached)");
        }

        $sandbox = (bool) config('services.liveavatar.sandbox', true);
        $this->line('Sandbox:    ' . ($sandbox ? 'YES (no credit consumption)' : 'NO (live — credits will burn)'));
        $this->line('');

        $this->info('Minting embed session...');
        try {
            $session = $client->createEmbedSession($agent);
        } catch (\Throwable $e) {
            $this->error('Session creation failed: ' . $e->getMessage());
            return 1;
        }

        $url = $session['url'] ?? null;
        if (!$url) {
            $this->error('Session returned without an embed url. Raw payload:');
            $this->line(json_encode($session, JSON_PRETTY_PRINT));
            return 1;
        }

        $this->info('Embed URL ready:');
        $this->line('');
        $this->line($url);
        $this->line('');

        $this->info('Minting LITE-mode session token...');
        try {
            $token = $client->createSessionToken($agent);
        } catch (\Throwable $e) {
            $this->error('Token minting failed: ' . $e->getMessage());
            $this->line('(Embed still works for video preview; interactive LITE needs this call.)');
            return 1;
        }

        $this->line('Session ID:    ' . $token['session_id']);
        $this->line('Session token: ' . substr($token['session_token'], 0, 48) . '…');
        $this->line('');
        $this->line('Mobile client will POST /v1/sessions/start with this token (Bearer auth)');
        $this->line('to get the livekit + WebSocket credentials for LITE mode.');

        return 0;
    }
}
