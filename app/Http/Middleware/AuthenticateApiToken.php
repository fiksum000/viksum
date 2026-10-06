<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $plain = (string) $request->bearerToken();
        if (! preg_match('/^(\d+)\|([A-Za-z0-9_-]{32,128})$/', $plain, $matches)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = ApiToken::with('user')->find((int) $matches[1]);
        if (! $token || ! $token->user || ! in_array($token->user->role, ['super_admin', 'admin'], true) || ! hash_equals($token->token_hash, hash('sha256', $matches[2])) || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        if (! in_array($ability, $token->abilities ?? [], true)) {
            return response()->json(['message' => 'Token tidak memiliki izin untuk operasi ini.'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_user', $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
