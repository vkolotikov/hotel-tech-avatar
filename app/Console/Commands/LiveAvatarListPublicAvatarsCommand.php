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
    protected $signature = 'liveavatar:list-avatars {--raw : dump the raw JSON response instead of a table} {--filter= : substring to match against avatar name (case-insensitive)}';

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

        if ($this->option('raw')) {
            $this->line($response->body());
            return 0;
        }

        $list = $this->extractAvatarList($response->json());

        if (empty($list)) {
            $this->warn('No public avatars decoded. Re-run with --raw to inspect the response.');
            return 0;
        }

        // Field names are defensive — different LiveAvatar API revisions
        // have shipped subtly different shapes for the sandbox flag.
        $filter = (string) ($this->option('filter') ?? '');
        $rows = [];
        foreach ($list as $a) {
            if (!is_array($a)) continue;
            $name = (string) ($a['name'] ?? '—');
            if ($filter !== '' && stripos($name, $filter) === false) continue;

            $sandboxFlag = $a['is_sandbox_supported']
                ?? $a['sandbox_supported']
                ?? $a['supports_sandbox']
                ?? $a['sandbox_enabled']
                ?? null;

            $rows[] = [
                (string) ($a['id'] ?? '—'),
                mb_substr($name, 0, 40),
                $sandboxFlag === null ? '?' : ($sandboxFlag ? '✓' : '·'),
                (string) ($a['gender']   ?? '—'),
                (string) ($a['language'] ?? $a['default_language'] ?? '—'),
            ];
        }

        if (empty($rows)) {
            $this->warn('No avatars matched the filter.');
            return 0;
        }

        $this->table(['ID', 'Name', 'Sandbox', 'Gender', 'Lang'], $rows);
        $this->line('');
        $this->line('Total: ' . count($rows) . ' shown.');
        $this->line('Pick an avatar with ✓ in the Sandbox column for free testing.');
        $this->line('Then update the agent: see this command\'s docblock for the tinker snippet.');
        $this->line('If the Sandbox column is all "?", LiveAvatar does not expose the flag in this');
        $this->line('list — pick a Preset Avatar from app.liveavatar.com (those are sandbox-eligible).');

        return 0;
    }

    /**
     * Walk the response payload looking for the actual list of avatars.
     * LiveAvatar wraps in a few different shapes depending on the
     * endpoint revision: data may be the list itself, or contain
     * `items` / `avatars` / `results`. Some revisions wrap everything
     * one level deeper still. Defensive in both directions.
     *
     * @param array<string,mixed>|null $body
     * @return array<int, array<string,mixed>>
     */
    private function extractAvatarList(?array $body): array
    {
        if (!is_array($body)) return [];

        // Common envelope: { code, message, data }
        $candidates = [
            $body['data']                 ?? null,
            $body['data']['items']        ?? null,
            $body['data']['avatars']      ?? null,
            $body['data']['results']      ?? null,
            $body['data']['data']         ?? null, // double-wrapped
            $body['items']                ?? null,
            $body['avatars']              ?? null,
            $body['results']              ?? null,
            // Fall back to the body itself if it's a flat list
            $body,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) continue;
            if (!array_is_list($candidate)) continue;
            // Sanity-check: items should be objects with at least an id field
            $first = $candidate[0] ?? null;
            if (is_array($first) && (isset($first['id']) || isset($first['avatar_id']) || isset($first['name']))) {
                return $candidate;
            }
        }

        return [];
    }
}
