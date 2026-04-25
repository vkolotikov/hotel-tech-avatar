<?php

declare(strict_types=1);

namespace App\Services\LiveAvatar;

use App\Models\Agent;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the LiveAvatar API. Two endpoints are used
 * directly by the controller + the smoke-test command:
 *
 *   POST /v1/contexts        — creates a persona record for an agent
 *   POST /v2/embeddings      — mints a WebRTC-ready embed session
 *
 * Keeping this in a service (rather than inline in the controller)
 * lets the smoke-test artisan command reuse the same code path —
 * "if it works from the shell, it works from mobile" — and gives the
 * future reconciliation / cleanup jobs a single place to touch the
 * upstream API.
 */
final class LiveAvatarClient
{
    public function isConfigured(): bool
    {
        return (string) config('services.liveavatar.api_key', '') !== '';
    }

    /**
     * Get-or-create a Context for this agent. LiveAvatar enforces
     * unique-by-name on contexts per account, so we look up first via
     * GET /v1/contexts before attempting a create. If the create
     * itself races and 400s with "already exists", we fall back to a
     * list-and-find as a recovery path. Either way, returns the id.
     *
     * @throws \RuntimeException when neither lookup nor create succeed.
     */
    public function createContext(Agent $agent): string
    {
        $name = $this->buildContextName($agent);

        // 1. Look it up first — happy path on every call after the first.
        $existing = $this->findContextIdByName($name);
        if ($existing !== null) {
            return $existing;
        }

        // 2. Create.
        $response = $this->http()->post($this->url('/v1/contexts'), [
            'name'         => $name,
            'prompt'       => $this->buildContextPrompt($agent),
            'opening_text' => $this->buildOpeningText($agent),
        ]);

        if ($response->successful()) {
            $id = $response->json('data.id');
            if (is_string($id) && $id !== '') {
                return $id;
            }
            throw new \RuntimeException(
                "LiveAvatar /v1/contexts returned an unexpected payload: " . $response->body(),
            );
        }

        // 3. Race-recovery: a parallel request created the same name
        //    between our list and create. Re-list and adopt.
        if ($response->status() === 400 && stripos($response->body(), 'already exists') !== false) {
            $existing = $this->findContextIdByName($name);
            if ($existing !== null) {
                return $existing;
            }
        }

        throw new \RuntimeException(
            "LiveAvatar /v1/contexts failed (HTTP {$response->status()}): " . $response->body(),
        );
    }

    /**
     * Stable name per agent — the same agent always points at the same
     * context regardless of avatar swaps, so we don't leak orphaned
     * contexts every time someone tries a new face.
     */
    private function buildContextName(Agent $agent): string
    {
        return "{$agent->name} — WellnessAI";
    }

