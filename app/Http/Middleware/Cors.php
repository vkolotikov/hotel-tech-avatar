<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $this->headers($request));
        }

        $response = $next($request);

        foreach ($this->headers($request) as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }

    private function headers(Request $request): array
    {
        $allowed = env('CORS_ALLOWED_ORIGINS', '*');

        if ($allowed !== '*') {
            $origins = array_map('trim', explode(',', $allowed));
            $origin  = $request->header('Origin', '');
            $allowed = in_array($origin, $origins) ? $origin : $origins[0];
        }

        return [
            'Access-Control-Allow-Origin'  => $allowed,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Max-Age'       => '86400',
        ];
    }
}
