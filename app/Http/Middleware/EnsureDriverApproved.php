<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureDriverApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || ($user->role ?? null) !== 'driver') {
            return response()->json(['message' => 'Accès refusé'], 403);
        }

        $profile = DB::table('driver_profiles')->where('user_id', $user->id)->first();
        if (!$profile || ($profile->status ?? 'pending') !== 'approved') {
            return response()->json(['message' => 'Driver non approuvé'], 403);
        }

        return $next($request);
    }
}
