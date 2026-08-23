<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = $request->user()->platformRoles()
            ->whereIn('role', $roles)
            ->exists();

        abort_unless($allowed, 403);

        return $next($request);
    }
}
