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
     * Create (or re-create — LiveAvatar doesn't have a lookup-by-name
     * endpoint) a Context for this agent. Returns the context id.
     *
     * @throws \RuntimeException when the upstream call fails.
     */
    public function createContext(Agent $agent): string
    {
        $payload = [
            'name'         => "{$agent->name} — WellnessAI",
            'prompt'       => $this->buildContextPrompt($agent),
            'opening_text' => $this->buildOpeningText($agent),
        ];

        $response = $this->http()->post($this->url('/v1/contexts'), $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "LiveAvatar /v1/contexts failed (HTTP {$response->status()}): " . $response->body(),
            );
        }

        $id = $response->json('data.id');
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(
                "LiveAvatar /v1/contexts returned an unexpected payload: " . $response->body(),
            );
        }

        return $id;
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