    /**
     * GET /v1/contexts (paginated) and find the first context whose
     * name matches exactly. Returns null when there's no match (or
     * when the list call fails — caller decides what to do).
     *
     * Walks pages while the response includes a `next` link.
     * page_size capped at 100 by LiveAvatar — we ask for the max so
     * an average account fits in one round-trip.
     */
    private function findContextIdByName(string $name): ?string
    {
        $page = 1;
        // Hard ceiling to keep us from looping forever on a misbehaving
        // upstream — 10 pages × 100 per page = 1000 contexts which is
        // far more than any sane account holds.
        $maxPages = 10;

        while ($page <= $maxPages) {
            try {
                $response = $this->http()->get($this->url('/v1/contexts'), [
                    'page'      => $page,
                    'page_size' => 100,
                ]);
            } catch (\Throwable) {
                return null;
            }
            if (!$response->successful()) {
                return null;
            }
            $payload = $response->json();
            if (!is_array($payload)) return null;

            $rows = $this->extractContextList($payload);
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $rowName = $row['name'] ?? null;
                $rowId   = $row['id']   ?? null;
                if (is_string($rowName) && is_string($rowId) && $rowName === $name) {
                    return $rowId;
                }
            }

            // Pagination — keep walking only if there's an explicit next.
            $next = $payload['data']['next'] ?? null;
            if (!$next) break;
            $page++;
        }
        return null;
    }

    /**
     * Pull the list of context rows out of a /v1/contexts response.
     * Real shape per the docs is data.results; older revisions used
     * data.items or a flat data array. Defensive in case of either.
     *
     * @param array<string,mixed> $payload
     * @return array<int, array<string,mixed>>
     */
    private function extractContextList(array $payload): array
    {
        $candidates = [
            $payload['data']['results']  ?? null,  // current shape (paginated)
            $payload['data']['items']    ?? null,  // older revisions
            $payload['data']['data']     ?? null,
            $payload['data']             ?? null,  // flat list at data
            $payload['results']          ?? null,
            $payload['items']            ?? null,
            $payload,                              // very flat fallback
        ];
        foreach ($candidates as $list) {
            if (is_array($list) && array_is_list($list)) {
                $first = $list[0] ?? null;
                // Sanity check: items should look like contexts
                if (is_array($first) && (isset($first['id']) || isset($first['name']))) {
                    return $list;
                }
            }
        }
        return [];
    }

    /**
     * Mint a LITE-mode session token. Returns a short-lived JWT the
     * client uses (as Bearer auth) to call /v1/sessions/start directly
     * against LiveAvatar — no further involvement from our backend
     * until keep-alive or stop.
     *
     * LITE vs FULL: FULL carries an `avatar_persona` object (voice +
     * context + language) because LiveAvatar runs an LLM. LITE leaves
     * that off — our server drives speech via WebSocket commands
     * instead, keeping the Phase-1 retrieval + verification pipeline
     * in charge.
     *
     * @return array{session_id:string,session_token:string}
     * @throws \RuntimeException when the upstream call fails.
     */
    public function createSessionToken(Agent $agent): array
    {
        $payload = array_filter([
            'mode'                 => 'LITE',
            'avatar_id'            => $agent->liveavatar_avatar_id,
            'is_sandbox'           => (bool) config('services.liveavatar.sandbox', true),
            'max_session_duration' => (int) config('services.liveavatar.max_session_seconds', 60),
        ], static fn ($v) => $v !== null && $v !== '');

        $response = $this->http()->post($this->url('/v1/sessions/token'), $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "LiveAvatar /v1/sessions/token failed (HTTP {$response->status()}): " . $response->body(),
            );
        }

        $data = $response->json('data');
        if (!is_array($data)
            || empty($data['session_id'])
            || empty($data['session_token'])
        ) {
            throw new \RuntimeException(
                "LiveAvatar /v1/sessions/token returned an unexpected payload: " . $response->body(),
            );
        }

        return [
            'session_id'    => (string) $data['session_id'],
            'session_token' => (string) $data['session_token'],
        ];
    }

    /**
     * Keep an already-started session alive. Called periodically by
     * the mobile client through our proxy to avoid leaking the
     * upstream session token client-side.
     *
     * Returns true if LiveAvatar accepts the ping; false if the
     * session is already gone (404) — the caller should stop pinging
     * in that case.
     */
    public function keepAlive(string $sessionId, string $sessionToken): bool
    {
        $response = Http::withToken($sessionToken)
            ->timeout((int) config('services.liveavatar.timeout', 15))
            ->acceptJson()
            ->post($this->url("/v1/sessions/{$sessionId}/keep-alive"));
        if ($response->status() === 404) {
            return false;
        }
        if (!$response->successful()) {
            throw new \RuntimeException(
                "LiveAvatar keep-alive failed (HTTP {$response->status()}): " . $response->body(),
            );
        }
        return true;
    }

    /**
     * Stop a live session. Idempotent — a 404 is treated as "already
     * stopped, no-op".
     */
    public function stopSession(string $sessionId, string $sessionToken): void
    {
        $response = Http::withToken($sessionToken)
            ->timeout((int) config('services.liveavatar.timeout', 15))
            ->acceptJson()
            ->delete($this->url("/v1/sessions/{$sessionId}"));
        if ($response->status() === 404) {
            return;
        }
        if (!$response->successful()) {
            throw new \RuntimeException(
                "LiveAvatar stop-session failed (HTTP {$response->status()}): " . $response->body(),
            );
        }
    }

    /**
     * Mint a session for the configured avatar + context. Returns the
     * raw `data` object from the API response (embed_id, url, script,
     * orientation) so callers can pass it through to clients.
     *
     * @return array{embed_id?:string,url?:string,script?:string,orientation?:string}
     * @throws \RuntimeException when the upstream call fails.
     */
    public function createEmbedSession(Agent $agent): array
    {
        $payload = array_filter([
            'avatar_id'            => $agent->liveavatar_avatar_id,
            'context_id'           => $agent->liveavatar_context_id,
            'voice_id'             => $agent->liveavatar_voice_id,
            'default_language'     => (string) config('services.liveavatar.default_language', 'en'),
            'is_sandbox'           => (bool) config('services.liveavatar.sandbox', true),
            'max_session_duration' => (int) config('services.liveavatar.max_session_seconds', 300),
            // Vertical orientation — mobile is portrait-primary; this
            // also ensures the embed fills the WebView rather than
            // leaving large horizontal-letterbox white bands on phone.
            'orientation'          => (string) config('services.liveavatar.orientation', 'vertical'),
        ], static fn ($v) => $v !== null && $v !== '');

        $response = $this->http()->post($this->url('/v2/embeddings'), $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "LiveAvatar /v2/embeddings failed (HTTP {$response->status()}): " . $response->body(),
            );
        }

        $data = $response->json('data');
        if (!is_array($data)) {
            throw new \RuntimeException(
                "LiveAvatar /v2/embeddings returned an unexpected payload: " . $response->body(),
            );
        }

        return $data;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
                'X-API-KEY'    => (string) config('services.liveavatar.api_key', ''),
                'Content-Type' => 'application/json',
            ])
            ->timeout((int) config('services.liveavatar.timeout', 15))
            ->acceptJson();
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('services.liveavatar.base_url', 'https://api.liveavatar.com'), '/');
        return $base . $path;
    }

    private function buildContextPrompt(Agent $agent): string
    {
        // Kept short on purpose — in LITE mode we drive speech from
        // our side, so the context prompt only matters for the avatar's
        // idle-state demeanor and opening turn. Our real persona +
        // safety rules live in the SystemPromptBuilder on our server.
        $parts = [
            "You are {$agent->name}, a wellness-education avatar in WellnessAI.",
        ];
        if (!empty($agent->role)) {
            $parts[] = "Specialty: {$agent->role}.";
        }
        if (!empty($agent->description)) {
            $parts[] = trim((string) $agent->description);
        }
        $parts[] = 'You do not diagnose, prescribe, or give medical advice. Redirect clinical questions to a licensed clinician.';

        return implode(' ', $parts);
    }

    private function buildOpeningText(Agent $agent): string
    {
        return "Hi, I'm {$agent->name}. How can I help you today?";
    }
}
