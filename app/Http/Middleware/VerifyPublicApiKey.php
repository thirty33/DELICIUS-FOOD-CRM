<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPublicApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');
        $configKey = config('services.public_api.key');

        if (empty($configKey)) {
            return response()->json([
                'message' => 'error',
                'errors' => ['api_key' => ['API key not configured on server.']],
            ], 500);
        }

        if (empty($apiKey) || !hash_equals($configKey, $apiKey)) {
            return response()->json([
                'message' => 'error',
                'errors' => ['api_key' => ['Invalid or missing API key.']],
            ], 401);
        }

        return $next($request);
    }
}
