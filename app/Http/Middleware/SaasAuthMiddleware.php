<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SaasAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Check gateway-injected headers
        $gatewayUserId = $request->header('X-Saas-User-Id');
        if ($gatewayUserId) {
            $request->attributes->set('saas_authenticated', true);
            $request->attributes->set('saas_user_id', $gatewayUserId);
            $request->attributes->set('saas_user_email', $request->header('X-Saas-User-Email', ''));
            $request->attributes->set('saas_org_id', $request->header('X-Saas-Org-Id', ''));
            return $next($request);
        }

        // Check Bearer JWT
        $authHeader = $request->header('Authorization', '');
        if ($authHeader && str_starts_with(strtolower($authHeader), 'bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token !== '') {
                $result = $this->verifyJwt($token);
                if ($result['valid'] ?? false) {
                    $request->attributes->set('saas_authenticated', true);
                    $request->attributes->set('saas_user_id', $result['user']['id'] ?? '');
                    $request->attributes->set('saas_user_email', $result['user']['email'] ?? '');
                    $request->attributes->set('saas_org_id', $result['organization']['id'] ?? '');
                    return $next($request);
                }

                return response()->json([
                    'error' => $result['error'] ?? 'Invalid or expired token',
                    'code'  => 'unauthorized',
                ], 401);
            }
        }

        return response()->json(['error' => 'Authentication required', 'code' => 'unauthorized'], 401);
    }

    private function verifyJwt(string $token): array
    {
        $secret = config('services.saas.jwt_secret', env('SAAS_JWT_SECRET', ''));
        if (!$secret) {
            return ['valid' => false, 'error' => 'JWT secret not configured'];
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['valid' => false, 'error' => 'Invalid token format'];
        }

        [$header, $payload, $signature] = $parts;

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature)) {
            return ['valid' => false, 'error' => 'Invalid signature'];
        }

        $data = json_decode(base64_decode(str_pad(strtr($payload, '-_', '+/'), strlen($payload) % 4 ? strlen($payload) + 4 - strlen($payload) % 4 : strlen($payload), '=')), true);
        if (!$data) {
            return ['valid' => false, 'error' => 'Invalid payload'];
        }

        if (isset($data['exp']) && $data['exp'] < time()) {
            return ['valid' => false, 'error' => 'Token expired'];
        }

        return [
            'valid' => true,
            'user'  => [
                'id'    => $data['userId'] ?? $data['sub'] ?? '',
                'email' => $data['email'] ?? '',
            ],
            'organization' => isset($data['currentOrgId']) ? [
                'id'   => $data['currentOrgId'],
                'slug' => $data['currentOrgSlug'] ?? '',
            ] : null,
        ];
    }
}
