<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HeygenController extends Controller
{
    /**
     * Create a short-lived streaming session token for the HeyGen
     * Streaming Avatar SDK. The browser calls this to obtain a token,
     * then hands it to @heygen/streaming-avatar which opens a WebRTC
     * session directly with HeyGen.
     */
    public function token(): JsonResponse
    {
        $apiKey = (string) config('services.heygen.api_key');

        if ($apiKey === '') {
            return response()->json([
                'error' => 'HeyGen is not configured on the server.',
            ], 503);
        }

        $baseUrl = rtrim((string) config('services.heygen.base_url'), '/');
        $timeout = (int) config('services.heygen.timeout', 15);

        $response = Http::withHeaders([
                'X-Api-Key'    => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout($timeout)
            ->post("{$baseUrl}/v1/streaming.create_token", (object) []);

        if (!$response->successful()) {
            Log::error('HeyGen token creation failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return response()->json([
                'error' => 'Failed to create HeyGen session token.',
            ], 502);
        }

        $token = $response->json('data.token');

        if (!$token) {
            Log::error('HeyGen token response missing token field', [
                'body' => $response->body(),
            ]);

            return response()->json([
                'error' => 'Invalid HeyGen response.',
            ], 502);
        }

        return response()->json([
            'token'   => $token,
            'config'  => [
                'avatar_name' => config('services.heygen.default_avatar'),
                'voice_id'    => config('services.heygen.default_voice'),
                'quality'     => config('services.heygen.default_quality'),
            ],
        ]);
    }
}
