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
        $sdkPath = base_path('../../../saas/packages/auth-sdk/php/SaasAuth.php');
        if (!file_exists($sdkPath)) {
            return ['valid' => false, 'error' => 'Auth SDK not found'];
        }

        require_once $sdkPath;

        $auth = new \SaasAuth([
            'platform_url' => config('services.saas.platform_url', 'http://localhost:3000'),
            'jwt_secret'   => config('services.saas.jwt_secret', ''),
        ]);

        return $auth->verifyToken($token);
    }
}
