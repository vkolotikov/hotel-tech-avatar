<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mints a short-lived session for the LiveAvatar streaming-avatar
 * platform (HeyGen's post-April-2026 successor product).
 *
 * Mobile calls POST /api/v1/liveavatar/session with { avatar_slug },
 * gets back either a ready-to-embed URL / token / session payload,
 * and hands it to the React Native WebView that renders the actual
 * WebRTC talking head.
 *
 * Returned status semantics:
 *   200 — session created, payload usable by the client
 *   404 — avatar_slug doesn't resolve to an agent we own
 *   422 — agent exists but has no liveavatar_avatar_id configured yet
 *         (operator hasn't mapped it in admin UI or config)
 *   503 — server-side LIVEAVATAR_API_KEY is empty — feature is
 *         disabled at the platform level; client should show
 *         "Voice avatar not configured" rather than an error
 *   502 — upstream LiveAvatar call failed; log the body, surface a
 *         generic error. Mobile can fall back to text + OpenAI TTS.
 *
 * The exact upstream URL and payload shape is pending — LiveAvatar's
 * public docs don't disclose it without an API key. This controller
 * is structured so that filling in $sessionEndpoint + response
 * extraction are the only code changes needed once keys land.
 */
final class LiveAvatarController extends Controller
{
    public function createSession(Request $request): JsonResponse
    {
        $apiKey = (string) config('services.liveavatar.api_key', '');
        if ($apiKey === '') {
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

        $baseUrl = rtrim((string) config('services.liveavatar.base_url'), '/');
        $timeout = (int) config('services.liveavatar.timeout', 15);

        // Upstream endpoint path is pending final confirmation from the
        // LiveAvatar developer portal (requires an active account to
        // access). When confirmed, replace this stub with the real call:
        //   $response = Http::withHeaders(['X-API-Key' => $apiKey])
        //       ->timeout($timeout)
        //       ->acceptJson()
        //       ->post("{$baseUrl}/v2/embeddings", [
        //           'avatar_id' => $agent->liveavatar_avatar_id,
        //           'voice_id'  => $agent->liveavatar_voice_id,
        //           'quality'   => config('services.liveavatar.default_quality'),
        //       ]);
        //
        // For now, short-circuit with 501 so the mobile UI shows the
        // "configuration pending" state consistently rather than
        // misleading the user with a successful call against a stub.
        Log::info('LiveAvatarController: upstream call skipped — endpoint pending', [
            'agent_slug'  => $agent->slug,
            'avatar_id'   => $agent->liveavatar_avatar_id,
        ]);

        return response()->json([
            'error' => 'LiveAvatar session creation is not yet wired — finish ops setup and enable in code.',
            'code'  => 'liveavatar_endpoint_pending',
        ], 501);

        // When the upstream call is wired, shape of success response
        // handed back to mobile will be roughly:
        //
        // return response()->json([
        //     'session' => [
        //         'embed_url' => $response->json('data.embed_url'),
        //         'token'     => $response->json('data.token'),
        //         'expires_at'=> $response->json('data.expires_at'),
        //         'avatar'    => [
        //             'id'    => $agent->liveavatar_avatar_id,
        //             'voice' => $agent->liveavatar_voice_id,
        //             'quality'=> config('services.liveavatar.default_quality'),
        //         ],
        //     ],
        // ]);
    }
}
