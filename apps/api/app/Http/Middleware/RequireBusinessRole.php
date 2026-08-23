<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Enums\BusinessRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireBusinessRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->attributes->get('support_access') === true) {
            return $next($request);
        }

        $membership = $request->attributes->get('membership');
        $role = $membership?->role;
        $value = $role instanceof BusinessRole ? $role->value : $role;
        abort_unless(in_array($value, $roles, true), 403);

        return $next($request);
    }
}
