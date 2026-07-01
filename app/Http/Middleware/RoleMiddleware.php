<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  array<int, string>  $roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        // Check against the static role column
        $hasStaticRole = in_array((string)($user->role ?? ''), $roles, true);
        
        // Check against Spatie roles if the trait is used
        $hasSpatieRole = method_exists($user, 'hasAnyRole') && $user->hasAnyRole($roles);

        if (!$hasStaticRole && !$hasSpatieRole) {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        return $next($request);
    }
}
