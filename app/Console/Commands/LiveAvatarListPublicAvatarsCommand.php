<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Lists the public avatars LiveAvatar offers, so ops can pick one
 * for sandbox-mode testing without bouncing through the dashboard.
 *
 *   php artisan liveavatar:list-avatars
 *
 * Once you find an avatar you want, copy its UUID and update the
 * agent row:
 *
 *   php artisan tinker
 *   \App\Models\Agent::where('slug','nora')->update([
 *     'liveavatar_avatar_id'   => '<uuid from this list>',
 *     'liveavatar_context_id'  => null,  // force re-create on next use
 *   ]);
 */
class LiveAvatarListPublicAvatarsCommand extends Command
{
    protected $signature = 'liveavatar:list-avatars';

    protected $description = 'List LiveAvatar public avatars (id + name + flags) for sandbox-friendly picking.';

    public function handle(): int
    {
        $apiKey = (string) config('services.liveavatar.api_key', '');
        if ($apiKey === '') {
            $this->error('LIVEAVATAR_API_KEY is empty.');
            return 1;
        }

        $base = rtrim((string) config('services.liveavatar.base_url', 'https://api.liveavatar.com'), '/');

        $this->info('Fetching public avatars...');
        try {
            $response = Http::withHeaders([
                    'X-API-KEY'    => $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(20)
                ->acceptJson()
                ->get("{$base}/v1/avatars/public");
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return 1;
        }

        if (!$response->successful()) {
            $this->error("HTTP {$response->status()}: " . $response->body());
            return 1;
        }

        $data = $response->json('data');
        $list = is_array($data) ? $data : (is_array($data['items'] ?? null) ? $data['items'] : []);

        if (empty($list)) {
            $this->warn('No public avatars returned. Raw response:');
            $this->line($response->body());
            return 0;
        }

        // Print one row per avatar with the fields we care about.
        // Field names defensive — different LiveAvatar API revisions have
        // shipped slightly different shapes.
        $rows = [];
        foreach ($list as $a) {
            if (!is_array($a)) continue;
            $sandboxFlag = $a['is_sandbox_supported']
                ?? $a['sandbox_supported']
                ?? $a['supports_sandbox']
                ?? null;
            $rows[] = [
                $a['id']         ?? '—',
                mb_substr((string) ($a['name'] ?? '—'), 0, 40),
                $sandboxFlag === null ? '?' : ($sandboxFlag ? '✓' : '·'),
                (string) ($a['gender']   ?? '—'),
                (string) ($a['language'] ?? $a['default_language'] ?? '—'),
            ];
        }

        $this->table(['ID', 'Name', 'Sandbox', 'Gender', 'Lang'], $rows);
        $this->line('');
        $this->line('Pick an avatar with ✓ in the Sandbox column for free testing.');
        $this->line('Then update the agent: see this command\'s docblock for the tinker snippet.');

        return 0;
    }
}
