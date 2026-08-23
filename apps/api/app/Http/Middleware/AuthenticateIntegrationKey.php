<?php

namespace App\Http\Middleware;

use App\Domain\Integrations\Models\IntegrationApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401, 'A valid integration API key is required.');
        $key = IntegrationApiKey::with('business')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();
        abort_unless($key !== null && $key->business->status->value === 'active', 401, 'The integration API key is invalid or inactive.');
        abort_unless(in_array('visits:write', $key->abilities ?? [], true), 403);
        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('integration_key', $key);
        $request->attributes->set('business', $key->business);

        return $next($request);
    }
}
