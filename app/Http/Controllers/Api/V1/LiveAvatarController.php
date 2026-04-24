<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\LiveAvatar\LiveAvatarClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mints a short-lived LITE-mode session for the LiveAvatar streaming-
 * avatar platform (HeyGen's post-April-2026 successor).
 *
 * LITE mode means: LiveAvatar only does the WebRTC video + lip-sync.
 * Our backend still owns the LLM (retrieval, grounding, citation
 * validation) and our own TTS — we just send the generated audio
 * over a WebSocket to the avatar for visual rendering. FULL mode
 * would have LiveAvatar bypass our pipeline, which is a non-starter
 * for the wellness safety contract.
 *
 * Flow on a session request:
 *   1. Resolve agent by slug. 404 if missing.
 *   2. Ensure the agent has an avatar_id mapped. 422 if not.
 *   3. Lazy-create a LiveAvatar Context for this agent (one-time,
 *      cached in agents.liveavatar_context_id). Contexts hold the
 *      persona + opening greeting the avatar shows between turns.
 *   4. POST /v2/embeddings to get a ready-to-embed URL + session id.
 *   5. Return to the client: { session: { embed_url, ... }, avatar: { ... } }.
 *
 * Status semantics:
 *   200 — session ready
 *   404 — avatar_slug unknown
 *   422 — agent has no liveavatar_avatar_id yet
 *   502 — upstream LiveAvatar error (context create or embed create)
 *   503 — server-side LIVEAVATAR_API_KEY missing
 */
final class LiveAvatarController extends Controller
{
    public function __construct(
        private readonly LiveAvatarClient $client,
    ) {}

    public function createSession(Request $request): JsonResponse
    {
        if (!$this->client->isConfigured()) {
            return response()->json([
                'error' => 'LiveAvatar is not configured on the server.',
                'code'  => 'liveavatar_disabled',
            ], 503);
        }

        $validated = $request->validate([
            'avatar_slug' => 'required|string|max:64',
        ]);

        $agent = Agent::where('slug', $validated['avatar_slug'])->first();
        if (!$agent) {
            return response()->json([
                'error' => 'Avatar not found.',
                'code'  => 'avatar_not_found',
            ], 404);
        }

        if (empty($agent->liveavatar_avatar_id)) {
            return response()->json([
                'error' => "Avatar '{$agent->slug}' is not mapped to a LiveAvatar ID yet.",
                'code'  => 'avatar_not_mapped',
            ], 422);
        }

        // Ensure a context exists. First-time session for an avatar
        // costs one extra upstream call; thereafter it's a single
        // POST /v2/embeddings.
        if (empty($agent->liveavatar_context_id)) {
            try {
                $contextId = $this->client->createContext($agent);
            } catch (\Throwable $e) {
                Log::error('LiveAvatar: context creation failed', [
                    'agent_slug' => $agent->slug,
                    'error'      => $e->getMessage(),
                ]);
                return response()->json([
                    'error' => 'Upstream error creating LiveAvatar context.',
                    'code'  => 'context_create_failed',
                ], 502);
            }
            $agent->update(['liveavatar_context_id' => $contextId]);
        }

        try {
            $session = $this->client->createEmbedSession($agent);
        } catch (\Throwable $e) {
            Log::error('LiveAvatar: embed session creation failed', [
                'agent_slug' => $agent->slug,
                'error'      => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Upstream error creating LiveAvatar session.',
                'code'  => 'session_create_failed',
            ], 502);
        }

        // LITE-mode session token — the JWT the mobile client uses to
        // call /v1/sessions/start directly against LiveAvatar. Failing
        // this is non-fatal; the client can still render the embed
        // URL for video preview and retry connect later.
        $connect = null;
        try {
            $token = $this->client->createSessionToken($agent);
            $connect = [
                'session_id'    => $token['session_id'],
                'session_token' => $token['session_token'],
                'start_url'     => rtrim((string) config('services.liveavatar.base_url'), '/') . '/v1/sessions/start',
            ];
        } catch (\Throwable $e) {
            Log::warning('LiveAvatar: session token minting failed — embed still usable', [
                'agent_slug' => $agent->slug,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'session' => [
                'embed_id'    => $session['embed_id'] ?? null,
                'url'         => $session['url'] ?? null,
                'script'      => $session['script'] ?? null,
                'orientation' => $session['orientation'] ?? 'horizontal',
                'sandbox'     => (bool) config('services.liveavatar.sandbox', true),
            ],
            'connect' => $connect,
            'avatar' => [
                'slug'       => $agent->slug,
                'name'       => $agent->name,
                'avatar_id'  => $agent->liveavatar_avatar_id,
                'context_id' => $agent->liveavatar_context_id,
            ],
        ]);
    }

    /**
     * Proxy for periodic keep-alive pings from the mobile client —
     * keeps the upstream session token on the server, not the wire.
     * Mobile hits this every ~30s while a live session is open.
     *
     * Returns 200 when accepted, 410 when the upstream session has
     * already ended (client should stop its keep-alive loop).
     */
    public function keepAlive(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => 'required|string',
        ]);

        try {
            $alive = $this->client->keepAlive($sessionId, $validated['session_token']);
        } catch (\Throwable $e) {
            Log::warning('LiveAvatar keep-alive: upstream error', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Upstream error.'], 502);
        }

        if (!$alive) {
            return response()->json(['error' => 'Session already ended.'], 410);
        }
        return response()->json(['alive' => true]);
    }

    /**
     * Proxy for session termination. Called when the user closes the
     * live-avatar modal so we don't leak credit for orphaned sessions.
     * Idempotent — already-stopped sessions return 200 not 404.
     */
    public function stopSession(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => 'required|string',
        ]);

        try {
            $this->client->stopSession($sessionId, $validated['session_token']);
        } catch (\Throwable $e) {
            Log::warning('LiveAvatar stop-session: upstream error', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Upstream error.'], 502);
        }

        return response()->json(['stopped' => true]);
    }
}
